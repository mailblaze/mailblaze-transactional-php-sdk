<?php

declare(strict_types=1);

namespace MailBlaze\Transactional;

use MailBlaze\Transactional\Http\Values;

final class EmailRecord
{
    private function __construct(
        private readonly string $emailUid,
        private readonly string $toEmail,
        private readonly string $toName,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly string $subject,
        private readonly ?string $body,
        private readonly ?string $plainText,
        private readonly ?string $category,
        private readonly int|string|null $sendAt,
        private readonly string $status,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $data = Values::object($payload['data'] ?? null, 'data');
        $record = Values::object($data['record'] ?? null, 'record');

        return new self(
            Values::string($record['email_uid'] ?? null, 'email_uid'),
            Values::text($record['to_email'] ?? null, 'to_email'),
            Values::text($record['to_name'] ?? null, 'to_name'),
            Values::text($record['from_email'] ?? null, 'from_email'),
            Values::text($record['from_name'] ?? null, 'from_name'),
            Values::text($record['subject'] ?? null, 'subject'),
            Values::nullableString($record['body'] ?? null, 'body'),
            Values::nullableString($record['plain_text'] ?? null, 'plain_text'),
            Values::nullableString($record['category'] ?? null, 'category'),
            Values::scalarTime($record['send_at'] ?? null),
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

    public function toName(): string
    {
        return $this->toName;
    }

    public function fromEmail(): string
    {
        return $this->fromEmail;
    }

    public function fromName(): string
    {
        return $this->fromName;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    public function plainText(): ?string
    {
        return $this->plainText;
    }

    public function category(): ?string
    {
        return $this->category;
    }

    public function sendAt(): int|string|null
    {
        return $this->sendAt;
    }

    public function status(): string
    {
        return $this->status;
    }
}
