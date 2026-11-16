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

    /**
     * The raw send_at value from the API, such as "2026-11-16 2:36:59 PM" or a Unix timestamp.
     *
     * @see self::sendAtDateTime() for a parsed value
     */
    public function sendAt(): int|string|null
    {
        return $this->sendAt;
    }

    /**
     * send_at as a DateTimeImmutable, or null when it is missing or in a format the SDK does not recognise.
     *
     * The API returns send_at without a UTC offset, for example "2026-11-16 2:36:59 PM". In testing
     * that clock time was UTC, so a value without an offset is read in UTC unless you pass another
     * timezone. A value with an offset, or a Unix timestamp, keeps its own instant.
     */
    public function sendAtDateTime(?\DateTimeZone $assumedTimezone = null): ?\DateTimeImmutable
    {
        $value = $this->sendAt;
        if ($value === null) {
            return null;
        }
        if (is_int($value) || preg_match('/\A[0-9]{1,12}\z/', $value) === 1) {
            return (new \DateTimeImmutable('@' . $value))->setTimezone(new \DateTimeZone('UTC'));
        }

        $value = trim($value);
        $timezone = $assumedTimezone ?? new \DateTimeZone('UTC');
        foreach (['!Y-m-d\TH:i:sP', '!Y-m-d H:i:sP', '!Y-m-d\TH:i:s.uP'] as $format) {
            $parsed = self::parse($format, $value, null);
            if ($parsed !== null) {
                return $parsed;
            }
        }
        foreach (['!Y-m-d g:i:s A', '!Y-m-d H:i:s', '!Y-m-d\TH:i:s'] as $format) {
            $parsed = self::parse($format, $value, $timezone);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    private static function parse(string $format, string $value, ?\DateTimeZone $timezone): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat($format, $value, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $parsed;
    }

    public function status(): string
    {
        return $this->status;
    }
}
