# Testing

PHPUnit 12 + orchestra/testbench. `Http::preventStrayRequests()` is on in the
base `TestCase`, so any unstubbed request fails the suite rather than escaping.

`composer check` (pint, rector dry-run, phpstan level 8, phpunit) must be green
before every commit.

## Golden fixtures

`tests/Fixtures/` holds **anonymised captures of real API responses**, run
through the SDK's own DTOs by `tests/Feature/GoldenFixturesTest.php`. That is
what makes a shape change from Plorea break the build instead of surfacing as a
production bug.

When you add one:

- Anonymise first — see [security.md](security.md).
- Preserve the shape exactly: key names, ordering, and null-vs-absent. That is
  the entire value of the fixture.
- Assert the surprising facts, not just the happy path. A fixture that only
  proves `status === 'paid'` has not earned its place.
- Say in the test's docblock **when** it was captured and **what** it pins.

Do not replace a golden fixture with a constructed payload. If a shape has not
been captured, say so in the test and claim nothing beyond what it proves — see
`test_it_routes_refunded_payment_events_to_payment_status_updated` for the
pattern.

## Gotchas

- **`TestCase::status()` is final in PHPUnit 12** — name test helpers something
  else.
- Illuminate's HTTP-client `Request` has **`data()`**, not `input()`.

## Verification scripts

Do not write verification scripts when a test covers the functionality. Unit
and feature tests are the deliverable.
