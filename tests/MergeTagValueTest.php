<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Tests;

use MailBlaze\Transactional\Email;
use MailBlaze\Transactional\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class MergeTagValueTest extends SdkTestCase
{
    #[DataProvider('forbiddenCharacters')]
    public function testAMergeTagValueWithAForbiddenCharacterIsRejectedBeforeTheRequest(string $value, string $message): void
    {
        $this->http->addResponse($this->successResponse());

        try {
            $this->client()->emails()->send($this->templateEmail()->mergeTags(['NAME' => $value]));
            self::fail('The merge tag value must be rejected');
        } catch (ValidationException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertNull($exception->httpStatus());
        }

        self::assertSame([], $this->http->getRequests());
    }

    #[DataProvider('forbiddenCharacters')]
    public function testAStringableMergeTagValueWithAForbiddenCharacterIsRejected(string $value, string $message): void
    {
        $stringable = new class ($value) implements \Stringable {
            public function __construct(private readonly string $value) {}

            public function __toString(): string
            {
                return $this->value;
            }
        };

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($message);
        $unused = $this->templateEmail()->mergeTags(['NAME' => $stringable]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forbiddenCharacters(): iterable
    {
        $lineBreak = 'Merge tag value must not contain line breaks';
        $control = 'Merge tag value must not contain control characters';

        yield 'CR' => ["Jane\rDoe", $lineBreak];
        yield 'LF' => ["Jane\nDoe", $lineBreak];
        yield 'CR LF header injection' => ["Jane\r\nBcc: evil@example.com", $lineBreak];
        yield 'U+0085 next line' => ["Jane\u{85}Doe", $lineBreak];
        yield 'U+2028 line separator' => ["Jane\u{2028}Doe", $lineBreak];
        yield 'U+2029 paragraph separator' => ["Jane\u{2029}Doe", $lineBreak];
        yield 'leading LF' => ["\nJane", $lineBreak];
        yield 'trailing LF' => ["Jane\n", $lineBreak];
        yield 'NUL' => ["Jane\0Doe", $control];
        yield 'U+0001' => ["Jane\x01Doe", $control];
        yield 'tab' => ["Jane\tDoe", $control];
        yield 'vertical tab' => ["Jane\x0BDoe", $control];
        yield 'form feed' => ["Jane\x0CDoe", $control];
        yield 'escape' => ["Jane\x1BDoe", $control];
        yield 'U+001F' => ["Jane\x1FDoe", $control];
        yield 'DEL' => ["Jane\x7FDoe", $control];
        yield 'U+0080' => ["Jane\u{80}Doe", $control];
        yield 'U+009B' => ["Jane\u{9B}Doe", $control];
        yield 'U+009F' => ["Jane\u{9F}Doe", $control];
    }

    public function testEveryC0AndC1ControlCharacterIsRejected(): void
    {
        $codePoints = [...range(0x00, 0x1F), ...range(0x7F, 0x9F)];
        foreach ($codePoints as $codePoint) {
            $character = $codePoint < 0x80 ? chr($codePoint) : "\xC2" . chr($codePoint);
            try {
                $unused = $this->templateEmail()->mergeTags(['NAME' => 'a' . $character . 'b']);
                self::fail(sprintf('U+%04X must be rejected', $codePoint));
            } catch (ValidationException $exception) {
                self::assertMatchesRegularExpression(
                    '/^Merge tag value must not contain (line breaks|control characters)$/',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testUnicodeSpacesAndEmptyValuesAreSentUnchanged(): void
    {
        $this->http->addResponse($this->successResponse());

        $tags = [
            'NAME' => '  Jane Doe  ',
            'CITY' => 'São Paulo 日本語 עברית',
            'EMOJI' => '👩🏽‍💻 🎉',
            'HTML' => 'Line one<br>Line two',
            'NBSP' => "Jane\u{A0}Doe",
            'EMPTY' => '',
        ];
        $this->client()->emails()->send($this->templateEmail()->mergeTags($tags));

        self::assertSame($tags, $this->lastJson()['merge_tags']);
    }

    public function testScalarValuesAreStillConverted(): void
    {
        $body = $this->templateEmail()
            ->mergeTags(['YES' => true, 'NO' => false, 'NONE' => null, 'COUNT' => 3, 'PRICE' => 9.5])
            ->toRequestBody();

        self::assertEquals(
            (object) ['YES' => '1', 'NO' => '0', 'NONE' => '', 'COUNT' => '3', 'PRICE' => '9.5'],
            $body['merge_tags'],
        );
    }

    private function templateEmail(): Email
    {
        return Email::make()
            ->to('jane@example.com', 'Jane Doe')
            ->from('billing@example.com', 'Acme')
            ->subject('Your order')
            ->templateId('ab123456-1234-1234-1234-123456789abc');
    }

    private function successResponse(): \Psr\Http\Message\ResponseInterface
    {
        return $this->jsonResponse(201, [
            'status' => 'success',
            'code' => '000.000',
            'email_uid' => 'em_tags',
            'warning' => null,
        ]);
    }
}
