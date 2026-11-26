"""
The sandbox runner: a long-lived HTTP server that runs ONE untrusted script at a time.

Identity model (see README.md):
  - the server runs as uid 0 inside the container with only the capabilities SETUID, SETGID and KILL;
  - every execution runs as uid/gid 10001 (no capabilities, no supplementary groups, PR_SET_NO_NEW_PRIVS, its own
    session and process group, resource limits);
  - after every execution every process of uid 10001 is killed (a /proc scan, which also catches processes that left
    the process group with setsid) and /workspace and /tmp are wiped.

Endpoints:
  POST /v1/executions   run one script (JSON in, JSON out); 503 while another execution runs or the server drains
  GET  /healthz         200 while the process serves
  GET  /readyz          200 when idle, 503 while busy or draining

Recycling: the server exits between two executions after SANDBOX_MAX_RUNS executions or SANDBOX_MAX_UPTIME_SECONDS
seconds of uptime; the orchestrator starts a fresh one.

Logging: one line per execution with its id, language, status and durations. Never code, file names, file contents,
output or results.
"""

import base64
import ctypes
import json
import os
import resource
import shutil
import signal
import stat
import subprocess
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

SERVICE_VERSION = "1"


def read_int_env(name, default):
    try:
        return int(os.environ.get(name, default))
    except ValueError:
        return default


PORT = read_int_env("SANDBOX_PORT", 8080)
WORKSPACE_DIRECTORY = os.environ.get("SANDBOX_WORKSPACE_DIRECTORY", "/workspace")
TEMPORARY_DIRECTORY = os.environ.get("SANDBOX_TEMPORARY_DIRECTORY", "/tmp")
EXECUTION_UID = read_int_env("SANDBOX_EXECUTION_UID", 10001)
EXECUTION_GID = read_int_env("SANDBOX_EXECUTION_GID", 10001)
MAX_RUNS = read_int_env("SANDBOX_MAX_RUNS", 200)
MAX_UPTIME_SECONDS = read_int_env("SANDBOX_MAX_UPTIME_SECONDS", 6 * 3600)
MAX_TIMEOUT_SECONDS = read_int_env("SANDBOX_MAX_TIMEOUT_SECONDS", 180)
DEFAULT_TIMEOUT_SECONDS = read_int_env("SANDBOX_DEFAULT_TIMEOUT_SECONDS", 60)
MAX_MEMORY_MB = read_int_env("SANDBOX_MAX_MEMORY_MB", 2048)
DEFAULT_MEMORY_MB = read_int_env("SANDBOX_DEFAULT_MEMORY_MB", 1024)
MAX_REQUEST_BYTES = read_int_env("SANDBOX_MAX_REQUEST_BYTES", 40 * 1024 * 1024)
MAX_INPUT_BYTES = read_int_env("SANDBOX_MAX_INPUT_BYTES", 25 * 1024 * 1024)
MAX_OUTPUT_BYTES = read_int_env("SANDBOX_MAX_OUTPUT_BYTES", 25 * 1024 * 1024)
MAX_OUTPUT_FILES = read_int_env("SANDBOX_MAX_OUTPUT_FILES", 20)
MAX_CODE_BYTES = read_int_env("SANDBOX_MAX_CODE_BYTES", 256 * 1024)
MAX_RESULT_BYTES = read_int_env("SANDBOX_MAX_RESULT_BYTES", 1024 * 1024)
MAX_STREAM_TAIL_BYTES = read_int_env("SANDBOX_MAX_STREAM_TAIL_BYTES", 8 * 1024)
MAX_PROCESSES = read_int_env("SANDBOX_MAX_PROCESSES", 64)
PYTHON_EXECUTABLE = os.environ.get("SANDBOX_PYTHON", "/usr/local/bin/python3")
NODE_EXECUTABLE = os.environ.get("SANDBOX_NODE", "/usr/local/bin/node")
TSX_LOADER = os.environ.get("SANDBOX_TSX_LOADER", "/opt/ddd-sandbox/node/node_modules/tsx/dist/loader.mjs")
NODE_MODULES_DIRECTORY = os.environ.get("SANDBOX_NODE_MODULES", "/opt/ddd-sandbox/node/node_modules")
PYTHON_HELPER_DIRECTORY = os.environ.get("SANDBOX_PYTHON_HELPERS", "/opt/ddd-sandbox/python")

LANGUAGE_PYTHON = "python"
LANGUAGE_TYPESCRIPT = "typescript"

STATUS_OK = "OK"
STATUS_ERROR = "ERROR"
STATUS_TIMEOUT = "TIMEOUT"
STATUS_LIMIT = "LIMIT"

PR_SET_NO_NEW_PRIVS = 38

STARTED_AT = time.monotonic()


class ExecutionRequestRefused(Exception):
    """A request the runner does not run: answered 400 with the reason."""


class RunnerState:
    """Busy flag, run counter and drain flag of the one server process."""

    def __init__(self):
        self.lock = threading.Lock()
        self.busy = False
        self.draining = False
        self.completed_runs = 0

    def try_acquire(self):
        with self.lock:
            if self.busy or self.draining:
                return False
            self.busy = True
            return True

    def release(self):
        with self.lock:
            self.completed_runs += 1
            self.busy = False
            if self.completed_runs >= MAX_RUNS or (time.monotonic() - STARTED_AT) >= MAX_UPTIME_SECONDS:
                self.draining = True
            return self.draining

    def is_ready(self):
        with self.lock:
            if not self.busy and (time.monotonic() - STARTED_AT) >= MAX_UPTIME_SECONDS:
                self.draining = True
            return not self.busy and not self.draining


RUNNER_STATE = RunnerState()


def log_line(message):
    sys.stderr.write(time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()) + " " + message + "\n")
    sys.stderr.flush()


def read_tail(path, max_bytes):
    """The last max_bytes of a file, decoded leniently; empty when the file is missing."""
    try:
        with open(path, "rb") as stream:
            stream.seek(0, os.SEEK_END)
            size = stream.tell()
            stream.seek(max(0, size - max_bytes))
            data = stream.read()
    except OSError:
        return "", False
    return data.decode("utf-8", errors="replace"), size > max_bytes


def sanitize_file_name(name):
    """A plain file name: no directories, no leading dot, at most 200 characters."""
    if not isinstance(name, str):
        raise ExecutionRequestRefused("a file name must be a string")
    base_name = os.path.basename(name.replace("\\", "/")).strip()
    if base_name in ("", ".", "..") or base_name.startswith("."):
        raise ExecutionRequestRefused("invalid file name")
    if len(base_name) > 200 or "\x00" in base_name:
        raise ExecutionRequestRefused("invalid file name")
    return base_name


def clamp(value, default, minimum, maximum):
    try:
        number = int(value)
    except (TypeError, ValueError):
        return default
    return max(minimum, min(number, maximum))


def kill_processes_of_execution_user():
    """SIGKILLs every process owned by the execution uid until none is left (a /proc scan, setsid escapes included)."""
    for _ in range(50):
        found = False
        for entry in os.listdir("/proc"):
            if not entry.isdigit():
                continue
            try:
                if os.stat("/proc/" + entry).st_uid != EXECUTION_UID:
                    continue
                found = True
                os.kill(int(entry), signal.SIGKILL)
            except (FileNotFoundError, ProcessLookupError, PermissionError):
                continue
        if not found:
            return True
        time.sleep(0.02)
    return False


def wipe_directory_contents(directory):
    """Removes everything below directory (symlinks are removed, never followed); the directory itself stays."""
    try:
        entries = os.listdir(directory)
    except FileNotFoundError:
        os.makedirs(directory, exist_ok=True)
        return
    for entry in entries:
        path = os.path.join(directory, entry)
        try:
            if os.path.islink(path) or not os.path.isdir(path):
                os.unlink(path)
            else:
                shutil.rmtree(path, ignore_errors=True)
        except OSError:
            pass


def prepare_workspace(request):
    """Writes code, context and input files; the output and state directories belong to the execution user."""
    wipe_directory_contents(WORKSPACE_DIRECTORY)
    wipe_directory_contents(TEMPORARY_DIRECTORY)
    os.chmod(TEMPORARY_DIRECTORY, 0o1777)
    input_directory = os.path.join(WORKSPACE_DIRECTORY, "in")
    output_directory = os.path.join(WORKSPACE_DIRECTORY, "out")
    state_directory = os.path.join(WORKSPACE_DIRECTORY, ".ddd")
    os.makedirs(input_directory, mode=0o755)
    os.makedirs(output_directory, mode=0o700)
    os.makedirs(state_directory, mode=0o700)
    os.chown(output_directory, EXECUTION_UID, EXECUTION_GID)
    os.chown(state_directory, EXECUTION_UID, EXECUTION_GID)
    os.chmod(WORKSPACE_DIRECTORY, 0o755)

    input_file_names = []
    input_bytes = 0
    for file_entry in request.get("files") or []:
        if not isinstance(file_entry, dict):
            raise ExecutionRequestRefused("files must be objects")
        file_name = sanitize_file_name(file_entry.get("name"))
        if file_name in input_file_names:
            raise ExecutionRequestRefused("duplicate input file name")
        try:
            content = base64.b64decode(file_entry.get("contentBase64") or "", validate=True)
        except (ValueError, TypeError):
            raise ExecutionRequestRefused("an input file is not valid base64")
        input_bytes += len(content)
        if input_bytes > MAX_INPUT_BYTES:
            raise ExecutionRequestRefused("the input files exceed the size limit")
        with open(os.path.join(input_directory, file_name), "wb") as stream:
            stream.write(content)
        os.chmod(os.path.join(input_directory, file_name), 0o444)
        input_file_names.append(file_name)

    language = request.get("language")
    code = request.get("code")
    if not isinstance(code, str) or code.strip() == "":
        raise ExecutionRequestRefused("code is empty")
    if len(code.encode("utf-8")) > MAX_CODE_BYTES:
        raise ExecutionRequestRefused("code exceeds the size limit")
    script_name = "main.py" if language == LANGUAGE_PYTHON else "main.ts"
    script_path = os.path.join(WORKSPACE_DIRECTORY, script_name)
    with open(script_path, "w", encoding="utf-8") as stream:
        stream.write(code)
    os.chmod(script_path, 0o444)

    context = request.get("context") if isinstance(request.get("context"), dict) else {}
    context = dict(context)
    context["inputFiles"] = input_file_names
    context_path = os.path.join(WORKSPACE_DIRECTORY, "context.json")
    with open(context_path, "w", encoding="utf-8") as stream:
        json.dump(context, stream)
    os.chmod(context_path, 0o444)

    if language == LANGUAGE_TYPESCRIPT:
        os.symlink(NODE_MODULES_DIRECTORY, os.path.join(WORKSPACE_DIRECTORY, "node_modules"))
    return script_path, context, state_directory, output_directory


def build_child_environment(context, state_directory, output_directory):
    timezone = context.get("timezone") if isinstance(context.get("timezone"), str) else "UTC"
    locale = context.get("locale") if isinstance(context.get("locale"), str) else ""
    return {
        "PATH": "/usr/local/bin:/usr/bin:/bin",
        "HOME": TEMPORARY_DIRECTORY,
        "TMPDIR": TEMPORARY_DIRECTORY,
        "LANG": "C.UTF-8",
        "TZ": timezone if timezone.replace("/", "").replace("_", "").replace("-", "").replace("+", "").isalnum() else "UTC",
        "PYTHONPATH": PYTHON_HELPER_DIRECTORY,
        "PYTHONDONTWRITEBYTECODE": "1",
        "PYTHONUNBUFFERED": "1",
        "MPLCONFIGDIR": os.path.join(TEMPORARY_DIRECTORY, "matplotlib"),
        "DDD_SANDBOX_CONTEXT": os.path.join(WORKSPACE_DIRECTORY, "context.json"),
        "DDD_SANDBOX_INPUT_DIRECTORY": os.path.join(WORKSPACE_DIRECTORY, "in"),
        "DDD_SANDBOX_OUTPUT_DIRECTORY": output_directory,
        "DDD_SANDBOX_RESULT_FILE": os.path.join(state_directory, "result.json"),
        "DDD_SANDBOX_LOCALE": locale,
    }


def build_child_preexec(memory_mb, cpu_seconds, language):
    """The limits and the identity switch, run in the child between fork and exec."""

    def preexec():
        os.setsid()
        libc = ctypes.CDLL(None, use_errno=True)
        if libc.prctl(PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0) != 0:
            os._exit(126)
        resource.setrlimit(resource.RLIMIT_CPU, (cpu_seconds, cpu_seconds + 1))
        resource.setrlimit(resource.RLIMIT_FSIZE, (MAX_OUTPUT_BYTES, MAX_OUTPUT_BYTES))
        resource.setrlimit(resource.RLIMIT_NOFILE, (256, 256))
        resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
        resource.setrlimit(resource.RLIMIT_NPROC, (MAX_PROCESSES, MAX_PROCESSES))
        if language == LANGUAGE_PYTHON:
            # Node reserves a large address space up front; its heap is capped by --max-old-space-size instead
            memory_bytes = memory_mb * 1024 * 1024
            resource.setrlimit(resource.RLIMIT_AS, (memory_bytes, memory_bytes))
        os.setgroups([])
        os.setgid(EXECUTION_GID)
        os.setuid(EXECUTION_UID)
        os.umask(0o077)

    return preexec


def collect_output_files(output_directory):
    """Regular files directly or below /workspace/out (symlinks and special files are ignored), within the limits."""
    collected = []
    total_bytes = 0
    truncated = False
    for root, directories, files in os.walk(output_directory, followlinks=False):
        directories.sort()
        for file_name in sorted(files):
            path = os.path.join(root, file_name)
            try:
                file_stat = os.lstat(path)
            except OSError:
                continue
            if not stat.S_ISREG(file_stat.st_mode):
                continue
            relative_name = os.path.relpath(path, output_directory)
            if len(collected) >= MAX_OUTPUT_FILES or total_bytes + file_stat.st_size > MAX_OUTPUT_BYTES:
                truncated = True
                continue
            with open(path, "rb") as stream:
                content = stream.read(MAX_OUTPUT_BYTES + 1)
            total_bytes += len(content)
            collected.append({
                "name": relative_name,
                "sizeInBytes": len(content),
                "contentBase64": base64.b64encode(content).decode("ascii"),
            })
    return collected, truncated


def read_result(state_directory):
    path = os.path.join(state_directory, "result.json")
    try:
        file_stat = os.lstat(path)
    except OSError:
        return None, None
    if not stat.S_ISREG(file_stat.st_mode):
        return None, "the result is not a regular file"
    if file_stat.st_size > MAX_RESULT_BYTES:
        return None, "the result exceeds " + str(MAX_RESULT_BYTES) + " bytes"
    with open(path, "rb") as stream:
        raw_result = stream.read()
    try:
        return json.loads(raw_result.decode("utf-8")), None
    except (ValueError, UnicodeDecodeError):
        return None, "the result is not valid JSON"


def run_execution(request):
    language = request.get("language")
    if language not in (LANGUAGE_PYTHON, LANGUAGE_TYPESCRIPT):
        raise ExecutionRequestRefused("language must be python or typescript")
    limits = request.get("limits") if isinstance(request.get("limits"), dict) else {}
    timeout_seconds = clamp(limits.get("timeoutSeconds"), DEFAULT_TIMEOUT_SECONDS, 1, MAX_TIMEOUT_SECONDS)
    memory_mb = clamp(limits.get("memoryMb"), DEFAULT_MEMORY_MB, 64, MAX_MEMORY_MB)

    script_path, context, state_directory, output_directory = prepare_workspace(request)
    stdout_path = os.path.join(TEMPORARY_DIRECTORY, ".stdout")
    stderr_path = os.path.join(TEMPORARY_DIRECTORY, ".stderr")
    if language == LANGUAGE_PYTHON:
        command = [PYTHON_EXECUTABLE, "-B", script_path]
    else:
        command = [NODE_EXECUTABLE, "--max-old-space-size=" + str(memory_mb), "--import", TSX_LOADER, script_path]

    started_at = time.monotonic()
    status = STATUS_OK
    exit_code = None
    usage_before = resource.getrusage(resource.RUSAGE_CHILDREN)
    with open(stdout_path, "wb") as stdout_stream, open(stderr_path, "wb") as stderr_stream:
        process = subprocess.Popen(
            command,
            cwd=WORKSPACE_DIRECTORY,
            env=build_child_environment(context, state_directory, output_directory),
            stdin=subprocess.DEVNULL,
            stdout=stdout_stream,
            stderr=stderr_stream,
            preexec_fn=build_child_preexec(memory_mb, timeout_seconds, language),
            close_fds=True,
        )
        try:
            exit_code = process.wait(timeout=timeout_seconds)
        except subprocess.TimeoutExpired:
            status = STATUS_TIMEOUT
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            exit_code = process.wait()
    kill_processes_of_execution_user()
    duration_ms = int((time.monotonic() - started_at) * 1000)
    usage_after = resource.getrusage(resource.RUSAGE_CHILDREN)
    cpu_ms = int(((usage_after.ru_utime - usage_before.ru_utime) + (usage_after.ru_stime - usage_before.ru_stime)) * 1000)
    peak_memory_mb = int(usage_after.ru_maxrss / 1024)

    if status == STATUS_OK and exit_code is not None and exit_code != 0:
        # a signal from a resource limit (CPU, file size) or the memory limit reads as LIMIT
        status = STATUS_LIMIT if exit_code in (-signal.SIGXCPU, -signal.SIGXFSZ, -signal.SIGKILL) else STATUS_ERROR
        stderr_tail_probe, _ = read_tail(stderr_path, 4096)
        if status == STATUS_ERROR and "MemoryError" in stderr_tail_probe:
            status = STATUS_LIMIT

    stdout_tail, stdout_truncated = read_tail(stdout_path, MAX_STREAM_TAIL_BYTES)
    stderr_tail, stderr_truncated = read_tail(stderr_path, MAX_STREAM_TAIL_BYTES)
    result, result_problem = read_result(state_directory)
    files, files_truncated = collect_output_files(output_directory)
    return {
        "executionId": request.get("executionId"),
        "status": status,
        "exitCode": exit_code,
        "stdoutTail": stdout_tail,
        "stdoutTruncated": stdout_truncated,
        "stderrTail": stderr_tail,
        "stderrTruncated": stderr_truncated,
        "result": result,
        "resultProblem": result_problem,
        "files": files,
        "filesTruncated": files_truncated,
        "durationMs": duration_ms,
        "cpuMs": cpu_ms,
        "peakMemoryMb": peak_memory_mb,
        "runnerVersion": SERVICE_VERSION,
    }


def clean_up_after_execution():
    kill_processes_of_execution_user()
    wipe_directory_contents(WORKSPACE_DIRECTORY)
    wipe_directory_contents(TEMPORARY_DIRECTORY)


class RunnerRequestHandler(BaseHTTPRequestHandler):
    server_version = "ddd-agents-sandbox/" + SERVICE_VERSION
    protocol_version = "HTTP/1.1"

    def log_message(self, format, *args):
        # the default access log would print paths only; keep the log to one line per execution
        return

    def send_json(self, status_code, payload):
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == "/healthz":
            self.send_json(200, {"status": "ok"})
        elif self.path == "/readyz":
            ready = RUNNER_STATE.is_ready()
            self.send_json(200 if ready else 503, {"ready": ready})
        else:
            self.send_json(404, {"error": "not found"})

    def do_POST(self):
        if self.path != "/v1/executions":
            self.send_json(404, {"error": "not found"})
            return
        content_length = clamp(self.headers.get("Content-Length"), -1, -1, MAX_REQUEST_BYTES + 1)
        if content_length < 0 or content_length > MAX_REQUEST_BYTES:
            self.send_json(413, {"error": "request too large or without length"})
            self.close_connection = True
            return
        raw_body = self.rfile.read(content_length)
        if not RUNNER_STATE.try_acquire():
            self.send_json(503, {"error": "busy"})
            return
        should_exit = False
        execution_id = None
        started_at = time.monotonic()
        try:
            try:
                request = json.loads(raw_body.decode("utf-8"))
                if not isinstance(request, dict):
                    raise ExecutionRequestRefused("the body must be a JSON object")
                execution_id = str(request.get("executionId") or "")[:64]
                response = run_execution(request)
                log_line("execution=%s language=%s status=%s exitCode=%s durationMs=%d" % (
                    execution_id, request.get("language"), response["status"], response["exitCode"], response["durationMs"]))
                self.send_json(200, response)
            except ExecutionRequestRefused as refusal:
                log_line("execution=%s refused" % execution_id)
                self.send_json(400, {"error": str(refusal)})
            except (ValueError, UnicodeDecodeError):
                self.send_json(400, {"error": "the body is not valid JSON"})
            except Exception as exception:  # noqa: BLE001 - the runner must answer and clean up whatever failed
                log_line("execution=%s runner failure %s" % (execution_id, type(exception).__name__))
                self.send_json(500, {"error": "runner failure"})
        finally:
            clean_up_after_execution()
            should_exit = RUNNER_STATE.release()
            log_line("execution=%s cleaned totalMs=%d" % (execution_id, int((time.monotonic() - started_at) * 1000)))
        if should_exit:
            log_line("recycling after %d runs" % RUNNER_STATE.completed_runs)
            self.close_connection = True
            threading.Thread(target=self.server.shutdown, daemon=True).start()


def main():
    if os.geteuid() != 0:
        log_line("the server must run as uid 0 (with only SETUID, SETGID and KILL) to switch executions to uid %d" % EXECUTION_UID)
        sys.exit(2)
    for directory in (WORKSPACE_DIRECTORY, TEMPORARY_DIRECTORY):
        os.makedirs(directory, exist_ok=True)
    clean_up_after_execution()
    server = ThreadingHTTPServer(("0.0.0.0", PORT), RunnerRequestHandler)
    server.daemon_threads = True
    signal.signal(signal.SIGTERM, lambda signum, frame: threading.Thread(target=server.shutdown, daemon=True).start())
    log_line("sandbox runner listening on %d (max runs %d, max uptime %ds)" % (PORT, MAX_RUNS, MAX_UPTIME_SECONDS))
    server.serve_forever()
    log_line("sandbox runner stopped")


if __name__ == "__main__":
    main()
