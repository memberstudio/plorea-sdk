# Security Policy

## Supported versions

Only the latest release receives security fixes.

## Reporting a vulnerability

If you discover a security vulnerability in this package, please email
**hello@memberflow.no** instead of opening a public issue. Include a
description of the issue, steps to reproduce, and the affected version.

You will receive a response within a few business days. Please give us a
reasonable window to release a fix before any public disclosure.

## Scope notes for integrators

- The SDK never logs or serializes your Plorea API key, and redacts the
  `Authorization` header from exception output.
- Webhook verification uses a shared secret compared with `hash_equals` and
  fails closed when no secret is configured. Keep `PLOREA_WEBHOOK_SECRET`
  out of source control.
