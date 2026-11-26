# Mail Blaze transactional email for PHP

`mailblaze/transactional` is the official PHP client for the Mail Blaze transactional API. You pass strings and files. The library sends the JSON the API expects.

It replaces the transactional parts of `mailblaze/api` for new work.

## Requirements

- PHP 8.3 or newer.
- `symfony/http-client` or `guzzlehttp/guzzle` 7 or 8, plus PSR-17 factories such as `nyholm/psr7`. To use another PSR-18 client, pass it as `httpClient:` with its own timeout and redirects turned off.

## Install

```bash
composer require mailblaze/transactional symfony/http-client nyholm/psr7
```

Pass your Mail Blaze public API key to `Client::create()`. The examples below read `MAILBLAZE_API_KEY` with `getenv()`. The SDK does not read environment variables. The key is sent as the raw `Authorization` header.

## Client options

```php
use MailBlaze\Transactional\Client;

$client = Client::create(
    apiKey: (string) getenv('MAILBLAZE_API_KEY'),
    baseUri: 'https://control.mailblaze.com/api',
    timeout: 30.0,
    logger: $logger,
    httpClient: $psr18Client,
    requestFactory: $requestFactory,
    streamFactory: $streamFactory,
    allowInsecureTls: false,
    allowHttp: false,
    getRetryLimit: 2,
    retryDelayMs: 200,
);
```

The default timeout is 30 seconds. The maximum is 120. Raise it for large attachments. The SDK applies that timeout when it builds a Symfony or Guzzle client. For any other PSR-18 client, set the timeout yourself.

`getRetryLimit` (0–5, default 2) sets how many times a GET is retried, and `retryDelayMs` (0–5000, default 200) sets the delay between retries. A send is never retried.

The client this package builds does not follow redirects. An injected Guzzle client must set `allow_redirects` to false. A response body larger than 16 MiB is rejected.

Pass a PSR-3 logger to record requests. The wrapper removes the API key, the `Authorization` header, and message bodies.

`allowInsecureTls: true` turns off certificate verification for the client this package builds. Leave it off in production. It cannot be combined with an injected HTTP client.

`allowHttp: true` allows an `http://` base URI for a local host such as `localhost`, `127.0.0.1`, `[::1]`, `host.docker.internal`, or `control.mailblaze.local`. A public host stays on HTTPS. An IP-literal host must be public or loopback. Hostnames are not resolved.

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

`to()`, `from()`, and `subject()` are required. Either `html()` or `templateId()` is required. `text()` is optional with `html()`.

`from()` must use an address on a domain you have verified in Mail Blaze. An unverified domain returns `150.010`.

`Email` is immutable. Each method returns a new `Email`, so a shared base email can be reused. Keep the returned value. A discarded call has no effect. PHP 8.5 and PHPStan report that. PHP 8.3 and 8.4 ignore the `#[\NoDiscard]` attribute.

```php
$base = Email::make()->from('billing@example.com', 'Acme')->subject('Your receipt');
$forJane = $base->to('jane@example.com', 'Jane Doe')->html('<p>Hi Jane</p>');
$forJohn = $base->to('john@example.com', 'John Doe')->html('<p>Hi John</p>');
```

The builder throws `ValidationException` before any request when:

- HTML, plain text, the subject, names, the category, merge-tag values, or an attachment filename is not valid UTF-8.
- A subject, name, category, or template id contains a line break, a control character, a bidirectional override, or an invisible character. Direction marks used in Hebrew and Arabic, and zero-width joiners, are allowed. The SDK does not limit subject length.
- A merge-tag value contains a line break or another control character, including tab.
- An attachment filename includes a path (`/` or `\`), a line break or other control character, or a hidden or formatting character (including direction marks), or is longer than 255 bytes.
- An email address fails `FILTER_VALIDATE_EMAIL`, or contains a comma, semicolon, or angle bracket. `cc` and `bcc` are one comma-separated string. A quoted local part such as `"jane"@example.com` is accepted. Non-ASCII local parts are rejected. Write a non-ASCII domain in punycode, for example `jane@xn--exmple-cua.example`.

## Send a template

`templateId()` cannot be combined with `html()` or `text()`. Attachments can be included with either a template or HTML. Merge tag names must match `[A-Z0-9_]`. `MAILBLAZE_PREHEADER` sets the preheader.

A template can put a merge tag in a single-line field such as the subject, so merge-tag values cannot contain line breaks, tabs, or other control characters. Unicode text, spaces, and empty values are allowed, and values are sent without trimming. Put line breaks for the body in the template, or pass HTML such as `<br>` in the value.

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

## Attachments, copies, and scheduling

`bcc()` recipients receive the email, and their addresses are not shown to the other recipients.

An attachment can be up to 10 MiB of base64, about 7.5 MiB of file bytes. A larger file is rejected before the request. The API detects each attachment's content type from its bytes. `attach()`, `cc()`, and `bcc()` add to the values already set.

`sendAt()` takes a `DateTimeInterface` and is sent as `Y-m-d\TH:i:sP`, including the timezone offset. The API holds the email until that time. Omit `sendAt()`, or pass a time in the past, to send immediately.

```php
use MailBlaze\Transactional\Attachment;

$email = Email::make()
    ->to('jane@example.com', 'Jane Doe')
    ->from('billing@example.com', 'Acme')
    ->subject('Invoice')
    ->html('<p>Invoice attached.</p>')
    ->attach(Attachment::fromPath('/tmp/invoice.pdf'));
```

## List, fetch, and attachments

```php
$page = $client->emails()->list(page: 1, perPage: 10);
$email = $client->emails()->get($uid);
$files = $client->emails()->attachments($uid);
```

`perPage` is clamped to 10–50. The default is 10. `page` must be at least 1. An empty list is a success response with code `050.001`.

`get()` returns an `EmailRecord` that includes `body()` and `plainText()`. When no `text()` was sent, the API generates the plain text from the HTML. `list()` returns summaries without the body or plain text. `attachments()` returns each stored file as an array with its `name` and `data`, or an empty list when the email has none.

`EmailRecord::sendAt()` returns the raw API value, such as `2026-11-16 2:36:59 PM`. `sendAtDateTime()` parses it into a `DateTimeImmutable`, or returns `null` when it does not recognise the format. The API value has no timezone offset and is read as UTC unless you pass another `DateTimeZone`.

## Errors

| Exception | When |
| --- | --- |
| `AuthenticationException` | `150.001` (sending is turned off), or HTTP 401/403 with no more specific code below. A missing or rejected key is HTTP 400 with the message `Invalid API key` |
| `QuotaException` | `150.015` insufficient credits |
| `DomainNotVerifiedException` | `150.010` the from-domain is not verified |
| `ValidationException` | HTTP 400/422, including `100.001`, `150.002`–`150.006`, `150.011`, `150.012`, and `150.014`. Also invalid input rejected by the builder |
| `NotFoundException` | HTTP 404, including `150.009` and `150.013` |
| `SendFailedException` | `150.007` and `150.016` |
| `ApiException` | Other HTTP 4xx/5xx responses, and `150.008` at any HTTP status |
| `UnconfirmedSendException` | HTTP 2xx on send whose body could not be read. The email may already have been accepted |
| `TransportException` | The request did not complete: network, DNS, TLS, timeout, or a body that could not be read |
| `ConfigurationException` | `Client::create()` rejected the configuration. Extends `\InvalidArgumentException`. `catch (MailBlazeException)` does not see it |

`POST /transactional` is never retried. After a `TransportException` or an `UnconfirmedSendException` on send, check `list()` or `get()` before sending that email again. `UnconfirmedSendException` extends `TransportException`. Catch it first when that case needs different handling. It does not extend `ApiException`.

A GET is tried once, then up to `getRetryLimit` times (default 2) after a connection failure, HTTP 500, 502, 503, or 504, or a body that could not be read, was empty, or was truncated. Other GET statuses, including 401 and 404, are not retried. Authentication, quota, and validation errors are not retried.

Use `TransportException::reason()` to tell failures apart. It is `timeout`, `dns`, `tls`, `connect`, `client`, `response`, or `unknown`. An `UnconfirmedSendException` is always `response`. The exception message ends with that reason in parentheses. When set, `getPrevious()` is a `RedactedException` with the API key removed.

`httpStatus()`, `errorCode()`, and `errorMessage()` are on every `MailBlazeException`. `errorCode()` keeps only an `NNN.NNN` code.

## SMTP

This package does not speak SMTP. Mail Blaze SMTP is `smtp.mailblaze.net`, port `587`, with STARTTLS, authenticated by the customer API key. The SMTP username and the login method are not documented here.

## Upgrading from 0.1.x

0.2.0 has breaking changes. The sections above describe the current behaviour. The full list is in [CHANGELOG.md](CHANGELOG.md).

1. Assign the result of any `Email` method call that is not part of a chain: `$email = $email->cc('ops@example.com');`.
2. Catch `ConfigurationException` where you create the client. `catch (MailBlazeException)` does not see it.
3. Remove bidirectional overrides and invisible characters from subjects, names, categories, and template ids.
4. Read `TransportException::reason()` where you used to match the exception message.
5. Keep email construction in the same `try` as `send()` if you catch `ValidationException`. Invalid UTF-8 and rejected addresses are thrown by the builder.
6. `list()` clamps `perPage` to 10–50. No change needed.

## Versioning

Pre-1.0 SemVer: minor versions may break. Supported versions are in [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).

## Further reading

This package does not provide batch send, webhooks, idempotency keys, or Laravel/Symfony mailer bridges.

The HTTP contract is in [docs/openapi.yaml](docs/openapi.yaml). Releases are in [CHANGELOG.md](CHANGELOG.md). To report a vulnerability, see [SECURITY.md](SECURITY.md).
