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

## The fake answers with states the API could return

`DefaultFixtures` infers its answers from what the fake has already been asked.
Two rules keep those answers honest:

- **Only fulfilled requests count.** A request whose stub threw is recorded for
  `assertSent()` but never added to `FakeClient::$fulfilled`, so a creation
  that failed cannot make the reference findable afterwards. Do not "simplify"
  this by recording after the stub runs — `assertSent()` must still see a
  request that was sent and threw.
- **Echo what was asked for.** A subscription whose `trialEndsAt` is in the
  future reports `trialing`, not `active`; a filtered list applies its
  `tenantId` / `status` query to the item it returns; a manual charge echoes
  the requested amount and VAT rather than a fixed one. A fake that answers
  with a state the API would never have returned makes a green suite prove
  nothing.

## Gotchas

- **`TestCase::status()` is final in PHPUnit 12** — name test helpers something
  else.
- Illuminate's HTTP-client `Request` has **`data()`**, not `input()`.

## Driving the Adyen test pay page — VERIFIED 2026-10-09 (test)

When a browser drives Plorea's hosted test pay page (manual captures, not the
suite):

- The card fields are Adyen iframes that **drop typed keys after the first
  character**. Insert the whole value at once with CDP `Input.insertText`.
- Test card `4111 1111 4555 1142`, expiry `03/30`, CVC `737` pays
  successfully. These are Adyen's public test values, not secrets.

## Verification scripts

Do not write verification scripts when a test covers the functionality. Unit
and feature tests are the deliverable.
