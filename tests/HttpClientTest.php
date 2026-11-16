<?php

declare(strict_types=1);

namespace MailBlaze\Transactional\Tests;

use MailBlaze\Transactional\Client;
use MailBlaze\Transactional\Email;
use MailBlaze\Transactional\Exception\RedactedException;
use MailBlaze\Transactional\Exception\TransportException;
use MailBlaze\Transactional\Http\Transport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

/**
 * Sends real HTTP requests through the clients the SDK builds, against PHP's built-in web server
 * on 127.0.0.1. CI installs Guzzle 7 and Guzzle 8 in separate jobs so the Guzzle tests run there.
 */
final class HttpClientTest extends TestCase
{
    private const API_KEY = 'mb-local-test-key';

    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            return;
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        self::$port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/Fixtures/server.php'],
            [0 => ['pipe', 'r'], 1 => ['file', self::nullDevice(), 'w'], 2 => ['file', self::nullDevice(), 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => '4'],
        );
        if (!is_resource($process)) {
            return;
        }
        self::$server = $process;

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::$server = null;
    }

    public function testTheSdkBuiltGuzzleClientSendsAndLists(): void
    {
        $guzzle = $this->sdkGuzzleClient(5.0);
        $client = $this->localClient(httpClient: $guzzle);

        $result = $client->emails()->send(self::email());
        $page = $client->emails()->list();

        self::assertSame('em_local', $result->emailUid());
        self::assertSame('em_local', $page->records()[0]->emailUid());
    }

    public function testTheSdkBuiltGuzzleClientReportsAConnectionFailure(): void
    {
        $guzzle = $this->sdkGuzzleClient(2.0);
        $client = $this->localClient(httpClient: $guzzle, port: self::closedPort());

        try {
            $client->emails()->list();
            self::fail('A refused connection must be a TransportException');
        } catch (TransportException $exception) {
            self::assertSame(TransportException::REASON_CONNECT, $exception->reason());
            self::assertInstanceOf(RedactedException::class, $exception->getPrevious());
            self::assertStringNotContainsString(self::API_KEY, (string) $exception . var_export($exception, true));
        }
    }

    public function testTheSdkBuiltSymfonyClientSendsAndLists(): void
    {
        $this->requireSymfony();
        $client = $this->localClient();

        $result = $client->emails()->send(self::email());
        $page = $client->emails()->list();

        self::assertSame('em_local', $result->emailUid());
        self::assertSame('em_local', $page->records()[0]->emailUid());
    }

    public function testTheSdkBuiltSymfonyClientReportsATimeout(): void
    {
        $this->requireSymfony();
        $client = $this->localClient(timeout: 0.5, path: '/slow/api');

        try {
            $client->emails()->list();
            self::fail('A slow response must time out');
        } catch (TransportException $exception) {
            self::assertSame(TransportException::REASON_TIMEOUT, $exception->reason());
            self::assertInstanceOf(RedactedException::class, $exception->getPrevious());
            self::assertStringNotContainsString(self::API_KEY, (string) $exception . var_export($exception, true));
        }
    }

    private function sdkGuzzleClient(float $timeout): ClientInterface
    {
        if (!class_exists('GuzzleHttp\\Client')) {
            self::markTestSkipped('guzzlehttp/guzzle is not installed. CI installs it in the Guzzle jobs.');
        }
        $this->requireServer();

        $method = new \ReflectionMethod(Transport::class, 'guzzleClient');
        $guzzle = $method->invoke(null, $timeout, false);
        self::assertInstanceOf(ClientInterface::class, $guzzle);

        return $guzzle;
    }

    private function requireSymfony(): void
    {
        if (!class_exists(\Symfony\Component\HttpClient\Psr18Client::class)) {
            self::markTestSkipped('symfony/http-client is not installed');
        }
        $this->requireServer();
    }

    private function requireServer(): void
    {
        if (!is_resource(self::$server)) {
            self::markTestSkipped('The local PHP web server could not be started');
        }
    }

    private function localClient(
        ?ClientInterface $httpClient = null,
        ?int $port = null,
        float $timeout = 5.0,
        string $path = '/api',
    ): Client {
        $factory = new Psr17Factory();

        return Client::create(
            apiKey: self::API_KEY,
            baseUri: 'http://127.0.0.1:' . ($port ?? self::$port) . $path,
            timeout: $timeout,
            httpClient: $httpClient,
            requestFactory: $factory,
            streamFactory: $factory,
            allowHttp: true,
            getRetryLimit: 0,
            retryDelayMs: 0,
        );
    }

    private static function email(): Email
    {
        return Email::make()
            ->to('jane@example.com', 'Jane Doe')
            ->from('billing@example.com', 'Acme')
            ->subject('Local test')
            ->html('<p>Local test</p>');
    }

    private static function closedPort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }
}
