<?php

namespace Rainwaves\ScaleMonitorClient\Support;

/**
 * Bounded data minimisation before queueing and transmission. Recognisable
 * secrets are scrubbed; arbitrary personal text cannot be guaranteed safe.
 * Exception messages use fixed summaries.
 */
class Redactor
{
    private const string REDACTED = '[redacted]';

    private const array KEYS = [
        'password', 'password_confirmation', 'otp', 'code', 'token', 'access_token',
        'refresh_token', 'api_key', 'apikey', 'secret', 'authorization', 'cookie',
        'set-cookie', 'session', 'csrf', 'id_number', 'identity_number', 'passport',
        'bank_account', 'account_number', 'card_number', 'cvv', 'expiry', 'medical',
        'diagnosis', 'email', 'phone', 'mobile', 'address', 'dob', 'date_of_birth',
        'first_name', 'last_name', 'full_name', 'name', 'document', 'attachment',
        'body', 'bindings', 'args',
        'credit_card',
    ];

    private const string CREDENTIAL_ASSIGNMENT = <<<'REGEX'
~((?:[?&]|\b)(?:password|passwd|pwd|secret|token|access_token|refresh_token|api[_-]?key|cookie)[\x22\x27]?\s*(?::|=>|=)\s*)(?:\[redacted\]|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|[^\s&,;}\]]+)~i
REGEX;

    public static function scrub(string $value): string
    {
        if (preg_match('/\b(?:SQLSTATE|select\s.+\sfrom|insert\s+into|update\s.+\sset|delete\s+from)\b/is', $value)) {
            return '[redacted SQL diagnostic; inspect application logs]';
        }
        // Inspect before truncating: otherwise a length limit can split a secret.
        $patterns = [
            '~(\b[a-z][a-z0-9+.-]*://)[^\s/@]+@~i' => '$1'.self::REDACTED.'@',
            '~\bBearer\s+[A-Za-z0-9._\~+\\/=-]+~i' => 'Bearer '.self::REDACTED,
            '~\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b~' => self::REDACTED,
            self::CREDENTIAL_ASSIGNMENT => '$1'.self::REDACTED,
        ];
        foreach ($patterns as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? self::REDACTED;
        }

        $value = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', self::REDACTED, $value) ?? self::REDACTED;

        return mb_substr($value, 0, 2000);
    }

    /** @param array<string, mixed> $payload */
    public static function payload(string $endpoint, array $payload): array
    {
        if ($endpoint === 'error' && ! isset($payload['fingerprint'])) {
            $payload['fingerprint'] = sha1(($payload['error_code'] ?? '').'|'.($payload['exception_class'] ?? '').'|'.trim(preg_replace('/\d+/', '#', strtolower((string) ($payload['message'] ?? ''))) ?? ''));
        }
        $payload = self::redact($payload, (array) config('scale-monitor.redaction', []));
        if (in_array($endpoint, ['error', 'failed-job'], true)) {
            $payload['message'] = $endpoint === 'error' ? 'Application exception reported; inspect application logs.' : 'Queued job failed; inspect application logs.';
        }

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    public static function redact(array $data, array $keys): array
    {
        $needles = array_map(strtolower(...), array_unique([...self::KEYS, ...$keys]));

        return self::walk($data, $needles);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $needles
     * @return array<array-key, mixed>
     */
    private static function walk(array $data, array $needles, int $depth = 0): array
    {
        if ($depth >= 8) {
            return [self::REDACTED];
        }
        // Keep protocol structures; constrain uncontrolled diagnostic maps.
        $data = array_slice($data, 0, 128, true);
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $needles, true)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::walk($value, $needles, $depth + 1);
            } elseif (is_string($value)) {
                $data[$key] = self::scrub($value);
            } elseif (is_object($value) || is_resource($value)) {
                $data[$key] = self::REDACTED;
            }
        }

        $cleaned = [];
        foreach ($data as $key => $value) {
            $cleaned[is_string($key) ? self::scrub($key) : $key] = $value;
        }

        return $cleaned;
    }
}
