<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Http;

use MailBlaze\Transactional\Exception\ApiException;

/**
 * @internal
 */
final class Values
{
    public static function string(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new ApiException('The Mail Blaze API returned an unexpected ' . $field);
        }

        return $value;
    }

    public static function text(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new ApiException('The Mail Blaze API returned an unexpected ' . $field);
        }

        return $value;
    }

    public static function nullableString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::text($value, $field);
    }

    public static function nullableInt(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new ApiException('The Mail Blaze API returned an unexpected ' . $field);
    }

    public static function scalarTime(mixed $value): int|string|null
    {
        if ($value === null || is_string($value) || is_int($value)) {
            return $value;
        }

        throw new ApiException('The Mail Blaze API returned an unexpected send_at');
    }

    /**
     * @return array<string, mixed>
     */
    public static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new ApiException('The Mail Blaze API returned an unexpected ' . $field);
        }

        $object = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new ApiException('The Mail Blaze API returned an unexpected ' . $field);
            }
            $object[$key] = $item;
        }

        return $object;
    }
}
