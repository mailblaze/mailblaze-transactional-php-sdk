# Mail Blaze transactional email for PHP

`mailblaze/transactional` is the official PHP client for the Mail Blaze transactional API. You pass normal strings and files. The library sends the JSON body the API expects.

This package replaces the transactional parts of the old `mailblaze/api` package for new work. `mailblaze/api` 1.0.2 is unchanged.

## Requirements

- PHP 8.3 or newer. PHP 8.3 is the minimum this library accepts. It is not the most secure runtime to deploy. Run the latest actively supported PHP in production. As of November 2026 that is PHP 8.5, or PHP 8.4 while it remains in active support. See [php.net supported versions](https://www.php.net/supported-versions.php).
- A PSR-18 HTTP client and PSR-17 factories. This package does not bundle one.

## Install

```bash
composer require mailblaze/transactional symfony/http-client nyholm/psr7
```

Set `MAILBLAZE_API_KEY` to your Mail Blaze public API key. The key is sent as the raw `Authorization` header. It is not a bearer token.

## Send an email

```php
use MailBlaze\Transactional\Client;
use MailBlaze\Transactional\Email;

$client = Client::create((string) getenv('MAILBLAZE_API_KEY'));

$result = $client->emails()->send(
    Email::make()
        ->to('jane@example.com', 'Jane Doe')
        ->from('billing@example.com', 'Acme')
        ->subject('Your receipt')
        ->html('<p>Thanks for your order.</p>')
        ->text('Thanks for your order.')
);

$uid = $result->emailUid();
```

`from()` must use an address on a domain you have verified in Mail Blaze. An unverified domain returns `150.010`.

## Send a template

`html()` and `templateId()` cannot be used on the same email. Attachments can be included with either one. Merge tag names must match `[A-Z0-9_]`. `MAILBLAZE_PREHEADER` is the preheader tag.

```php
$result = $client->emails()->send(
    Email::make()
        ->to('jane@example.com', 'Jane Doe')
        ->from('billing@example.com', 'Acme')
        ->subject('Your order')
        ->templateId('ab123456-1234-1234-1234-123456789abc')
        ->mergeTags(['NAME' => 'Jane', 'ORDER_ID' => '1001'])
);
```

## Attachments, copies, and a scheduled send

Each attachment is limited to 10 MiB of base64 text, which is the check the API performs. That is about 7.5 MiB of file bytes. The library rejects a larger file before the request.

`sendAt()` is a `DateTimeInterface`. The library sends `Y-m-d\TH:i:s` with no timezone suffix. Mail Blaze reads that clock time in the customer account timezone.

```php
use MailBlaze\Transactional\Attachment;
use DateTimeImmutable;

$result = $client->emails()->send(
    Email::make()
        ->to('jane@example.com', 'Jane Doe')
        ->from('billing@example.com', 'Acme')
        ->subject('Invoice')
        ->html('<p>Invoice attached.</p>')
        ->cc('ops@example.com', 'finance@example.com')
        ->bcc('archive@example.com')
        ->attach(Attachment::fromPath('/tmp/invoice.pdf'))
        ->sendAt(new DateTimeImmutable('+1 hour'))
);
```

Several `cc()` or `bcc()` addresses are joined with a comma, which is the single string field the API stores.

## List, fetch, and attachments

```php
$page = $client->emails()->list(page: 1, perPage: 10);
$email = $client->emails()->get($uid);
$files = $client->emails()->attachments($uid);
```

`per_page` is clamped by the API to the range 10–50. The default page size is 10. An empty list is a successful response with code `050.001`.

## Errors

| Exception | When |
| --- | --- |
| `AuthenticationException` | HTTP 401/403, including `150.001` (sending is inactive) |
| `QuotaException` | `150.015` insufficient credits |
| `DomainNotVerifiedException` | `150.010` the from-domain is not verified |
| `ValidationException` | HTTP 400/422, including `150.002`–`150.006`, `150.011`, `150.012`, and `150.014` |
| `NotFoundException` | HTTP 404, including `150.009` and `150.013` |
| `SendFailedException` | `150.007` and `150.016` |
| `ApiException` | Other HTTP 4xx/5xx responses, including `150.008` |
| `TransportException` | Network, TLS, or timeout failures |

`httpStatus()`, `errorCode()`, and `errorMessage()` are available on every `MailBlazeException`. The API key is removed from those strings.

`POST /transactional` is never retried. A retry could send a second copy of the same email. A `TransportException` on send, including a timeout, does not mean the API rejected the message. The message may already be saved. Call `list()` or `get()` before you send it again. GET requests retry up to two times on a connection failure or an HTTP 5xx. Quota, authentication, and validation errors are not retried.

There is no documented HTTP rate limit on this API. `150.001` and `150.015` are account quota errors, not `429` responses.

## Client options

```php
$client = Client::create(
    apiKey: (string) getenv('MAILBLAZE_API_KEY'),
    baseUri: 'https://control.mailblaze.com/api',
    timeout: 30.0,
    logger: $logger,
    httpClient: $psr18Client,
    requestFactory: $requestFactory,
    streamFactory: $streamFactory,
);
```

The default timeout is 30 seconds. A send with an attachment can take longer than 10 seconds, and a short timeout can fire after the API has already accepted the message. Raise `timeout` for large attachments. It is applied when this package builds a Symfony HTTP client. An injected PSR-18 client keeps the timeout and TLS settings you configured on it. The default client does not follow redirects, so the API key is not forwarded to another host.

Pass a PSR-3 logger if you want request logs. The logger wrapper removes the API key, `Authorization` header, and message bodies.

`allowInsecureTls: true` turns off certificate verification for the client this package builds. Do not use it in production. It cannot be combined with an injected HTTP client.

`allowHttp: true` allows an `http://` base URI only for a local host such as `control.mailblaze.local`, `localhost`, `127.0.0.1`, or `host.docker.internal`. A public host still has to use HTTPS. Local control-panel steps are in [LOCAL-TESTING.md](LOCAL-TESTING.md).

## SMTP

This package is not an SMTP client. These facts are verified:

- Host: `smtp.mailblaze.net`
- Port: `587`
- STARTTLS is expected. MBDI-1004 was a certificate incident and was marked done on 3 September 2026.
- Authentication uses the customer API key. Production has returned `535 Invalid API key` (MBDI-1003). MBDI-696 designed the SMTP service to accept customer API keys.

The SMTP username and the exact AUTH mechanism (PLAIN or LOGIN) are not verified. This README does not guess them.

## Not in this version

Batch send, webhooks, idempotency keys, universal email elements, and non-PHP languages are not part of this client. Laravel and Symfony mailer bridges are separate packages and are not included here.

The HTTP contract is described in [docs/openapi.yaml](docs/openapi.yaml). The design notes for this release are in [SPEC.md](SPEC.md).
