<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Exceptions;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

class RequestException extends PloreaException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?Response $response = null,
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Create the appropriate exception for a failed Plorea response.
     */
    public static function fromResponse(Response $response): self
    {
        // The transfer stats hold the original outbound request, including
        // the Authorization header with the API key. Strip them so dumping
        // or serializing the exception can never leak the key.
        $response->transferStats = null;

        $status = $response->status();
        $message = self::messageFor($response, $status);
        $body = is_array($response->json()) ? $response->json() : [];

        return match (true) {
            $status === 400 && is_array($body['activeSubscriptionIds'] ?? null) => new PaymentMethodInUseException($message, $status, $response),
            ($status === 400 || $status === 410) && is_string($body['cancelledAt'] ?? null) => new PaymentLinkCancelledException($message, $status, $response),
            $status === 400 && array_key_exists('remainingAmount', $body) => new RefundAmountExceededException($message, $status, $response),
            $status === 400 => new ValidationException($message, $status, $response),
            $status === 401, $status === 403 => new AuthenticationException($message, $status, $response),
            $status === 402 => new ChargeFailedException($message, $status, $response),
            $status === 404 => new NotFoundException($message, $status, $response),
            $status === 409 && array_key_exists('paidUntil', $body) => new NextChargeInPaidPeriodException($message, $status, $response),
            $status === 409 && array_key_exists('refundedTotal', $body) && is_string($body['status'] ?? null) && str_contains(strtolower($message), 'captured') => new PaymentAlreadyCapturedException($message, $status, $response),
            $status === 409 && is_string($body['merchantOrgNr'] ?? null) && str_contains(strtolower($message), 'another partner') => new MerchantOnboardedByAnotherPartnerException($message, $status, $response),
            $status >= 500 => new ServerException($message, $status, $response),
            default => new self($message, $status, $response),
        };
    }

    /**
     * Plorea error bodies are not consistently shaped — try the common keys,
     * then fall back to the raw body. The full response stays available on
     * the exception either way.
     */
    protected static function messageFor(Response $response, int $status): string
    {
        foreach (['error', 'message'] as $key) {
            $value = $response->json($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $body = trim($response->body());

        if ($body !== '' && ! str_starts_with($body, '<')) {
            return "Plorea request failed with status {$status}: ".Str::limit($body, 200);
        }

        return "Plorea request failed with status {$status}.";
    }
}
