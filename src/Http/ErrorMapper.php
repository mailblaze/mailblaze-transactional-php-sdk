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
        $code = isset($payload['code']) && is_string($payload['code']) ? $payload['code'] : null;
        $error = isset($payload['error']) && is_string($payload['error'])
            ? $payload['error']
            : 'The Mail Blaze API request failed';
        $message = $code === null ? $error : $error . ' (' . $code . ')';

        if ($code === '150.001' || ($code === null && ($status === 401 || $status === 403))) {
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
}
