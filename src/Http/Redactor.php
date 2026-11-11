<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Http;

final class Redactor
{
    public static function redact(string $value, #[\SensitiveParameter] ?string $secret): string
    {
        if ($secret === null || $secret === '') {
            return $value;
        }

        $value = str_replace($secret, '[redacted]', $value);
        $encoded = rawurlencode($secret);
        if ($encoded !== $secret) {
            $value = str_replace($encoded, '[redacted]', $value);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    public static function redactContext(array $context, #[\SensitiveParameter] ?string $secret): array
    {
        $redacted = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $redacted[$key] = '[redacted]';
                continue;
            }
            $redacted[$key] = self::redactValue($value, $secret);
        }

        return $redacted;
    }

    private static function redactValue(mixed $value, #[\SensitiveParameter] ?string $secret): mixed
    {
        if (is_string($value)) {
            return self::redact($value, $secret);
        }
        if (is_array($value)) {
            return self::redactContext($value, $secret);
        }

        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        return preg_match('/authorization|api[-_]?key|html|plain|body|secret|attachment|password/i', $key) === 1;
    }
}
