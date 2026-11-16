<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Http;

use Http\Discovery\Psr17FactoryDiscovery;
use MailBlaze\Transactional\Client;
use MailBlaze\Transactional\Exception\ApiException;
use MailBlaze\Transactional\Exception\MailBlazeException;
use MailBlaze\Transactional\Exception\TransportException;
use MailBlaze\Transactional\Exception\UnconfirmedSendException;
use Nyholm\Psr7\Factory\Psr17Factory;
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
    private const MAX_CONTENT_LENGTH = 16 * 1024 * 1024;

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
        if (preg_match('/\A[\x21-\x7E]+\z/', $apiKey) !== 1) {
            self::rejectMalformedApiKey();
        }
        SecretBag::store($this, $apiKey);

        $this->baseUri = self::normalizeBaseUri($baseUri, $allowHttp);
        $this->httpClient = $httpClient === null
            ? self::discoverClient($this->timeout, $this->allowInsecureTls)
            : self::assertSafeInjectedClient($httpClient);
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
                $request = $this->createRequest($method, $path, $body, $query);
            } catch (MailBlazeException $exception) {
                throw $exception;
            } catch (\Throwable) {
                self::failTransport();
            }

            try {
                $response = $this->execute($request);
            } catch (TransportException $exception) {
                if ($attempt === $attempts || $method !== 'GET') {
                    throw $exception;
                }
                $this->pause($attempt);
                continue;
            }

            try {
                $status = $response->getStatusCode();
            } catch (\Throwable) {
                self::failTransport();
            }

            try {
                $rawBody = $this->readBody($response);
            } catch (TransportException) {
                if ($method === 'POST' && $status >= 200 && $status < 300) {
                    self::unconfirmed($status);
                }
                if ($status >= 300 && $status < 400) {
                    throw new ApiException('The Mail Blaze API returned an unexpected redirect', $status);
                }
                if ($method === 'GET' && $attempt < $attempts && self::isRetryableGetStatus($status)) {
                    $this->pause($attempt);
                    continue;
                }
                if ($status >= 400) {
                    throw $this->errors->map($status, [
                        'error' => 'The Mail Blaze API returned an unexpected response',
                    ], $this->apiKey());
                }
                self::failTransport();
            }

            if ($status >= 300 && $status < 400) {
                throw new ApiException('The Mail Blaze API returned an unexpected redirect', $status);
            }
            if ($method === 'GET' && in_array($status, [500, 502, 503, 504], true) && $attempt < $attempts) {
                $this->logger?->info('Mail Blaze transactional API response', [
                    'method' => $method,
                    'path' => $path,
                    'status' => $status,
                ]);
                $this->pause($attempt);
                continue;
            }

            try {
                $decoded = Json::decodeObject($rawBody);
            } catch (ApiException) {
                if ($method === 'POST' && $status >= 200 && $status < 300) {
                    self::unconfirmed($status);
                }
                // Guzzle can return an empty or truncated body when a stream stalls, instead of
                // throwing while reading. Treat that like a transport failure and retry the GET.
                if ($method === 'GET' && $attempt < $attempts && self::isRetryableGetStatus($status)) {
                    $this->pause($attempt);
                    continue;
                }
                throw $this->errors->map($status, [
                    'error' => 'The Mail Blaze API returned an unexpected response',
                ], $this->apiKey());
            }
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

    private function readBody(ResponseInterface $response): string
    {
        try {
            // Validate Content-Length before reading. A huge value can make some HTTP
            // clients allocate until the worker is killed, before any SDK catch runs.
            $lengthInfo = self::contentLengthInfo($response);
            $chunked = self::isChunked($response);
            $stream = $response->getBody();
            $raw = (string) $stream;
        } catch (\Throwable) {
            self::failTransport();
        }
        if (self::streamLooksIncomplete($stream)) {
            self::failTransport();
        }
        // Symfony may keep Transfer-Encoding: chunked after it has already decoded the body.
        // Only decode when the bytes still look like chunk framing.
        if ($chunked && self::looksLikeChunkedBody($raw)) {
            return self::decodeChunked($raw);
        }
        if ($lengthInfo['compare'] && $lengthInfo['length'] !== null && strlen($raw) < $lengthInfo['length']) {
            self::failTransport();
        }
        if (strlen($raw) > self::MAX_CONTENT_LENGTH) {
            self::failTransport();
        }

        return $raw;
    }

    /**
     * @return array{length: int|null, compare: bool}
     */
    private static function contentLengthInfo(ResponseInterface $response): array
    {
        $encoding = strtolower($response->getHeaderLine('Content-Encoding'));
        $compare = $encoding === '' || $encoding === 'identity';
        $lengthHeader = $response->getHeaderLine('Content-Length');
        if ($lengthHeader === '') {
            return ['length' => null, 'compare' => $compare];
        }

        return [
            'length' => self::parseContentLengthHeader($lengthHeader),
            'compare' => $compare,
        ];
    }

    private static function parseContentLengthHeader(string $lengthHeader): int
    {
        $parts = preg_split('/\s*,\s*/', $lengthHeader);
        if ($parts === false) {
            self::failTransport();
        }
        $declared = null;
        foreach ($parts as $part) {
            // ASCII digits only. ctype_digit() needs ext-ctype and can follow the process locale.
            if (preg_match('/\A[0-9]+\z/', $part) !== 1) {
                self::failTransport();
            }
            if (self::digitStringExceeds($part, self::MAX_CONTENT_LENGTH)) {
                self::failTransport();
            }
            $value = (int) $part;
            if ($declared === null) {
                $declared = $value;
                continue;
            }
            if ($value !== $declared) {
                self::failTransport();
            }
        }
        if ($declared === null) {
            self::failTransport();
        }

        return $declared;
    }

    private static function digitStringExceeds(string $digits, int $max): bool
    {
        $normalized = ltrim($digits, '0');
        if ($normalized === '') {
            return false;
        }
        $maxDigits = (string) $max;
        if (strlen($normalized) !== strlen($maxDigits)) {
            return strlen($normalized) > strlen($maxDigits);
        }

        return $normalized > $maxDigits;
    }

    private static function isChunked(ResponseInterface $response): bool
    {
        return str_contains(strtolower($response->getHeaderLine('Transfer-Encoding')), 'chunked');
    }

    private static function looksLikeChunkedBody(string $raw): bool
    {
        return preg_match('/\A[0-9A-Fa-f]+(?:;[^\r\n]*)?\r\n/', $raw) === 1;
    }

    private static function decodeChunked(string $raw): string
    {
        $out = '';
        $offset = 0;
        $length = strlen($raw);
        while ($offset < $length) {
            $lineEnd = strpos($raw, "\r\n", $offset);
            if ($lineEnd === false) {
                self::failTransport();
            }
            $sizeLine = substr($raw, $offset, $lineEnd - $offset);
            $sizeHex = explode(';', $sizeLine, 2)[0];
            $size = self::chunkSize($sizeHex);
            $offset = $lineEnd + 2;
            if ($size === 0) {
                self::assertCompleteChunkedTrailer(substr($raw, $offset));
                if (strlen($out) > self::MAX_CONTENT_LENGTH) {
                    self::failTransport();
                }

                return $out;
            }
            if (strlen($out) > self::MAX_CONTENT_LENGTH - $size) {
                self::failTransport();
            }
            if ($offset + $size + 2 > $length) {
                self::failTransport();
            }
            $out .= substr($raw, $offset, $size);
            $offset += $size;
            if (substr($raw, $offset, 2) !== "\r\n") {
                self::failTransport();
            }
            $offset += 2;
        }

        self::failTransport();
    }

    private static function chunkSize(string $sizeHex): int
    {
        if ($sizeHex === '' || preg_match('/\A[0-9A-Fa-f]+\z/', $sizeHex) !== 1) {
            self::failTransport();
        }
        $significant = ltrim($sizeHex, '0');
        if ($significant === '') {
            return 0;
        }
        // Reject sizes that cannot fit a safe integer parse, but allow zero padding.
        if (strlen($significant) > 7) {
            self::failTransport();
        }
        if (self::hexStringExceeds($significant, self::MAX_CONTENT_LENGTH)) {
            self::failTransport();
        }

        return (int) hexdec($significant);
    }

    private static function hexStringExceeds(string $hex, int $max): bool
    {
        $normalized = strtolower(ltrim($hex, '0'));
        if ($normalized === '') {
            return false;
        }
        $maxHex = dechex($max);
        if (strlen($normalized) !== strlen($maxHex)) {
            return strlen($normalized) > strlen($maxHex);
        }

        return $normalized > $maxHex;
    }

    private static function assertCompleteChunkedTrailer(string $rest): void
    {
        if ($rest === "\r\n") {
            return;
        }
        // field-name = token (RFC 9110). Reject names such as "Bad(Header)" or ":Bad".
        if (preg_match('/\A(?:[!#$%&\'*+\-.0-9A-Z^_`a-z|~]+:[^\r\n]*\r\n)+\r\n\z/', $rest) === 1) {
            return;
        }

        self::failTransport();
    }

    private static function streamLooksIncomplete(\Psr\Http\Message\StreamInterface $stream): bool
    {
        try {
            $meta = $stream->getMetadata();
        } catch (\Throwable) {
            return false;
        }
        if (!is_array($meta)) {
            return false;
        }
        if (($meta['timed_out'] ?? false) === true) {
            return true;
        }

        $unread = $meta['unread_bytes'] ?? 0;

        return ($meta['eof'] ?? true) === false && is_int($unread) && $unread > 0;
    }

    private static function isRetryableGetStatus(int $status): bool
    {
        return ($status >= 200 && $status < 300) || in_array($status, [500, 502, 503, 504], true);
    }

    private function execute(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->httpClient->sendRequest($request);
        } catch (\Throwable) {
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

        $guzzle = self::guzzleClient($timeout, $allowInsecureTls);
        if ($guzzle !== null) {
            return $guzzle;
        }

        if ($allowInsecureTls) {
            self::rejectInsecureWithoutManagedClient();
        }

        self::rejectUnmanagedClient();
    }

    private static function assertSafeInjectedClient(ClientInterface $httpClient): ClientInterface
    {
        if (!is_a($httpClient, 'GuzzleHttp\\Client')) {
            return $httpClient;
        }

        $getConfig = [$httpClient, 'getConfig'];
        if (!is_callable($getConfig)) {
            return $httpClient;
        }

        $redirects = $getConfig('allow_redirects');
        if ($redirects !== false) {
            self::rejectRedirectingClient();
        }

        return $httpClient;
    }

    private static function guzzleClient(float $timeout, bool $allowInsecureTls): ?ClientInterface
    {
        $class = 'GuzzleHttp\\Client';
        if (!class_exists($class)) {
            return null;
        }

        $options = [
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'http_errors' => false,
            'allow_redirects' => false,
            'stream' => true,
            'verify' => !$allowInsecureTls,
        ];
        // Keep chunked framing so a missing terminal chunk is not accepted as a complete body.
        if (defined('CURLOPT_HTTP_TRANSFER_DECODING')) {
            $options['curl'] = [\CURLOPT_HTTP_TRANSFER_DECODING => false];
        }
        $client = new $class($options);
        if (!$client instanceof ClientInterface) {
            return null;
        }

        return $client;
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
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if ($host === '' || ($scheme !== 'https' && $scheme !== 'http') || isset($parts['fragment']) || !self::isAcceptableHost($host)) {
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

    private static function isAcceptableHost(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $inner = substr($host, 1, -1);
            if ($inner === '' || filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return false;
            }

            return self::isAllowedIp($inner);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isAllowedIp($host);
        }

        return preg_match('/\A(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*\z/', $host) === 1;
    }

    private static function isAllowedIp(string $ip): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }

        // Reject private, link-local, and other reserved ranges (including cloud metadata IPs).
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function rejectEmptyApiKey(): never
    {
        throw new TransportException('API key must not be empty');
    }

    private static function rejectMalformedApiKey(): never
    {
        throw new TransportException('API key must be printable ASCII without spaces or control characters');
    }

    private static function rejectUnmanagedClient(): never
    {
        throw new TransportException('Install symfony/http-client or guzzlehttp/guzzle so the SDK can apply the timeout, or pass an HTTP client that already has one.');
    }

    private static function rejectRedirectingClient(): never
    {
        throw new TransportException('Injected Guzzle clients must set allow_redirects to false so the API key is not forwarded to another host');
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

    private static function rejectInsecureWithoutManagedClient(): never
    {
        throw new TransportException('allowInsecureTls requires symfony/http-client or guzzlehttp/guzzle so certificate verification can be configured');
    }

    private static function unconfirmed(int $status): never
    {
        throw new UnconfirmedSendException(
            'The Mail Blaze API returned HTTP ' . $status . ', but the response could not be read. The email may already have been accepted. Check the list before sending it again.',
            $status,
        );
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
