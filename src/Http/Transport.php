<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Http;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use MailBlaze\Transactional\Client;
use MailBlaze\Transactional\Exception\TransportException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * @internal
 */
final class Transport
{
    private readonly ClientInterface $httpClient;

    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    private readonly string $baseUri;

    private readonly ErrorMapper $errors;

    private readonly ?LoggerInterface $logger;

    public function __construct(
        #[\SensitiveParameter]
        string $apiKey,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        string $baseUri = Client::DEFAULT_BASE_URI,
        private readonly float $timeout = Client::DEFAULT_TIMEOUT,
        private readonly bool $allowInsecureTls = false,
        bool $allowHttp = false,
        private readonly int $getRetryLimit = 2,
        private readonly int $retryDelayMs = 200,
        ?LoggerInterface $logger = null,
    ) {
        if ($apiKey === '' || trim($apiKey) === '') {
            self::rejectEmptyApiKey();
        }
        if ($apiKey !== trim($apiKey) || preg_match('/[\r\n]/', $apiKey) === 1) {
            self::rejectMalformedApiKey();
        }
        SecretBag::store($this, $apiKey);

        $this->baseUri = self::normalizeBaseUri($baseUri, $allowHttp);
        $this->httpClient = $httpClient ?? self::discoverClient($this->timeout, $this->allowInsecureTls);
        $this->requestFactory = $requestFactory ?? self::discoverRequestFactory();
        $this->streamFactory = $streamFactory ?? self::discoverStreamFactory();
        $this->errors = new ErrorMapper();
        $this->logger = $logger === null ? null : new RedactingLogger($logger, $apiKey);
    }

    private function apiKey(): string
    {
        $apiKey = SecretBag::get($this);
        if ($apiKey === null || $apiKey === '') {
            self::rejectEmptyApiKey();
        }

        return $apiKey;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, int|string> $query
     *
     * @return array<string, mixed>
     */
    public function submit(
        string $method,
        string $path,
        #[\SensitiveParameter]
        ?array $body = null,
        array $query = [],
    ): array {
        $method = strtoupper($method);
        $attempts = $method === 'GET' ? $this->getRetryLimit + 1 : 1;

        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            $this->logger?->info('Mail Blaze transactional API request', [
                'method' => $method,
                'path' => $path,
                'attempt' => $attempt,
            ]);

            try {
                $response = $this->execute($this->createRequest($method, $path, $body, $query));
            } catch (TransportException $exception) {
                if ($attempt === $attempts || $method !== 'GET') {
                    throw $exception;
                }
                $this->pause($attempt);
                continue;
            }

            $status = $response->getStatusCode();
            if ($method === 'GET' && $status >= 500 && $attempt < $attempts) {
                $this->logger?->info('Mail Blaze transactional API response', [
                    'method' => $method,
                    'path' => $path,
                    'status' => $status,
                ]);
                $this->pause($attempt);
                continue;
            }

            $decoded = Json::decodeObject((string) $response->getBody());
            $code = isset($decoded['code']) && is_string($decoded['code']) ? $decoded['code'] : null;
            $this->logger?->info('Mail Blaze transactional API response', [
                'method' => $method,
                'path' => $path,
                'status' => $status,
                'code' => $code,
            ]);

            if ($status >= 400 || ($decoded['status'] ?? null) === 'error') {
                throw $this->errors->map($status, $decoded, $this->apiKey());
            }

            return $decoded;
        }

        self::failTransport();
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, int|string> $query
     */
    private function createRequest(
        string $method,
        string $path,
        #[\SensitiveParameter]
        ?array $body,
        array $query,
    ): RequestInterface {
        $request = $this->requestFactory
            ->createRequest($method, $this->uri($path, $query))
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'mailblaze-transactional-php-sdk/' . Client::VERSION)
            ->withHeader('Authorization', $this->apiKey());

        if ($body === null) {
            return $request;
        }

        return $request
            ->withBody($this->streamFactory->createStream(Json::encode($body)))
            ->withHeader('Content-Type', 'application/json');
    }

    private function execute(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface) {
            $this->logger?->error('Mail Blaze transactional API transport failure', [
                'message' => 'The HTTP client could not complete the request',
            ]);
            self::failTransport();
        }
    }

    /**
     * @param array<string, int|string> $query
     */
    private function uri(string $path, array $query): string
    {
        $uri = $this->baseUri . '/' . ltrim($path, '/');
        if ($query === []) {
            return $uri;
        }

        return $uri . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function pause(int $attempt): void
    {
        if ($this->retryDelayMs === 0) {
            return;
        }

        usleep($this->retryDelayMs * 1000 * $attempt);
    }

    private static function discoverClient(float $timeout, bool $allowInsecureTls): ClientInterface
    {
        if (class_exists(HttpClient::class) && class_exists(Psr18Client::class)) {
            $verify = !$allowInsecureTls;
            $symfony = HttpClient::create([
                'timeout' => $timeout,
                'max_duration' => $timeout,
                'max_redirects' => 0,
                'verify_peer' => $verify,
                'verify_host' => $verify,
            ]);
            $factory = class_exists(Psr17Factory::class) ? new Psr17Factory() : null;

            return new Psr18Client($symfony, $factory, $factory);
        }

        if ($allowInsecureTls) {
            self::rejectInsecureWithoutSymfony();
        }

        try {
            return Psr18ClientDiscovery::find();
        } catch (\Throwable) {
            self::missingHttpClient();
        }
    }

    private static function discoverRequestFactory(): RequestFactoryInterface
    {
        try {
            return Psr17FactoryDiscovery::findRequestFactory();
        } catch (\Throwable) {
            self::missingHttpFactory();
        }
    }

    private static function discoverStreamFactory(): StreamFactoryInterface
    {
        try {
            return Psr17FactoryDiscovery::findStreamFactory();
        } catch (\Throwable) {
            self::missingHttpFactory();
        }
    }

    private static function normalizeBaseUri(string $baseUri, bool $allowHttp): string
    {
        $baseUri = rtrim(trim($baseUri), '/');
        $parts = parse_url($baseUri);
        if ($parts === false) {
            self::rejectBaseUri();
        }
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        if ($host === '' || ($scheme !== 'https' && $scheme !== 'http')) {
            self::rejectBaseUri();
        }
        if ($scheme === 'http' && (!$allowHttp || !self::isLocalHttpHost($host))) {
            self::rejectHttpBaseUri();
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])) {
            self::rejectCredentialsInUri();
        }

        return $baseUri;
    }

    private static function isLocalHttpHost(string $host): bool
    {
        $host = strtolower($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || $host === 'host.docker.internal'
            || str_ends_with($host, '.local');
    }

    private static function rejectEmptyApiKey(): never
    {
        throw new TransportException('API key must not be empty');
    }

    private static function rejectMalformedApiKey(): never
    {
        throw new TransportException('API key must not contain whitespace or line breaks');
    }

    private static function rejectBaseUri(): never
    {
        throw new TransportException('Base URI must be an https URL');
    }

    private static function rejectHttpBaseUri(): never
    {
        throw new TransportException('HTTP is only allowed for a local development host when allowHttp is true');
    }

    private static function rejectCredentialsInUri(): never
    {
        throw new TransportException('Base URI must not contain credentials or a query string');
    }

    private static function rejectInsecureWithoutSymfony(): never
    {
        throw new TransportException('allowInsecureTls requires symfony/http-client so certificate verification can be configured');
    }

    private static function missingHttpClient(): never
    {
        throw new TransportException('No PSR-18 client was found. Install symfony/http-client and nyholm/psr7.');
    }

    private static function missingHttpFactory(): never
    {
        throw new TransportException('No PSR-17 factory was found. Install nyholm/psr7.');
    }

    private static function failTransport(): never
    {
        throw new TransportException('The Mail Blaze API request could not be completed');
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
}
