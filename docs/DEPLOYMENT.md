# OpportunityHub Backend — Staging/Production Deployment

Backend Configuration & Safety Pass. This documents the operational steps
that follow from the environment-driven configuration this phase added —
it does not choose a hosting provider, hostname, database credentials, or
mail transport; those are set once the real staging infrastructure is
known.

## 1. Environment variables

Set in the real environment's own `.env` (never committed) — see
`.env.example` for the full, documented list. The ones this phase made
newly configurable:

- `APP_ENV=production`, `APP_DEBUG=false` — required for every real
  environment.
- `APP_URL=https://<this API's real domain>`
- `FRONTEND_URL=https://<the deployed Flutter Web build's real domain>`
- `CORS_ALLOWED_ORIGINS=https://<flutter-web-domain>` (comma-separated for
  more than one). Leave unset only for local development — see §2.
- `TRUSTED_PROXIES=<proxy IP/CIDR list>` or `TRUSTED_PROXIES=*` — set once
  the real reverse proxy/load balancer is known. See §3.
- `QUEUE_CONNECTION=database` — already the default; must stay `database`
  in every real environment (see §4). Never `sync`.
- `MAIL_MAILER=<real transport>` plus that transport's own credentials —
  see §5.
- `GROQ_API_KEY` — server-side only, see §6.

## 2. CORS

`config/cors.php` reads `CORS_ALLOWED_ORIGINS` (comma-separated exact
origins). When it's unset, a safe loopback-only pattern applies instead
(any `http(s)://localhost` or `127.0.0.1` origin, any port) — never a
wildcard. Set `CORS_ALLOWED_ORIGINS` to the real Flutter Web origin(s)
before going live; the loopback fallback stops applying the moment this
is set. `supports_credentials` stays `false` — this app authenticates
with a Sanctum bearer token, never a cookie session.

`MediaController` (`GET /api/media/{path}`, public company logos/post
images) no longer sets its own CORS header — it's covered by the same
`config/cors.php` policy as every other `/api/*` route, since `api/*` is
in `paths`.

## 3. Trusted proxies / HTTPS URL generation

`bootstrap/app.php` reads `TRUSTED_PROXIES` and calls
`$middleware->trustProxies(...)` only when it's set. Empty by default —
identical to today's local behavior (no proxy, URLs reflect the real
connection scheme). Set once the staging reverse proxy/load balancer is
known:

- A specific proxy IP or CIDR list, comma-separated, or
- The literal `*` to trust whichever proxy is directly connecting
  (Laravel's own supported shorthand — appropriate only when the app is
  guaranteed unreachable except through that proxy, the normal case for a
  containerized PaaS deploy).

This fixes `route()`/`url()` (media links, password-reset and
email-verification signed links) generating `http://` instead of
`https://` behind a TLS-terminating proxy. Deliberately not
`URL::forceScheme('https')` — that would also force HTTPS locally,
breaking local `http://127.0.0.1:8000` development.

## 4. Queue

`QUEUE_CONNECTION` must stay `database` (already the `.env.example`
default) in every real environment — `ReleaseQuizResultJob` is dispatched
with `->delay()` to a future timestamp, which `sync` silently ignores
(running it immediately instead of at the scheduled time). The `jobs` and
`failed_jobs` tables already exist (earliest migration) — no additional
migration is needed.

A persistent worker must run continuously:

```
php artisan queue:work --queue=default,emails --tries=3 --backoff=30,300,1800 --max-time=3600
```

`--tries=3`/`--backoff=30,300,1800` match `ReleaseQuizResultJob` and every
`QueuedTransactionalMail`'s own retry configuration (so a worker-level
override doesn't silently fight the job's own settings). `--max-time`
lets a process manager (Supervisor, systemd, or the PaaS's own worker
process type) safely recycle the worker periodically. Run under a real
process manager, not a bare shell — a crashed worker must restart
automatically or delayed quiz results and every queued email silently
stop being processed.

## 5. Scheduler

One scheduled task exists (`routes/console.php`):
`opportunities:close-expired`, daily. Requires the standard Laravel cron
entry:

```
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
```

Low severity if missed — both real read paths already independently
re-check the deadline regardless of stored `status`, so a missing cron
only means a stale `status`/`closed_at` value, not a business-rule
bypass. **The scheduler is independent of the queue worker in §4** —
quiz-result release is a delayed queue job, not a scheduled command; the
cron does not execute it, and the worker does not run
`close-expired`.

## 6. Mail

`MAIL_MAILER=log` is local-development-only. Every real environment needs
a genuine transport (SMTP/SES/Postmark/Resend/etc.) with credentials set
only in that environment's own `.env` — never in source. Every outbound
email link is built from `FRONTEND_URL`/`APP_URL` (never hardcoded), so
setting those correctly (§1) is what keeps links correct once a real
transport is configured.

## 7. AI (Groq)

`GROQ_API_KEY`/`GROQ_MODEL` are server-side-only environment
configuration — never sent to Flutter, never written to `.env.example`.
Outbound HTTPS to `api.groq.com` must be permitted from wherever the
backend runs. If the key is blank or the service is unavailable, the
feature already fails to a controlled `503`, not a crash — unchanged by
this phase.

## 8. Storage

Unchanged by this phase. CVs and education-verification documents stay
on the private `local` disk, reachable only through their existing
authenticated routes — never made public, never symlinked. Organization
logos/post images stay on the `public` disk, served only through
`MediaController` (`/api/media/{path}`). Both disks need genuinely
persistent storage wherever this app is deployed — an ephemeral container
filesystem without a mounted volume would silently lose every uploaded
file on restart/redeploy.

## 9. Database seeding

`php artisan migrate --force` followed by `php artisan db:seed --force`
is sufficient and safe on a fresh staging database. `DatabaseSeeder` now
calls only the required, idempotent baseline seeders:

- `BaselineSkillSeeder`
- `BaselineLocationSeeder`
- `BaselineLocationAliasSeeder` (must run after `BaselineLocationSeeder`)

It no longer creates any demo/test account or Admin account. Demo data
(`test@example.com`) is local-development-only, run explicitly:

```
php artisan db:seed --class=Database\Seeders\DemoDataSeeder
```

Never run this against staging/production.

## 10. First Admin account

No Admin account is seeded automatically. Create one explicitly once the
database is migrated and seeded:

```
php artisan admin:create admin@example.com
```

Prompts for the password interactively (hidden input, confirmed twice) —
pass `--password=...` only if you understand it may end up in shell
history. Refuses to run if the email already exists, rather than
silently mutating an existing account. The password is hashed by the
`User` model's own cast, the same as every other password write in this
app; it is never logged, printed back, or stored anywhere in source.
