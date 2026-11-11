# Mail Blaze PHP transactional SDK: final spec

Saved from the final spec (11 November 2026). The Composer package name is `mailblaze/transactional`. The public GitHub repository name is `mailblaze/mailblaze-transactional-php-sdk`, so later language SDKs can sit beside it. Nothing new is created on Bitbucket.

Prepared for Christopher Gumera at Mail Blaze. This is the PHP-first transactional SDK. Java, C#, Node, Python, and universal email elements are out of scope.

## Goal

From the 9 November 2026 dev check-in: make transactional sending via Mail Blaze easy for development houses that want pre-written code.

In scope:

- Hand-written Composer package `mailblaze/transactional` for `POST /transactional`, `GET /transactional`, `GET /transactional/{email_uid}`, and `GET /transactional/attachments/{email_uid}`.
- Later, separate packages `mailblaze/transactional-laravel` and `mailblaze/transactional-symfony`.
- A README SMTP section that states only verified facts.
- Security, efficiency, PHP-FIG standards, tests, CI, and Packagist publishing.

Out of scope:

- Universal email elements. The SDK still supports `template_id` and `merge_tags` because those already exist on the API.
- Marketing and Control campaign APIs, the CRM API, and other languages.
- Batch send, webhooks, and idempotency keys. The public API does not expose them.
- Implementing SMTP inside this HTTP package.

## Decisions

| Topic | Decision |
| --- | --- |
| Package names | Core: `mailblaze/transactional`. Bridges: `mailblaze/transactional-laravel` and `mailblaze/transactional-symfony`. Namespace `MailBlaze\Transactional\`. Do not publish over `mailblaze/api`. |
| GitHub repository | `github.com/mailblaze/mailblaze-transactional-php-sdk`. Packagist requires a public VCS. Product source stays on private Bitbucket. |
| Minimum PHP | `php: ^8.3`. This is the support floor. CI matrix: 8.3, 8.4, 8.5. Add 8.6 after its 19 November 2026 GA. Production apps should run the latest actively supported PHP. |
| HTTP stack | PSR-18 + PSR-17 + PSR-7 + `php-http/discovery`. Do not bundle Guzzle. Suggest `nyholm/psr7` and `symfony/http-client`. |
| Base URI | HTTPS by default. `allowHttp: true` permits HTTP only for a local host (`*.local`, `localhost`, `127.0.0.1`, `::1`, `host.docker.internal`). |
| `mailblaze/api` | Leave 1.0.2 untouched. After `mailblaze/transactional` 1.0.0 is published, mark `mailblaze/api` abandoned on Packagist with a replacement pointer. |
| SMTP in the README | Document only verified facts. Host `smtp.mailblaze.net`, port 587, STARTTLS expected, API-key auth indicated by production 535 errors and MBDI-696. Username and the exact AUTH mechanism (PLAIN vs LOGIN) are unverified. |
| Rate limits | No HTTP request-rate limit was found. Account quota errors are `150.001` and `150.015`. Do not invent 429 retries. Never auto-retry `POST /transactional`. |

## API contract

Confirmed from `apps/api/controllers/TransactionalController.php` on Bitbucket `mailblaze/mailblaze-control-panel`, branch `service-control`, commit `d2fd239e28b6452bfc678ede0b85e1b9a6efee53`.

- Base URL `https://control.mailblaze.com/api`. The public API key is sent as the raw `Authorization` header value, not `Bearer`.
- JSON is parsed only when `Content-Type` is exactly `application/json`. A charset parameter can make the controller ignore the JSON body. Attachments are processed only on the JSON path.
- One of `body` or `template_id` is required (`150.012`). Templates are loaded by `template_uid` for the authenticated customer (`150.013`). Merge tags in templates are `[A-Z0-9_]`. Missing tags return `150.014`. `MAILBLAZE_PREHEADER` is a special merge tag. On the template path the API base64-encodes the merged HTML and plain text itself.
- Caller-supplied `body` and `plain_text` must be strict base64 (`150.002`, `150.003`). The support-article sentence that says plain text should be sent as text conflicts with the code.
- Each attachment `data` value must be valid base64 (`150.004`). The size check is `mb_strlen($attachment['data'], '8bit') > 10 * 1024 * 1024` (`150.005`). That measures the base64 string, not decoded bytes. The support article's 2MB line is stale. There is no extension allowlist in this controller.
- `send_at` is parsed with `new DateTime($value, customer timezone)`. Invalid values are `150.006` / 422. Omitted means send immediately. The SDK accepts `DateTimeInterface` and sends `Y-m-d\TH:i:s`.
- List pagination: `page` (default 1) and `per_page` (clamped 10–50, default 10).
- `from_email` domain must be an active verified domain (`150.010` / 403).
- `sending_inactive` returns `150.001` / 403. `getIsOverQuota()` returns `150.015` / 403. Do not auto-retry these.
- `cc` and `bcc` are single strings. The SDK may join several addresses with a comma.

### Error codes

| Code | HTTP | Meaning |
| --- | --- | --- |
| 000.000 | 201 / 200 | Success |
| 050.001 | 200 | List succeeded with no records |
| 100.001 | 400 | Only POST allowed |
| 150.001 | 403 | Customer is not allowed to send (`sending_inactive`) |
| 150.002 | 400 | `body` is not valid base64 |
| 150.003 | 400 | `plain_text` is not valid base64 |
| 150.004 | 400 | Attachment data is not valid base64 |
| 150.005 | 400 | Attachment exceeds the 10MB size limit (base64 string length) |
| 150.006 | 422 | `send_at` is not valid |
| 150.007 | 422 | The transactional email was not sent (save failed) |
| 150.008 | 503 | Failed to list transactional emails |
| 150.009 | 404 | No transactional email found for the unique id |
| 150.010 | 403 | From domain is not verified |
| 150.011 | 400 | Invalid JSON data |
| 150.012 | 400 | Either body or `template_id` must be provided |
| 150.013 | 404 | Template not found |
| 150.014 | 400 | Missing merge tags |
| 150.015 | 403 | Insufficient credits |
| 150.016 | 500 | Unhandled failure |

## Public API

```php
use MailBlaze\Transactional\Client;
use MailBlaze\Transactional\Email;

$client = Client::create(getenv('MAILBLAZE_API_KEY'));

$result = $client->emails()->send(
    Email::make()
        ->to('jane@example.com', 'Jane Doe')
        ->from('billing@example.com', 'Acme')
        ->subject('Your receipt')
        ->html('<p>Thanks for your order.</p>')
        ->text('Thanks for your order.')
);
// $result->emailUid()
```

Optional named arguments on `Client::create`: `baseUri`, `timeout`, `logger`, `httpClient`, `requestFactory`, `streamFactory`.

Template send:

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

Attachments, copies, and a scheduled send:

```php
use MailBlaze\Transactional\Attachment;
use DateTimeImmutable;

$result = $client->emails()->send(
    Email::make()
        ->to('jane@example.com', 'Jane Doe')
        ->from('billing@example.com', 'Acme')
        ->subject('Invoice')
        ->html('<p>Invoice attached.</p>')
        ->cc('ops@example.com')
        ->bcc('archive@example.com')
        ->attach(Attachment::fromPath('/tmp/invoice.pdf'))
        ->sendAt(new DateTimeImmutable('+1 hour'))
);
```

```php
$page = $client->emails()->list(page: 1, perPage: 10);
$email = $client->emails()->get($uid);
$files = $client->emails()->attachments($uid);
```

### Exceptions

Messages, logs, and `__toString()` must not contain the API key.

- `MailBlazeException`: base, with `httpStatus()`, `errorCode()`, `errorMessage()`.
- `AuthenticationException`: 401/403, including `150.001`.
- `QuotaException`: `150.015`.
- `DomainNotVerifiedException`: `150.010`.
- `ValidationException`: 400/422, including `150.002`–`150.006`, `150.011`, `150.012`, `150.014`.
- `NotFoundException`: 404, including `150.009` and `150.013`.
- `SendFailedException`: `150.007` and `150.016`.
- `ApiException`: other 4xx/5xx, including `150.008`.
- `TransportException`: PSR-18 network, TLS, or timeout failures.

## Security and efficiency

- TLS only. Certificate verification stays on. `allowInsecureTls` is a named test-only override and is never the default.
- API keys come from the environment or a secrets manager (`MAILBLAZE_API_KEY`). The constructor rejects an empty key. Keys are not accepted in URLs.
- Redact `Authorization`, the raw key, and email bodies from PSR-3 logs and exception messages. Serialized exceptions omit the key and the stack trace. The client itself cannot be serialized.
- Validate email addresses. Reject CR/LF in names, subjects, categories, and filenames. Refuse empty required fields before the HTTP call. Enforce `html()` XOR `templateId()`.
- Encode attachments from bytes or a path. Reject an encoded payload over 10 MiB before the request. Refuse a path that is not a readable file.
- Reuse one PSR-18 client for the life of `Client`. Default timeout is 30 seconds. A send timeout does not prove the API rejected the message.
- Retry only safe GET requests on transient 5xx or connection errors, with a small cap. Never auto-retry `POST /transactional`. Do not retry `150.001`, `150.015`, 403, or 422.

## Standards

- PER Coding Style 3.1 via `php-cs-fixer`.
- PSR-4: `MailBlaze\Transactional\` maps to `src/`.
- PSR-18 / PSR-17 / PSR-7 and `php-http/discovery`.
- PSR-3 optional logger.
- PHPStan level 9 and PHPUnit with `php-http/mock-client`. No live sends in CI.
- GitHub Actions matrix: PHP 8.3, 8.4, and 8.5.
- OpenAPI 3.1 in `docs/openapi.yaml`, matching this controller (including `150.010`–`150.016`, `per_page`, and the 10MB rule).
- MIT license. `CHANGELOG.md` uses Keep a Changelog. Ship `0.1.0` when the acceptance tests pass. Ship `1.0.0` when the public docs match the code.

## Repository layout

```text
mailblaze-transactional-php-sdk/
  src/                Client, Email, Attachment, Emails, exceptions
  tests/
  docs/openapi.yaml
  .github/workflows/ci.yml
  composer.json
  CHANGELOG.md
  README.md
  LICENSE
  SECURITY.md
  phpunit.xml.dist
  phpstan.neon
  .php-cs-fixer.dist.php
```

## Acceptance criteria

- A developer can send a simple email and a template email in five or fewer lines after `composer require`, using only `MAILBLAZE_API_KEY`.
- HTML, plain text, and attachments are accepted as native PHP values. The library produces strict base64 and `Content-Type: application/json` with no charset.
- Encoded attachments over 10 MiB are rejected before the request. Every `150.xxx` code maps to the typed hierarchy. The API key never appears in logs or exception strings.
- list, get, and attachments work. POST is never auto-retried.
- PHPStan level 9, PHPUnit, and PER-CS 3.1 are green on PHP 8.3, 8.4, and 8.5.
- The package is installable from Packagist as `mailblaze/transactional` under MIT.

## Later

- Laravel and Symfony bridges.
- After 1.0.0, mark `mailblaze/api` abandoned on Packagist.
- Add PHP 8.6 to CI after the 19 November 2026 GA.
- Fix the public support article (`plain_text` encoding, 10MB attachment limit, codes `150.010`–`150.016`, `per_page`, verified from-domain).
- Confirm the SMTP username and AUTH mechanism before documenting them.
