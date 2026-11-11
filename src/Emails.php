<?php

declare(strict_types=1);

namespace MailBlaze\Transactional;

use MailBlaze\Transactional\Exception\ApiException;
use MailBlaze\Transactional\Exception\ValidationException;
use MailBlaze\Transactional\Http\Transport;
use MailBlaze\Transactional\Http\Values;

final class Emails
{
    public function __construct(private readonly Transport $transport) {}

    public function send(Email $email): SendResult
    {
        return SendResult::fromPayload($this->transport->submit('POST', '/transactional', $email->toRequestBody()));
    }

    public function list(int $page = 1, int $perPage = 10): EmailPage
    {
        if ($page < 1) {
            throw new ValidationException('page must be at least 1');
        }
        if ($perPage < 1) {
            throw new ValidationException('perPage must be at least 1');
        }

        return EmailPage::fromPayload($this->transport->submit('GET', '/transactional', null, [
            'page' => $page,
            'per_page' => $perPage,
        ]));
    }

    public function get(string $emailUid): EmailRecord
    {
        return EmailRecord::fromPayload($this->transport->submit(
            'GET',
            '/transactional/' . self::uid($emailUid),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function attachments(string $emailUid): array
    {
        $payload = $this->transport->submit('GET', '/transactional/attachments/' . self::uid($emailUid));
        $data = $payload['data'] ?? null;
        if (!is_array($data) || !array_is_list($data)) {
            throw new ApiException('The Mail Blaze API returned an unexpected attachment payload');
        }

        $files = [];
        foreach ($data as $file) {
            $files[] = Values::object($file, 'attachment');
        }

        return $files;
    }

    private static function uid(string $emailUid): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $emailUid) !== 1) {
            throw new ValidationException('email uid is invalid');
        }

        return $emailUid;
    }
}
