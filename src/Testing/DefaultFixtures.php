<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Testing;

use MemberFlow\Plorea\Exceptions\NotFoundException;
use MemberFlow\Plorea\Exceptions\PloreaException;

/**
 * Sensible default responses for every Plorea endpoint, based on the
 * examples in the API documentation. Used by the fake client whenever a
 * request has no explicit stub, so Plorea::fake() works out of the box.
 */
final class DefaultFixtures
{
    /**
     * @param  list<RecordedRequest>  $history  Requests already recorded by the fake.
     * @return array<string, mixed>
     */
    public static function for(RecordedRequest $request, array $history = []): array
    {
        $method = strtoupper($request->method);
        $path = $request->path;

        return match (true) {
            $method === 'POST' && $path === 'payments/link' => self::paymentLinkCreated($request),
            $method === 'POST' && $path === 'payments/session' => self::paymentSession($request),
            $method === 'GET' && str_starts_with($path, 'pay/') => self::paymentLink($path),
            $method === 'GET' && str_starts_with($path, 'payments/status/') => self::paymentStatus($path, $history),
            $method === 'POST' && $path === 'payments/refund' => self::refund($request),
            $method === 'POST' && $path === 'payments/cancel' => self::cancellation($request),
            $method === 'POST' && $path === 'payment-methods/setup' => self::paymentMethod($request, pending: true),
            $method === 'POST' && $path === 'payment-methods/setup/session' => self::paymentMethodSession($request),
            $method === 'GET' && preg_match('#^payment-methods/[^/]+$#', $path) === 1 => self::paymentMethodFound($path),
            $method === 'POST' && $path === 'subscriptions' => self::subscription(array_diff_key($request->data, ['merchantName' => true, 'merchantEmail' => true])),
            $method === 'GET' && $path === 'subscriptions' => self::subscriptionList($request, $history),
            $method === 'GET' && preg_match('#^subscriptions/[^/]+$#', $path) === 1 => self::subscription([
                'subscriptionId' => basename($path),
                ...self::merchantOf(self::createdSubscription($history, subscriptionId: basename($path))),
            ]),
            $method === 'PATCH' && preg_match('#^subscriptions/[^/]+$#', $path) === 1 => self::subscription([...$request->data, 'subscriptionId' => basename($path)]),
            $method === 'POST' && str_ends_with($path, '/charge') => self::charge($request),
            $method === 'GET' && str_ends_with($path, '/charges') => self::chargeList($path, $history),
            $method === 'POST' && str_ends_with($path, '/cancel') => self::subscriptionCanceled($path),
            $method === 'POST' && str_ends_with($path, '/reactivate') => self::subscription(['subscriptionId' => basename(dirname($path)), 'status' => 'active']),
            default => throw new PloreaException(
                "No fake response registered for [{$method} {$path}]. Pass a stub to Plorea::fake().",
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function paymentLinkCreated(RecordedRequest $request): array
    {
        return [
            'status' => 'created',
            'environment' => 'test',
            'paymentLinkUrl' => 'https://pay.plorea.no/pl_fake_link',
            'paymentLinkId' => 'pl_fake_link',
            'reference' => $request->input('reference', 'fake-reference'),
            'tenantId' => $request->input('tenantId', 'fake-tenant'),
            'merchantAccount' => 'FakeMerchant',
            'splitsEnabled' => false,
            'partnerSplitsApplied' => false,
            'provider' => 'plorea',
            'expiresAt' => '2099-12-31T12:00:00Z',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function paymentSession(RecordedRequest $request): array
    {
        // Observed 2026-09-19: like card setup, the channel is echoed (Web
        // when none is sent) and a client key comes back for every channel.
        return [
            'sessionId' => 'CS_FAKE_SESSION',
            'sessionData' => 'fake-session-data',
            'environment' => 'test',
            'channel' => $request->input('channel', 'Web'),
            'clientKey' => 'test_FAKE_CLIENT_KEY',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function paymentLink(string $path): array
    {
        return [
            'id' => basename($path),
            'tenantId' => 'fake-tenant',
            'reference' => 'fake-reference',
            'product' => 'Fake Product',
            'amount' => 50000,
            'currency' => 'NOK',
            'environment' => 'test',
            'returnUrl' => 'https://example.test/return',
            'expiresAt' => '2099-12-31T12:00:00Z',
            'expired' => false,
        ];
    }

    /**
     * Mirrors the real API: references the fake has seen a link created for
     * report an open status echoing that link, anything else is a 404. Stub
     * `payments/status/*` to simulate paid, failed, or other states.
     *
     * @param  list<RecordedRequest>  $history
     * @return array<string, mixed>
     */
    private static function paymentStatus(string $path, array $history): array
    {
        $reference = rawurldecode(basename($path));

        foreach ($history as $recorded) {
            if ($recorded->matches('POST payments/link') && $recorded->input('reference') === $reference) {
                return [
                    'reference' => $reference,
                    'tenantId' => $recorded->input('tenantId', 'fake-tenant'),
                    'status' => 'active',
                    'provider' => 'plorea',
                    'paymentLinkId' => 'pl_fake_link',
                    'paymentLinkUrl' => 'https://pay.plorea.no/pl_fake_link',
                    'amount' => $recorded->input('amount', 50000),
                    'currency' => $recorded->input('currency', 'NOK'),
                    'environment' => 'test',
                    'splitsEnabled' => false,
                ];
            }
        }

        throw new NotFoundException(
            "No payment found for reference [{$reference}]. The fake returns a status only for references it has seen a payment link created for — stub [payments/status/*] to fake other payments.",
            404,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function refund(RecordedRequest $request): array
    {
        return [
            'status' => 'refund_requested',
            'reference' => $request->input('reference', 'fake-reference'),
            'modificationReference' => $request->input('modificationReference', 'fake-refund'),
            'refundPspReference' => 'FAKEREFUND123',
            'paymentPspReference' => 'FAKEPSP123',
            'amount' => $request->input('amount', 50000),
            'currency' => 'NOK',
            'environment' => 'test',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function cancellation(RecordedRequest $request): array
    {
        return [
            'status' => 'cancel_requested',
            'reference' => $request->input('reference', 'fake-reference'),
            'modificationReference' => $request->input('modificationReference', 'fake-cancel'),
            'cancelPspReference' => 'FAKECANCEL123',
            'paymentPspReference' => 'FAKEPSP123',
            'environment' => 'test',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function paymentMethod(RecordedRequest $request, bool $pending = false): array
    {
        return [
            'paymentMethodId' => 'pm_fake_method',
            'tenantId' => $request->input('tenantId', 'fake-tenant'),
            'customerId' => $request->input('customerId'),
            'shopperReference' => $request->input('shopperReference', 'fake-shopper'),
            'recurringType' => $request->input('recurringType', 'Subscription'),
            'environment' => 'test',
            'status' => $pending ? 'pending_setup' : 'active',
            'adyenPaymentLinkId' => 'PL_FAKE_SETUP',
            'adyenPaymentLinkUrl' => 'https://test.adyen.link/PL_FAKE_SETUP',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function paymentMethodFound(string $path): array
    {
        return [
            'paymentMethodId' => basename($path),
            'tenantId' => 'fake-tenant',
            'shopperReference' => 'fake-shopper',
            'recurringType' => 'Subscription',
            'environment' => 'test',
            'status' => 'active',
            'storedPaymentMethodId' => 'FAKESTORED123',
            'cardLast4' => '0004',
            'cardBrand' => 'mc',
            'expiryDate' => '03/2030',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function paymentMethodSession(RecordedRequest $request): array
    {
        // Observed 2026-09-17: the channel is echoed (Web when none is sent)
        // and a client key comes back for every channel, web included.
        return [
            'paymentMethodId' => 'pm_fake_method',
            'tenantId' => $request->input('tenantId', 'fake-tenant'),
            'shopperReference' => $request->input('shopperReference', 'fake-shopper'),
            'recurringType' => $request->input('recurringType', 'Subscription'),
            'status' => 'pending_setup',
            'environment' => 'test',
            'channel' => $request->input('channel', 'Web'),
            'sessionId' => 'CS_FAKE_SESSION',
            'sessionData' => 'fake-session-data',
            'clientKey' => 'test_FAKE_CLIENT_KEY',
            'expiresAt' => '2099-12-31T12:00:00Z',
        ];
    }

    /**
     * A subscription with an unexpired trialEndsAt is reported as trialing,
     * matching the wire — the API does not report a trialing subscription as
     * active, and a fake that did would let a consumer's trial branch pass a
     * test it fails in production. An explicit status override still wins.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function subscription(array $overrides = []): array
    {
        $subscription = [
            'subscriptionId' => 'sub_fake_subscription',
            'tenantId' => 'fake-tenant',
            'paymentMethodId' => 'pm_fake_method',
            'recurringType' => 'Subscription',
            'amount' => ['value' => 19900, 'currency' => 'NOK'],
            'interval' => ['unit' => 'month', 'count' => 1],
            'status' => 'active',
            'retryCount' => 0,
            ...$overrides,
        ];

        if (! array_key_exists('status', $overrides) && self::isTrialing($subscription)) {
            $subscription['status'] = 'trialing';
        }

        return $subscription;
    }

    /**
     * The most recent subscription the fake has created that matches the
     * given id or external id, so reads can echo what was sent on create.
     * Every subscription the fake creates shares one id, so any other id
     * has no creation to echo.
     *
     * @param  list<RecordedRequest>  $history
     */
    private static function createdSubscription(array $history, ?string $subscriptionId = null, mixed $externalId = null): ?RecordedRequest
    {
        if ($subscriptionId !== null && $subscriptionId !== self::subscription()['subscriptionId']) {
            return null;
        }

        foreach (array_reverse($history) as $recorded) {
            if (! $recorded->matches('POST subscriptions')) {
                continue;
            }

            if ($externalId === null || $recorded->input('externalId') === $externalId) {
                return $recorded;
            }
        }

        return null;
    }

    /**
     * Reads return the organisation number sent on create, but not the
     * merchant's name or email — matching the wire since 2026-09-22.
     *
     * @return array{merchantOrgNr?: mixed}
     */
    private static function merchantOf(?RecordedRequest $created): array
    {
        $orgNr = $created?->input('merchantOrgNr');

        return $orgNr === null ? [] : ['merchantOrgNr' => $orgNr];
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private static function isTrialing(array $subscription): bool
    {
        $trialEndsAt = $subscription['trialEndsAt'] ?? null;

        if (! is_string($trialEndsAt) || $trialEndsAt === '') {
            return false;
        }

        $timestamp = strtotime($trialEndsAt);

        return $timestamp !== false && $timestamp > time();
    }

    /**
     * Always one subscription for the external id, but the tenant and status
     * filters are applied to it rather than ignored — a consumer filtering
     * for canceled subscriptions must not be handed an active one.
     *
     * @param  list<RecordedRequest>  $history
     * @return array<string, mixed>
     */
    private static function subscriptionList(RecordedRequest $request, array $history): array
    {
        $externalId = $request->input('externalId', 'fake-external');
        $tenantId = $request->input('tenantId');
        $status = $request->input('status');

        $overrides = [
            'externalId' => $externalId,
            ...self::merchantOf(self::createdSubscription($history, externalId: $externalId)),
        ];

        if (is_string($tenantId) && $tenantId !== '') {
            $overrides['tenantId'] = $tenantId;
        }

        if (is_string($status) && $status !== '') {
            $overrides['status'] = $status;
        }

        $items = [self::subscription($overrides)];

        return [
            'externalId' => $externalId,
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * A manual charge echoes the amount and VAT it was asked for. The API
     * charges what it is sent, so a fake that always answered with the
     * subscription's own amount would hide a wrong amount in the payload.
     *
     * @return array<string, mixed>
     */
    private static function charge(RecordedRequest $request): array
    {
        $subscriptionId = basename(dirname($request->path));
        $amount = $request->input('amount');

        return [
            'status' => 'charge_created',
            'subscriptionId' => $subscriptionId,
            'chargeId' => 'chg_fake_charge',
            'reference' => $subscriptionId.'-chg_fake_charge',
            'pspReference' => 'FAKECHARGE123',
            'resultCode' => 'Authorised',
            'amount' => is_array($amount) ? $amount : ['value' => 19900, 'currency' => 'NOK'],
            'vatRate' => $request->input('vatRate'),
            'vatAmount' => $request->input('vatAmount'),
            'nextChargeAt' => '2099-12-31T12:00:00Z',
        ];
    }

    /**
     * @param  list<RecordedRequest>  $history
     * @return array<string, mixed>
     */
    private static function chargeList(string $path, array $history): array
    {
        $subscriptionId = basename(dirname($path));

        return [
            'subscriptionId' => $subscriptionId,
            ...self::merchantOf(self::createdSubscription($history, subscriptionId: $subscriptionId)),
            'items' => [
                [
                    'subscriptionId' => $subscriptionId,
                    'chargeId' => 'chg_fake_charge',
                    'tenantId' => 'fake-tenant',
                    'reference' => $subscriptionId.'-chg_fake_charge',
                    'pspReference' => 'FAKECHARGE123',
                    'amount' => ['value' => 19900, 'currency' => 'NOK'],
                    'status' => 'authorised',
                    'retryNumber' => 0,
                    'reason' => 'scheduled_charge',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function subscriptionCanceled(string $path): array
    {
        return [
            'subscriptionId' => basename(dirname($path)),
            'status' => 'canceled',
            'canceledAt' => '2026-08-26T12:00:00Z',
            'accessEndsAt' => '2026-09-26T12:00:00Z',
            'reason' => 'customer_requested',
        ];
    }
}
