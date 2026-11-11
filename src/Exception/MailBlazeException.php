<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Exception;

use MailBlaze\Transactional\Http\Redactor;
use MailBlaze\Transactional\Http\SecretBag;
use RuntimeException;

class MailBlazeException extends RuntimeException
{
    private readonly ?int $httpStatus;

    private readonly ?string $errorCode;

    private readonly ?string $errorMessage;

    public function __construct(
        string $message,
        ?int $httpStatus = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        #[\SensitiveParameter]
        ?string $secret = null,
    ) {
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage === null ? null : Redactor::redact($errorMessage, $secret);
        parent::__construct(Redactor::redact($message, $secret));
        if ($secret !== null && $secret !== '') {
            SecretBag::store($this, $secret);
        }
        $this->dropTraceArguments();
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function __toString(): string
    {
        return Redactor::redact(parent::__toString(), SecretBag::get($this));
    }

    /**
     * @return array<string, int|string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'message' => $this->getMessage(),
            'httpStatus' => $this->httpStatus,
            'errorCode' => $this->errorCode,
            'errorMessage' => $this->errorMessage,
        ];
    }

    /**
     * The API key is omitted. The stack trace is omitted because a frame can still carry request state.
     *
     * @return array{message: string, code: int, file: string, line: int, httpStatus: int|null, errorCode: string|null, errorMessage: string|null}
     */
    public function __serialize(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'httpStatus' => $this->httpStatus,
            'errorCode' => $this->errorCode,
            'errorMessage' => $this->errorMessage,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->message = is_string($data['message'] ?? null) ? $data['message'] : '';
        $this->code = is_int($data['code'] ?? null) ? $data['code'] : 0;
        $this->file = is_string($data['file'] ?? null) ? $data['file'] : '';
        $this->line = is_int($data['line'] ?? null) ? $data['line'] : 0;
        $httpStatus = $data['httpStatus'] ?? null;
        $errorCode = $data['errorCode'] ?? null;
        $errorMessage = $data['errorMessage'] ?? null;
        $this->httpStatus = is_int($httpStatus) ? $httpStatus : null;
        $this->errorCode = is_string($errorCode) ? $errorCode : null;
        $this->errorMessage = is_string($errorMessage) ? $errorMessage : null;
    }

    private function dropTraceArguments(): void
    {
        $trace = [];
        foreach ($this->getTrace() as $frame) {
            unset($frame['args']);
            $trace[] = $frame;
        }
        $property = new \ReflectionProperty(\Exception::class, 'trace');
        $property->setValue($this, $trace);
    }
}
