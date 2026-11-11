<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Tests;

use Http\Mock\Client as MockClient;
use MailBlaze\Transactional\Client;
use MailBlaze\Transactional\Email;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

abstract class SdkTestCase extends TestCase
{
    protected Psr17Factory $factory;

    protected MockClient $http;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->http = new MockClient();
    }

    protected function client(
        string $apiKey = 'mb-secret-key-1112',
        ?LoggerInterface $logger = null,
        int $getRetryLimit = 2,
    ): Client {
        return Client::create(
            apiKey: $apiKey,
            logger: $logger,
            httpClient: $this->http,
            requestFactory: $this->factory,
            streamFactory: $this->factory,
            getRetryLimit: $getRetryLimit,
            retryDelayMs: 0,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function jsonResponse(int $status, array $payload): ResponseInterface
    {
        return $this->factory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream(json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    protected function simpleEmail(): Email
    {
        return Email::make()
            ->to('jane@example.com', 'Jane Doe')
            ->from('billing@example.com', 'Acme')
            ->subject('Your receipt')
            ->html('<p>Thanks for your order.</p>');
    }

    protected function lastRequest(): RequestInterface
    {
        return $this->http->getLastRequest();
    }

    /**
     * @return array<string, mixed>
     */
    protected function lastJson(): array
    {
        $body = $this->lastRequest()->getBody();
        $body->rewind();
        $decoded = json_decode($body->getContents(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
