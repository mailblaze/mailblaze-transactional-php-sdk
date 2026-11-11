# Local control panel test

Run these commands from this directory. The local control panel must already be serving `http://control.mailblaze.local:8080`. The transactional API is `http://control.mailblaze.local:8080/api`.

Copy `examples/local.env.example` to `examples/local.env`. Set `MAILBLAZE_API_KEY` to the public API key of a local account, and set `MAILBLAZE_FROM` to an address on a domain that account has verified. `examples/local.env` is gitignored. Do not commit it, and do not paste the key into a log or a ticket.

This package needs PHP 8.3 or newer. These commands use the `php:8.3-cli` image and mount the current directory. `--add-host` is required because the container does not use the machine hosts file.

`examples/local.php` sets `allowHttp: true`. HTTP is accepted only for a local host.

## List, without sending

```bash
docker run --rm --add-host control.mailblaze.local:host-gateway -v "${PWD}:/app" -w /app php:8.3-cli php examples/local.php list
```

A success line is `code: 050.001` when the account has no transactional emails, or `code: 000.000` followed by one line per email.

## Send one email

```bash
docker run --rm --add-host control.mailblaze.local:host-gateway -v "${PWD}:/app" -w /app php:8.3-cli php examples/local.php send
```

This calls `POST /transactional` on the local control panel. Expect `code: 000.000` and an `email_uid`. A timeout does not prove the message was rejected. Run `list` before sending it again. Then read it back:

```bash
docker run --rm --add-host control.mailblaze.local:host-gateway -v "${PWD}:/app" -w /app php:8.3-cli php examples/local.php get EMAIL_UID
```

`from` must use a verified domain. An unverified domain returns `150.010`.
