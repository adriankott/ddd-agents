<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\LlmRendering;

/**
 * Removes what a model must never be handed from a text the system wrote about itself: an error message, the output
 * of a console command, a line of a stack trace. Such a text can quote a connection string, an environment value, a
 * signed URL, a request header, a command line with its options or a user's e-mail address.
 *
 * What is masked
 * - a private key block;
 * - a connection URL of a database, broker, cache or mail server whole (`postgresql://…`, `redis://…`, `amqp://…`),
 *   so neither its credentials nor its host or database name are shown; a PDO DSN (`pgsql:host=…;dbname=…`) whole;
 * - the credentials of any other URL (`scheme://user:password@host` keeps scheme and host);
 * - a bearer or basic token, a JSON web token, an AWS access key id, and any opaque value of 40 or more letters and
 *   digits;
 * - the value of an environment assignment (`APP_SECRET=…`, `export DATABASE_URL=…`; upper-case names);
 * - the value after a name that says it is a secret (`password=…`, `PGPASSWORD=…`, `token: …`, `"apiKey":"…"`,
 *   `--password …`, `Authorization: …`), quoted or not;
 * - an IPv4 address and a cluster-internal host name (`….svc`, `….svc.cluster.local`);
 * - an e-mail address.
 *
 * A pure text fold: it knows no record and reads nothing. It is no proof that a text is free of secrets: the tools
 * that use it also show only the first line of a message and never a whole payload.
 */
class LlmSecretRedaction
{
    /** @var string What a masked value reads as */
    public const string MASK = '[redacted]';

    /** @var string What a masked e-mail address reads as */
    public const string EMAIL_MASK = '[e-mail]';

    /** @var string What a masked IP address or internal host name reads as */
    public const string HOST_MASK = '[host]';

    /** @var string The names whose value is a secret (matched without case; a prefix like `PG`, `db_` or `mailer-` is allowed) */
    protected const string SECRET_NAMES = 'pass(?:word|wd|phrase)?|pwd|secret|token|api[_-]?key|apikey|access[_-]?key|private[_-]?key|client[_-]?secret'
    . '|credentials?|authorization|signature|x-amz-signature|x-amz-credential|sig|dsn|cookie|set-cookie|session[_-]?id';

    /** @var string URL schemes of infrastructure whose URL is masked whole: the host and database name are no one's business either */
    protected const string INFRASTRUCTURE_SCHEMES = 'postgres(?:ql)?|pgsql|pdo-[a-z]+|mysqli?|mariadb|redis|rediss|valkey|amqps?|doctrine|mongodb(?:\+srv)?'
    . '|sqs|smtps?|ldaps?|s?ftp|s3|memcached?|nats|kafka|sentry|null|in-memory|sync';

    /** @var string PDO driver prefixes of a DSN (`pgsql:host=…`) */
    protected const string PDO_DRIVERS = 'pgsql|mysql|sqlsrv|oci|odbc|sqlite|dblib|firebird|ibm|informix|cubrid';

    /**
     * @param string $text
     * @return string The text with every credential, connection string, environment value, token, internal address
     *                and e-mail address masked
     */
    public static function redact(string $text): string
    {
        // a private key block, to its end or to the end of the text
        $redactedText = (string)preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/s', self::MASK, $text);
        // the connection URL of a database, broker, cache or mail server, whole
        $redactedText = (string)preg_replace('#\b(' . self::INFRASTRUCTURE_SCHEMES . ')://[^\s"\'<>`]+#iu', '$1://' . self::MASK, $redactedText);
        // a PDO DSN: pgsql:host=…;port=…;dbname=…;user=…;password=…
        $redactedText = (string)preg_replace('/\b(' . self::PDO_DRIVERS . '):(?=[^\s"\']*(?:host|dbname|unix_socket|server|database)=)[^\s"\']+/iu', '$1:' . self::MASK, $redactedText);
        // scheme://user:password@host and scheme://token@host
        $redactedText = (string)preg_replace('#\b([a-z][a-z0-9+.\-]*://)[^\s/@"\']+@#iu', '$1' . self::MASK . '@', $redactedText);
        // Authorization: Bearer <token> and a bare bearer token
        $redactedText = (string)preg_replace('/\b(bearer|basic)\s+[A-Za-z0-9._~+\/=\-]{8,}/iu', '$1 ' . self::MASK, $redactedText);
        // a JSON web token
        $redactedText = (string)preg_replace('/\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{4,}/u', self::MASK, $redactedText);
        // an AWS access key id
        $redactedText = (string)preg_replace('/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/u', self::MASK, $redactedText);
        // an environment assignment: APP_SECRET=…, export DATABASE_URL="…" (upper-case names of three or more characters)
        $redactedText = (string)preg_replace(
            '/(?<![\w.\-\/])((?:export\s+)?[A-Z][A-Z0-9_]{2,}=)(?!\[redacted\])("[^"]*"|\'[^\']*\'|[^\s,;&)\]}]+)/u',
            '$1' . self::MASK,
            $redactedText
        );
        // a command-line option with a secret name and its value after a space: --password hunter2, --api-key abc
        $redactedText = (string)preg_replace(
            '/((?<![\w])--?[a-z0-9_.\-]*?(?:' . self::SECRET_NAMES . ')(?:[_-][a-z0-9]+)*)\s+(?![-\[])("[^"]*"|\'[^\']*\'|\S+)/iu',
            '$1 ' . self::MASK,
            $redactedText
        );
        // <secret name> = <value> and <secret name>: <value>, quoted or not, also inside a JSON text or a query string
        $redactedText = (string)preg_replace(
            '/(["\']?(?<![a-z0-9])[a-z0-9_.\-]*?(?:' . self::SECRET_NAMES . ')(?:[_-][a-z0-9]+)*["\']?\s*[=:]\s*)(?!\[redacted\])("[^"]*"|\'[^\']*\'|[^\s,;&)\]}]+)/iu',
            '$1' . self::MASK,
            $redactedText
        );
        // an opaque value of 40 or more letters and digits (API keys, signatures, session ids)
        $redactedText = (string)preg_replace('/(?<![A-Za-z0-9_\-])(?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{40,}(?![A-Za-z0-9_\-])/u', self::MASK, $redactedText);
        // an e-mail address
        $redactedText = (string)preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', self::EMAIL_MASK, $redactedText);
        // a cluster-internal host name and an IPv4 address
        $redactedText = (string)preg_replace('/\b[a-z0-9](?:[a-z0-9\-]*)(?:\.[a-z0-9\-]+)*\.svc(?:\.cluster\.local)?\b/iu', self::HOST_MASK, $redactedText);
        return (string)preg_replace('/(?<![\d.])(?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|1?\d?\d)){3}(?![\d.])/u', self::HOST_MASK, $redactedText);
    }

    /**
     * @param string|null $text
     * @param int $maxLength
     * @return string The first non-empty line of the text, masked and cut to $maxLength characters; '' for no text
     */
    public static function redactFirstLine(?string $text, int $maxLength): string
    {
        foreach (preg_split('/\R/u', (string)$text) ?: [] as $line) {
            $line = LlmTextRendering::toSingleLine($line);
            if ($line !== '') {
                return LlmTextRendering::shorten(self::redact($line), $maxLength);
            }
        }
        return '';
    }
}
