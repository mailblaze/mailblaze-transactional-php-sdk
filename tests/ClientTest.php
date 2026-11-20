<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Tests;

use MailBlaze\Transactional\Attachment;
use MailBlaze\Transactional\Exception\ApiException;
use MailBlaze\Transactional\Exception\ConfigurationException;
use MailBlaze\Transactional\Exception\RedactedException;
use MailBlaze\Transactional\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
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
        self::assertSame('mb-test-key', $request->getHeaderLine('Authorization'));
        self::assertStringNotContainsString('Bearer', $request->getHeaderLine('Authorization'));
        self::assertStringNotContainsString('mb-test-key', (string) $request->getUri());
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

    public function testAQuotedLocalPartIsSentIntactInTheJsonBody(): void
    {
        $this->http->addResponse($this->jsonResponse(201, [
            'status' => 'success',
            'code' => '000.000',
            'email_uid' => 'em_quoted',
            'warning' => null,
        ]));

        $this->client()->emails()->send(
            EmailBuilder::receipt()->to('"jane"@example.com', 'Jane Doe')->cc('"jane"@example.com', 'ops@example.com'),
        );

        $raw = $this->lastRequest()->getBody();
        $raw->rewind();
        $json = $raw->getContents();
        $payload = $this->lastJson();

        self::assertStringContainsString('"to_email":"\\"jane\\"@example.com"', $json);
        self::assertSame('"jane"@example.com', $payload['to_email']);
        self::assertSame('"jane"@example.com,ops@example.com', $payload['cc']);
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

    public function testNumericMergeTagNamesStayAJsonObject(): void
    {
        $this->http->addResponse($this->jsonResponse(201, [
            'status' => 'success',
            'code' => '000.000',
            'email_uid' => 'em_tags',
            'warning' => null,
        ]));

        $this->client()->emails()->send(
            EmailBuilder::base()
                ->templateId('ab123456-1234-1234-1234-123456789abc')
                ->mergeTags(['0' => 'zero', '1' => 'one']),
        );

        $raw = $this->lastRequest()->getBody();
        $raw->rewind();
        $json = $raw->getContents();

        self::assertStringContainsString('"merge_tags":{"0":"zero","1":"one"}', $json);
        self::assertStringNotContainsString('"merge_tags":[', $json);
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

    public function testSendAtKeepsTheTimezoneOffset(): void
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

        self::assertSame('2026-11-11T15:04:05+08:00', $this->lastJson()['send_at']);
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
        } catch (ConfigurationException $exception) {
            self::assertSame('API key must not be empty', $exception->getMessage());
        }

        $this->expectException(ConfigurationException::class);
        \MailBlaze\Transactional\Client::create(
            'mb-test-key',
            baseUri: 'http://control.mailblaze.com/api',
            httpClient: $this->http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    public function testAllowHttpDoesNotPermitAPublicHost(): void
    {
        $this->expectException(ConfigurationException::class);
        \MailBlaze\Transactional\Client::create(
            'mb-test-key',
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
            'mb-test-key',
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

    public function testASuccessfulSendWithAnUnreadableBodyIsNotARetryableFailure(): void
    {
        $this->http->addResponse(
            $this->factory->createResponse(201)->withBody($this->factory->createStream('not-json')),
        );

        try {
            $this->client()->emails()->send(EmailBuilder::receipt());
            self::fail('An unreadable success response must not look like a normal failure');
        } catch (\MailBlaze\Transactional\Exception\UnconfirmedSendException $exception) {
            self::assertSame(201, $exception->httpStatus());
            self::assertStringContainsString('may already have been accepted', $exception->getMessage());
            self::assertInstanceOf(\MailBlaze\Transactional\Exception\TransportException::class, $exception);
        }
        self::assertCount(1, $this->http->getRequests());
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
        self::assertStringNotContainsString('mb-test-key', $encoded);
        self::assertStringNotContainsString($html, $encoded);
        self::assertStringNotContainsString(base64_encode($html), $encoded);
        self::assertNotSame([], $logger->records);
    }

    public function testAnInvalidApiKeyIsAnAuthenticationError(): void
    {
        $this->http->addResponse($this->factory->createResponse(400)->withBody($this->factory->createStream(
            '{"status":"error","error":"Invalid API key. Please refer to the documentation."}',
        )));

        try {
            $this->client()->emails()->send(EmailBuilder::receipt());
            self::fail('A bad key must not be reported as a validation error');
        } catch (\MailBlaze\Transactional\Exception\AuthenticationException $exception) {
            self::assertSame(400, $exception->httpStatus());
            self::assertNull($exception->errorCode());
        }
    }

    public function testABodyReadFailureAfterASuccessfulSendIsNotARetryableFailure(): void
    {
        $stream = $this->createStub(\Psr\Http\Message\StreamInterface::class);
        $stream->method('__toString')->willThrowException(new RuntimeException('Unable to read stream contents'));
        $this->http->addResponse($this->factory->createResponse(201)->withBody($stream));
        $this->http->addResponse($this->jsonResponse(201, [
            'status' => 'success',
            'code' => '000.000',
            'email_uid' => 'should-not-be-used',
            'warning' => null,
        ]));

        try {
            $this->client()->emails()->send(EmailBuilder::receipt());
            self::fail('A body read after a successful send must not look like a network failure');
        } catch (\MailBlaze\Transactional\Exception\UnconfirmedSendException $exception) {
            self::assertSame(201, $exception->httpStatus());
            self::assertStringContainsString('may already have been accepted', $exception->getMessage());
        }
        self::assertCount(1, $this->http->getRequests());
    }

    public function testAGetBodyReadFailureIsRetried(): void
    {
        $stream = $this->createStub(\Psr\Http\Message\StreamInterface::class);
        $stream->method('__toString')->willThrowException(new RuntimeException('Unable to read stream contents'));
        $this->http->addResponse($this->factory->createResponse(200)->withBody($stream));
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

        $page = $this->client(getRetryLimit: 1)->emails()->list();

        self::assertSame('050.001', $page->code());
        self::assertCount(2, $this->http->getRequests());
    }

    public function testAGetWithAnEmptyBodyIsRetriedLikeAStalledStream(): void
    {
        $this->http->addResponse($this->factory->createResponse(200)->withBody($this->factory->createStream('')));
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

        $page = $this->client(getRetryLimit: 1)->emails()->list();

        self::assertSame('050.001', $page->code());
        self::assertCount(2, $this->http->getRequests());
    }

    public function testAGetWithTruncatedJsonIsRetried(): void
    {
        $this->http->addResponse($this->factory->createResponse(200)->withBody($this->factory->createStream('{"status":"success"')));
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

        $page = $this->client(getRetryLimit: 1)->emails()->list();

        self::assertSame('000.000', $page->code());
        self::assertCount(2, $this->http->getRequests());
    }

    public function testAGetWithInvalidJsonIsNotRetriedAfter401(): void
    {
        $this->http->addResponse($this->factory->createResponse(401)->withBody($this->factory->createStream('<html>denied</html>')));
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

        try {
            $this->client()->emails()->list();
            self::fail('A 401 with a non-JSON body must not be retried');
        } catch (\MailBlaze\Transactional\Exception\AuthenticationException $exception) {
            self::assertSame(401, $exception->httpStatus());
        }
        self::assertCount(1, $this->http->getRequests());
    }

    public function testInvalidUtf8StaysAValidationError(): void
    {
        try {
            $this->client()->emails()->send(EmailBuilder::receipt()->subject("\xFF"));
            self::fail('Invalid UTF-8 must be a validation error');
        } catch (\MailBlaze\Transactional\Exception\ValidationException $exception) {
            self::assertSame('Subject must be valid UTF-8', $exception->getMessage());
        }
        self::assertSame([], $this->http->getRequests());
    }

    public function testANumericEmailUidOnSuccessIsNotANormalFailure(): void
    {
        $this->http->addResponse($this->jsonResponse(201, [
            'status' => 'success',
            'code' => '000.000',
            'email_uid' => 123,
            'warning' => null,
        ]));

        $this->expectException(\MailBlaze\Transactional\Exception\UnconfirmedSendException::class);
        $this->client()->emails()->send(EmailBuilder::receipt());
    }

    public function testAControlCharacterInTheApiKeyIsRejectedBeforeTheRequest(): void
    {
        try {
            $this->client("mb-test-key\x7F");
            self::fail('A control character in the API key must be rejected');
        } catch (ConfigurationException $exception) {
            self::assertStringNotContainsString('mb-test-key', $exception->getTraceAsString());
            self::assertStringNotContainsString('mb-test-key', $exception->getMessage());
            self::assertStringNotContainsString("\x7F", $exception->getMessage());
        }
        self::assertSame([], $this->http->getRequests());
    }

    public function testAnHtmlErrorKeepsItsHttpStatus(): void
    {
        $this->http->addResponse(
            $this->factory->createResponse(401)->withBody($this->factory->createStream('<html>denied</html>')),
        );

        try {
            $this->client()->emails()->list();
            self::fail('An HTML error must still carry its HTTP status');
        } catch (\MailBlaze\Transactional\Exception\AuthenticationException $exception) {
            self::assertSame(401, $exception->httpStatus());
        }
    }

    public function testARedirectIsNotTreatedAsSuccess(): void
    {
        $this->http->addResponse($this->factory->createResponse(302)->withHeader('Location', 'https://example.test/'));

        try {
            $this->client()->emails()->send(EmailBuilder::receipt());
            self::fail('A redirect must not be treated as a successful send');
        } catch (ApiException $exception) {
            self::assertSame(302, $exception->httpStatus());
        }
    }

    public function testTheBaseUriRejectsAFragmentAndADisguisedHost(): void
    {
        $this->expectException(ConfigurationException::class);
        \MailBlaze\Transactional\Client::create(
            'mb-test-key',
            baseUri: 'https://control.mailblaze.com/api#key',
            httpClient: $this->http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    public function testTheBaseUriRejectsPrivateAndLinkLocalIps(): void
    {
        foreach ([
            'https://169.254.169.254/api',
            'https://192.168.1.10/api',
            'https://10.0.0.5/api',
            'https://[fe80::1]/api',
            'https://0x7f.1/api',
            'https://0x7f000001/api',
            'https://2130706433/api',
            'https://127.1/api',
            'https://0177.0.0.1/api',
            'https://10.1/api',
        ] as $baseUri) {
            try {
                \MailBlaze\Transactional\Client::create(
                    'mb-test-key',
                    baseUri: $baseUri,
                    httpClient: $this->http,
                    requestFactory: $this->factory,
                    streamFactory: $this->factory,
                );
                self::fail('Private or link-local base URIs must be rejected: ' . $baseUri);
            } catch (ConfigurationException $exception) {
                self::assertStringStartsWith('Base URI host must be a hostname', $exception->getMessage());
            }
        }
    }

    public function testTheBaseUriAcceptsLoopbackHttps(): void
    {
        $client = \MailBlaze\Transactional\Client::create(
            'mb-test-key',
            baseUri: 'https://127.0.0.1/api',
            httpClient: $this->http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        self::assertInstanceOf(\MailBlaze\Transactional\Client::class, $client);
    }

    public function testAnInjectedGuzzleClientMustDisableRedirects(): void
    {
        $guzzleClass = 'GuzzleHttp\\Client';
        if (!class_exists($guzzleClass)) {
            self::markTestSkipped('guzzlehttp/guzzle is not installed');
        }

        $guzzle = new $guzzleClass();
        if (!$guzzle instanceof \Psr\Http\Client\ClientInterface) {
            self::fail('GuzzleHttp\\Client must implement ClientInterface');
        }

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('allow_redirects');
        \MailBlaze\Transactional\Client::create(
            'mb-test-key',
            httpClient: $guzzle,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }

    public function testAllowHttpAcceptsIpv6Localhost(): void
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
            'mb-test-key',
            baseUri: 'http://[::1]:8080/api',
            allowHttp: true,
            httpClient: $this->http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
        $client->emails()->list();

        self::assertSame(
            'http://[::1]:8080/api/transactional?page=1&per_page=10',
            (string) $this->lastRequest()->getUri(),
        );
    }

    public function testANonPsrClientErrorDoesNotLeakTheApiKey(): void
    {
        $client = \MailBlaze\Transactional\Client::create(
            'mb-test-key',
            httpClient: new KeyLeakingClient(),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );

        try {
            $client->emails()->send(EmailBuilder::receipt());
            self::fail('A client error must stay inside TransportException');
        } catch (TransportException $exception) {
            self::assertSame(TransportException::REASON_CLIENT, $exception->reason());
            $previous = $exception->getPrevious();
            self::assertInstanceOf(RedactedException::class, $previous);
            self::assertSame(\TypeError::class, $previous->originalClass());
            self::assertSame('TypeError: header [redacted]', $previous->getMessage());
            $rendered = $exception->getMessage() . $exception->getTraceAsString() . var_export($exception, true)
                . (string) $exception . $previous->getTraceAsString() . (string) $previous;
            self::assertStringNotContainsString('mb-test-key', $rendered);
        }
    }

    public function testACustomResponseStatusAccessorDoesNotLeakTheApiKey(): void
    {
        $this->http->addResponse(new StatusLeakingResponse('mb-test-key'));

        try {
            $this->client()->emails()->send(EmailBuilder::receipt());
            self::fail('A broken status accessor must stay inside TransportException');
        } catch (TransportException $exception) {
            self::assertSame(TransportException::REASON_RESPONSE, $exception->reason());
            self::assertInstanceOf(RedactedException::class, $exception->getPrevious());
            $rendered = $exception->getMessage() . $exception->getTraceAsString() . var_export($exception, true);
            self::assertStringNotContainsString('mb-test-key', $rendered);
        }
        self::assertCount(1, $this->http->getRequests());
    }

    public function testARedirectWithAnUnreadableBodyIsNotRetried(): void
    {
        $stream = $this->createStub(\Psr\Http\Message\StreamInterface::class);
        $stream->method('__toString')->willThrowException(new RuntimeException('Unable to read stream contents'));
        $this->http->addResponse($this->factory->createResponse(302)->withHeader('Location', 'https://example.test/')->withBody($stream));
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

        try {
            $this->client()->emails()->list();
            self::fail('A redirect must not be retried when its body cannot be read');
        } catch (ApiException $exception) {
            self::assertSame(302, $exception->httpStatus());
            self::assertStringContainsString('redirect', $exception->getMessage());
        }
        self::assertCount(1, $this->http->getRequests());
    }

    public function testATimedOutStreamIsNotTreatedAsACompleteBody(): void
    {
        $stream = $this->createStub(\Psr\Http\Message\StreamInterface::class);
        $stream->method('__toString')->willReturn('{"status":"success","code":"000.000","email_uid":"em_partial","warning":null}');
        $stream->method('getMetadata')->willReturn(['timed_out' => true, 'eof' => false, 'unread_bytes' => 100]);
        $this->http->addResponse($this->factory->createResponse(201)->withBody($stream));

        try {
            $this->client()->emails()->send(EmailBuilder::receipt());
            self::fail('A timed-out stream must not look like a confirmed send');
        } catch (\MailBlaze\Transactional\Exception\UnconfirmedSendException $exception) {
            self::assertSame(201, $exception->httpStatus());
        }
        self::assertCount(1, $this->http->getRequests());
    }

    public function testABodyShorterThanContentLengthIsIncomplete(): void
    {
        $json = '{"status":"success","code":"000.000","email_uid":"em_partial","warning":null}';
        $this->http->addResponse(
            $this->factory->createResponse(201)
                ->withHeader('Content-Length', (string) (strlen($json) + 100))
                ->withBody($this->factory->createStream($json)),
        );

        try {
            $this->client()->emails()->send(EmailBuilder::receipt());
            self::fail('A short body must not look like a confirmed send');
        } catch (\MailBlaze\Transactional\Exception\UnconfirmedSendException $exception) {
            self::assertSame(201, $exception->httpStatus());
        }
        self::assertCount(1, $this->http->getRequests());
    }

    public function testADuplicateContentLengthStillDetectsAShortBody(): void
    {
        $json = '{"status":"success","code":"000.000","email_uid":"em_partial","warning":null}';
        $declared = (string) (strlen($json) + 100);
        $this->http->addResponse(
            $this->factory->createResponse(201)
                ->withHeader('Content-Length', $declared . ', ' . $declared)
                ->withBody($this->factory->createStream($json)),
        );

        try {
            $this->client()->emails()->send(EmailBuilder::receipt());
            self::fail('A short body with a duplicate Content-Length must not look confirmed');
        } catch (\MailBlaze\Transactional\Exception\UnconfirmedSendException $exception) {
            self::assertSame(201, $exception->httpStatus());
        }
        self::assertCount(1, $this->http->getRequests());
    }

    public function testConflictingContentLengthValuesAreRejected(): void
    {
        $json = '{"status":"success","code":"000.000","email_uid":"em_partial","warning":null}';
        $this->http->addResponse(
            $this->factory->createResponse(201)
                ->withHeader('Content-Length', strlen($json) . ', ' . (strlen($json) + 100))
                ->withBody($this->factory->createStream($json)),
        );

        try {
            $this->client()->emails()->send(EmailBuilder::receipt());
            self::fail('Conflicting Content-Length values must not look confirmed');
        } catch (\MailBlaze\Transactional\Exception\UnconfirmedSendException $exception) {
            self::assertSame(201, $exception->httpStatus());
        }
        self::assertCount(1, $this->http->getRequests());
    }

    public function testMalformedContentLengthWithGzipIsStillRejected(): void
    {
        $json = '{"status":"success","code":"000.000","email_uid":"em_gzip","warning":null}';
        $this->http->addResponse(
            $this->factory->createResponse(201)
                ->withHeader('Content-Encoding', 'gzip')
                ->withHeader('Content-Length', '81,181')
                ->withBody($this->factory->createStream($json)),
        );

        try {
            $this->client()->emails()->send(EmailBuilder::receipt());
            self::fail('Malformed Content-Length must be rejected even with gzip');
        } catch (\MailBlaze\Transactional\Exception\UnconfirmedSendException $exception) {
            self::assertSame(201, $exception->httpStatus());
        }
        self::assertCount(1, $this->http->getRequests());
    }

    public function testAnEnormousContentLengthIsRejected(): void
    {
        $json = '{"status":"success","code":"000.000","email_uid":"em_huge","warning":null}';
        $this->http->addResponse(
            $this->factory->createResponse(201)
                ->withHeader('Content-Length', str_repeat('9', 40))
                ->withBody($this->factory->createStream($json)),
        );

        try {
            $this->client()->emails()->send(EmailBuilder::receipt());
            self::fail('An enormous Content-Length must not look confirmed');
        } catch (\MailBlaze\Transactional\Exception\UnconfirmedSendException $exception) {
            self::assertSame(201, $exception->httpStatus());
        }
        self::assertCount(1, $this->http->getRequests());
    }

    public function testAnOversizedBodyWithoutContentLengthIsRejected(): void
    {
        $body = str_repeat('a', (16 * 1024 * 1024) + 1);
        $this->http->addResponse(
            $this->factory->createResponse(201)->withBody($this->factory->createStream($body)),
        );

        try {
            $this->client()->emails()->send(EmailBuilder::receipt());
            self::fail('An oversized body without Content-Length must not look confirmed');
        } catch (\MailBlaze\Transactional\Exception\UnconfirmedSendException $exception) {
            self::assertSame(201, $exception->httpStatus());
        }
        self::assertCount(1, $this->http->getRequests());
    }

    public function testAnOversizedChunkSizeIsRejected(): void
    {
        // 0x1000001 = 16 MiB + 1. Reject before allocating that many bytes.
        $chunked = "1000001\r\nx\r\n0\r\n\r\n";
        $this->http->addResponse(
            $this->factory->createResponse(201)
                ->withHeader('Transfer-Encoding', 'chunked')
                ->withBody($this->factory->createStream($chunked)),
        );

        try {
            $this->client()->emails()->send(EmailBuilder::receipt());
            self::fail('An oversized chunk size must not look confirmed');
        } catch (\MailBlaze\Transactional\Exception\UnconfirmedSendException $exception) {
            self::assertSame(201, $exception->httpStatus());
        }
        self::assertCount(1, $this->http->getRequests());
    }

    public function testGzipDoesNotCompareContentLengthToTheDecodedBody(): void
    {
        $json = '{"status":"success","code":"000.000","email_uid":"em_gzip_ok","warning":null}';
        $this->http->addResponse(
            $this->factory->createResponse(201)
                ->withHeader('Content-Encoding', 'gzip')
                ->withHeader('Content-Length', (string) (strlen($json) + 100))
                ->withBody($this->factory->createStream($json)),
        );

        $result = $this->client()->emails()->send(EmailBuilder::receipt());

        self::assertSame('em_gzip_ok', $result->emailUid());
    }

    public function testAChunkedBodyWithoutATerminalChunkIsIncomplete(): void
    {
        $json = '{"status":"success","code":"000.000","email_uid":"em_partial","warning":null}';
        $chunked = sprintf("%x\r\n%s\r\n", strlen($json), $json);
        $this->http->addResponse(
            $this->factory->createResponse(201)
                ->withHeader('Transfer-Encoding', 'chunked')
                ->withBody($this->factory->createStream($chunked)),
        );

        try {
            $this->client()->emails()->send(EmailBuilder::receipt());
            self::fail('A chunked body without a terminal chunk must not look like a confirmed send');
        } catch (\MailBlaze\Transactional\Exception\UnconfirmedSendException $exception) {
            self::assertSame(201, $exception->httpStatus());
        }
        self::assertCount(1, $this->http->getRequests());
    }

    public function testACompleteChunkedBodyIsAccepted(): void
    {
        $json = '{"status":"success","code":"000.000","email_uid":"em_chunk","warning":null}';
        $chunked = sprintf("%x\r\n%s\r\n0\r\n\r\n", strlen($json), $json);
        $this->http->addResponse(
            $this->factory->createResponse(201)
                ->withHeader('Transfer-Encoding', 'chunked')
                ->withBody($this->factory->createStream($chunked)),
        );

        $result = $this->client()->emails()->send(EmailBuilder::receipt());

        self::assertSame('em_chunk', $result->emailUid());
    }

    public function testZeroPaddedChunkSizesAreAccepted(): void
    {
        $json = '{"status":"success","code":"000.000","email_uid":"em_padded","warning":null}';
        $chunked = sprintf("000000%X\r\n%s\r\n00000000\r\n\r\n", strlen($json), $json);
        $this->http->addResponse(
            $this->factory->createResponse(201)
                ->withHeader('Transfer-Encoding', 'chunked')
                ->withBody($this->factory->createStream($chunked)),
        );

        $result = $this->client()->emails()->send(EmailBuilder::receipt());

        self::assertSame('em_padded', $result->emailUid());
    }

    public function testInvalidChunkTrailerNamesAreRejected(): void
    {
        $json = '{"status":"success","code":"000.000","email_uid":"em_trailer","warning":null}';
        foreach (["Bad(Header): yes\r\n\r\n", ":Bad: yes\r\n\r\n"] as $trailer) {
            $this->http->reset();
            $chunked = sprintf("%x\r\n%s\r\n0\r\n%s", strlen($json), $json, $trailer);
            $this->http->addResponse(
                $this->factory->createResponse(201)
                    ->withHeader('Transfer-Encoding', 'chunked')
                    ->withBody($this->factory->createStream($chunked)),
            );

            try {
                $this->client()->emails()->send(EmailBuilder::receipt());
                self::fail('Invalid chunk trailer names must not look confirmed');
            } catch (\MailBlaze\Transactional\Exception\UnconfirmedSendException $exception) {
                self::assertSame(201, $exception->httpStatus());
            }
            self::assertCount(1, $this->http->getRequests());
        }
    }

    public function testValidChunkTrailersAreAccepted(): void
    {
        $json = '{"status":"success","code":"000.000","email_uid":"em_ok_trailer","warning":null}';
        $chunked = sprintf("%x\r\n%s\r\n0\r\nExpires: Wed, 21 Oct 2015 07:28:00 GMT\r\n\r\n", strlen($json), $json);
        $this->http->addResponse(
            $this->factory->createResponse(201)
                ->withHeader('Transfer-Encoding', 'chunked')
                ->withBody($this->factory->createStream($chunked)),
        );

        $result = $this->client()->emails()->send(EmailBuilder::receipt());

        self::assertSame('em_ok_trailer', $result->emailUid());
    }

    public function testAnAlreadyDecodedChunkedJsonBodyIsNotDecodedAgain(): void
    {
        $json = '{"status":"success","code":"000.000","email_uid":"em_symfony_chunk","warning":null}';
        $this->http->addResponse(
            $this->factory->createResponse(201)
                ->withHeader('Transfer-Encoding', 'chunked')
                ->withBody($this->factory->createStream($json)),
        );

        $result = $this->client()->emails()->send(EmailBuilder::receipt());

        self::assertSame('em_symfony_chunk', $result->emailUid());
    }

    public function testAThrowingHeaderAccessorDoesNotLeakTheApiKey(): void
    {
        $this->http->addResponse(new HeaderLeakingResponse('mb-test-key', 401));

        try {
            $this->client()->emails()->list();
            self::fail('A broken header accessor must stay inside an SDK exception');
        } catch (\MailBlaze\Transactional\Exception\MailBlazeException $exception) {
            self::assertNull($exception->getPrevious());
            $rendered = $exception->getMessage() . $exception->getTraceAsString() . var_export($exception, true);
            self::assertStringNotContainsString('mb-test-key', $rendered);
        }
        self::assertCount(1, $this->http->getRequests());
    }

    public function testATimedOutGetStreamIsRetried(): void
    {
        $stream = $this->createStub(\Psr\Http\Message\StreamInterface::class);
        $stream->method('__toString')->willReturn('{"status":"success","code":"050.001"}');
        $stream->method('getMetadata')->willReturn(['timed_out' => true, 'eof' => false, 'unread_bytes' => 50]);
        $this->http->addResponse($this->factory->createResponse(200)->withBody($stream));
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

        $page = $this->client(getRetryLimit: 1)->emails()->list();

        self::assertSame('050.001', $page->code());
        self::assertCount(2, $this->http->getRequests());
    }

    public function testAFailedGetBodyReadOn401IsNotRetried(): void
    {
        $stream = $this->createStub(\Psr\Http\Message\StreamInterface::class);
        $stream->method('__toString')->willThrowException(new RuntimeException('Unable to read stream contents'));
        $this->http->addResponse($this->factory->createResponse(401)->withBody($stream));
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

        try {
            $this->client()->emails()->list();
            self::fail('A 401 whose body cannot be read must not be retried');
        } catch (\MailBlaze\Transactional\Exception\AuthenticationException $exception) {
            self::assertSame(401, $exception->httpStatus());
        }
        self::assertCount(1, $this->http->getRequests());
    }

    public function testAFailedGetBodyReadOn404IsNotRetried(): void
    {
        $stream = $this->createStub(\Psr\Http\Message\StreamInterface::class);
        $stream->method('__toString')->willThrowException(new RuntimeException('Unable to read stream contents'));
        $this->http->addResponse($this->factory->createResponse(404)->withBody($stream));
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

        try {
            $this->client()->emails()->get('em_123');
            self::fail('A 404 whose body cannot be read must not be retried');
        } catch (\MailBlaze\Transactional\Exception\NotFoundException $exception) {
            self::assertSame(404, $exception->httpStatus());
        }
        self::assertCount(1, $this->http->getRequests());
    }

    public function testANonFiniteTimeoutIsRejected(): void
    {
        // ConfigurationException extends \InvalidArgumentException, so 0.1.x handlers still match.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectException(ConfigurationException::class);
        \MailBlaze\Transactional\Client::create(
            'mb-test-key',
            timeout: \NAN,
            httpClient: $this->http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
        );
    }
}

final class NetworkFailure extends RuntimeException implements ClientExceptionInterface {}

final class KeyLeakingClient implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new \TypeError('header ' . $request->getHeaderLine('Authorization'));
    }
}

final class StatusLeakingResponse implements ResponseInterface
{
    public function __construct(private readonly string $secret) {}

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): ResponseInterface
    {
        return $this;
    }

    public function getHeaders(): array
    {
        return [];
    }

    public function hasHeader(string $name): bool
    {
        return false;
    }

    public function getHeader(string $name): array
    {
        return [];
    }

    public function getHeaderLine(string $name): string
    {
        return '';
    }

    public function withHeader(string $name, $value): ResponseInterface
    {
        return $this;
    }

    public function withAddedHeader(string $name, $value): ResponseInterface
    {
        return $this;
    }

    public function withoutHeader(string $name): ResponseInterface
    {
        return $this;
    }

    public function getBody(): \Psr\Http\Message\StreamInterface
    {
        throw new RuntimeException('body was not read');
    }

    public function withBody(\Psr\Http\Message\StreamInterface $body): ResponseInterface
    {
        return $this;
    }

    public function getStatusCode(): int
    {
        throw new RuntimeException('Authorization: ' . $this->secret);
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        return $this;
    }

    public function getReasonPhrase(): string
    {
        return '';
    }
}

final class HeaderLeakingResponse implements ResponseInterface
{
    public function __construct(
        private readonly string $secret,
        private readonly int $status = 201,
    ) {}

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): ResponseInterface
    {
        return $this;
    }

    public function getHeaders(): array
    {
        return [];
    }

    public function hasHeader(string $name): bool
    {
        return false;
    }

    public function getHeader(string $name): array
    {
        return [];
    }

    public function getHeaderLine(string $name): string
    {
        throw new RuntimeException('Authorization: ' . $this->secret);
    }

    public function withHeader(string $name, $value): ResponseInterface
    {
        return $this;
    }

    public function withAddedHeader(string $name, $value): ResponseInterface
    {
        return $this;
    }

    public function withoutHeader(string $name): ResponseInterface
    {
        return $this;
    }

    public function getBody(): \Psr\Http\Message\StreamInterface
    {
        return (new \Nyholm\Psr7\Factory\Psr17Factory())->createStream('{}');
    }

    public function withBody(\Psr\Http\Message\StreamInterface $body): ResponseInterface
    {
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        return $this;
    }

    public function getReasonPhrase(): string
    {
        return '';
    }
}

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
