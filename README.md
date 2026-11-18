# Nudge

Nudge is a self-hosted email marketing platform. It covers contacts, lists, segments, campaigns, automations, and templates, and you send through your own email provider. It's built with Laravel, Inertia and React.

## Features

- **Contacts:** lists, tags, custom fields and CSV imports with column mapping. Suppressions are managed automatically from bounces, complaints and unsubscribes.
- **Segments:** rule-based audiences built from contact fields, tags, lists and engagement.
- **Campaigns:** a drag-and-drop email builder and starter templates, merge tags, scheduling, A/B tests that pick a winner automatically, and a pre-send checklist with optional approval.
- **Automations:** visual workflows triggered when a contact joins a list, gets a tag, enters a segment, hits a date field, goes inactive, or when an API event arrives. Steps can send email, wait, branch on conditions, update the contact, call a webhook, or exit.
- **Bring your own provider:** SMTP, Amazon SES, Mailgun, Postmark, SendGrid, Brevo, Mailjet, Resend, MailerSend, Mandrill and Scaleway. Delivery webhooks feed bounce and complaint tracking, and a workspace whose rates climb too high is paused automatically.
- **Tracking:** open and click tracking, signed unsubscribe links, and per-campaign reporting.
- **Workspaces and teams:** multiple workspaces, invitations and custom roles with fine-grained permissions.
- **REST API:** token-authenticated, with an OpenAPI spec and docs served at `/api/v1/docs`, plus a request log in workspace settings.
- **Account security:** passkeys, two-factor authentication and email verification.

## Requirements

- PHP 8.3 or later (8.4 recommended)
- Composer and Node 22
- MySQL 8.4 and Redis in production. SQLite and the database queue are fine for local development.

## Quick start (local)

```sh
git clone https://github.com/Gerenuk-LTD/nudge.git
cd nudge
composer setup
```

`composer setup` installs dependencies, creates `.env`, generates the app key, runs migrations and builds the frontend. The defaults in `.env.example` are production-safe, so for local work set:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
SESSION_SECURE_COOKIE=false
```

Then create the first account, which becomes the owner, and start the app:

```sh
php artisan app:create-owner you@example.com --name="Your Name"
composer dev
```

To try the app with realistic fake data (contacts, campaigns with engagement, automations and more):

```sh
php artisan app:demo-data --email=you@example.com
```

## Deploying

See [DEPLOYING.md](DEPLOYING.md) for the production checklist. It covers environment values, the first-run owner account, queue workers and the scheduler, HTTPS and proxies, sending setup (DNS and webhooks), and rotating `APP_KEY`.

## Artisan commands

| Command                                              | Purpose                                                                    |
| ---------------------------------------------------- | -------------------------------------------------------------------------- |
| `app:create-owner {email} --name=`                   | Create the first (owner) account. It refuses to run if any account exists. |
| `app:set-password {email} [--clear-sign-in-methods]` | Reset a user's password, and optionally remove their passkeys and 2FA.     |
| `app:reencrypt-secrets`                              | Re-encrypt stored secrets after rotating `APP_KEY`.                        |
| `app:demo-data [--contacts=500] [--email=]`          | Fill a workspace with demo data for local testing.                         |

## Project layout

- `app/`: the host application, which handles authentication, registration, account settings and console commands.
- `packages/core/`: `gerenuk/marketing-core`, the marketing engine. It holds the models, sending, automations, segments, imports, the API, and the marketing UI pages.
- `resources/js/`: the auth and settings pages, and shared React components.

## Development

```sh
composer dev          # app server, queue worker, logs and Vite
composer lint         # format PHP with Pint
composer test         # Pint check, PHPStan, app tests and core package tests
composer ci:check     # everything CI runs, including the frontend checks
```

## License

Nudge is [fair source](https://fair.io), licensed under the [Functional Source License, Version 1.1, ALv2 Future License](LICENSE.md) (FSL-1.1-ALv2).

- **You can** use, fork, modify and self-host Nudge, including inside your own business, and use it to provide services to your clients.
- **You can't** offer Nudge, or a modified version of it, as a commercial product or hosted service that competes with Nudge.
- **Each release becomes Apache 2.0** two years after it's published, with no restrictions.

Want Nudge without running it yourself? A hosted version is available from Gerenuk.
