<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Tests;

use MailBlaze\Transactional\Exception\AuthenticationException;
use MailBlaze\Transactional\Http\Redactor;

final class RedactionTest extends SdkTestCase
{
    public function testExceptionTextDoesNotContainTheApiKey(): void
    {
        $apiKey = 'mb-test-key';
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
        $apiKey = 'mb-test-key';
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
        $apiKey = 'mb-test-key';
        $client = $this->client($apiKey);

        try {
            serialize($client);
            self::fail('The client must not be serializable');
        } catch (\LogicException $exception) {
            self::assertStringNotContainsString($apiKey, $exception->getMessage());
        }
    }

    public function testATruncatedErrorStillRedactsTheApiKey(): void
    {
        $apiKey = 'FAKEKEY_r9_SecretControl';
        $prefix = substr($apiKey, 0, -2);
        $error = str_repeat('x', 500 - strlen($prefix)) . $apiKey;
        $this->http->addResponse($this->jsonResponse(400, [
            'status' => 'error',
            'error' => $error,
        ]));

        try {
            $this->client($apiKey)->emails()->send($this->simpleEmail());
            self::fail('Expected an authentication or validation exception');
        } catch (\MailBlaze\Transactional\Exception\MailBlazeException $exception) {
            $fields = [
                $exception->getMessage(),
                (string) $exception->errorMessage(),
                (string) $exception,
                serialize($exception),
                print_r($exception, true),
                var_export($exception, true),
            ];
            foreach ($fields as $field) {
                self::assertStringNotContainsString($apiKey, $field);
                self::assertStringNotContainsString($prefix, $field);
            }
        }
    }

    public function testAReflectedApiKeyIsNotStoredAsTheErrorCode(): void
    {
        $apiKey = 'mb-test-key';
        $this->http->addResponse($this->jsonResponse(400, [
            'status' => 'error',
            'code' => $apiKey,
            'error' => 'rejected ' . $apiKey,
        ]));

        try {
            $this->client($apiKey)->emails()->send($this->simpleEmail());
            self::fail('Expected the reflected key to stay out of the exception');
        } catch (\MailBlaze\Transactional\Exception\MailBlazeException $exception) {
            self::assertNull($exception->errorCode());
            self::assertStringNotContainsString($apiKey, $exception->getMessage());
            self::assertStringNotContainsString($apiKey, (string) $exception->errorMessage());
            self::assertStringNotContainsString($apiKey, (string) json_encode($exception->__debugInfo()));
            self::assertStringNotContainsString($apiKey, serialize($exception));
        }
    }

    public function testRedactorReplacesTheSecretAndItsUrlEncoding(): void
    {
        $secret = 'mb-test-key';
        $encoded = rawurlencode($secret);

        self::assertSame(
            'rejected [redacted] and [redacted]',
            Redactor::redact('rejected ' . $secret . ' and ' . $encoded, $secret),
        );
    }
}
