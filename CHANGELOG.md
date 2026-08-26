# Changelog

All notable changes to `memberflow/plorea` will be documented in this file.

## Unreleased

- Initial release: payment links, payment status, refunds, cancellations,
  payment method setup (hosted + Drop-in), subscriptions (create, update,
  list by external ID, manual charges, charge history, cancel, reactivate),
  webhook scaffolding with events, and a full testing fake
  (`Plorea::fake()`).
- `firstOrCreate()` reuses an open payment link for a reference or creates
  one, guarding against duplicate live links. Reuse requires matching
  amount, currency, tenant, and environment, and verifies expiry against
  the pay-page endpoint. Already-paid references throw
  `PaymentAlreadyPaidException`.
- Webhook authentication matches Plorea's production behavior: the
  merchant-generated shared secret is compared with `hash_equals` and the
  route fails closed until `PLOREA_WEBHOOK_SECRET` is configured.
- `Plorea::fake()` mirrors the real API for status lookups: references
  with a created link report an open status, unknown references 404.
- Failed responses attached to `RequestException` have their transfer stats
  stripped so exception output never carries the `Authorization` header,
  and malformed response dates parse to `null` instead of throwing.
- CI: tests across PHP 8.4/8.5 × Laravel 12/13, Pint, PHPStan level 8,
  Rector, gitleaks secret scanning, and dependency audits.
