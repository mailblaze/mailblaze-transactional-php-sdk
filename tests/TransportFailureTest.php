<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Tests;

use MailBlaze\Transactional\Client;
use MailBlaze\Transactional\Exception\ConfigurationException;
use MailBlaze\Transactional\Exception\RedactedException;
use MailBlaze\Transactional\Exception\TransportException;
use MailBlaze\Transactional\Exception\UnconfirmedSendException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class TransportFailureTest extends SdkTestCase
{
    #[DataProvider('failures')]
    public function testANetworkFailureCarriesAReasonAndARedactedCause(\Exception $failure, string $reason): void
    {
        $this->http->addException($failure);

        try {
            $this->client(getRetryLimit: 0)->emails()->list();
            self::fail('A network failure must be a TransportException');
        } catch (TransportException $exception) {
            self::assertSame($reason, $exception->reason());
            self::assertSame('The Mail Blaze API request could not be completed (' . $reason . ')', $exception->getMessage());
            $previous = $exception->getPrevious();
            self::assertInstanceOf(RedactedException::class, $previous);
            self::assertSame($failure::class, $previous->originalClass());
            self::assertStringContainsString('[redacted]', $previous->getMessage());
            $rendered = (string) $exception . var_export($exception, true) . print_r($exception, true) . serialize($exception);
            self::assertStringNotContainsString('mb-test-key', $rendered);
        }
    }

    /**
     * @return iterable<string, array{\Exception, string}>
     */
    public static function failures(): iterable
    {
        $key = 'mb-test-key';

        yield 'curl timeout' => [new NetworkFailure('cURL error 28: Operation timed out after 1000 milliseconds (key ' . $key . ')'), TransportException::REASON_TIMEOUT];
        yield 'symfony idle timeout' => [new NetworkFailure('Idle timeout reached for "https://control.mailblaze.com/api" ' . $key), TransportException::REASON_TIMEOUT];
        yield 'dns' => [new NetworkFailure('cURL error 6: Could not resolve host: control.mailblaze.com ' . $key), TransportException::REASON_DNS];
        yield 'tls' => [new NetworkFailure('cURL error 60: SSL certificate problem: self-signed certificate ' . $key), TransportException::REASON_TLS];
        yield 'refused' => [new NetworkFailure('Connection refused ' . $key), TransportException::REASON_CONNECT];
        yield 'psr network exception' => [new PsrNetworkFailure('socket closed ' . $key), TransportException::REASON_CONNECT];
        yield 'non-PSR error' => [new \LogicException('bad option ' . $key), TransportException::REASON_CLIENT];
        yield 'nested cause' => [new NetworkFailure('request failed ' . $key, 0, new \RuntimeException('operation timed out')), TransportException::REASON_TIMEOUT];
    }

    public function testALongClientMessageIsCapped(): void
    {
        $this->http->addException(new NetworkFailure(str_repeat('x', 2000) . 'mb-test-key'));

        try {
            $this->client(getRetryLimit: 0)->emails()->list();
            self::fail('Expected a TransportException');
        } catch (TransportException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(RedactedException::class, $previous);
            self::assertLessThanOrEqual(600, strlen($previous->getMessage()));
        }
    }

    public function testAnIncompleteBodyIsAResponseFailure(): void
    {
        $this->http->addResponse(
            $this->factory->createResponse(200)
                ->withHeader('Content-Length', '100')
                ->withBody($this->factory->createStream('{"status":"success"}')),
        );

        try {
            $this->client(getRetryLimit: 0)->emails()->list();
            self::fail('Expected a TransportException');
        } catch (TransportException $exception) {
            self::assertSame(TransportException::REASON_RESPONSE, $exception->reason());
        }
    }

    #[DataProvider('unconfirmedSends')]
    public function testAnUnconfirmedSendHasTheResponseReason(int $status, string $body): void
    {
        $this->http->addResponse($this->factory->createResponse($status)->withBody($this->factory->createStream($body)));

        try {
            $this->client()->emails()->send(EmailBuilder::receipt());
            self::fail('Expected an UnconfirmedSendException');
        } catch (UnconfirmedSendException $exception) {
            self::assertSame(TransportException::REASON_RESPONSE, $exception->reason());
        }
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function unconfirmedSends(): iterable
    {
        // The Transport cannot decode the body.
        yield 'body is not JSON' => [201, 'not-json'];
        // The body decodes, but SendResult cannot confirm it (Emails::send()).
        yield 'body has no email uid' => [201, '{"status":"success","code":"000.000"}'];
    }

    public function testAStalledSuccessfulSendIsUnconfirmedWithTheResponseReason(): void
    {
        $this->http->addResponse(
            $this->factory->createResponse(201)
                ->withHeader('Content-Length', '100')
                ->withBody($this->factory->createStream('{"status":"success"}')),
        );

        try {
            $this->client()->emails()->send(EmailBuilder::receipt());
            self::fail('Expected an UnconfirmedSendException');
        } catch (UnconfirmedSendException $exception) {
            self::assertSame(TransportException::REASON_RESPONSE, $exception->reason());
        }
    }

    #[DataProvider('failures')]
    public function testReasonSurvivesSerialization(\Exception $failure, string $reason): void
    {
        $this->http->addException($failure);

        try {
            $this->client(getRetryLimit: 0)->emails()->list();
            self::fail('Expected a TransportException');
        } catch (TransportException $exception) {
            $restored = unserialize(serialize($exception));
            self::assertInstanceOf(TransportException::class, $restored);
            self::assertSame($reason, $restored->reason());
            self::assertSame($exception->getMessage(), $restored->getMessage());
        }
    }

    public function testAnUnconfirmedSendKeepsItsReasonAndStatusAfterSerialization(): void
    {
        $exception = new UnconfirmedSendException('unconfirmed', 201);
        $restored = unserialize(serialize($exception));

        self::assertInstanceOf(UnconfirmedSendException::class, $restored);
        self::assertSame(TransportException::REASON_RESPONSE, $restored->reason());
        self::assertSame(201, $restored->httpStatus());
    }

    public function testAnUnknownSerializedReasonBecomesUnknown(): void
    {
        $stored = serialize(TransportException::create('failed', TransportException::REASON_TIMEOUT));
        $tampered = str_replace('s:7:"timeout"', 's:7:"badness"', $stored);
        self::assertNotSame($stored, $tampered);

        $restored = unserialize($tampered);
        self::assertInstanceOf(TransportException::class, $restored);
        self::assertSame(TransportException::REASON_UNKNOWN, $restored->reason());
    }

    public function testAnExceptionSerializedWithoutAReasonRestoresAsUnknown(): void
    {
        // A TransportException serialized by 0.1.x has no reason entry.
        $stored = serialize(TransportException::create('failed', TransportException::REASON_DNS));
        $legacy = str_replace([':8:{', 's:6:"reason";s:3:"dns";'], [':7:{', ''], $stored);
        self::assertNotSame($stored, $legacy);

        $restored = unserialize($legacy);
        self::assertInstanceOf(TransportException::class, $restored);
        self::assertSame(TransportException::REASON_UNKNOWN, $restored->reason());
        self::assertSame('failed', $restored->getMessage());
    }

    public function testConfigurationErrorsAreNotRetryableTransportFailures(): void
    {
        $cases = [
            'empty key' => fn(): Client => $this->client(''),
            'malformed key' => fn(): Client => $this->client("key\n"),
            'bad base uri' => fn(): Client => Client::create('mb-test-key', baseUri: 'ftp://example.com', httpClient: $this->http, requestFactory: $this->factory, streamFactory: $this->factory),
            'private ip' => fn(): Client => Client::create('mb-test-key', baseUri: 'https://10.0.0.1', httpClient: $this->http, requestFactory: $this->factory, streamFactory: $this->factory),
            'credentials' => fn(): Client => Client::create('mb-test-key', baseUri: 'https://user:pass@example.com', httpClient: $this->http, requestFactory: $this->factory, streamFactory: $this->factory),
            'timeout' => fn(): Client => Client::create('mb-test-key', timeout: 0, httpClient: $this->http, requestFactory: $this->factory, streamFactory: $this->factory),
            'retries' => fn(): Client => Client::create('mb-test-key', getRetryLimit: 9, httpClient: $this->http, requestFactory: $this->factory, streamFactory: $this->factory),
            'insecure with injected client' => fn(): Client => Client::create('mb-test-key', allowInsecureTls: true, httpClient: $this->http, requestFactory: $this->factory, streamFactory: $this->factory),
        ];

        foreach ($cases as $name => $build) {
            try {
                $build();
                self::fail('Expected a ConfigurationException: ' . $name);
            } catch (ConfigurationException $exception) {
                self::assertInstanceOf(\InvalidArgumentException::class, $exception, $name);
                self::assertStringNotContainsString('mb-test-key', $exception->getMessage() . $exception->getTraceAsString() . var_export($exception, true), $name);
            }
        }
        self::assertSame([], $this->http->getRequests());
    }

    public function testAPublicHostnameEndingInLettersIsStillAccepted(): void
    {
        foreach (['https://control.mailblaze.com/api', 'https://api.example.co.za/v1', 'https://8.8.8.8/api', 'https://a1.b2.example/api'] as $baseUri) {
            $client = Client::create('mb-test-key', baseUri: $baseUri, httpClient: $this->http, requestFactory: $this->factory, streamFactory: $this->factory);
            self::assertInstanceOf(Client::class, $client);
        }
    }
}

final class PsrNetworkFailure extends \RuntimeException implements NetworkExceptionInterface
{
    public function getRequest(): RequestInterface
    {
        return (new \Nyholm\Psr7\Factory\Psr17Factory())->createRequest('GET', 'https://example.test/');
    }
}
