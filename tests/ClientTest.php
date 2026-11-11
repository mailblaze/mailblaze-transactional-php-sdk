<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Tests;

use MailBlaze\Transactional\Attachment;
use MailBlaze\Transactional\Exception\ApiException;
use MailBlaze\Transactional\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

final class ClientTest extends SdkTestCase
{
    public function testSendEncodesNativeValuesAndSetsTheExactJsonContentType(): void
    {
        $html = '<p>Thanks for your order.</p>';
        $text = "Thanks for your order.\nLine two.";
        $this->http->addResponse($this->jsonResponse(201, [
            'status' => 'success',
            'code' => '000.000',
            'email_uid' => 'em_123',
            'warning' => null,
        ]));

        $result = $this->client()->emails()->send(
            EmailBuilder::receipt()
                ->text($text)
                ->cc('ops@example.com', 'finance@example.com')
                ->bcc('archive@example.com')
                ->category('receipts')
                ->attach(Attachment::fromBytes('pdf-bytes', 'invoice.pdf')),
        );

        $request = $this->lastRequest();
        $payload = $this->lastJson();

        self::assertSame('em_123', $result->emailUid());
        self::assertSame('POST', $request->getMethod());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertStringNotContainsString('charset', strtolower($request->getHeaderLine('Content-Type')));
        self::assertSame('mb-secret-key-1112', $request->getHeaderLine('Authorization'));
        self::assertStringNotContainsString('Bearer', $request->getHeaderLine('Authorization'));
        self::assertStringNotContainsString('mb-secret-key-1112', (string) $request->getUri());
        self::assertSame(base64_encode($html), $payload['body']);
        self::assertSame(base64_encode($text), $payload['plain_text']);
        self::assertStringNotContainsString("\n", (string) $payload['body']);
        self::assertSame('ops@example.com,finance@example.com', $payload['cc']);
        self::assertSame('archive@example.com', $payload['bcc']);
        self::assertSame('receipts', $payload['category']);
        $attachments = $payload['attachments'] ?? null;
        if (!is_array($attachments) || !isset($attachments[0]) || !is_array($attachments[0])) {
            self::fail('Expected an attachment payload');
        }
        $attachment = $attachments[0];
        self::assertSame('invoice.pdf', $attachment['name']);
        self::assertSame(base64_encode('pdf-bytes'), $attachment['data']);
    }

    public function testTemplateSendOmitsBodyAndSendsMergeTagsAsAnObject(): void
    {
        $this->http->addResponse($this->jsonResponse(201, [
            'status' => 'success',
            'code' => '000.000',
            'email_uid' => 'em_tpl',
            'warning' => null,
        ]));

        $this->client()->emails()->send(
            EmailBuilder::base()
                ->templateId('ab123456-1234-1234-1234-123456789abc')
                ->mergeTags(['NAME' => 'Jane', 'ORDER_ID' => '1001']),
        );

        $raw = $this->lastRequest()->getBody();
        $raw->rewind();
        $json = $raw->getContents();
        $payload = $this->lastJson();

        self::assertSame('ab123456-1234-1234-1234-123456789abc', $payload['template_id']);
        self::assertSame(['NAME' => 'Jane', 'ORDER_ID' => '1001'], $payload['merge_tags']);
        self::assertStringContainsString('"merge_tags":{"NAME":"Jane","ORDER_ID":"1001"}', $json);
        self::assertArrayNotHasKey('body', $payload);
        self::assertArrayNotHasKey('plain_text', $payload);
    }

    public function testTemplateSendIncludesAttachments(): void
    {
        $this->http->addResponse($this->jsonResponse(201, [
            'status' => 'success',
            'code' => '000.000',
            'email_uid' => 'em_tpl_file',
            'warning' => null,
        ]));

        $this->client()->emails()->send(
            EmailBuilder::base()
                ->templateId('ab123456-1234-1234-1234-123456789abc')
                ->mergeTags(['NAME' => 'Jane'])
                ->attach(Attachment::fromBytes('pdf-bytes', 'invoice.pdf')),
        );

        $payload = $this->lastJson();
        $attachments = $payload['attachments'] ?? null;
        if (!is_array($attachments) || !isset($attachments[0]) || !is_array($attachments[0])) {
            self::fail('Expected an attachment on a template send');
        }

        self::assertSame('invoice.pdf', $attachments[0]['name']);
        self::assertSame(base64_encode('pdf-bytes'), $attachments[0]['data']);
        self::assertArrayNotHasKey('body', $payload);
        self::assertArrayNotHasKey('plain_text', $payload);
    }

    public function testSendAtUsesTheClockTimeWithoutATimezoneSuffix(): void
    {
        $this->http->addResponse($this->jsonResponse(201, [
            'status' => 'success',
            'code' => '000.000',
            'email_uid' => 'em_when',
            'warning' => null,
        ]));

        $this->client()->emails()->send(
            EmailBuilder::receipt()->sendAt(new \DateTimeImmutable('2026-11-11 15:04:05', new \DateTimeZone('Asia/Manila'))),
        );

        self::assertSame('2026-11-11T15:04:05', $this->lastJson()['send_at']);
    }

    public function testListGetAndAttachments(): void
    {
        $this->http->addResponse($this->jsonResponse(200, [
            'status' => 'success',
            'code' => '000.000',
            'data' => [
                'count' => 1,
                'total_pages' => 3,
                'current_page' => 2,
                'next_page' => 3,
                'prev_page' => 1,
                'records' => [[
                    'email_uid' => 'em_123',
                    'to_email' => 'jane@example.com',
                    'subject' => 'Your receipt',
                    'status' => 'sent',
                ]],
            ],
        ]));
        $this->http->addResponse($this->jsonResponse(200, [
            'status' => 'success',
            'code' => '050.001',
            'data' => [
                'count' => null,
                'total_pages' => null,
                'current_page' => null,
                'next_page' => null,
                'prev_page' => null,
                'records' => [],
            ],
        ]));
        $this->http->addResponse($this->jsonResponse(200, [
            'status' => 'success',
            'code' => '000.000',
            'data' => [
                'record' => [
                    'email_uid' => 'em_123',
                    'to_email' => 'jane@example.com',
                    'to_name' => 'Jane Doe',
                    'from_email' => 'billing@example.com',
                    'from_name' => 'Acme',
                    'subject' => 'Your receipt',
                    'body' => '<p>Thanks</p>',
                    'plain_text' => 'Thanks',
                    'category' => 'receipts',
                    'send_at' => 1700000000,
                    'status' => 'sent',
                ],
            ],
        ]));
        $this->http->addResponse($this->jsonResponse(200, [
            'status' => 'success',
            'code' => '000.000',
            'data' => [[
                'name' => 'invoice.pdf',
                'mime_type' => 'application/pdf',
            ]],
        ]));

        $emails = $this->client()->emails();
        $page = $emails->list(page: 2, perPage: 25);
        $empty = $emails->list();
        $email = $emails->get('em_123');
        $files = $emails->attachments('em_123');

        self::assertSame(1, $page->count());
        self::assertSame(3, $page->totalPages());
        self::assertSame(2, $page->currentPage());
        self::assertSame(3, $page->nextPage());
        self::assertSame(1, $page->prevPage());
        self::assertSame('em_123', $page->records()[0]->emailUid());
        self::assertSame('050.001', $empty->code());
        self::assertSame([], $empty->records());
        self::assertSame('<p>Thanks</p>', $email->body());
        self::assertSame(1700000000, $email->sendAt());
        self::assertSame('invoice.pdf', $files[0]['name']);

        $requests = $this->http->getRequests();
        self::assertCount(4, $requests);
        parse_str($requests[0]->getUri()->getQuery(), $query);
        self::assertSame('2', $query['page']);
        self::assertSame('25', $query['per_page']);
        self::assertStringEndsWith('/transactional/em_123', $requests[2]->getUri()->getPath());
        self::assertStringEndsWith('/transactional/attachments/em_123', $requests[3]->getUri()->getPath());
    }

    public function testPostIsNeverRetried(): void
    {
        $this->http->addException(new NetworkFailure('timeout'));
        $this->http->addResponse($this->jsonResponse(201, [
            'status' => 'success',
            'code' => '000.000',
            'email_uid' => 'should-not-be-used',
            'warning' => null,
        ]));

        $this->expectException(TransportException::class);
        $this->client()->emails()->send(EmailBuilder::receipt());
    }

    public function testPostServerErrorIsNotRetried(): void
    {
        $this->http->addResponse($this->jsonResponse(500, [
            'status' => 'error',
            'code' => '150.016',
            'error' => 'The transactional email was not sent',
        ]));
        $this->http->addResponse($this->jsonResponse(201, [
            'status' => 'success',
            'code' => '000.000',
            'email_uid' => 'should-not-be-used',
            'warning' => null,
        ]));

        $this->expectException(\MailBlaze\Transactional\Exception\SendFailedException::class);
        $this->client()->emails()->send(EmailBuilder::receipt());
    }

    public function testGetRetriesTransientFailuresThenSucceeds(): void
    {
        $this->http->addException(new NetworkFailure('reset'));
        $this->http->addResponse($this->jsonResponse(503, [
            'status' => 'error',
            'code' => '150.008',
            'error' => 'Failed to get the list of all transactional emails',
        ]));
        $this->http->addResponse($this->jsonResponse(200, [
            'status' => 'success',
            'code' => '000.000',
            'data' => [
                'count' => 0,
                'total_pages' => 0,
                'current_page' => 1,
                'next_page' => null,
                'prev_page' => null,
                'records' => [],
            ],
        ]));

        $page = $this->client()->emails()->list();

        self::assertSame('000.000', $page->code());
        self::assertCount(3, $this->http->getRequests());
    }

    public function testGetDoesNotRetryQuotaFailures(): void
    {
        $this->http->addResponse($this->jsonResponse(403, [
            'status' => 'error',
            'code' => '150.015',
            'error' => 'Insufficient credits to send transactional email.',
        ]));
        $this->http->addResponse($this->jsonResponse(200, [
            'status' => 'success',
            'code' => '000.000',
            'data' => [
                'count' => 0,
                'total_pages' => 0,
                'current_page' => 1,
                'next_page' => null,
                'prev_page' => null,
                'records' => [],
            ],
        ]));

        $this->expectException(\MailBlaze\Transactional\Exception\QuotaException::class);
        $this->client()->emails()->list();
    }

    public function testEmptyApiKeyAndInsecureHttpAreRejected(): void
    {
        try {
            $this->client('');
            self::fail('An empty API key must be rejected');
        } catch (TransportException $exception) {
            self::assertSame('API key must not be empty', $exception->getMessage());
        }

        $this->expectException(TransportException::class);
        \MailBlaze\Transactional\Client::create(
            'mb-secret-key-1112',
            baseUri: 'http://control.mailblaze.com/api',
            httpClient: $this->http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    public function testAllowHttpDoesNotPermitAPublicHost(): void
    {
        $this->expectException(TransportException::class);
        \MailBlaze\Transactional\Client::create(
            'mb-secret-key-1112',
            baseUri: 'http://control.mailblaze.com/api',
            allowHttp: true,
            httpClient: $this->http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    public function testAllowHttpAcceptsTheLocalControlPanel(): void
    {
        $this->http->addResponse($this->jsonResponse(200, [
            'status' => 'success',
            'code' => '050.001',
            'data' => [
                'count' => null,
                'total_pages' => null,
                'current_page' => null,
                'next_page' => null,
                'prev_page' => null,
                'records' => [],
            ],
        ]));

        $client = \MailBlaze\Transactional\Client::create(
            'mb-secret-key-1112',
            baseUri: 'http://control.mailblaze.local:8080/api',
            allowHttp: true,
            httpClient: $this->http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
            retryDelayMs: 0,
        );

        $page = $client->emails()->list();

        self::assertSame('050.001', $page->code());
        self::assertSame(
            'http://control.mailblaze.local:8080/api/transactional?page=1&per_page=10',
            (string) $this->lastRequest()->getUri(),
        );
    }

    public function testInvalidJsonFromTheApiIsAnApiException(): void
    {
        $this->http->addResponse(
            $this->factory->createResponse(200)->withBody($this->factory->createStream('not-json')),
        );

        $this->expectException(ApiException::class);
        $this->client()->emails()->send(EmailBuilder::receipt());
    }

    public function testLogsOmitTheApiKeyAndMessageBody(): void
    {
        $logger = new ArrayLogger();
        $html = '<p>Thanks for your order.</p>';
        $this->http->addResponse($this->jsonResponse(201, [
            'status' => 'success',
            'code' => '000.000',
            'email_uid' => 'em_123',
            'warning' => null,
        ]));

        $this->client(logger: $logger)->emails()->send(EmailBuilder::receipt());

        $encoded = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('mb-secret-key-1112', $encoded);
        self::assertStringNotContainsString($html, $encoded);
        self::assertStringNotContainsString(base64_encode($html), $encoded);
        self::assertNotSame([], $logger->records);
    }
}

final class NetworkFailure extends RuntimeException implements ClientExceptionInterface {}

final class EmailBuilder
{
    public static function base(): \MailBlaze\Transactional\Email
    {
        return \MailBlaze\Transactional\Email::make()
            ->to('jane@example.com', 'Jane Doe')
            ->from('billing@example.com', 'Acme')
            ->subject('Your receipt');
    }

    public static function receipt(): \MailBlaze\Transactional\Email
    {
        return self::base()->html('<p>Thanks for your order.</p>');
    }
}
