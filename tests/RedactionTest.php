<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Tests;

use MailBlaze\Transactional\Exception\AuthenticationException;
use MailBlaze\Transactional\Http\Redactor;

final class RedactionTest extends SdkTestCase
{
    public function testExceptionTextDoesNotContainTheApiKey(): void
    {
        $apiKey = 'mb-secret-key-1112';
        $this->http->addResponse($this->jsonResponse(403, [
            'status' => 'error',
            'code' => '150.001',
            'error' => 'rejected ' . $apiKey,
        ]));

        try {
            $this->client($apiKey)->emails()->send($this->simpleEmail());
            self::fail('Expected an authentication exception');
        } catch (AuthenticationException $exception) {
            self::assertStringNotContainsString($apiKey, $exception->getMessage());
            self::assertStringNotContainsString($apiKey, (string) $exception);
            self::assertStringNotContainsString($apiKey, (string) json_encode($exception->__debugInfo()));
            $stored = serialize($exception);
            self::assertStringNotContainsString($apiKey, $stored);
            $restored = unserialize($stored);
            self::assertInstanceOf(AuthenticationException::class, $restored);
            self::assertSame('150.001', $restored->errorCode());
            self::assertStringNotContainsString($apiKey, $restored->getMessage());
            self::assertStringNotContainsString($apiKey, (string) $restored);
        }
    }

    public function testVarExportDoesNotContainTheApiKey(): void
    {
        $apiKey = 'mb-secret-key-1112';
        $client = $this->client($apiKey, new ArrayLogger());
        self::assertStringNotContainsString($apiKey, var_export($client, true));

        $this->http->addResponse($this->jsonResponse(403, [
            'status' => 'error',
            'code' => '150.001',
            'error' => 'rejected ' . $apiKey,
        ]));

        try {
            $client->emails()->send($this->simpleEmail());
            self::fail('Expected an authentication exception');
        } catch (AuthenticationException $exception) {
            self::assertStringNotContainsString($apiKey, var_export($exception, true));
            self::assertStringNotContainsString($apiKey, (string) $exception);
        }
    }

    public function testTheClientRefusesSerialization(): void
    {
        $apiKey = 'mb-secret-key-1112';
        $client = $this->client($apiKey);

        try {
            serialize($client);
            self::fail('The client must not be serializable');
        } catch (\LogicException $exception) {
            self::assertStringNotContainsString($apiKey, $exception->getMessage());
        }
    }

    public function testRedactorReplacesTheSecretAndItsUrlEncoding(): void
    {
        $secret = 'mb-secret-key-1112';
        $encoded = rawurlencode($secret);

        self::assertSame(
            'rejected [redacted] and [redacted]',
            Redactor::redact('rejected ' . $secret . ' and ' . $encoded, $secret),
        );
    }
}
