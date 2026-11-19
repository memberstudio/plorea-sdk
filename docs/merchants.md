# Merchants and KYC

`Plorea::merchants()` lists partner merchants, reads onboarding progress and
prepares explicit onboarding. The contract is [Plorea OpenAPI 0.39.0](https://docs.plorea.no/openapi.yaml),
checked on 2026-11-19. SDK tests use synthetic responses; these endpoints have
not been verified against live merchants by this implementation.

## Read without starting onboarding

Both GET endpoints are read-only and live-only. They create nothing, send no
verification email and order no card methods. Configure a live key with
`PLOREA_ENVIRONMENT=live`. The SDK never switches the environment for you.

```php
use MemberFlow\Plorea\Facades\Plorea;

$page = Plorea::merchants()->list(limit: 25);
$merchant = Plorea::merchants()->status($merchantOrgNr);

$merchant->kyc->status;        // from steps.kyc.status, NOT stored kycStatus
$merchant->kyc->isApproved();  // receivePayments capability is allowed
$merchant->kyc->problems;     // list<MerchantKycProblem>
$merchant->steps;             // all onboarding steps, including partial failures
```

The configured `plorea.tenant_id` is sent by default. Override it with
`list(tenantId: 'other-tenant')` or `status($merchantOrgNr, tenantId: 'other-tenant')`.
When no tenant is configured, the SDK omits it: a tenant-scoped key can omit
`tenantId`, but a shared platform key requires it for listing and onboarding.
On status reads, `tenantId` narrows access; it cannot grant access.

### Pagination and freshness

`list()` returns `MerchantPage`, not an exhaustive collection. Its `items` is
a `Collection<Merchant>` in newest-first order. `count` counts this page only.
`limit` must be 1–100 (default 25). Pass `nextCursor` unchanged; null means the
last page. Keep the same tenant while paging.

```php
$cursor = null;
do {
    $page = Plorea::merchants()->list(limit: 25, cursor: $cursor);
    foreach ($page->items as $merchant) {
        // Use $merchant->kyc; do not interpret deferred as a verification outcome.
    }
    $cursor = $page->nextCursor;
} while ($cursor !== null);
```

The list reads KYC from the provider. If the request runs out of time, some
merchants have `kyc.status: deferred`, counted in `kycDeferred`. Read them
again with `status()`; do not automatically start onboarding.

Card methods use a five-minute cache. In the list, legal entity, balance
account and store come from Plorea's stored row. The single-merchant status
read asks the platform and is authoritative when these disagree. Merchants
without stores remain in the list (`store: missing`, `cardMethods: no_store`).

### KYC states

- `approved`: the provider allows the merchant to receive payments.
- `pending`: verification is processing; nothing for the merchant to do.
- `action_required`: inspect `problems` for what the provider needs.
- `missing`: no legal entity exists for the organisation number.
- `deferred`: the list did not attempt the lookup before its time budget ran out.
- `unknown`: the platform did not answer, or its answer was not recognized.

The SDK preserves new status strings and problem codes. Missing KYC data
remains null, never approved. It does not fall back to the stored `kycStatus`,
which Plorea documents as written once and never updated.

Branch on a problem's `code` or `type`, not its operator-facing English
`message`. Supply merchant-facing wording yourself. `pendingStatus` / `1_77`
is ordinary processing, not a request for action.

## Start onboarding only with approval

**`start()` performs a real write.** It can create the legal entity and
verification, email the merchant, set up payouts and the store, and order
card methods. Obtain authorization for that merchant before calling it.
The SDK refuses `start()` unless its configuration explicitly says `live`;
this guard also applies with `Plorea::fake()`. The API verifies key access.

Building or inspecting the payload sends nothing:

```php
$pending = Plorea::merchants()->onboarding($merchantOrgNr, $verificationEmail)
    ->name($legalName)
    ->phone($phone)
    ->country('NO')
    ->cardDescriptor($statementName);

$payload = $pending->toPayload(); // no request
// Only after explicit approval: $result = $pending->start();
```

`merchantOrgNr` and `email` are required. The API validates the organisation
number's nine digits and mod-11 check digit. `name`, `phone`, `country` and
`cardDescriptor` are optional; `country` defaults to `NO` at the API. A card
descriptor is at most 22 characters: ASCII letters, digits, spaces and
`.,'_-?+*`. `tenant('other-tenant')` overrides the configured tenant.

Both new (`201`, `created: true`) and existing (`200`, `created: false`)
responses become `Merchant`. Partial success is still a successful HTTP
response: inspect `steps`; neither `created` nor HTTP success means KYC is approved.
A repeat POST can order missing card methods and update Plorea's row. It does
**not** change the email, phone or country. An existing merchant without a store
is reported, not repaired. Poll with GET, not repeated POST.

## Errors and testing

Status `404` becomes `NotFoundException`. It deliberately does not distinguish
an unknown merchant, another partner's merchant, a tenant mismatch or a test
key. Do not treat `404` as permission to onboard. A list with a test key returns
`400`; a tenant key naming another tenant returns `403`. Onboarding for another
partner's merchant returns `409` (`RequestException` with `status === 409`).
Other errors use the [existing exception mapping](errors.md).

`Plorea::fake()` supplies all three routes with pending KYC defaults. Those
defaults do not persist onboarding or implement cursor paging. Stub pages,
repeat results, missing merchants and KYC changes for application tests. Use
`Http::preventStrayRequests()` too. See [Testing](testing.md).
