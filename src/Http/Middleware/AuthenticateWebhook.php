<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Authenticates incoming Plorea webhooks.
 *
 * Real deliveries (captured from Plorea's test environment) carry no
 * Authorization header. Instead they are signed: the X-Plorea-Signature
 * header holds a base64-encoded HMAC-SHA256 of the raw request body. When
 * that header is present it is verified against the configured secret.
 *
 * As a fallback — for registrations where a shared secret was handed to
 * Plorea to echo back — a request without a signature header is accepted
 * when the Authorization header matches the secret verbatim (optionally
 * "Bearer "-prefixed). Requests are rejected when neither check passes, or
 * when no secret is configured at all, so the endpoint fails closed until
 * webhooks are fully set up.
 */
class AuthenticateWebhook
{
    public function __construct(protected Repository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->config->get('plorea.webhooks.secret');

        if (! is_string($secret) || $secret === '') {
            throw new AccessDeniedHttpException(
                'Plorea webhooks require a configured secret (PLOREA_WEBHOOK_SECRET).',
            );
        }

        $signature = (string) $request->header('X-Plorea-Signature', '');

        if ($signature !== '') {
            if (! $this->signatureIsValid($request, $secret, $signature)) {
                throw new AccessDeniedHttpException('Invalid Plorea webhook signature.');
            }

            return $next($request);
        }

        if (! $this->sharedSecretIsValid($request, $secret)) {
            throw new AccessDeniedHttpException('Invalid Plorea webhook secret.');
        }

        return $next($request);
    }

    /**
     * Whether the X-Plorea-Signature header matches a base64-encoded
     * HMAC-SHA256 of the raw request body, keyed with the webhook secret.
     */
    protected function signatureIsValid(Request $request, string $secret, string $signature): bool
    {
        $expected = base64_encode(hash_hmac('sha256', (string) $request->getContent(), $secret, true));

        return hash_equals($expected, $signature);
    }

    /**
     * Whether the Authorization header echoes the shared secret verbatim.
     */
    protected function sharedSecretIsValid(Request $request, string $secret): bool
    {
        $provided = (string) $request->header('Authorization', '');

        // The exact prefix casing is unverified, so strip it case-insensitively.
        $provided = preg_replace('/^Bearer\s+/i', '', $provided) ?? $provided;

        return $provided !== '' && hash_equals($secret, $provided);
    }
}
