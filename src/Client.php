<?php

declare(strict_types=1);

namespace MailBlaze\Transactional;

use MailBlaze\Transactional\Http\Transport;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

final class Client
{
    public const VERSION = '0.1.0';

    public const DEFAULT_BASE_URI = 'https://control.mailblaze.com/api';

    public const DEFAULT_TIMEOUT = 30.0;

    private function __construct(private readonly Transport $transport) {}

    public static function create(
        #[\SensitiveParameter]
        string $apiKey,
        string $baseUri = self::DEFAULT_BASE_URI,
        float $timeout = self::DEFAULT_TIMEOUT,
        ?LoggerInterface $logger = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        bool $allowInsecureTls = false,
        bool $allowHttp = false,
        int $getRetryLimit = 2,
        int $retryDelayMs = 200,
    ): self {
        if ($httpClient !== null && $allowInsecureTls) {
            self::rejectInsecureFlagWithInjectedClient();
        }
        if ($timeout <= 0) {
            self::rejectTimeout();
        }
        if ($getRetryLimit < 0 || $retryDelayMs < 0) {
            self::rejectRetrySettings();
        }

        return new self(new Transport(
            $apiKey,
            $httpClient,
            $requestFactory,
            $streamFactory,
            $baseUri,
            $timeout,
            $allowInsecureTls,
            $allowHttp,
            $getRetryLimit,
            $retryDelayMs,
            $logger,
        ));
    }

    public function emails(): Emails
    {
        return new Emails($this->transport);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['version' => self::VERSION];
    }

    public function __serialize(): never
    {
        throw new \LogicException('The Mail Blaze client cannot be serialized because it holds the API key.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): never
    {
        throw new \LogicException('The Mail Blaze client cannot be serialized because it holds the API key.');
    }

    /**
     * @return never
     */
    private static function rejectInsecureFlagWithInjectedClient(): void
    {
        throw new \InvalidArgumentException('allowInsecureTls applies only when the SDK builds the HTTP client. Configure TLS on the injected client instead.');
    }

    /**
     * @return never
     */
    private static function rejectTimeout(): void
    {
        throw new \InvalidArgumentException('timeout must be greater than zero');
    }

    /**
     * @return never
     */
    private static function rejectRetrySettings(): void
    {
        throw new \InvalidArgumentException('retry settings must not be negative');
    }
}
