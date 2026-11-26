"""
Helpers for a script that runs in the sandbox.

    from ddd_sandbox import ctx, result, input_path, output_path, now

    ctx.timezone        the time zone of the person or space the agent works for (also set as TZ)
    ctx.locale          the locale, e.g. "de-DE"; empty when unknown
    ctx.brand           the brand of the document a script makes: name, colors (dict; empty when none)
    ctx.input_files     the names of the input files in input_path()
    now()               the current moment in ctx.timezone, derived from the moment the platform handed in
    input_path(name)    the path of an input file (read only)
    output_path(name)   the path to write an output file to; every file written there is returned
    result(value)       the structured result the agent reads (JSON-serializable; a later call replaces it)
"""

import datetime
import json
import os
import time
from pathlib import Path

__all__ = ["ctx", "now", "result", "input_path", "output_path", "SandboxContext"]

_STARTED_MONOTONIC = time.monotonic()


class SandboxContext:
    """The context the platform hands into one execution."""

    def __init__(self, values):
        self.values = values
        self.timezone = values.get("timezone") or "UTC"
        self.locale = values.get("locale") or ""
        self.brand = values.get("brand") or {}
        self.input_files = list(values.get("inputFiles") or [])
        self.now_iso = values.get("now")

    def __repr__(self):
        return "SandboxContext(timezone=%r, locale=%r, input_files=%r)" % (self.timezone, self.locale, self.input_files)


def _load_context():
    context_path = os.environ.get("DDD_SANDBOX_CONTEXT")
    if not context_path or not os.path.exists(context_path):
        return SandboxContext({})
    with open(context_path, encoding="utf-8") as stream:
        return SandboxContext(json.load(stream))


ctx = _load_context()


def _zone():
    try:
        from zoneinfo import ZoneInfo
        return ZoneInfo(ctx.timezone)
    except Exception:  # noqa: BLE001 - an unknown zone falls back to UTC
        return datetime.timezone.utc


def now():
    """The current moment in ctx.timezone: the moment the platform handed in plus the time this script has run."""
    elapsed = datetime.timedelta(seconds=time.monotonic() - _STARTED_MONOTONIC)
    if ctx.now_iso:
        try:
            handed_in = datetime.datetime.fromisoformat(ctx.now_iso)
            if handed_in.tzinfo is None:
                handed_in = handed_in.replace(tzinfo=datetime.timezone.utc)
            return (handed_in + elapsed).astimezone(_zone())
        except ValueError:
            pass
    return datetime.datetime.now(tz=_zone())


def input_path(name):
    return Path(os.environ.get("DDD_SANDBOX_INPUT_DIRECTORY", "/workspace/in")) / os.path.basename(name)


def output_path(name):
    output_directory = Path(os.environ.get("DDD_SANDBOX_OUTPUT_DIRECTORY", "/workspace/out"))
    target = (output_directory / name).resolve()
    if output_directory.resolve() not in target.parents:
        raise ValueError("an output file must stay inside the output directory")
    target.parent.mkdir(parents=True, exist_ok=True)
    return target


def result(value):
    """Stores the structured result of the execution (JSON). A later call replaces an earlier one."""
    result_file = os.environ.get("DDD_SANDBOX_RESULT_FILE", "/workspace/.ddd/result.json")
    temporary_file = result_file + ".tmp"
    with open(temporary_file, "w", encoding="utf-8") as stream:
        json.dump(value, stream, default=str, ensure_ascii=False)
    os.replace(temporary_file, result_file)
    return value
