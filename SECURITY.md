# Security policy

## Supported versions

| Version | Supported |
| --- | --- |
| 0.2.x | Yes |
| 0.1.x | No. Upgrade to 0.2.x; see the upgrade notes in [CHANGELOG.md](CHANGELOG.md). |

Security fixes are released for the latest 0.2.x version.

## Reporting a vulnerability

Report vulnerabilities privately to support@mailblaze.com. Do not open a public GitHub issue for a security problem. Do not include API keys, message bodies, or customer data in the report.

## How this package handles the API key

This package does not read environment variables. You pass the API key to `Client::create()`, for example from your own `getenv('MAILBLAZE_API_KEY')` call or a secrets manager. It sends that key only in the `Authorization` header. It does not put the key in the URL. Exception messages, `__toString()`, and PSR-3 logs redact the key. `var_export()` does not include the key, because the key is not stored as an object property. `serialize()` on an exception omits the key and the stack trace. `serialize()` on the client is rejected.

## Sending safely

A timeout on `POST /transactional` does not mean the message failed. The API may already have saved it. This package does not retry that request. Check the list before sending the same message again.

`allowInsecureTls` disables TLS certificate verification when this package builds the HTTP client. Leave it off. It is for local debugging only.

`POST /transactional` is never retried. The API has no idempotency key, so a retry can send the same email twice.
