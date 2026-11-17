# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- README reflects the current Mail Blaze API: `sendAt()` holds an email until it is due, attachment content types are detected from the file bytes, `attachments()` returns the stored files, and `get()` returns the plain text. SDK behaviour is unchanged.
- README lists `cc()` and `replyTo()` not being applied to the delivered message as the only known API limitation, and states that `bcc()` recipients receive the email without their addresses being shown to other recipients.

### Removed

- README statements that `sendAt()` sends immediately, that attachments arrive as `text/plain`, and that `attachments()` returns an empty list.

## [0.2.1] - 2026-11-17

### Changed

- Attachment filename rejections have separate messages: "must not include a path", "must not contain line breaks or control characters", and "must not contain hidden or formatting characters". The same filenames are rejected, and the exception is still a `ValidationException`, but code that matched the old "must not include a path or line breaks" text must be updated.
- README states each behaviour once. It names the HTTP clients the SDK builds, the retry options, `100.001`, and the filename rules. The README documents that a template cannot be combined with `html()` or `text()`.

## [0.2.0] - 2026-11-17

This version has breaking changes, marked **BREAKING** below. The README has the same notes with examples.

**Upgrading from 0.1.x**

1. Assign the result of every `Email` method that is not part of a chain: `$email = $email->cc(...)`. PHP 8.5 and PHPStan report each call that discards it.
2. Catch `ConfigurationException` (or `\InvalidArgumentException`) where you create the client. It is not a `MailBlazeException`, and it must not be retried.
3. Remove bidirectional overrides and isolates (U+202A–U+202E, U+2066–U+2069) and invisible characters (U+200B, U+2060–U+2064, U+FEFF) from subjects, names, categories, and template ids. Direction marks and zero-width joiners can stay.
4. Use `TransportException::reason()` instead of matching `TransportException` messages.
5. Build emails inside the same `try` as `send()` if you catch `ValidationException`, because invalid input is now rejected by the builder.
6. `perPage` outside 10–50 is now clamped rather than sent as is. No change is needed.

### Added

- `ConfigurationException`, thrown for an invalid client configuration before any request is made. It extends `\InvalidArgumentException` and is not a `MailBlazeException`. See the breaking change under Changed.
- `TransportException::reason()` returns `timeout`, `dns`, `tls`, `connect`, `client`, `response`, or `unknown`. An `UnconfirmedSendException` always has the reason `response`. The reason is kept when the exception is serialized.
- `RedactedException`. `TransportException::getPrevious()` is a `RedactedException` that names the HTTP client's exception class and carries its message, capped at 500 bytes, with the API key removed. The original exception is not kept, because it can hold the request and its `Authorization` header.
- `MailBlazeException::__construct()` accepts an optional `?\Throwable $previous` as its last argument.
- The `Email` builder methods carry `#[\NoDiscard]`. PHP 8.5 warns when a returned `Email` is discarded, and PHPStan reports it on every PHP version. PHP 8.3 and 8.4 ignore the attribute.
- `EmailRecord::sendAtDateTime()` parses `send_at`, including the API's `2026-11-16 2:36:59 PM` format, into a `DateTimeImmutable`. A value without an offset is read as UTC unless another timezone is passed. `sendAt()` still returns the raw value.
- `Emails::MIN_PER_PAGE` (10) and `Emails::MAX_PER_PAGE` (50).
- CI runs the real-HTTP tests against the clients the SDK builds with Guzzle 7, Guzzle 8, Guzzle 8 without Symfony, and Symfony, and measures code coverage with pcov. PHPStan runs on the Symfony jobs.

### Changed

- **BREAKING:** `Email` is immutable. Every method returns a new `Email` and leaves the original unchanged, so reusing a base email no longer overwrites the recipient or other fields of an email built from it. Chained calls such as `Email::make()->to(...)->subject(...)` are unaffected. A call whose return value is not used, such as `$email->cc('a@example.com');` on its own line, no longer has any effect; assign it: `$email = $email->cc('a@example.com');`.
- **BREAKING:** Configuration errors throw `ConfigurationException` instead of `TransportException`. `ConfigurationException` is not a `MailBlazeException`, so `catch (MailBlazeException)` and `catch (TransportException)` no longer catch them. This covers an empty or malformed API key, an invalid or private base URI, `http://` on a public host, credentials or a query string in the base URI, an injected Guzzle client that follows redirects, `allowInsecureTls` when neither Symfony HTTP Client nor Guzzle is installed, and a missing HTTP client or PSR-17 factory. `TransportException` now only means a request that did not complete.
- An invalid timeout or retry setting, or `allowInsecureTls` combined with an injected HTTP client, throws `ConfigurationException` instead of a plain `\InvalidArgumentException`. `ConfigurationException` extends `\InvalidArgumentException`, so existing `catch (\InvalidArgumentException)` handlers still work.
- **BREAKING:** `TransportException` messages end with the reason in parentheses, for example `The Mail Blaze API request could not be completed (timeout)`. Use `reason()` instead of matching the message.
- **BREAKING:** Subjects, names, the category, and the template id reject bidirectional embeddings, overrides, and isolates (U+202A–U+202E, U+2066–U+2069) and invisible characters (U+200B, U+2060–U+2064, U+FEFF), which can disguise text such as `invoice\u{202E}fdp.exe`. The direction marks LRM (U+200E), RLM (U+200F), and ALM (U+061C), which Hebrew and Arabic text uses, and the zero-width joiners (U+200C, U+200D) are allowed.
- **BREAKING:** Email addresses with commas, semicolons, or angle brackets are rejected. `FILTER_VALIDATE_EMAIL` accepts `"a,b"@example.com`, which split into two addresses in the comma-separated `cc` and `bcc` fields. A quoted local part without those characters, such as `"jane"@example.com`, is still accepted and sent unchanged.
- **BREAKING:** HTML, plain text, header values, merge tag values, and attachment file names that are not valid UTF-8 throw a `ValidationException` from the builder method that receives them, not from `send()`. Invalid UTF-8 HTML or text used to reach the API and come back as a `SendFailedException` (`150.007`).
- **BREAKING:** Base URIs whose host is a numeric IP address in a non-standard notation, such as `0x7f.1`, `2130706433`, or `127.1`, are rejected.
- `list()` clamps `perPage` to 10–50, the range the API returns, instead of sending any value. A smaller value requests 10 records and a larger value requests 50. This matches what the API already returned.
- `Client::VERSION`, and so the `User-Agent` header, is `0.2.0`.
- README notes the live API limitations found in testing: `sendAt()` sends immediately, `cc()` and `replyTo()` are not applied, `bcc()` is unconfirmed, attachments are delivered as `text/plain`, and `attachments()` returns an empty list.
- README documents that the IP-literal base URI check is best effort and that internationalised email addresses must use punycode.
- `.gitattributes` and `.gitignore` are excluded from Composer package downloads.

### Fixed

- The Guzzle client the SDK builds failed every request on Guzzle 8 with a generic `TransportException`. The SDK passed a `curl` option to Guzzle's stream handler, which Guzzle 7 ignored and Guzzle 8 rejects. The option is no longer set. Guzzle 7 behaviour is unchanged.
- A tab, NUL, or other control character in a header value reports "must not contain control characters" instead of "must not contain line breaks". A private or reserved base URI host reports why it was rejected instead of "Base URI must be an https URL".
- `150.008` is mapped to `ApiException` at every HTTP status. At HTTP 400 it was a `ValidationException`.
- The README listed `150.001` both as an authentication error and as a quota error. It is an `AuthenticationException` (sending is turned off for the account); only `150.015` (insufficient credits) is a `QuotaException`. The mapping is unchanged.
- `SECURITY.md` said the SDK reads the API key from `MAILBLAZE_API_KEY`. It does not; the caller passes the key to `Client::create()`. `SECURITY.md` now lists the supported versions.

### Security

- Header values reject bidirectional overrides and invisible characters, which can make a subject, sender name, or recipient name display differently from its content (see Changed).
- `TransportException::getPrevious()` carries a redacted copy of the HTTP client's exception, never the original, which can hold the `Authorization` header.

## [0.1.1] - 2026-11-16

### Changed

- `UnconfirmedSendException` extends `TransportException` and does not extend `ApiException`. `TransportException` is no longer final so that subclass can exist. A `catch (TransportException)` from 0.1.0 still sees a send whose body could not be read, and that handler must still check the list before sending again. A `catch (ApiException)` does not see it, because a 2xx body that cannot be read is not a confirmed rejection. Catch `UnconfirmedSendException` before `TransportException` when those two cases need different handling.
- README links to `CHANGELOG.md` and `SECURITY.md`.
- Composer package downloads omit tests, CI, and local tooling via `.gitattributes` `export-ignore`.

### Removed

- Maintainer notes and the local control-panel example are no longer in the repository. `README.md`, `CHANGELOG.md`, `SECURITY.md`, and `docs/openapi.yaml` remain.

### Fixed

- A rejected API key (HTTP 400, no error code) is an `AuthenticationException`.
- A timeout while reading a GET response is a `TransportException` and is retried. A timeout while reading a successful send is an `UnconfirmedSendException` and is not retried.
- A successful send whose body cannot be read is an `UnconfirmedSendException`. Do not send that email again until you check the list.
- Text that cannot be encoded as JSON stays a `ValidationException`.
- `sendAt()` includes the timezone offset.
- An API key with a space or control character is rejected before the request, and the key is not included in the error.
- The SDK applies its timeout when it builds a Symfony HTTP client or a Guzzle client. For any other client, pass one that already has a timeout.
- `http://[::1]` is accepted as a local base URI when `allowHttp` is true.
- An HTTP client error that is not a PSR-18 exception is still a `TransportException`, and the API key is not left in its trace.
- Guzzle streams response bodies, so a stalled body after a successful send is an `UnconfirmedSendException`.
- A GET body that cannot be read is not retried when the status is already 401 or 404.
- A GET that returns an empty or truncated body is retried, matching Symfony when Guzzle streams a stalled response.
- A custom response whose status accessor throws becomes a `TransportException`, and the API key is not left in its trace.
- A redirect is never retried, including when its body cannot be read.
- A response stream that reports `timed_out` is treated as incomplete, so Guzzle matches Symfony on a truncated body.
- A body shorter than `Content-Length`, including a duplicated length header, is incomplete. Conflicting or malformed `Content-Length` values are rejected, including when `Content-Encoding` is set.
- An absurdly large `Content-Length` is rejected before the response body is read, so the worker is not killed by an oversized allocation.
- A response body larger than 16 MiB is rejected after it is read, including when `Content-Length` is absent.
- Chunk sizes and decoded chunked bodies are capped at 16 MiB.
- An injected Guzzle client must set `allow_redirects` to false.
- IP-literal base URIs must be public or loopback; private and link-local addresses are rejected.
- A raw chunked body without a complete terminal chunk is incomplete. Zero-padded chunk sizes are accepted. Chunk trailer names must be valid HTTP tokens.
- An already-decoded JSON body that still carries `Transfer-Encoding: chunked` is left alone.
- A response header accessor that throws becomes a `TransportException`, and the API key is not left in its trace.
- API error text is redacted before it is truncated, so a key cannot survive as a prefix past the 500-byte cap.
- Content-Length checks use ASCII digits, so a response stays valid when `ext-ctype` is missing or the process locale is not C.
- `errorCode()` keeps only `NNN.NNN` codes. A key reflected in the API `code` field is omitted.
- Numeric merge-tag names such as `0` are sent as a JSON object, not a list.

### Security

- `composer audit` may report abandoned `php-http/message-factory` through the dev-only `php-http/mock-client` tree. It is not a production dependency and is not shipped to package users.

## [0.1.0] - 2026-11-11

### Added

- First preview of `mailblaze/transactional`.
- Send, list, get, and attachment lookup for the Mail Blaze transactional API.
- Native PHP values for HTML, plain text, and attachments. The library sends strict base64 and `Content-Type: application/json` with no charset.
- Typed exceptions for the transactional `150.xxx` error codes, with the API key redacted from messages and logs.
- `POST /transactional` is not retried. Safe GET requests retry on transport failures and HTTP 5xx.
- `allowHttp` lets a local development host such as `control.mailblaze.local` use HTTP. Public hosts stay on HTTPS.
- Template sends include attachments. The default timeout is 30 seconds.
- Serialized exceptions omit the API key and the stack trace. The client cannot be serialized.
- `var_export()` does not include the API key. The key is kept off the object.

[Unreleased]: https://github.com/mailblaze/mailblaze-transactional-php-sdk/compare/0.2.1...HEAD
[0.2.1]: https://github.com/mailblaze/mailblaze-transactional-php-sdk/compare/0.2.0...0.2.1
[0.2.0]: https://github.com/mailblaze/mailblaze-transactional-php-sdk/compare/0.1.1...0.2.0
[0.1.1]: https://github.com/mailblaze/mailblaze-transactional-php-sdk/compare/0.1.0...0.1.1
[0.1.0]: https://github.com/mailblaze/mailblaze-transactional-php-sdk/releases/tag/0.1.0
