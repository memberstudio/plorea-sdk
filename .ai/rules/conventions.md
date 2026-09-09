# Conventions

Open-source Laravel SDK for the Plorea Payments API (`memberflow/plorea`,
namespace `MemberFlow\Plorea`). Structure is modelled on `laravel/ai`:
facade → manager → resources → pending builders → readonly DTOs.

## Architecture

- `src/PloreaServiceProvider.php` — config merge/publish, webhook route
  loading, `AboutCommand`.
- `src/Facades/Plorea.php` → `PloreaManager` → `src/Resources/*` (payments,
  paymentMethods, subscriptions, payByLink).
- `src/Pending/*` — fluent builders (`PendingPaymentLink`, etc.).
- `src/Data/*` — final readonly DTOs with `fromArray()`.
- `src/Testing/*` — `Plorea::fake()` fake client with default fixtures, stubs
  and recorded-request assertions.

**Builders mutate and return `$this`** for chaining — they are a mutable
scratchpad, not a value object. Do not share one between two different links.

## Commands

- `composer check` — pint, rector (dry-run), phpstan level 8, phpunit. Green
  before every commit.
- `composer format` — apply rector + pint fixes.
- `composer test` — phpunit only.
- `composer boost` — regenerate the Boost guidelines/skills (the tagged block
  in `CLAUDE.md`, `AGENTS.md`, `.claude/skills`). Runs through
  `vendor/bin/testbench` since this is a package without artisan.

## Documentation lives in two places, for two audiences

- **`docs/`** — reference material for a human reading the repo. Long, dated,
  provenance-heavy. `export-ignore`d in `.gitattributes`, so it stays in git and
  on GitHub but never lands in a consumer's `vendor/`.
- **`resources/boost/**`** — short, imperative guidance that **does** ship to
  consuming apps via Boost. Keep it operational.

A finding that affects consuming apps belongs in both. Adding it to `docs/`
alone means it never reaches the people who hit it.

## PHPStan

`config/plorea.php` has a documented ignore for env-calls — package config
lives outside an app's config dir.
