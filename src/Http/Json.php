<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Http;

use JsonException;
use MailBlaze\Transactional\Exception\ApiException;
use MailBlaze\Transactional\Exception\ValidationException;

final class Json
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ValidationException('The request payload could not be encoded as JSON');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException('The Mail Blaze API returned invalid JSON');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiException('The Mail Blaze API returned invalid JSON');
        }

        $object = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new ApiException('The Mail Blaze API returned invalid JSON');
            }
            $object[$key] = $value;
        }

        return $object;
    }
}
