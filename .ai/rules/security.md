# Security

Applies to: **everything**. Read before touching credentials, fixtures, logging
or exception output.

## Credentials never enter this repository

- **Never log or serialize the API key.** Redact `Authorization` in debug and
  exception output.
- **Real credentials never go in this repo** — not in tests, not in fixtures,
  not in comments, not in a commit message. This includes the webhook signing
  secret and any real `X-Plorea-Signature` value.
- This repo has never held a webhook signing secret and must not start. When a
  check needs one, it runs in the consuming app and only a match/no-match
  verdict comes back.

## The Adyen client key is publishable, but not ours

It ends up in browsers and apps by design, so it is not a secret in the way the
API key is. It is still **issued to one integrator** and scoped to their
origins and app identifiers: it lives in the consuming app's
`PLOREA_CLIENT_KEY`, `plorea.client_key` has no default, and no real key —
test or live — goes in this repo. Fixtures and tests use obvious fakes
(`test_FAKE_...`).

## Integrator identifiers stay out of the repo

App bundle ids, package names, Apple Team IDs and whitelisted domains are an
integrator's business. Docs describe them generically; they are never quoted.

## Two paths carry the key out of the SDK

Both are closed, and both must stay closed.

- **The response path.** `RequestException::fromResponse()` nulls the
  response's `transferStats` before the exception is built, because those stats
  hold the outbound request and its `Authorization` header.
- **The connection path.** Illuminate wraps Guzzle's transfer exception, which
  keeps the PSR-7 request — header included — reachable through two
  `getPrevious()` calls. `ConnectionException::from()` walks the chain and
  rewrites each request with a redacted header. PSR-7 requests are immutable
  and Guzzle's `request` property is private, so reflection is the only way to
  write the copy back; that is deliberate, not a shortcut. When a link in the
  chain cannot be redacted, **the chain is dropped rather than rethrown** — a
  lost stack trace is cheaper than a leaked credential.

`tests/Unit/ConnectionExceptionTest.php` proves both outcomes. Do not relax
the drop-the-chain fallback into a best-effort redaction.

## Why there is no golden fixture for signature verification

A test proving signature verification would have to contain a real signature
over a real body — exactly the artefact the rule above forbids. Its absence
from `GoldenFixturesTest` is **deliberate, not a coverage gap**. Do not "fix"
it by generating one from a real capture.

The verification itself is proven — see [webhooks.md](webhooks.md).

## Anonymising captures

Every fixture is an anonymised capture. Before a captured body enters
`tests/Fixtures/`, replace: tenant id, PSP references, shopper references,
payment link ids, card last4, and real email addresses. Keep the *shape*
exactly — key names, ordering, and null-vs-absent — because that is the whole
point of the fixture.

**This applies to prose, not only to fixtures.** Citing a real reference as
evidence in a rule or a doc puts the same identifier in the same public repo,
just outside `tests/Fixtures/` where nobody thinks to look for it. Describe
what was created and when; do not quote the reference. The date and the
outcome are the evidence — the identifier never was.
