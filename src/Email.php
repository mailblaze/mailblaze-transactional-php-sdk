<?php

declare(strict_types=1);

namespace MailBlaze\Transactional;

use DateTimeInterface;
use MailBlaze\Transactional\Exception\ValidationException;

/**
 * An email to send. Email is immutable: every method returns a new Email and leaves the
 * original unchanged, so a shared base email can be reused safely.
 *
 *     $base = Email::make()->from('billing@example.com', 'Acme')->subject('Receipt');
 *     $a = $base->to('a@example.com', 'A')->html('<p>A</p>');
 *     $b = $base->to('b@example.com', 'B')->html('<p>B</p>'); // $a still goes to A
 *
 * Always use the returned value. A call whose result is discarded has no effect. The methods
 * carry #[\NoDiscard], so PHP 8.5 and static analysers warn about a discarded result.
 */
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

    #[\NoDiscard]
    public function to(string $email, string $name): self
    {
        $clone = clone $this;
        $clone->toEmail = self::emailAddress($email, 'Recipient email');
        $clone->toName = self::headerValue($name, 'Recipient name');

        return $clone;
    }

    #[\NoDiscard]
    public function from(string $email, string $name): self
    {
        $clone = clone $this;
        $clone->fromEmail = self::emailAddress($email, 'From email');
        $clone->fromName = self::headerValue($name, 'From name');

        return $clone;
    }

    #[\NoDiscard]
    public function subject(string $subject): self
    {
        $clone = clone $this;
        $clone->subject = self::headerValue($subject, 'Subject');

        return $clone;
    }

    #[\NoDiscard]
    public function html(string $html): self
    {
        self::assertUtf8($html, 'html()');
        if (trim($html) === '') {
            throw new ValidationException('html() must not be empty');
        }
        if ($this->templateId !== null || $this->mergeTags !== []) {
            throw new ValidationException('html() cannot be combined with templateId()');
        }
        $clone = clone $this;
        $clone->html = $html;

        return $clone;
    }

    #[\NoDiscard]
    public function text(string $text): self
    {
        self::assertUtf8($text, 'text()');
        if ($this->templateId !== null) {
            throw new ValidationException('text() cannot be combined with templateId()');
        }
        $clone = clone $this;
        $clone->text = $text;

        return $clone;
    }

    #[\NoDiscard]
    public function templateId(string $templateId): self
    {
        if ($this->html !== null || $this->text !== null) {
            throw new ValidationException('templateId() cannot be combined with html() or text()');
        }
        $clone = clone $this;
        $clone->templateId = self::headerValue($templateId, 'Template id');

        return $clone;
    }

    /**
     * @param array<int|string, bool|float|int|string|null|\Stringable> $tags
     */
    #[\NoDiscard]
    public function mergeTags(array $tags): self
    {
        if ($this->html !== null || $this->text !== null) {
            throw new ValidationException('mergeTags() cannot be combined with html() or text()');
        }

        $clone = clone $this;
        foreach ($tags as $key => $value) {
            $name = is_int($key) ? (string) $key : $key;
            if (preg_match('/\A[A-Z0-9_]+\z/', $name) !== 1) {
                throw new ValidationException('Merge tag names must match [A-Z0-9_]');
            }
            $clone->mergeTags[$name] = self::mergeTagValue($value);
        }

        return $clone;
    }

    #[\NoDiscard]
    public function replyTo(string $email, ?string $name = null): self
    {
        $clone = clone $this;
        $clone->replyToEmail = self::emailAddress($email, 'Reply-to email');
        $clone->replyToName = $name === null ? null : self::headerValue($name, 'Reply-to name');

        return $clone;
    }

    /**
     * Adds Cc addresses. Each call appends to the addresses already set.
     */
    #[\NoDiscard]
    public function cc(string ...$addresses): self
    {
        $clone = clone $this;
        foreach ($addresses as $address) {
            $clone->cc[] = self::emailAddress($address, 'Cc email');
        }

        return $clone;
    }

    /**
     * Adds Bcc addresses. Each call appends to the addresses already set.
     */
    #[\NoDiscard]
    public function bcc(string ...$addresses): self
    {
        $clone = clone $this;
        foreach ($addresses as $address) {
            $clone->bcc[] = self::emailAddress($address, 'Bcc email');
        }

        return $clone;
    }

    #[\NoDiscard]
    public function category(string $category): self
    {
        $clone = clone $this;
        $clone->category = self::headerValue($category, 'Category');

        return $clone;
    }

    #[\NoDiscard]
    public function sendAt(DateTimeInterface $sendAt): self
    {
        $clone = clone $this;
        $clone->sendAt = \DateTimeImmutable::createFromInterface($sendAt);

        return $clone;
    }

    /**
     * Adds an attachment. Each call appends to the attachments already set.
     */
    #[\NoDiscard]
    public function attach(Attachment $attachment): self
    {
        $clone = clone $this;
        $clone->attachments[] = $attachment;

        return $clone;
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

    private static function headerValue(string $value, string $label): string
    {
        self::assertUtf8($value, $label);
        if (preg_match('/[\r\n]|\x{85}|\x{2028}|\x{2029}/u', $value) === 1) {
            throw new ValidationException($label . ' must not contain line breaks');
        }
        if (preg_match('/\p{Cc}/u', $value) === 1) {
            throw new ValidationException($label . ' must not contain control characters');
        }
        // Bidirectional embeddings, overrides, and isolates (U+202A-U+202E, U+2066-U+2069) and
        // invisible characters (U+200B, U+2060-U+2064, U+FEFF) can disguise a subject or name, for
        // example "invoice\u{202E}fdp.exe". The direction marks LRM (U+200E), RLM (U+200F), and
        // ALM (U+061C) are allowed because Hebrew and Arabic text uses them normally, and the
        // zero-width joiners (U+200C, U+200D) are allowed because emoji and several scripts need them.
        if (preg_match('/[\x{200B}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u', $value) === 1) {
            throw new ValidationException($label . ' must not contain bidirectional override or invisible characters');
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new ValidationException($label . ' must not be empty');
        }

        return $trimmed;
    }

    private static function emailAddress(string $value, string $label): string
    {
        $value = self::headerValue($value, $label);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException($label . ' must be a valid email address');
        }
        // FILTER_VALIDATE_EMAIL accepts quoted local parts such as "a,b"@example.com. Cc and Bcc are
        // sent as one comma-separated string, and the display name is a separate field, so a comma,
        // semicolon, or angle bracket would split or disguise an address. A quoted local part without
        // them, such as "jane"@example.com, is valid and is sent unchanged.
        if (strpbrk($value, ',;<>') !== false) {
            throw new ValidationException($label . ' must not contain commas, semicolons, or angle brackets');
        }

        return $value;
    }

    private static function assertUtf8(string $value, string $label): void
    {
        // preg_match() with /u returns false for invalid UTF-8, and does not need ext-mbstring.
        if (preg_match('//u', $value) !== 1) {
            throw new ValidationException($label . ' must be valid UTF-8');
        }
    }

    private static function mergeTagValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return '';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            self::assertUtf8($value, 'Merge tag value');

            return $value;
        }
        if (is_float($value) && is_finite($value)) {
            return (string) $value;
        }
        if ($value instanceof \Stringable) {
            $string = (string) $value;
            self::assertUtf8($string, 'Merge tag value');

            return $string;
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
