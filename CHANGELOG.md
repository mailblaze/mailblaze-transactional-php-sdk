# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.1] - 2026-11-12

### Changed

- README links to `CHANGELOG.md` and `SECURITY.md`.
- Composer package downloads omit tests, CI, and local tooling via `.gitattributes` `export-ignore`.

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
