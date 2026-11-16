<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Exception;

/**
 * The request did not complete: a network, DNS, TLS, or timeout failure, or a response that
 * could not be read. A GET is safe to retry. A send may or may not have reached the API.
 *
 * reason() is a short, stable category. getPrevious(), when set, is a RedactedException that
 * names the HTTP client's exception class and carries its message with the API key removed.
 */
class TransportException extends MailBlazeException
{
    public const REASON_TIMEOUT = 'timeout';

    public const REASON_DNS = 'dns';

    public const REASON_TLS = 'tls';

    public const REASON_CONNECT = 'connect';

    public const REASON_CLIENT = 'client';

    public const REASON_RESPONSE = 'response';

    public const REASON_UNKNOWN = 'unknown';

    private const REASONS = [
        self::REASON_TIMEOUT,
        self::REASON_DNS,
        self::REASON_TLS,
        self::REASON_CONNECT,
        self::REASON_CLIENT,
        self::REASON_RESPONSE,
        self::REASON_UNKNOWN,
    ];

    private string $reason = self::REASON_UNKNOWN;

    /**
     * @internal
     */
    public static function create(string $message, string $reason, ?\Throwable $previous = null): self
    {
        $exception = new self($message, null, null, null, null, $previous);
        $exception->setReason($reason);

        return $exception;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * @return array<string, int|string|null>
     */
    public function __debugInfo(): array
    {
        return parent::__debugInfo() + ['reason' => $this->reason];
    }

    /**
     * @return array{message: string, code: int, file: string, line: int, httpStatus: int|null, errorCode: string|null, errorMessage: string|null, reason: string}
     */
    public function __serialize(): array
    {
        return parent::__serialize() + ['reason' => $this->reason];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        parent::__unserialize($data);
        $this->setReason(is_string($data['reason'] ?? null) ? $data['reason'] : self::REASON_UNKNOWN);
    }

    /**
     * An unknown value becomes REASON_UNKNOWN, so reason() only returns one of the REASON_* constants.
     */
    protected function setReason(string $reason): void
    {
        $this->reason = in_array($reason, self::REASONS, true) ? $reason : self::REASON_UNKNOWN;
    }
}
