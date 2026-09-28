<?php

namespace Rainwaves\ScaleMonitorClient\Support;

/**
 * Client-side redaction backstop — strips keys listed in
 * config('scale-monitor.redaction') (case-insensitively, at any depth) from
 * an outbound context/message payload before it's ever serialized and sent.
 * Scale Monitor's own ingestion applies its own Redactor too; this one runs
 * first, so a secret never leaves the host app's process at all.
 */
class Redactor
{
    private const string REDACTED = '[redacted]';

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    public static function redact(array $data, array $keys): array
    {
        $needles = array_map(strtolower(...), $keys);

        return self::walk($data, $needles);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $needles
     * @return array<array-key, mixed>
     */
    private static function walk(array $data, array $needles): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $needles, true)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::walk($value, $needles);
            }
        }

        return $data;
    }
}
