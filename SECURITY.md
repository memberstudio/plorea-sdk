# Security Policy

## Supported versions

Only the latest release receives security fixes.

## Reporting a vulnerability

If you discover a security vulnerability in this package, please email
**hei@memberflow.no** instead of opening a public issue. Include a
description of the issue, steps to reproduce, and the affected version.

You will receive a response within a few business days. Please give us a
reasonable window to release a fix before any public disclosure.

## Scope notes for integrators

- The SDK never logs or serializes your Plorea API key. Exception messages
  never contain the `Authorization` header, and both paths that could carry
  the outbound request out of the SDK are closed:
  - **Failed responses.** The response attached to `RequestException::$response`
    has its transfer stats (which carry the outbound request headers) stripped
    before the exception is thrown.
  - **Connection failures.** Laravel wraps the underlying transport exception,
    which retains the PSR-7 request and its `Authorization` header.
    `MemberFlow\Plorea\Exceptions\ConnectionException` rewrites every request
    in the wrapped chain with a redacted header, and **drops the chain entirely
    when it cannot rewrite one** — so `getPrevious()` is occasionally `null` on
    a transport failure. That is deliberate: a lost stack trace is cheaper than
    a leaked credential. The message and the request URI are always preserved.

  Both are covered by tests. If you find a third path, please report it under
  the process above rather than opening a public issue.
- Webhook verification uses a shared secret compared with `hash_equals` and
  fails closed when no secret is configured. Keep `PLOREA_WEBHOOK_SECRET`
  out of source control.
