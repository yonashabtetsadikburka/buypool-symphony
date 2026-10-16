# BuyPool backend (Symfony API skeleton)

JSON API for the BuyPool group-buying platform. This is a skeleton: domain logic
is ported later, parity-first (see ADR 0002). No legacy logic lives here yet.

## Requirements

- PHP 8.3 with extensions `pdo_mysql`, `intl`, `opcache` (plus `mbstring`, `curl`, `openssl`, `fileinfo`, `zip`)
- Composer 2
- MySQL 8 (Docker service or local server)
- Optional: Docker with the Compose plugin

## Installation

```bash
cd backend
composer install --no-interaction
```

Copy `.env` to `.env.local` and adjust only your local values (never commit `.env.local`):

```bash
cp .env .env.local
```

## Running tests

```bash
cd backend
composer test        # full PHPUnit suite (Unit, Integration, Functional, Parity)
composer stan        # PHPStan level 6 on src and tests
composer check       # stan + test
```

The Functional suite needs a reachable MySQL server. Create the test database first:

```bash
php bin/console doctrine:database:create --env=test --if-not-exists
```

Run a single suite:

```bash
vendor/bin/phpunit --testsuite=Unit
vendor/bin/phpunit --testsuite=Parity
```

## Running locally

### Option A: Docker (recommended)

From the repository root:

```bash
docker compose up --build
```

- API: http://localhost:8000 (health: `GET /api/health`)
- Mailpit UI: http://localhost:8025

### Option B: local PHP + local MySQL (no Docker)

```bash
cd backend
composer install --no-interaction
# Point DATABASE_URL in .env.local to your MySQL, then:
php bin/console doctrine:database:create --if-not-exists
symfony serve
# or: php -S 127.0.0.1:8000 -t public
curl http://127.0.0.1:8000/api/health
```

## Environment variables

| Variable              | Example (local only)                                              | Description                              |
|-----------------------|-------------------------------------------------------------------|------------------------------------------|
| `APP_ENV`             | `dev`                                                             | `dev`, `test` or `prod`                  |
| `APP_SECRET`          | (set your own, never commit real secrets)                         | Symfony secret, sessions and CSRF        |
| `DATABASE_URL`        | `mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=8.0`      | Doctrine connection string               |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0`                             | Async transport DSN                      |
| `MAILER_DSN`          | `null://null` (dev), `smtp://mailpit:1025` (docker)               | Mailer transport                         |
| `CORS_ALLOW_ORIGIN`   | `http://localhost:8000`                                           | Only origin allowed to call `/api/*`     |
| `DEFAULT_URI`         | `http://localhost`                                                | URL generation outside HTTP contexts     |

Real credentials belong in `.env.local` or in the environment, never in committed files.

## Useful commands

```bash
php bin/console about            # platform and bundle versions
php bin/console lint:container   # validate the service container
php bin/console debug:router     # list routes
```

## Troubleshooting

- **Composer reports SSL/certificate errors**: some antivirus TLS scanners replace
  certificates. Point Composer at a CA bundle that includes your scanner root, e.g.
  `COMPOSER_CAFILE=/path/to/combined-ca.pem`, instead of disabling verification.
- **Functional tests return 503**: MySQL is unreachable or the test database was not
  created (see above). The health endpoint returns 503 by design when the database
  does not answer.
