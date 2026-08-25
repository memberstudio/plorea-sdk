<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Verifies the HMAC-SHA256 signature of incoming Plorea webhooks.
 *
 * Verification only runs when a webhook secret is configured. The expected
 * signature is the hex-encoded HMAC-SHA256 digest of the raw request body.
 */
class VerifyWebhookSignature
{
    public function __construct(protected Repository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->config->get('plorea.webhooks.secret');

        if (! is_string($secret) || $secret === '') {
            return $next($request);
        }

        $header = (string) $this->config->get('plorea.webhooks.signature_header', 'X-Plorea-Signature');
        $signature = (string) $request->header($header, '');

        $expected = hash_hmac('sha256', (string) $request->getContent(), $secret);

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            throw new AccessDeniedHttpException('Invalid Plorea webhook signature.');
        }

        return $next($request);
    }
}
