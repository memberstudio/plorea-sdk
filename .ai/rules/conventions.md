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

## Documentation lives in three places, for three audiences

- **`docs/`** — reference material for a human reading the repo. Long, dated,
  provenance-heavy. `export-ignore`d in `.gitattributes`, so it stays in git and
  on GitHub but never lands in a consumer's `vendor/`.
- **`resources/boost/**`** — short, imperative guidance that **does** ship to
  consuming apps via Boost. Keep it operational.
- **`docs/plorea-api/`** — the public HTML docs site on GitHub Pages, for any
  integrator of the Plorea API. Every claim carries an evidence badge.

A finding that affects consuming apps belongs in both `docs/` and
`resources/boost/**`. Adding it to `docs/` alone means it never reaches the
people who hit it.

**Update the docs in the same PR as the change.** A change to the SDK, a new
finding about the API, or an answer from Plorea updates every place above that
describes it. Check the matching pages in `docs/plorea-api/` too: the topic
page, `field-notes.html`, `wishlist.html` (mark a gap delivered, with the date)
and `changelog.html` (add a dated entry). A PR that changes behaviour and
leaves the docs stale is not done.

The site is public. Run `bin/check-docs` before you push. It fails on
real-looking ids, org numbers, emails, PSP references, unknown hosts, broken
HTML, links and anchors. Never name customers, people or integrator
identifiers on the site (`security.md`).

## PHPStan

`config/plorea.php` has a documented ignore for env-calls — package config
lives outside an app's config dir.
