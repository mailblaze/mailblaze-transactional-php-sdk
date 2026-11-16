<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Tests;

use MailBlaze\Transactional\EmailRecord;
use MailBlaze\Transactional\Emails;
use PHPUnit\Framework\Attributes\DataProvider;

final class ListAndRecordTest extends SdkTestCase
{
    #[DataProvider('outOfRangePerPage')]
    public function testPerPageOutsideTheApiRangeIsClamped(int $perPage, int $sent): void
    {
        $this->http->addResponse($this->jsonResponse(200, self::emptyPage()));
        $this->client()->emails()->list(perPage: $perPage);
        parse_str($this->lastRequest()->getUri()->getQuery(), $query);

        self::assertSame((string) $sent, $query['per_page']);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function outOfRangePerPage(): iterable
    {
        yield 'negative' => [-5, Emails::MIN_PER_PAGE];
        yield 'zero' => [0, Emails::MIN_PER_PAGE];
        yield 'below the minimum' => [9, Emails::MIN_PER_PAGE];
        yield 'above the maximum' => [51, Emails::MAX_PER_PAGE];
        yield 'far above' => [1000, Emails::MAX_PER_PAGE];
    }

    public function testPerPageAtTheBoundsIsSent(): void
    {
        foreach ([Emails::MIN_PER_PAGE, Emails::MAX_PER_PAGE] as $perPage) {
            $this->http->addResponse($this->jsonResponse(200, self::emptyPage()));
            $this->client()->emails()->list(perPage: $perPage);
            parse_str($this->lastRequest()->getUri()->getQuery(), $query);
            self::assertSame((string) $perPage, $query['per_page']);
        }
    }

    #[DataProvider('sendAtValues')]
    public function testSendAtIsParsed(int|string|null $raw, ?string $expected, ?string $timezone = null): void
    {
        $record = $this->record($raw);
        $parsed = $record->sendAtDateTime($timezone === null ? null : new \DateTimeZone($timezone));

        self::assertSame($raw, $record->sendAt());
        self::assertSame($expected, $parsed?->format(DATE_ATOM));
    }

    /**
     * @return iterable<string, array{0: int|string|null, 1: string|null, 2?: string}>
     */
    public static function sendAtValues(): iterable
    {
        yield 'live API format, PM' => ['2026-11-16 2:36:59 PM', '2026-11-16T14:36:59+00:00'];
        yield 'live API format, AM' => ['2026-11-16 12:05:00 AM', '2026-11-16T00:05:00+00:00'];
        yield 'live API format, assumed zone' => ['2026-11-16 2:36:59 PM', '2026-11-16T14:36:59+08:00', 'Asia/Manila'];
        yield '24-hour' => ['2026-11-16 14:36:59', '2026-11-16T14:36:59+00:00'];
        yield 'with offset' => ['2026-11-11T15:04:05+08:00', '2026-11-11T15:04:05+08:00'];
        yield 'offset wins over assumed zone' => ['2026-11-11T15:04:05-05:00', '2026-11-11T15:04:05-05:00', 'Asia/Manila'];
        yield 'unix timestamp' => [1700000000, '2023-11-14T22:13:20+00:00'];
        yield 'numeric string' => ['1700000000', '2023-11-14T22:13:20+00:00'];
        yield 'null' => [null, null];
        yield 'garbage' => ['tomorrow-ish', null];
        yield 'impossible date' => ['2026-02-30 10:00:00', null];
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyPage(): array
    {
        return [
            'status' => 'success',
            'code' => '050.001',
            'data' => ['count' => null, 'total_pages' => null, 'current_page' => null, 'next_page' => null, 'prev_page' => null, 'records' => []],
        ];
    }

    private function record(int|string|null $sendAt): EmailRecord
    {
        return EmailRecord::fromPayload([
            'status' => 'success',
            'code' => '000.000',
            'data' => ['record' => [
                'email_uid' => 'em_1',
                'to_email' => 'jane@example.com',
                'to_name' => 'Jane',
                'from_email' => 'billing@example.com',
                'from_name' => 'Acme',
                'subject' => 'Hi',
                'body' => null,
                'plain_text' => '',
                'category' => null,
                'send_at' => $sendAt,
                'status' => 'sent',
            ]],
        ]);
    }
}
