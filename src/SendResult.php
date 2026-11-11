<?php

declare(strict_types=1);

namespace MailBlaze\Transactional;

use MailBlaze\Transactional\Exception\ApiException;
use MailBlaze\Transactional\Http\Values;

final class SendResult
{
    private function __construct(
        private readonly string $emailUid,
        private readonly string $code,
        private readonly ?string $warning,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $warning = $payload['warning'] ?? null;
        if ($warning !== null && !is_string($warning)) {
            throw new ApiException('The Mail Blaze API returned an unexpected warning');
        }

        return new self(
            Values::string($payload['email_uid'] ?? null, 'email_uid'),
            Values::string($payload['code'] ?? null, 'code'),
            $warning,
        );
    }

    public function emailUid(): string
    {
        return $this->emailUid;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function warning(): ?string
    {
        return $this->warning;
    }
}
