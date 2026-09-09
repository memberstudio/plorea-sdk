# Rules index

Committed, area-grouped rules for this package: settled decisions, non-obvious
traps, and standing constraints. Everything here is **field-tested against the
live Plorea API** — dates and evidence are given so you can tell what is proven
from what is merely stated. Do not "fix" a documented behaviour to match what
the API *should* do.

Before editing a file, read every rule file whose globs cover it, and
`grep -rin '<keyword>' .ai/rules` to catch what a path match alone misses.

| Rule file | Applies to |
| --- | --- |
| [security.md](security.md) | **always** — read before touching credentials, fixtures, or logging |
| [payments.md](payments.md) | `src/Resources/PaymentResource.php`, `src/Resources/PayByLinkResource.php`, `src/Pending/PendingPaymentLink.php`, `src/Data/Payment*.php`, `src/Data/Refund.php`, `src/Data/PaymentCancellation.php`, `src/Data/Amount.php` |
| [subscriptions.md](subscriptions.md) | `src/Resources/SubscriptionResource.php`, `src/Resources/PaymentMethodResource.php`, `src/Pending/PendingSubscription*.php`, `src/Pending/PendingPaymentMethodSetup.php`, `src/Data/Subscription*.php`, `src/Data/PaymentMethod*.php`, `src/Data/BillingInterval.php`, `src/Data/RetryPolicy.php`, `src/Enums/RecurringType.php` |
| [webhooks.md](webhooks.md) | `src/Http/Controllers/**`, `src/Http/Middleware/**`, `src/Events/**`, `routes/**`, `tests/Feature/WebhookTest.php` |
| [testing.md](testing.md) | `tests/**`, `src/Testing/**` |
| [conventions.md](conventions.md) | `src/**`, `composer.json`, `.gitattributes` |

## Evidence legend

Used throughout these files:

- **VERIFIED / CAPTURED** — observed on the wire, usually with a fixture in `tests/Fixtures/`.
- **Stated by Plorea** — told to us, not observed. Treat as likely but unproven.
- **UNOBSERVED** — nobody has seen it. Do not model a shape for it.
- **Dead end** — known impossible to reproduce in test; do not spend time retrying.
