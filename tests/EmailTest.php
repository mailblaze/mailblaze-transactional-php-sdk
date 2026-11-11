<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Tests;

use MailBlaze\Transactional\Email;
use MailBlaze\Transactional\Exception\MailBlazeException;
use MailBlaze\Transactional\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class EmailTest extends SdkTestCase
{
    public function testRejectsASubjectWithHeaderInjection(): void
    {
        $this->http->addResponse($this->jsonResponse(201, [
            'status' => 'success',
            'code' => '000.000',
            'email_uid' => 'unused',
            'warning' => null,
        ]));

        try {
            $this->client()->emails()->send($this->simpleEmail()->subject("Hello\r\nBcc: evil@example.com"));
            self::fail('A subject with line breaks must be rejected');
        } catch (ValidationException $exception) {
            self::assertNull($exception->httpStatus());
        }

        self::assertSame([], $this->http->getRequests());
    }

    public function testRejectsHtmlCombinedWithATemplate(): void
    {
        $this->expectException(ValidationException::class);
        Email::make()
            ->to('jane@example.com', 'Jane Doe')
            ->from('billing@example.com', 'Acme')
            ->subject('Hello')
            ->html('<p>Hi</p>')
            ->templateId('template-1');
    }

    public function testRejectsAnInvalidMergeTagBeforeTheRequest(): void
    {
        $email = Email::make()
            ->to('jane@example.com', 'Jane Doe')
            ->from('billing@example.com', 'Acme')
            ->subject('Hello')
            ->templateId('template-1');

        $this->expectException(ValidationException::class);
        $email->mergeTags(['name' => 'Jane']);
    }

    public function testRejectsAnInvalidRecipient(): void
    {
        $this->expectException(ValidationException::class);
        Email::make()->to('not-an-email', 'Jane Doe');
    }

    public function testRequiresContentBeforeSending(): void
    {
        $this->expectException(ValidationException::class);
        $this->client()->emails()->send(
            Email::make()
                ->to('jane@example.com', 'Jane Doe')
                ->from('billing@example.com', 'Acme')
                ->subject('Hello'),
        );
    }

    /**
     * @param class-string<MailBlazeException> $class
     */
    #[DataProvider('apiErrors')]
    public function testMapsApiErrorCodes(string $code, int $status, string $class): void
    {
        $this->http->addResponse($this->jsonResponse($status, [
            'status' => 'error',
            'code' => $code,
            'error' => 'failed ' . $code,
        ]));

        try {
            $this->client()->emails()->send($this->simpleEmail());
            self::fail('Expected ' . $code . ' to throw');
        } catch (MailBlazeException $exception) {
            self::assertInstanceOf($class, $exception);
            self::assertSame($status, $exception->httpStatus());
            self::assertSame($code, $exception->errorCode());
            self::assertSame('failed ' . $code, $exception->errorMessage());
        }
    }

    /**
     * @return iterable<string, array{string, int, class-string<MailBlazeException>}>
     */
    public static function apiErrors(): iterable
    {
        yield '100.001' => ['100.001', 400, \MailBlaze\Transactional\Exception\ValidationException::class];
        yield '150.001' => ['150.001', 403, \MailBlaze\Transactional\Exception\AuthenticationException::class];
        yield '150.002' => ['150.002', 400, \MailBlaze\Transactional\Exception\ValidationException::class];
        yield '150.003' => ['150.003', 400, \MailBlaze\Transactional\Exception\ValidationException::class];
        yield '150.004' => ['150.004', 400, \MailBlaze\Transactional\Exception\ValidationException::class];
        yield '150.005' => ['150.005', 400, \MailBlaze\Transactional\Exception\ValidationException::class];
        yield '150.006' => ['150.006', 422, \MailBlaze\Transactional\Exception\ValidationException::class];
        yield '150.007' => ['150.007', 422, \MailBlaze\Transactional\Exception\SendFailedException::class];
        yield '150.008' => ['150.008', 503, \MailBlaze\Transactional\Exception\ApiException::class];
        yield '150.009' => ['150.009', 404, \MailBlaze\Transactional\Exception\NotFoundException::class];
        yield '150.010' => ['150.010', 403, \MailBlaze\Transactional\Exception\DomainNotVerifiedException::class];
        yield '150.011' => ['150.011', 400, \MailBlaze\Transactional\Exception\ValidationException::class];
        yield '150.012' => ['150.012', 400, \MailBlaze\Transactional\Exception\ValidationException::class];
        yield '150.013' => ['150.013', 404, \MailBlaze\Transactional\Exception\NotFoundException::class];
        yield '150.014' => ['150.014', 400, \MailBlaze\Transactional\Exception\ValidationException::class];
        yield '150.015' => ['150.015', 403, \MailBlaze\Transactional\Exception\QuotaException::class];
        yield '150.016' => ['150.016', 500, \MailBlaze\Transactional\Exception\SendFailedException::class];
    }
}
