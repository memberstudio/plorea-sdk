# Contributing

Thank you for considering contributing to the Plorea SDK for Laravel!

## Development setup

```bash
git clone https://github.com/memberstudio/plorea-sdk.git
cd plorea-sdk
composer install
```

Requirements: PHP 8.4+ and Composer. Everything else (testbench, PHPUnit,
PHPStan, Pint, Rector) is installed as a dev dependency.

## Running the checks

All four checks must be green before a pull request can be merged — CI
enforces them:

```bash
composer check    # pint, rector (dry-run), phpstan level 8, phpunit
```

Or individually:

```bash
composer pint     # code style (fixes in place)
composer rector   # automated refactoring (fixes in place)
composer analyse  # PHPStan level 8
composer test     # PHPUnit
```

Use `composer format` to auto-fix style and refactoring issues before
committing.

## Guidelines

- **Add tests for everything.** Every endpoint, builder option, and error
  path has a feature test; keep it that way. `Http::preventStrayRequests()`
  is enabled in the base `TestCase` — no real HTTP leaves the suite.
- **Follow the existing structure.** Facade → manager → resources → pending
  builders → readonly DTOs. New endpoints get a resource method, a DTO with
  `fromArray()`, and a default fixture in the fake client.
- **Never commit credentials.** No real API keys, tenant IDs, or webhook
  secrets — not even in tests or fixtures. CI scans every push with
  gitleaks.
- **Document behavior honestly.** Where the Plorea API deviates from its
  OpenAPI spec (statuses, error shapes, webhook payloads), the README and
  docblocks describe observed reality. Keep that distinction when editing.

## Trying the SDK against Plorea's test environment

The `workbench/` harness sends real requests to Plorea's **test** environment.
It refuses to run with `PLOREA_ENVIRONMENT` set to anything else.

```bash
cp workbench/.env.example workbench/.env    # git-ignored; fill in test credentials
vendor/bin/testbench plorea:probe            # opens sessions per channel, prints redacted fields
vendor/bin/testbench plorea:probe --app-return-url --only=setup
vendor/bin/testbench serve                   # web Drop-in at http://localhost:8000/plorea
```

- The probe writes Plorea's full responses to `build/plorea-probe/`
  (git-ignored). They hold tenant and shopper data: anonymise anything before
  it becomes a fixture in `tests/Fixtures`.
- The web page needs `APP_KEY` and a `PLOREA_ADYEN_CLIENT_KEY` that Plorea has
  whitelisted for `http://localhost:*`.
- While a command runs, Testbench copies `workbench/.env` to
  `vendor/orchestra/testbench-core/laravel/.env` and deletes it on exit. A
  killed command leaves that copy, credentials included, behind, and later
  runs keep using it instead of your `workbench/.env`. Delete it by hand.

## Reporting bugs

Open an issue with the SDK version, Laravel/PHP versions, and a minimal
reproduction (ideally a failing test using `Plorea::fake()`).

## Security vulnerabilities

Please do not open public issues for security problems — see
[SECURITY.md](SECURITY.md).
