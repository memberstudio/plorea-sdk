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
