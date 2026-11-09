# Partner merchants and transactions

Both endpoints arrived with API 0.31. Read through `Plorea::merchants()` and
`Plorea::transactions()`.

## Partner merchants are live only — VERIFIED 2026-11-09

- A test key gets `400` "Partner merchants are live only" on the list and `404`
  on a single merchant. The reads are captured from live and anonymised
  (`partner-merchant.json`, `partner-merchants-page.json`). **Never commit a
  real org number, `kycUrl`, store id or e-mail** (see
  [security.md](security.md)).
- `limit` is 1–100. `101` gets `400`. A cursor from another endpoint gets `400`.
- The step names, in order: `merchant`, `legalEntity`, `kyc`,
  `balanceAccount`, `store`, `cardMethods`, `partnerRow`
  (`PartnerMerchant::STEPS`). Statuses seen live: `existed`, `approved`,
  `action_required`, `ready`, `no_store`. Model only what was seen.
- `kyc` can read `deferred`: the list ran out of time before it read KYC. The
  page counts these in `kycDeferred`. It is not a verification status. Ask
  again.
- The single read carries `tenantId` and `checkedAt`; a list item carries
  `email` instead. Do not "fix" one shape to match the other.

## A merchant without a store is never charged — OBSERVED live 2026-11-06 to 2026-11-09

A live subscription for a merchant with approved KYC but no store was never
charged. `isReadyForPayments()` needs KYC `approved`, a `storeId` and `ready`
card methods. Do not loosen it to KYC alone.

## `onboard()` success is stated, not seen — UNOBSERVED

`POST partners/merchants` is built from the 0.31 spec. Plorea states that it
creates what is missing, e-mails the KYC link, and answers `created: false` on
a repeat. **No successful call has been seen.** It is live only, so a test
means a real merchant: **do not send it without the user's explicit OK.**
After the first successful call, capture and anonymise the answer as a
fixture.

**VERIFIED 2026-11-09 (live), with the user's OK:** a company that another
Plorea partner already onboarded answers `409`
`{"error":"Merchant is onboarded by another partner","merchantOrgNr":"…"}`.
Nothing is created and no KYC e-mail is sent. The SDK throws the generic
`RequestException` (status 409); the spec does not list this 409. A `GET` of
the same org number answered `404` first, so `find()` cannot tell you that
another partner owns the company.

**The 409 does not block payment links — VERIFIED 2026-11-09 (live), with the
user's OK.** A 10 kr link with the same org number answered `200`. The create
response read `store: null` and `balanceAccountId: null`. `payments/status`
then read a store id and `splitsEnabled: true`; `balanceAccountId` stayed
`null`. The pay page opened and showed the payment. **UNOBSERVED:** a paid
link, which balance account receives the money, and whether our split applies.
So do not treat the 409 as "merchant unusable", and do not treat it as
"merchant ready" either: `find()` gives `404`, so `isReadyForPayments()` cannot
be read for such a merchant. Asked Plorea 2026-11-09. Never commit the store
id or the org number.

Send the legal entity (hovedenhet), not a sub-unit (underenhet, `BEDR`): KYC
verifies a legal entity.

Spec bug: the POST schema gives `country` `default: false` and `example: false`.
It is an ISO 3166-1 alpha-2 string. `PendingMerchantOnboarding` sends it
upper-cased and only when set.

`cardDescriptor` is checked before the request: 1–22 characters from a-z, A-Z,
0-9, space and `.,'_-?+*` (spec pattern). `&` is refused.

## Transactions — VERIFIED 2026-11-09 (test)

- `limit` is 1–1000; a page of 705 came back in one call. Without a tenant
  Plorea answers `404`, so the SDK always sends the configured tenant.
- The spec says subscription charges are absent. Rows named
  `{subscriptionId}-{chargeId}` **do** appear, and their status can be stale
  (`created` while `payments/status` read `authorised`). Never act on a row:
  read `payments()->status()` first. Do not filter these rows out either; the
  list is Plorea's, not ours.
- `webhookEventCode` is the last inbound Adyen event, not a webhook to you.
- Card-setup rows read `platform: "unknown"`.
