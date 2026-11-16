<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Tests;

use MailBlaze\Transactional\Attachment;
use MailBlaze\Transactional\Exception\ValidationException;

final class AttachmentTest extends SdkTestCase
{
    public function testFromPathEncodesFileBytes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mb');
        self::assertIsString($path);
        $named = $path . '.pdf';
        self::assertTrue(rename($path, $named));
        file_put_contents($named, 'hello-pdf');

        try {
            $attachment = Attachment::fromPath($named);
            self::assertStringEndsWith('.pdf', $attachment->name());
            self::assertSame(base64_encode('hello-pdf'), $attachment->toPayload()['data']);
        } finally {
            unlink($named);
        }
    }

    public function testRejectsAFilenameWithALineBreak(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Attachment filename must not contain line breaks or control characters');
        Attachment::fromBytes('hello', "invoice\r\n.pdf");
    }

    public function testRejectsAFilenameWithATab(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Attachment filename must not contain line breaks or control characters');
        Attachment::fromBytes('hello', "invoice\t.pdf");
    }

    public function testRejectsAFilenameThatContainsAPath(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Attachment filename must not include a path');
        Attachment::fromBytes('hello', 'invoices/invoice.pdf');
    }

    public function testRejectsAFilenameWithAHiddenDirectionMark(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Attachment filename must not contain hidden or formatting characters');
        Attachment::fromBytes('hello', "invoice\u{200E}.pdf");
    }

    public function testRejectsAFilenameWithABidirectionalOverride(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Attachment filename must not contain hidden or formatting characters');
        Attachment::fromBytes('hello', "invoice\u{202E}fdp.pdf");
    }

    public function testRejectsAnEncodedPayloadOverTenMebibytesBeforeTheRequest(): void
    {
        $this->http->addResponse($this->jsonResponse(201, [
            'status' => 'success',
            'code' => '000.000',
            'email_uid' => 'unused',
            'warning' => null,
        ]));

        try {
            $bytes = str_repeat('a', 7_864_321);
            $this->client()->emails()->send(
                $this->simpleEmail()->attach(Attachment::fromBytes($bytes, 'large.bin')),
            );
            self::fail('An oversized attachment must be rejected');
        } catch (ValidationException $exception) {
            self::assertSame('150.005', $exception->errorCode());
            self::assertNull($exception->httpStatus());
        }

        self::assertSame([], $this->http->getRequests());
    }

    public function testAcceptsAnEncodedPayloadAtTheTenMebibyteBoundary(): void
    {
        $bytes = str_repeat('a', 7_864_320);
        $attachment = Attachment::fromBytes($bytes, 'exact.bin');

        self::assertSame(Attachment::MAX_ENCODED_BYTES, strlen($attachment->toPayload()['data']));
    }
}
