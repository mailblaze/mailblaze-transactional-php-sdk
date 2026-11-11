# Security policy

Report vulnerabilities privately to support@mailblaze.com. Do not open a public GitHub issue for a security problem. Do not include API keys, message bodies, or customer data in the report.

This package reads the API key from `MAILBLAZE_API_KEY` or from the value you pass to `Client::create()`. It sends that key only in the `Authorization` header. It does not put the key in the URL. Exception messages, `__toString()`, and PSR-3 logs redact the key. `var_export()` does not include the key, because the key is not stored as an object property. `serialize()` on an exception omits the key and the stack trace. `serialize()` on the client is rejected.

A timeout on `POST /transactional` does not mean the message failed. The API may already have saved it. This package does not retry that request. Check the list before sending the same message again.

`allowInsecureTls` disables TLS certificate verification when this package builds the HTTP client. Leave it off. It is for local debugging only.

`POST /transactional` is never retried. The API has no idempotency key, so a retry can send the same email twice.
