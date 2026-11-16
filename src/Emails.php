<?php

declare(strict_types=1);

namespace MailBlaze\Transactional;

use MailBlaze\Transactional\Exception\ApiException;
use MailBlaze\Transactional\Exception\UnconfirmedSendException;
use MailBlaze\Transactional\Exception\ValidationException;
use MailBlaze\Transactional\Http\Transport;
use MailBlaze\Transactional\Http\Values;

final class Emails
{
    /** The API returns at least this many records per page. A smaller perPage is raised to it. */
    public const MIN_PER_PAGE = 10;

    /** The API returns at most this many records per page. A larger perPage is lowered to it. */
    public const MAX_PER_PAGE = 50;

    public function __construct(private readonly Transport $transport) {}

    public function send(Email $email): SendResult
    {
        $payload = $this->transport->submit('POST', '/transactional', $email->toRequestBody());
        try {
            return SendResult::fromPayload($payload);
        } catch (ApiException $exception) {
            throw new UnconfirmedSendException(
                'The Mail Blaze API accepted the request, but the response could not be read. The email may already have been accepted. Check the list before sending it again.',
                $exception->httpStatus(),
                $exception->errorCode(),
                $exception->getMessage(),
            );
        }
    }

    public function list(int $page = 1, int $perPage = 10): EmailPage
    {
        if ($page < 1) {
            throw new ValidationException('page must be at least 1');
        }

        return EmailPage::fromPayload($this->transport->submit('GET', '/transactional', null, [
            'page' => $page,
            // The API returns 10 records for a smaller value and 50 for a larger one. Clamp to
            // the same range so the request matches what comes back.
            'per_page' => max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $perPage)),
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
        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $emailUid) !== 1) {
            throw new ValidationException('email uid is invalid');
        }

        return $emailUid;
    }
}
