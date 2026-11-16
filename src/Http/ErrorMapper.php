<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Http;

use MailBlaze\Transactional\Exception\ApiException;
use MailBlaze\Transactional\Exception\AuthenticationException;
use MailBlaze\Transactional\Exception\DomainNotVerifiedException;
use MailBlaze\Transactional\Exception\MailBlazeException;
use MailBlaze\Transactional\Exception\NotFoundException;
use MailBlaze\Transactional\Exception\QuotaException;
use MailBlaze\Transactional\Exception\SendFailedException;
use MailBlaze\Transactional\Exception\ValidationException;

final class ErrorMapper
{
    /**
     * @param array<string, mixed> $payload
     */
    public function map(
        int $status,
        array $payload,
        #[\SensitiveParameter]
        ?string $secret,
    ): MailBlazeException {
        $code = self::code($payload['code'] ?? null);
        $error = isset($payload['error']) && is_string($payload['error'])
            ? $payload['error']
            : 'The Mail Blaze API request failed';
        $error = Redactor::redact($error, $secret);
        if (strlen($error) > 500) {
            $error = self::truncateUtf8($error, 500);
        }
        $message = $code === null ? $error : $error . ' (' . $code . ')';

        // 150.001: sending is turned off for the account. Not a credit problem, so not a QuotaException.
        if ($code === '150.001' || ($code === null && ($status === 401 || $status === 403))) {
            return new AuthenticationException($message, $status, $code, $error, $secret);
        }
        if ($code === null && $status === 400 && preg_match('/invalid api key/i', $error) === 1) {
            return new AuthenticationException($message, $status, $code, $error, $secret);
        }
        if ($code === '150.015') {
            return new QuotaException($message, $status, $code, $error, $secret);
        }
        if ($code === '150.010') {
            return new DomainNotVerifiedException($message, $status, $code, $error, $secret);
        }
        if (in_array($code, ['100.001', '150.002', '150.003', '150.004', '150.005', '150.006', '150.011', '150.012', '150.014'], true)) {
            return new ValidationException($message, $status, $code, $error, $secret);
        }
        if ($code === '150.009' || $code === '150.013') {
            return new NotFoundException($message, $status, $code, $error, $secret);
        }
        if ($code === '150.007' || $code === '150.016') {
            return new SendFailedException($message, $status, $code, $error, $secret);
        }
        // 150.008: the list could not be loaded. A server-side failure whatever the HTTP status.
        if ($code === '150.008') {
            return new ApiException($message, $status, $code, $error, $secret);
        }
        if ($status === 401 || $status === 403) {
            return new AuthenticationException($message, $status, $code, $error, $secret);
        }
        if ($status === 404) {
            return new NotFoundException($message, $status, $code, $error, $secret);
        }
        if ($status === 400 || $status === 422) {
            return new ValidationException($message, $status, $code, $error, $secret);
        }

        return new ApiException($message, $status, $code, $error, $secret);
    }

    private static function code(mixed $code): ?string
    {
        if (!is_string($code) || preg_match('/\A\d{3}\.\d{3}\z/', $code) !== 1) {
            return null;
        }

        return $code;
    }

    private static function truncateUtf8(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }
        if (function_exists('mb_strcut')) {
            return mb_strcut($value, 0, $maxBytes, 'UTF-8');
        }

        return substr($value, 0, $maxBytes);
    }
}
