# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

### Compatibility

- `UnconfirmedSendException` extends `TransportException` and does not extend `ApiException`. `TransportException` is no longer final so that subclass can exist. A `catch (TransportException)` from 0.1.0 still sees a send whose body could not be read, and that handler must still check the list before sending again. A `catch (ApiException)` does not see it, because a 2xx body that cannot be read is not a confirmed rejection. Catch `UnconfirmedSendException` before `TransportException` when those two cases need different handling.

### Changed

- README links to `CHANGELOG.md` and `SECURITY.md`.
- Composer package downloads omit tests, CI, and local tooling via `.gitattributes` `export-ignore`.
- Maintainer notes and the local control-panel example are no longer in the repository. `README.md`, `CHANGELOG.md`, `SECURITY.md`, and `docs/openapi.yaml` remain.

### Notes

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
