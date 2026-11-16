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

Pass your Mail Blaze public API key to `Client::create()`. The examples read it from a `MAILBLAZE_API_KEY` environment variable with `getenv()`; the SDK itself does not read environment variables. The key is sent as the raw `Authorization` header. It is not a bearer token.

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

`Email` is immutable. Every method returns a new `Email` and leaves the one you called it on unchanged, so a shared base email can be reused safely. Always use the returned value: a call whose result is discarded has no effect. The builder methods carry `#[\NoDiscard]`, so PHP 8.5 warns about a discarded result and PHPStan reports it on every PHP version. PHP 8.3 and 8.4 ignore the attribute.

```php
$base = Email::make()->from('billing@example.com', 'Acme')->subject('Your receipt');
$forJane = $base->to('jane@example.com', 'Jane Doe')->html('<p>Hi Jane</p>');
$forJohn = $base->to('john@example.com', 'John Doe')->html('<p>Hi John</p>'); // $forJane still goes to Jane
```

The builder rejects invalid input with a `ValidationException` before any request is made:

- HTML, plain text, the subject, names, the category, merge tag values, and attachment file names must be valid UTF-8.
- Header values (subject, names, category, template id) must not contain line breaks, control characters, bidirectional embeddings, overrides, or isolates (U+202A–U+202E, U+2066–U+2069), or invisible characters (U+200B, U+2060–U+2064, U+FEFF). These can disguise text, for example `invoice\u{202E}fdp.exe`. The direction marks used in Hebrew and Arabic text (LRM U+200E, RLM U+200F, ALM U+061C) and the zero-width joiners (U+200C, U+200D) used by emoji and several scripts are allowed. There is no SDK limit on subject length; the API builds and folds the header.
- Email addresses must pass PHP's `FILTER_VALIDATE_EMAIL` and must not contain commas, semicolons, or angle brackets, because `cc` and `bcc` are sent as one comma-separated string and the display name is a separate field. A quoted local part such as `"jane"@example.com` is accepted and sent unchanged; `"a,b"@example.com` is rejected because of the comma. Internationalised (non-ASCII) addresses are not accepted. Use the punycode form of the domain, for example `jane@xn--exmple-cua.com`.

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

`sendAt()` is a `DateTimeInterface`. The library sends `Y-m-d\TH:i:sP`, including the timezone offset, so the instant does not shift when it differs from the customer account timezone. Omit `sendAt()` to send immediately.

> **Known API limitations (November 2026).** In testing against the live API, `sendAt()` had no effect: scheduled emails were delivered immediately. The `cc()` and `replyTo()` values were accepted but the delivered message had no `Cc` or `Reply-To` header, and `bcc()` delivery could not be confirmed. Attachments were delivered as `text/plain` whatever their type, and `attachments()` returned an empty list. The SDK sends these fields as `docs/openapi.yaml` describes. Do not rely on them until the API supports them.

```php
use MailBlaze\Transactional\Attachment;

$result = $client->emails()->send(
    Email::make()
        ->to('jane@example.com', 'Jane Doe')
        ->from('billing@example.com', 'Acme')
        ->subject('Invoice')
        ->html('<p>Invoice attached.</p>')
        ->cc('ops@example.com', 'finance@example.com')
        ->bcc('archive@example.com')
        ->attach(Attachment::fromPath('/tmp/invoice.pdf'))
        ->sendAt(new \DateTimeImmutable('+1 hour'))
);
```

Several `cc()` or `bcc()` addresses are joined with a comma, which is the single string field the API stores. Each `cc()`, `bcc()`, and `attach()` call adds to the values already set.

## List, fetch, and attachments

```php
$page = $client->emails()->list(page: 1, perPage: 10);
$email = $client->emails()->get($uid);
$files = $client->emails()->attachments($uid);
```

`perPage` is clamped to 10–50 (`Emails::MIN_PER_PAGE` and `Emails::MAX_PER_PAGE`), the range the API returns: a smaller value requests 10 records and a larger value requests 50. The default page size is 10. `page` must be at least 1. An empty list is a successful response with code `050.001`.

`EmailRecord::sendAt()` returns the raw API value, such as `2026-11-16 2:36:59 PM`. `sendAtDateTime()` parses it into a `DateTimeImmutable`, or returns `null` for a format it does not recognise. The API returns that clock time without an offset. In testing it was UTC, so it is read as UTC unless you pass another `DateTimeZone`.

## Errors

| Exception | When |
| --- | --- |
| `AuthenticationException` | HTTP 401/403, including `150.001` (sending is turned off for the account). A missing or rejected key is HTTP 400 with no code and the message `Invalid API key`; that is an authentication error too |
| `QuotaException` | `150.015` insufficient credits |
| `DomainNotVerifiedException` | `150.010` the from-domain is not verified |
| `ValidationException` | HTTP 400/422, including `150.002`–`150.006`, `150.011`, `150.012`, and `150.014` |
| `NotFoundException` | HTTP 404, including `150.009` and `150.013` |
| `SendFailedException` | `150.007` and `150.016` |
| `ApiException` | Other HTTP 4xx/5xx responses, and `150.008` (the list could not be loaded) at any HTTP status |
| `UnconfirmedSendException` | A `TransportException`. HTTP 2xx on send whose body cannot be read, including a timeout while reading that body. The email may already have been accepted. Do not send it again until you check the list. It is not an `ApiException` |
| `TransportException` | Network, DNS, TLS, or timeout failures before the response is complete, or a response that could not be read. A timeout while reading a successful send is `UnconfirmedSendException` |
| `ConfigurationException` | Thrown by `Client::create()` for a malformed API key or base URI, an invalid timeout or retry setting, or a missing HTTP client. It extends `\InvalidArgumentException`, not `MailBlazeException`, and must not be retried |

Use `TransportException::reason()` to tell failures apart instead of matching the message text. The message ends with the reason in parentheses, for example `The Mail Blaze API request could not be completed (timeout)`, and its wording may change. `reason()` is one of `timeout`, `dns`, `tls`, `connect`, `client` (the HTTP client rejected the request, for example a configuration error), `response` (the body was incomplete or could not be read; always the reason of an `UnconfirmedSendException`), or `unknown`. It is kept when the exception is serialized. `getPrevious()` is a `RedactedException` that names the HTTP client's exception class and carries its message, capped at 500 bytes, with the API key removed. The original exception is not kept, because it can hold the request and its `Authorization` header.

`httpStatus()`, `errorCode()`, and `errorMessage()` are available on every `MailBlazeException`. The API key is removed from those strings. `errorCode()` is only a `NNN.NNN` API code. Any other value, including a key echoed by the API, is omitted.

`UnconfirmedSendException` extends `TransportException`, so a `catch (TransportException)` written for 0.1.0 still sees an unconfirmed send. Catch `UnconfirmedSendException` first when you need to tell it apart from a failure that happened before any response. It does not extend `ApiException`.

`POST /transactional` is never retried. A retry could send a second copy of the same email. A `TransportException` on send means the request may not have finished. An `UnconfirmedSendException` means the API returned success but the body could not be read. In either case, call `list()` or `get()` before you send it again. Invalid input, including text that is not valid UTF-8, is a `ValidationException` and must not be retried. A GET is tried once and then retried up to two more times after a connection failure, HTTP 500, 502, 503, or 504, or a body that could not be read or was empty or truncated. Any other GET status, including 401 and 404, is not retried. Quota, authentication, and validation errors are not retried.

There is no documented HTTP rate limit on this API. `150.001` (sending turned off, `AuthenticationException`) and `150.015` (insufficient credits, `QuotaException`) are account errors returned as HTTP 403, not `429` responses. Neither is retried.

## Upgrading from 0.1.x

0.2.0 has breaking changes. Most code that chains builder calls and catches `MailBlazeException` needs only the changes below. See [CHANGELOG.md](CHANGELOG.md) for the full list.

1. **`Email` is immutable.** A call on its own line, such as `$email->cc('ops@example.com');`, no longer changes `$email`. Assign the result: `$email = $email->cc('ops@example.com');`. Chained calls such as `Email::make()->to(...)->subject(...)` are unaffected. The methods carry `#[\NoDiscard]`, so PHP 8.5 and PHPStan point at every call to fix.
2. **Configuration errors throw `ConfigurationException`, which is not a `MailBlazeException`.** It extends `\InvalidArgumentException`. In 0.1.x a malformed API key, an invalid or private base URI, an injected Guzzle client that follows redirects, `allowInsecureTls` without Symfony HTTP Client or Guzzle installed, or a missing HTTP client or PSR-17 factory threw `TransportException`, so `catch (MailBlazeException)` or `catch (TransportException)` caught them. Those handlers no longer do. Catch `ConfigurationException` (or `\InvalidArgumentException`) where you build the client, and do not retry it. An invalid timeout, retry setting, or `allowInsecureTls` combined with an injected client already threw `\InvalidArgumentException` and still does.
3. **Bidirectional overrides and invisible characters are rejected in header values.** A subject, name, category, or template id containing U+202A–U+202E, U+2066–U+2069, U+200B, U+2060–U+2064, or U+FEFF throws a `ValidationException`. Remove those characters before building the email. Direction marks (U+200E, U+200F, U+061C) and zero-width joiners are still accepted.
4. **Use `TransportException::reason()` instead of matching messages.** `TransportException` messages now end with the reason, such as `(timeout)`. Code that compared the whole message must check `reason()` instead.
5. **Some invalid input is rejected earlier.** Email addresses containing a comma, semicolon, or angle bracket (such as `"a,b"@example.com`) and base URIs with a numeric host in a non-standard notation (such as `0x7f.1` or `2130706433`) are rejected. Invalid UTF-8 is a `ValidationException` thrown by the builder method that received it, not by `send()`. For `html()` and `text()` it used to reach the API and come back as a `SendFailedException` (`150.007`). Keep the code that builds an email inside the same `try` as `send()` if you catch `ValidationException`.
6. **`perPage` is clamped, not rejected.** `list()` sends a value outside 10–50 as 10 or 50, the page size the API returns anyway. No change is needed.

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

The default timeout is 30 seconds, and the maximum is 120. A send with an attachment can take longer than 10 seconds, and a short timeout can fire after the API has already accepted the message. Raise `timeout` for large attachments. This package applies that timeout when it builds a Symfony HTTP client or a Guzzle 7 or 8 client. Guzzle streams the response, so a body that stalls after a successful send is an `UnconfirmedSendException`. A stalled GET body is retried only after a connection failure or HTTP 500, 502, 503, or 504. A body is also incomplete when it is shorter than `Content-Length`, when raw chunk framing is missing its terminal chunk, or when the stream reports that it timed out, even if the bytes already read look like valid JSON. Malformed or conflicting `Content-Length` values are rejected even when the response is compressed. A `Content-Length` larger than 16 MiB is rejected before the body is read, and a body larger than 16 MiB is rejected after it is read (including chunked payloads). If the HTTP client has already decoded a chunked body, that JSON is used as-is. With Guzzle streaming, framing headers and compressed or chunked bytes may already be removed; if that layer accepts a premature EOF and returns valid JSON without incomplete-stream metadata, the SDK cannot verify the original framing and may return success. For any other PSR-18 client, pass one that already has a timeout. An injected client keeps the timeout and TLS settings you configured on it. The client this package builds does not follow redirects, so the API key is not forwarded to another host. An injected Guzzle client must set `allow_redirects` to false; other injected clients should disable redirects too.

Pass a PSR-3 logger if you want request logs. The logger wrapper removes the API key, `Authorization` header, and message bodies.

`allowInsecureTls: true` turns off certificate verification for the client this package builds. Do not use it in production. It cannot be combined with an injected HTTP client.

`allowHttp: true` allows an `http://` base URI only for a local host such as `control.mailblaze.local`, `localhost`, `127.0.0.1`, `[::1]`, or `host.docker.internal`. A public host still has to use HTTPS. IP-literal hosts must be public or loopback; private, link-local, and reserved addresses (for example `169.254.169.254`) are rejected, and so are numeric hosts in other notations such as `0x7f.1` or `2130706433`. This check is best effort. It does not resolve hostnames, so a hostname that points at a private address is accepted.

## SMTP

This package is not an SMTP client. These facts are verified:

- Host: `smtp.mailblaze.net`
- Port: `587`
- STARTTLS is expected.
- Authentication uses the customer API key.

The SMTP username and the exact AUTH mechanism (PLAIN or LOGIN) are not verified. This README does not guess them.

## Not in this version

Batch send, webhooks, idempotency keys, universal email elements, and non-PHP languages are not part of this client. Laravel and Symfony mailer bridges are separate packages and are not included here.

The HTTP contract is described in [docs/openapi.yaml](docs/openapi.yaml). See [CHANGELOG.md](CHANGELOG.md) for release notes and [SECURITY.md](SECURITY.md) for how to report a vulnerability.
