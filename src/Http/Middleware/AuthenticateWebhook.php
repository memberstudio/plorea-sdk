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
 * Plorea does not sign webhooks. Instead, the shared secret you registered
 * alongside your webhook URL is sent back verbatim in the Authorization
 * header, sometimes prefixed with "Bearer ". Requests are rejected when the
 * secret does not match — or when no secret is configured at all, so the
 * endpoint fails closed until webhooks are fully set up.
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

        $provided = (string) $request->header('Authorization', '');

        // The exact prefix casing is unverified, so strip it case-insensitively.
        $provided = preg_replace('/^Bearer\s+/i', '', $provided) ?? $provided;

        if ($provided === '' || ! hash_equals($secret, $provided)) {
            throw new AccessDeniedHttpException('Invalid Plorea webhook secret.');
        }

        return $next($request);
    }
}
