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

## Reporting bugs

Open an issue with the SDK version, Laravel/PHP versions, and a minimal
reproduction (ideally a failing test using `Plorea::fake()`).

## Security vulnerabilities

Please do not open public issues for security problems — see
[SECURITY.md](SECURITY.md).
