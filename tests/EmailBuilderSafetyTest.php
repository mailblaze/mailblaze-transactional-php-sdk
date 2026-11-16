<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Tests;

use MailBlaze\Transactional\Attachment;
use MailBlaze\Transactional\Email;
use MailBlaze\Transactional\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailBuilderSafetyTest extends TestCase
{
    public function testReusingABaseEmailDoesNotChangeEarlierEmails(): void
    {
        $base = Email::make()
            ->from('billing@example.com', 'Acme')
            ->subject('Your receipt')
            ->html('<p>Thanks</p>');

        $forA = $base->to('a@example.com', 'A')->cc('ops@example.com');
        $forB = $base->to('b@example.com', 'B');

        self::assertSame('a@example.com', $forA->toRequestBody()['to_email']);
        self::assertSame('ops@example.com', $forA->toRequestBody()['cc']);
        self::assertSame('b@example.com', $forB->toRequestBody()['to_email']);
        self::assertArrayNotHasKey('cc', $forB->toRequestBody());
    }

    public function testEveryMethodReturnsANewEmail(): void
    {
        $email = Email::make();

        self::assertNotSame($email, $email->to('a@example.com', 'A'));
        self::assertNotSame($email, $email->from('a@example.com', 'A'));
        self::assertNotSame($email, $email->subject('Hello'));
        self::assertNotSame($email, $email->html('<p>Hi</p>'));
        self::assertNotSame($email, $email->text('Hi'));
        self::assertNotSame($email, $email->templateId('template-1'));
        self::assertNotSame($email, $email->mergeTags(['NAME' => 'Jane']));
        self::assertNotSame($email, $email->replyTo('a@example.com'));
        self::assertNotSame($email, $email->cc('a@example.com'));
        self::assertNotSame($email, $email->bcc('a@example.com'));
        self::assertNotSame($email, $email->category('receipts'));
        self::assertNotSame($email, $email->sendAt(new \DateTimeImmutable()));
        self::assertNotSame($email, $email->attach(Attachment::fromBytes('x', 'x.txt')));
    }

    public function testACallDoesNotChangeTheEmailItWasCalledOn(): void
    {
        $email = Email::make()->to('a@example.com', 'A')->from('b@example.com', 'B')->subject('Hi')->html('<p>Hi</p>');
        $withCc = $email->cc('ops@example.com');

        self::assertArrayNotHasKey('cc', $email->toRequestBody());
        self::assertSame('ops@example.com', $withCc->toRequestBody()['cc']);
    }

    public function testEveryMethodThatReturnsACopyIsMarkedNoDiscard(): void
    {
        $methods = ['to', 'from', 'subject', 'html', 'text', 'templateId', 'mergeTags', 'replyTo', 'cc', 'bcc', 'category', 'sendAt', 'attach'];
        $reflection = new \ReflectionClass(Email::class);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isStatic() && $method->getName() !== 'toRequestBody') {
                self::assertContains($method->getName(), $methods, 'New Email method needs #[\\NoDiscard]: ' . $method->getName());
            }
        }
        foreach ($methods as $name) {
            $attributes = array_map(
                static fn(\ReflectionAttribute $attribute): string => $attribute->getName(),
                $reflection->getMethod($name)->getAttributes(),
            );
            self::assertContains('NoDiscard', $attributes, $name . '() must carry #[\\NoDiscard]');
        }
    }

    public function testSendAtCopiesAMutableDateTime(): void
    {
        $when = new \DateTime('2026-11-11 15:04:05', new \DateTimeZone('UTC'));
        $email = Email::make()->to('a@example.com', 'A')->from('b@example.com', 'B')->subject('Hi')->html('<p>Hi</p>')->sendAt($when);
        $when->modify('+1 day');

        self::assertSame('2026-11-11T15:04:05+00:00', $email->toRequestBody()['send_at']);
    }

    /**
     * @param callable(Email): Email $build
     */
    #[DataProvider('invalidUtf8')]
    public function testInvalidUtf8IsRejectedByTheBuilder(callable $build, string $message): void
    {
        try {
            $build(Email::make());
            self::fail('Invalid UTF-8 must be rejected before the request');
        } catch (ValidationException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertNull($exception->httpStatus());
        }
    }

    /**
     * @return iterable<string, array{callable(Email): Email, string}>
     */
    public static function invalidUtf8(): iterable
    {
        yield 'html' => [static fn(Email $e): Email => $e->html("<p>\xC3\x28</p>"), 'html() must be valid UTF-8'];
        yield 'text' => [static fn(Email $e): Email => $e->text("caf\xE9"), 'text() must be valid UTF-8'];
        yield 'subject' => [static fn(Email $e): Email => $e->subject("Hi \xFF"), 'Subject must be valid UTF-8'];
        yield 'to name' => [static fn(Email $e): Email => $e->to('a@example.com', "J\xFFne"), 'Recipient name must be valid UTF-8'];
        yield 'from name' => [static fn(Email $e): Email => $e->from('a@example.com', "\x80"), 'From name must be valid UTF-8'];
        yield 'reply-to name' => [static fn(Email $e): Email => $e->replyTo('a@example.com', "\xED\xA0\x80"), 'Reply-to name must be valid UTF-8'];
        yield 'category' => [static fn(Email $e): Email => $e->category("\xFE"), 'Category must be valid UTF-8'];
        yield 'merge tag' => [static fn(Email $e): Email => $e->mergeTags(['NAME' => "\xFF"]), 'Merge tag value must be valid UTF-8'];
    }

    public function testAnInvalidUtf8AttachmentFilenameIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Attachment filename must be valid UTF-8');
        Attachment::fromBytes('x', "inv\xFFoice.pdf");
    }

    #[DataProvider('controlCharacters')]
    public function testControlCharactersGetAnAccurateMessage(string $value, string $message): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($message);
        $unused = Email::make()->subject($value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function controlCharacters(): iterable
    {
        yield 'CR LF' => ["Hi\r\nBcc: x@example.com", 'Subject must not contain line breaks'];
        yield 'U+2028' => ["Hi\u{2028}there", 'Subject must not contain line breaks'];
        yield 'tab' => ["Hi\tthere", 'Subject must not contain control characters'];
        yield 'NUL' => ["Hi\0there", 'Subject must not contain control characters'];
        yield 'DEL' => ["Hi\x7Fthere", 'Subject must not contain control characters'];
    }

    #[DataProvider('spoofingCharacters')]
    public function testBidiOverridesAndInvisibleCharactersAreRejected(string $character): void
    {
        foreach ([
            static fn(): Email => Email::make()->subject('invoice' . $character . 'fdp.exe'),
            static fn(): Email => Email::make()->from('billing@example.com', 'Acme' . $character . 'Bank'),
            static fn(): Email => Email::make()->to('jane@example.com', 'Jane' . $character),
            static fn(): Email => Email::make()->replyTo('jane@example.com', $character . 'Jane'),
        ] as $build) {
            try {
                $build();
                self::fail('A bidirectional override or invisible character must be rejected');
            } catch (ValidationException $exception) {
                self::assertStringContainsString('must not contain bidirectional override or invisible characters', $exception->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function spoofingCharacters(): iterable
    {
        yield 'LRE U+202A' => ["\u{202A}"];
        yield 'RLE U+202B' => ["\u{202B}"];
        yield 'PDF U+202C' => ["\u{202C}"];
        yield 'LRO U+202D' => ["\u{202D}"];
        yield 'RLO U+202E' => ["\u{202E}"];
        yield 'LRI U+2066' => ["\u{2066}"];
        yield 'RLI U+2067' => ["\u{2067}"];
        yield 'FSI U+2068' => ["\u{2068}"];
        yield 'PDI U+2069' => ["\u{2069}"];
        yield 'ZWSP U+200B' => ["\u{200B}"];
        yield 'word joiner U+2060' => ["\u{2060}"];
        yield 'invisible plus U+2064' => ["\u{2064}"];
        yield 'BOM U+FEFF' => ["\u{FEFF}"];
    }

    #[DataProvider('directionMarks')]
    public function testDirectionMarksAreAllowed(string $text): void
    {
        $body = Email::make()
            ->to('a@example.com', $text)
            ->from('b@example.com', $text)
            ->replyTo('c@example.com', $text)
            ->subject($text)
            ->category($text)
            ->html('<p>Hi</p>')
            ->toRequestBody();

        self::assertSame($text, $body['subject']);
        self::assertSame($text, $body['to_name']);
        self::assertSame($text, $body['from_name']);
        self::assertSame($text, $body['reply_to_name']);
        self::assertSame($text, $body['category']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function directionMarks(): iterable
    {
        // Hebrew "order 1001" with RLM after the number so it stays in right-to-left order.
        yield 'Hebrew with RLM U+200F' => ["\u{05D4}\u{05D6}\u{05DE}\u{05E0}\u{05D4} 1001\u{200F}"];
        // Arabic "invoice #42" with ALM before the number.
        yield 'Arabic with ALM U+061C' => ["\u{0641}\u{0627}\u{062A}\u{0648}\u{0631}\u{0629} \u{061C}#42"];
        // An English product name inside Hebrew text, closed with LRM.
        yield 'Hebrew with LRM U+200E' => ["\u{05EA}\u{05D5}\u{05D3}\u{05D4} Acme Pro\u{200E}"];
        yield 'all three marks' => ["\u{200E}\u{200F}\u{061C} Jane"];
    }

    public function testEmojiSequencesAndJoinersAreStillAllowed(): void
    {
        $subject = "Family \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467} \u{0645}\u{06CC}\u{200C}\u{062E}\u{0648}\u{0627}\u{0647}\u{0645} \u{4F60}\u{597D} \u{05E9}\u{05DC}\u{05D5}\u{05DD}";
        $email = Email::make()->to('a@example.com', "J\u{00F6}rg \u{1F600}")->from('b@example.com', 'B')->subject($subject)->html('<p>Hi</p>');

        self::assertSame($subject, $email->toRequestBody()['subject']);
    }

    public function testALongSubjectIsSentUnchanged(): void
    {
        // The API builds and folds the header, so the SDK does not cap the subject length.
        $subject = str_repeat('a', 1200) . str_repeat("\u{4F60}", 400);
        $body = Email::make()->to('a@example.com', 'A')->from('b@example.com', 'B')->subject($subject)->html('<p>Hi</p>')->toRequestBody();

        self::assertSame($subject, $body['subject']);
    }

    #[DataProvider('ambiguousAddresses')]
    public function testCcAndBccRejectAddressesThatWouldSplitTheList(string $address): void
    {
        foreach ([
            static fn(): Email => Email::make()->cc($address),
            static fn(): Email => Email::make()->bcc($address),
            static fn(): Email => Email::make()->to($address, 'Jane'),
        ] as $build) {
            try {
                $build();
                self::fail('Address must be rejected: ' . $address);
            } catch (ValidationException $exception) {
                self::assertNull($exception->httpStatus());
                self::assertStringContainsString('must not contain commas, semicolons, or angle brackets', $exception->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ambiguousAddresses(): iterable
    {
        yield 'quoted comma' => ['"a,b"@example.com'];
        yield 'quoted semicolon' => ['"a;b"@example.com'];
        yield 'quoted angle brackets' => ['"a<b>"@example.com'];
    }

    public function testAQuotedLocalPartIsAcceptedAndSentUnchanged(): void
    {
        $address = '"jane"@example.com';
        $body = Email::make()
            ->to($address, 'Jane')
            ->from('"billing"@example.com', 'Acme')
            ->replyTo($address)
            ->subject('Hi')
            ->html('<p>Hi</p>')
            ->cc($address, 'ops@example.com')
            ->bcc($address)
            ->toRequestBody();

        self::assertSame($address, $body['to_email']);
        self::assertSame('"billing"@example.com', $body['from_email']);
        self::assertSame($address, $body['reply_to_email']);
        self::assertSame('"jane"@example.com,ops@example.com', $body['cc']);
        self::assertSame($address, $body['bcc']);
    }

    public function testSeveralCcAddressesAreJoinedWithACommaAndKeepTheirOrder(): void
    {
        $body = Email::make()->to('a@example.com', 'A')->from('b@example.com', 'B')->subject('Hi')->html('<p>Hi</p>')
            ->cc('one@example.com', 'two@example.com')
            ->cc('three@example.com')
            ->toRequestBody();

        self::assertSame('one@example.com,two@example.com,three@example.com', $body['cc']);
    }
}
