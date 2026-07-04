# Webhook Poller

A small tool for testing webhooks against a local dev environment when you can't expose your machine directly (no tunneling tool available) and can't deploy your actual codebase to a server either.

It works in two halves:

- **Logger** (`logger.php`) - a single dependency-free PHP file you deploy to any cheap PHP host. Real webhook providers (Stripe, GitHub, etc.) point at it and it stores every request it receives (method, headers, body) in a local SQLite file.
- **Poller** (`poller.php`) - a plain PHP script you run on your own machine. It polls the Logger every few seconds, and replays anything new - verbatim, same method/headers/body - to your local dev server.

Requirements: PHP with the `pdo_sqlite` extension (bundled with virtually all PHP installs). No Composer, no other dependencies, on either side.

## 1. Deploy the Logger

Upload `logger.php` and `logger-config.php` to your low-cost host, in the same directory. That's the entire deployment - no build step, no install.

Pick a **secret source name** for each webhook integration you want to test, e.g. `stripe-9f3ka2` (make it long/random - see [Security](#security) below).

Point the real webhook provider at:

```
https://your-host.example.com/logger.php?source=stripe-9f3ka2
```

That's it - the Logger will now record every request sent to that URL.

### `logger-config.php`

```php
<?php
return [
    'db_path' => __DIR__ . '/logger.sqlite',
    'body_cap_bytes' => 2 * 1024 * 1024, // 2 MB
];
```

| Key              | Meaning                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `db_path`        | Path to the SQLite file where logs are stored. Defaults to alongside the script. Created automatically on first request.                                                                                                                                                                                                                                                                                                                                                                         |
| `body_cap_bytes` | Max request body size to actually store. Bodies larger than this are **never rejected** - the webhook is still logged (method, headers, timestamp) - but the body itself is replaced with the placeholder text `Body too big` and flagged as `truncated`, so it won't be replayed by the Poller (a partial body would just fail to parse/verify locally anyway). The Logger checks the `Content-Length` header before reading the body, so oversized requests are never fully read off the wire. |

There is no log retention/cleanup - the SQLite file grows until you delete it manually. This is meant for short-lived dev sessions, not long-term use.

## 2. Configure and run the Poller

Edit `poller-config.php` **locally** (this file never gets deployed to the low-cost host - it only needs to reach your local dev server):

```php
<?php
return [
    'logger_url' => 'https://your-host.example.com/logger.php',
    'poll_interval_seconds' => 5,
    'tracking_db_path' => __DIR__ . '/poller-tracking.sqlite',
    'sources' => [
        'stripe-9f3ka2' => 'http://localhost:8000/webhooks/stripe',
        'github-9f3ka2' => 'http://localhost:8000/webhooks/github',
    ],
];
```

| Key                     | Meaning                                                                                                                                           |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| `logger_url`            | Full URL to the deployed `logger.php`.                                                                                                            |
| `poll_interval_seconds` | How often to check for new webhooks, per source.                                                                                                  |
| `tracking_db_path`      | Path to the Poller's own SQLite file, tracking the last-seen ID per source so it never re-delivers the same webhook twice. Created automatically. |
| `sources`               | Maps each secret source name to the local URL that source's webhooks get replayed to. Add one entry per webhook integration you're testing.       |

Then just run it and leave it running for the duration of your dev session:

```
php poller.php
```

Ctrl+C to stop. There's no daemon/service setup - it's a foreground loop by design, meant to be started and stopped alongside your local dev server.

### What happens on first run

If `tracking_db_path` doesn't have a cursor yet for a source (fresh install, or you deleted the file), the Poller starts from ID 0 - it will replay the **entire existing backlog** already sitting in the Logger for that source, not just webhooks that arrive from that point on. If you don't want old webhooks replayed, clear them from the Logger's database (or use a new `source` name) before starting the Poller.

### Delivery behavior

Delivery is fire-and-forget: the Poller tries once, logs success or failure to the console, and advances its cursor either way. There's no retry queue - if your local dev server was down when a webhook arrived, that delivery is lost; re-trigger it from the provider's dashboard if you need it again. Entries flagged `truncated` (body too big - see above) are skipped entirely and logged as such, since there's nothing useful to replay.

## Security

There is no username/password or token system. Instead, the `source` value you choose (e.g. `stripe-9f3ka2`) doubles as the shared secret for **both** ingesting and querying that source's logs - anyone who doesn't know the exact string can neither inject fake webhooks under that name nor read its logged data. Treat it like a password: make it long and random, and don't reuse a guessable name like `stripe` on its own.

This is intentionally lightweight and meant for short-lived local development use, not for storing sensitive production data long-term.
