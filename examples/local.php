<?php

declare(strict_types=1);

use MailBlaze\Transactional\Client;
use MailBlaze\Transactional\Email;
use MailBlaze\Transactional\Exception\MailBlazeException;

require dirname(__DIR__) . '/vendor/autoload.php';

loadLocalEnv(__DIR__ . '/local.env');

$command = $argv[1] ?? 'list';
$apiKey = getenv('MAILBLAZE_API_KEY');
$baseUri = getenv('MAILBLAZE_BASE_URI') ?: 'http://control.mailblaze.local:8080/api';
$from = getenv('MAILBLAZE_FROM') ?: 'tester-account@mailblaze.com';
$fromName = getenv('MAILBLAZE_FROM_NAME') ?: 'Mail Blaze SDK';
$to = getenv('MAILBLAZE_TO') ?: 'tester-account@mailblaze.com';

if (!is_string($apiKey) || $apiKey === '') {
    fwrite(STDERR, "Set MAILBLAZE_API_KEY in examples/local.env\n");
    exit(1);
}

$client = Client::create(
    apiKey: $apiKey,
    baseUri: $baseUri,
    allowHttp: true,
);

try {
    if ($command === 'list') {
        $page = $client->emails()->list();
        echo 'code: ' . $page->code() . PHP_EOL;
        echo 'count: ' . ($page->count() === null ? 'null' : (string) $page->count()) . PHP_EOL;
        foreach ($page->records() as $record) {
            echo $record->emailUid() . ' ' . $record->status() . ' ' . $record->toEmail() . ' ' . $record->subject() . PHP_EOL;
        }
        exit(0);
    }

    if ($command === 'get') {
        $uid = $argv[2] ?? '';
        $record = $client->emails()->get($uid);
        echo 'uid: ' . $record->emailUid() . PHP_EOL;
        echo 'status: ' . $record->status() . PHP_EOL;
        echo 'to: ' . $record->toEmail() . PHP_EOL;
        echo 'from: ' . $record->fromEmail() . PHP_EOL;
        echo 'subject: ' . $record->subject() . PHP_EOL;
        exit(0);
    }

    if ($command === 'send') {
        $result = $client->emails()->send(
            Email::make()
                ->to($to, 'SDK test')
                ->from($from, $fromName)
                ->subject('SDK local test ' . gmdate('Y-m-d H:i:s'))
                ->html('<p>Local transactional SDK test.</p>')
                ->text('Local transactional SDK test.'),
        );
        echo 'code: ' . $result->code() . PHP_EOL;
        echo 'email_uid: ' . $result->emailUid() . PHP_EOL;
        echo 'warning: ' . ($result->warning() ?? '') . PHP_EOL;
        exit(0);
    }
} catch (MailBlazeException $exception) {
    fwrite(STDERR, $exception::class . PHP_EOL);
    fwrite(STDERR, 'http: ' . ($exception->httpStatus() === null ? '' : (string) $exception->httpStatus()) . PHP_EOL);
    fwrite(STDERR, 'code: ' . ($exception->errorCode() ?? '') . PHP_EOL);
    fwrite(STDERR, 'error: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDERR, "Usage: php examples/local.php list|send|get EMAIL_UID\n");
exit(1);

function loadLocalEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $separator = strpos($line, '=');
        if ($separator === false) {
            continue;
        }
        $name = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));
        if ($name === '' || getenv($name) !== false) {
            continue;
        }
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}
