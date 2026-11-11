<?php

declare(strict_types=1);

namespace MailBlaze\Transactional;

use MailBlaze\Transactional\Http\Values;

final class EmailSummary
{
    private function __construct(
        private readonly string $emailUid,
        private readonly string $toEmail,
        private readonly string $subject,
        private readonly string $status,
    ) {}

    /**
     * @param array<string, mixed> $record
     */
    public static function fromArray(array $record): self
    {
        return new self(
            Values::string($record['email_uid'] ?? null, 'email_uid'),
            Values::text($record['to_email'] ?? null, 'to_email'),
            Values::text($record['subject'] ?? null, 'subject'),
            Values::text($record['status'] ?? null, 'status'),
        );
    }

    public function emailUid(): string
    {
        return $this->emailUid;
    }

    public function toEmail(): string
    {
        return $this->toEmail;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function status(): string
    {
        return $this->status;
    }
}
