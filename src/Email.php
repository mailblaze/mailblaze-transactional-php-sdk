<?php

declare(strict_types=1);

namespace MailBlaze\Transactional;

use DateTimeInterface;
use MailBlaze\Transactional\Exception\ValidationException;

final class Email
{
    private ?string $toEmail = null;

    private ?string $toName = null;

    private ?string $fromEmail = null;

    private ?string $fromName = null;

    private ?string $subject = null;

    private ?string $html = null;

    private ?string $text = null;

    private ?string $templateId = null;

    /** @var array<int|string, string> */
    private array $mergeTags = [];

    private ?string $replyToEmail = null;

    private ?string $replyToName = null;

    /** @var list<string> */
    private array $cc = [];

    /** @var list<string> */
    private array $bcc = [];

    private ?string $category = null;

    private ?DateTimeInterface $sendAt = null;

    /** @var list<Attachment> */
    private array $attachments = [];

    public static function make(): self
    {
        return new self();
    }

    public function to(string $email, string $name): self
    {
        $this->toEmail = $this->emailAddress($email, 'Recipient email');
        $this->toName = $this->headerValue($name, 'Recipient name');

        return $this;
    }

    public function from(string $email, string $name): self
    {
        $this->fromEmail = $this->emailAddress($email, 'From email');
        $this->fromName = $this->headerValue($name, 'From name');

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $this->headerValue($subject, 'Subject');

        return $this;
    }

    public function html(string $html): self
    {
        if (trim($html) === '') {
            throw new ValidationException('html() must not be empty');
        }
        if ($this->templateId !== null || $this->mergeTags !== []) {
            throw new ValidationException('html() cannot be combined with templateId()');
        }
        $this->html = $html;

        return $this;
    }

    public function text(string $text): self
    {
        if ($this->templateId !== null) {
            throw new ValidationException('text() cannot be combined with templateId()');
        }
        $this->text = $text;

        return $this;
    }

    public function templateId(string $templateId): self
    {
        if ($this->html !== null || $this->text !== null) {
            throw new ValidationException('templateId() cannot be combined with html() or text()');
        }
        $this->templateId = $this->headerValue($templateId, 'Template id');

        return $this;
    }

    /**
     * @param array<int|string, bool|float|int|string|null|\Stringable> $tags
     */
    public function mergeTags(array $tags): self
    {
        if ($this->html !== null || $this->text !== null) {
            throw new ValidationException('mergeTags() cannot be combined with html() or text()');
        }

        foreach ($tags as $key => $value) {
            $name = is_int($key) ? (string) $key : $key;
            if (preg_match('/\A[A-Z0-9_]+\z/', $name) !== 1) {
                throw new ValidationException('Merge tag names must match [A-Z0-9_]');
            }
            $this->mergeTags[$name] = self::mergeTagValue($value);
        }

        return $this;
    }

    public function replyTo(string $email, ?string $name = null): self
    {
        $this->replyToEmail = $this->emailAddress($email, 'Reply-to email');
        $this->replyToName = $name === null ? null : $this->headerValue($name, 'Reply-to name');

        return $this;
    }

    public function cc(string ...$addresses): self
    {
        foreach ($addresses as $address) {
            $this->cc[] = $this->emailAddress($address, 'Cc email');
        }

        return $this;
    }

    public function bcc(string ...$addresses): self
    {
        foreach ($addresses as $address) {
            $this->bcc[] = $this->emailAddress($address, 'Bcc email');
        }

        return $this;
    }

    public function category(string $category): self
    {
        $this->category = $this->headerValue($category, 'Category');

        return $this;
    }

    public function sendAt(DateTimeInterface $sendAt): self
    {
        $this->sendAt = \DateTimeImmutable::createFromInterface($sendAt);

        return $this;
    }

    public function attach(Attachment $attachment): self
    {
        $this->attachments[] = $attachment;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRequestBody(): array
    {
        if ($this->toEmail === null || $this->toName === null || $this->fromEmail === null || $this->fromName === null || $this->subject === null) {
            throw new ValidationException('to(), from(), and subject() are required');
        }
        if (($this->html === null) === ($this->templateId === null)) {
            throw new ValidationException(
                'Either html() or templateId() is required',
                null,
                '150.012',
                'Either body or template_id must be provided',
            );
        }

        $body = [
            'to_email' => $this->toEmail,
            'to_name' => $this->toName,
            'from_email' => $this->fromEmail,
            'from_name' => $this->fromName,
            'subject' => $this->subject,
        ];

        if ($this->replyToEmail !== null) {
            $body['reply_to_email'] = $this->replyToEmail;
        }
        if ($this->replyToName !== null) {
            $body['reply_to_name'] = $this->replyToName;
        }
        if ($this->cc !== []) {
            $body['cc'] = implode(',', $this->cc);
        }
        if ($this->bcc !== []) {
            $body['bcc'] = implode(',', $this->bcc);
        }
        if ($this->category !== null) {
            $body['category'] = $this->category;
        }
        if ($this->sendAt !== null) {
            $body['send_at'] = $this->sendAt->format('Y-m-d\\TH:i:sP');
        }

        if ($this->templateId !== null) {
            $body['template_id'] = $this->templateId;
            // Numeric names such as "0" are valid tags, but PHP stores them as list keys.
            // stdClass keeps every name a JSON object property.
            $body['merge_tags'] = self::mergeTagObject($this->mergeTags);
        } else {
            $body['body'] = self::strictBase64((string) $this->html);
            if ($this->text !== null) {
                $body['plain_text'] = self::strictBase64($this->text);
            }
        }
        if ($this->attachments !== []) {
            $body['attachments'] = array_map(
                static fn(Attachment $attachment): array => $attachment->toPayload(),
                $this->attachments,
            );
        }

        return $body;
    }

    /**
     * @param array<int|string, string> $tags
     */
    private static function mergeTagObject(array $tags): \stdClass
    {
        $object = new \stdClass();
        foreach ($tags as $name => $value) {
            $object->{(string) $name} = $value;
        }

        return $object;
    }

    private function headerValue(string $value, string $label): string
    {
        if (preg_match('/\p{Cc}|\x{85}|\x{2028}|\x{2029}/u', $value) === 1) {
            throw new ValidationException($label . ' must not contain line breaks');
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new ValidationException($label . ' must not be empty');
        }

        return $trimmed;
    }

    private function emailAddress(string $value, string $label): string
    {
        $value = $this->headerValue($value, $label);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException($label . ' must be a valid email address');
        }

        return $value;
    }

    private static function mergeTagValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_string($value)) {
            return (string) $value;
        }
        if (is_float($value) && is_finite($value)) {
            return (string) $value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        throw new ValidationException('Merge tag values must be scalar');
    }

    private static function strictBase64(string $value): string
    {
        $encoded = base64_encode($value);
        $decoded = base64_decode($encoded, true);
        if ($decoded !== $value || base64_encode((string) $decoded) !== $encoded || str_contains($encoded, "\n")) {
            throw new ValidationException('Content could not be encoded as strict base64');
        }

        return $encoded;
    }
}
