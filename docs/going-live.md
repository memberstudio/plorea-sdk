# Going live

A checklist for moving a Plorea integration from test to production. Each item
is something that has either failed silently or cost real money when missed.

## Credentials

- [ ] **A live API key.** Ask Plorea for one; do not reuse the test key in
      production. Store it only in your production environment.
- [ ] **`PLOREA_ENVIRONMENT=live`.** It is sent as `X-Environment` on every
      request, and it defaults to `test`, so an unset variable sends
      production traffic to test.
- [ ] **`PLOREA_TENANT_ID`** set to your production tenant, if Plorea gave you a
      different one.
- [ ] **`PLOREA_PLATFORM`** set to your own identifier, so Plorea can attribute
      your traffic. It has no default.
- [ ] **Rotate on exposure.** Anyone with the key can create links and refund
      payments. If it has been in a chat, a ticket or a log, ask Plorea for a new
      one before launch.

## Webhooks

- [ ] **Your production URL registered with Plorea**, for example
      `https://api.example.com/plorea/webhook`. Registration is manual and done
      separately per environment.
- [ ] **The production signing secret** in `PLOREA_WEBHOOK_SECRET`, received
      over a secure channel. It differs from the test secret.
- [ ] **`PLOREA_WEBHOOK_VERIFY=true`**, or unset. `false` is a staging escape
      hatch; in production it lets anyone post fake events.
- [ ] **The webhook path excluded from CSRF.** Otherwise every delivery gets a
      `419` and nothing reaches your listeners.
- [ ] **Listeners are queued** and deduplicate on `$event->eventId`.
- [ ] **A scheduled polling backstop** re-reads open payments and card setups.
      Webhooks can be missed, and card setup, cancel, reactivate and failed
      scheduler charges send none. See [Webhooks](webhooks.md).

## Merchants

- [ ] **Every payment link carries the right `merchantOrgNr`**: the invoice
      issuer, never your own. It decides who is paid.
- [ ] **Merchants know about KYC.** The first live payment for a new org number
      starts onboarding by email. The payment succeeds, but the payout is held
      until KYC is approved, which takes 1 to 5 business days.

## Money-handling code

- [ ] **`PLOREA_RETRY_TIMES` stays 0**, unless every write you retry is
      idempotent. A retried link creation or charge can bill twice. See
      [Error handling](errors.md#retries-and-idempotency).
- [ ] **Links are created with `firstOrCreate()`** inside a per-reference lock.
- [ ] **Refund polling is sized in hours**, and a timeout never reverses your
      books or tells the customer that a refund failed.
- [ ] **The `Refund` DTO is persisted.** Its `refundPspReference` exists nowhere
      else.
- [ ] **Booking is idempotent on the reference**, because webhook, poll and
      return page race.

## Embedded and native checkout

Only if you mount Drop-in yourself. See [Embedded and native checkout](checkout.md).

- [ ] **A live client key** in `PLOREA_ADYEN_CLIENT_KEY`. Test keys do not work
      against live sessions.
- [ ] **Production origins whitelisted** by Plorea for the live key, including
      any customer-owned domains that show checkout.
- [ ] **Production app identifiers whitelisted**: bundle id with Team ID, and
      package name.
- [ ] **Return URLs work in production builds**: universal links / App Links
      verified on the production domain, and the redirect page finishes
      `redirectResult`.
- [ ] **CSP allows Adyen's live hosts.** They differ from the test hosts.
- [ ] **Wallets are configured**, if you show them: Apple Pay domain
      verification on each domain and merchant setup with Plorea.
- [ ] **App store review.** Payments for real-world services only, as described
      in [Checkout](checkout.md#app-store-rules).

## Observability

- [ ] **Alert on `ResponseReceived` with a status of 400 or above**, and on
      `ConnectionException`. See [Request logging](request-logging.md).
- [ ] **Alert on stuck states**: links open past `expiresAt`, card setups
      `pending_setup` past their `expiresAt`, subscriptions from
      `needingAttention()`.
- [ ] **Logs contain no key.** The SDK redacts it; check that your own HTTP
      logging does too.

## The launch test

Before announcing, run one real transaction end to end in production:

1. Create a low-value live link for a merchant whose KYC is complete.
2. Pay it with a real card, on every surface you ship: hosted, web Drop-in,
   iOS and Android.
3. Confirm that the webhook arrived, verified and was booked once.
4. Refund it, and confirm that the refund settles. Allow hours.
5. Store a card and let a subscription charge it, if you sell subscriptions.
