# Request logging

The SDK stores **nothing** in your database. Payment links and subscriptions
are your domain data, and a package-owned table would be a second source of
truth that drifts — Plorea settles refunds and cancellations asynchronously,
and webhook delivery is best-effort.

Instead, every API call dispatches events.

| Event | When | Payload |
| --- | --- | --- |
| `RequestSent` | Immediately before a request goes out | `method`, `uri`, `payload` |
| `ResponseReceived` | For **every** response, including errors, before the exception is thrown | `method`, `uri`, `payload`, `status`, `response`, `durationMs` |

`ResponseReceived` is not dispatched when the API is unreachable — a
`ConnectionException` means no response existed. Neither event ever carries the
API key or any headers.

## An audit trail

One listener gives you a full request/response row:

```php
use MemberFlow\Plorea\Events\ResponseReceived;

Event::listen(ResponseReceived::class, function (ResponseReceived $event) {
    PloreaRequestLog::create([
        'method' => $event->method,          // "POST"
        'uri' => $event->uri,                // "payments/link"
        'payload' => $event->payload,        // json column
        'status' => $event->status,          // 200, 404, ...
        'response' => $event->response,      // json column, nullable
        'duration_ms' => $event->durationMs,
    ]);
});
```

The schema stays in your hands — add the columns and relations you actually
need: a `payable` morph to your invoice model, an indexed `reference` pulled
out of the payload, a retention policy.

```php
Schema::create('plorea_request_logs', function (Blueprint $table) {
    $table->id();
    $table->string('method', 8);
    $table->string('uri');
    $table->string('reference')->nullable()->index();
    $table->json('payload')->nullable();
    $table->unsignedSmallInteger('status');
    $table->json('response')->nullable();
    $table->unsignedInteger('duration_ms');
    $table->timestamps();
});
```

## Practical notes

- **Queue it** if you don't want logging on the request's critical path.
- **Treat the payloads as sensitive.** They contain customer emails, references
  and amounts. Apply the same retention and access rules as the rest of your
  payment data.
- **Never add headers to the log.** The events omit them precisely so that a
  log table cannot end up holding the API key.
- `ResponseReceived` fires for failures too, so a log table plus an alert on
  `status >= 400` is a serviceable monitor without any extra plumbing.

## Latency

`durationMs` is the round trip as measured by the client. Watching its
distribution is the cheapest early warning you have for Plorea-side slowness,
which matters most on the checkout path where a timeout leaves you in the
"unknown, not failed" state described in [Error handling](errors.md#retries-and-idempotency).
