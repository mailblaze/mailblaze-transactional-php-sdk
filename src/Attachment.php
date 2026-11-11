<?php

declare(strict_types=1);

namespace MailBlaze\Transactional;

use MailBlaze\Transactional\Exception\ValidationException;

final class Attachment
{
    public const MAX_ENCODED_BYTES = 10 * 1024 * 1024;

    private function __construct(
        private readonly string $name,
        private readonly string $encoded,
    ) {}

    public static function fromBytes(
        #[\SensitiveParameter]
        string $bytes,
        string $filename,
    ): self {
        self::assertEstimatedSize(strlen($bytes));
        $encoded = base64_encode($bytes);
        self::assertEncodedSize(strlen($encoded));

        return new self(self::filename($filename), $encoded);
    }

    public static function fromPath(string $path): self
    {
        if (preg_match('/[\r\n\0]/', $path) === 1) {
            throw new ValidationException('Attachment path must not contain line breaks');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new ValidationException('Attachment path is not a readable file');
        }

        $size = filesize($path);
        if ($size === false) {
            throw new ValidationException('Attachment size could not be read');
        }
        self::assertEstimatedSize($size);

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new ValidationException('Attachment could not be read');
        }

        return self::fromBytes($bytes, self::basename($path));
    }

    /**
     * @return array{name: string, data: string}
     */
    public function toPayload(): array
    {
        return [
            'name' => $this->name,
            'data' => $this->encoded,
        ];
    }

    public function name(): string
    {
        return $this->name;
    }

    private static function basename(string $path): string
    {
        $name = basename(str_replace('\\', '/', $path));

        return self::filename($name);
    }

    private static function filename(string $filename): string
    {
        if (preg_match('/[\r\n\0]/', $filename) === 1 || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new ValidationException('Attachment filename must not include a path or line breaks');
        }

        $filename = trim($filename);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new ValidationException('Attachment filename is invalid');
        }

        return $filename;
    }

    private static function assertEstimatedSize(int $bytes): void
    {
        if ($bytes < 0) {
            throw new ValidationException('Attachment size could not be read');
        }

        $estimated = 4 * (int) ceil($bytes / 3);
        if ($estimated > self::MAX_ENCODED_BYTES) {
            self::tooLarge();
        }
    }

    private static function assertEncodedSize(int $bytes): void
    {
        if ($bytes > self::MAX_ENCODED_BYTES) {
            self::tooLarge();
        }
    }

    private static function tooLarge(): never
    {
        throw new ValidationException(
            'Attachment exceeds the 10MB size limit',
            null,
            '150.005',
            'Attachment exceeds the 10MB size limit',
        );
    }
}
