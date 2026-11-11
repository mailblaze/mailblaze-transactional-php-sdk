<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Http;

/**
 * Holds API keys off the object so var_export() cannot print them.
 *
 * @internal
 */
final class SecretBag
{
    /** @var \WeakMap<object, string> */
    private static \WeakMap $values;

    public static function store(object $owner, #[\SensitiveParameter] string $secret): void
    {
        self::$values ??= new \WeakMap();
        self::$values[$owner] = $secret;
    }

    public static function get(object $owner): ?string
    {
        if (!isset(self::$values) || !isset(self::$values[$owner])) {
            return null;
        }

        return self::$values[$owner];
    }
}
