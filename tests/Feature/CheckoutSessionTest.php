<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Enums\Channel;
use MemberFlow\Plorea\Enums\RecurringType;
use MemberFlow\Plorea\Facades\Plorea;
use MemberFlow\Plorea\Tests\TestCase;

/**
 * Embedded and native checkout: the channel, the client key, and what a
 * session is allowed to hand to a browser or an app.
 *
 * The native shapes are stated by Plorea (2026-09-15), not captured. These
 * tests pin what the SDK sends and how it reads a key, nothing more.
 */
class CheckoutSessionTest extends TestCase
{
    public function test_no_channel_is_sent_unless_one_is_given(): void
    {
        Http::fake(['payments.plorea.no/*' => Http::response(['sessionId' => 'CS1'])]);

        Plorea::payByLink()->session('pl_1');
        Plorea::paymentMethods()->setup('shopper-1', RecurringType::Subscription, 'https://app.test/return')->session();
        Plorea::paymentMethods()->setup('shopper-1', RecurringType::Subscription, 'https://app.test/return')
            ->channel(Channel::IOS)
            ->create();

        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => array_key_exists('channel', $request->data()));
    }

    public function test_a_payment_session_sends_the_channel(): void
    {
        Http::fake(['payments.plorea.no/payments/session' => Http::response(['sessionId' => 'CS1'])]);

        Plorea::payByLink()->session('pl_1', 'https://app.test/paid', Channel::Android);

        Http::assertSent(fn (Request $request): bool => $request->data() === [
            'paymentLinkId' => 'pl_1',
            'returnUrl' => 'https://app.test/paid',
            'channel' => 'Android',
            'platform' => 'memberflow',
        ]);
    }

    public function test_a_card_setup_session_sends_the_channel(): void
    {
        Http::fake(['payments.plorea.no/payment-methods/setup/session' => Http::response(['sessionId' => 'CS1'], 201)]);

        Plorea::paymentMethods()
            ->setup('shopper-1', RecurringType::Subscription, 'https://app.test/return')
            ->channel(Channel::IOS)
            ->session();

        Http::assertSent(fn (Request $request): bool => $request->data()['channel'] === 'iOS');
    }

    public function test_the_response_client_key_wins_over_the_configured_one(): void
    {
        config(['plorea.adyen_client_key' => 'test_CONFIGURED']);

        Http::fake([
            'payments.plorea.no/payments/session' => Http::response(['sessionId' => 'CS1', 'clientKey' => 'test_FROM_RESPONSE']),
            'payments.plorea.no/payment-methods/setup/session' => Http::response(['sessionId' => 'CS2', 'clientKey' => 'test_FROM_RESPONSE'], 201),
        ]);

        $this->assertSame('test_FROM_RESPONSE', Plorea::payByLink()->session('pl_1', channel: Channel::IOS)->clientKey);
        $this->assertSame('test_FROM_RESPONSE', Plorea::paymentMethods()
            ->setup('shopper-1', RecurringType::Subscription, 'https://app.test/return')
            ->channel(Channel::IOS)
            ->session()->clientKey);
    }

    /**
     * A web session comes back with `clientKey: null` (captured 2026-09-09),
     * and a card setup session without the key at all. Both fall back to the
     * key Plorea issued out of band, and to the configured environment.
     */
    public function test_a_session_without_a_key_falls_back_to_the_configured_one(): void
    {
        config(['plorea.adyen_client_key' => 'test_CONFIGURED', 'plorea.environment' => 'live']);

        Http::fake([
            'payments.plorea.no/payments/session' => Http::response(['sessionId' => 'CS1', 'sessionData' => 'data', 'environment' => null, 'clientKey' => null]),
            'payments.plorea.no/payment-methods/setup/session' => Http::response($this->fixture('payment-method-setup-session'), 201),
        ]);

        $payment = Plorea::payByLink()->session('pl_1');
        $setup = Plorea::paymentMethods()->setup('shopper-1', RecurringType::Subscription, 'https://app.test/return')->session();

        $this->assertSame('test_CONFIGURED', $payment->clientKey);
        $this->assertSame('live', $payment->environment);
        $this->assertSame('test_CONFIGURED', $setup->clientKey);
        $this->assertSame('test', $setup->environment, 'The environment in the response wins.');
        $this->assertArrayNotHasKey('clientKey', $setup->raw);
    }

    public function test_the_client_key_stays_null_when_nothing_provides_one(): void
    {
        config(['plorea.adyen_client_key' => '']);

        Http::fake(['payments.plorea.no/payments/session' => Http::response(['sessionId' => 'CS1', 'clientKey' => null])]);

        $this->assertNull(Plorea::payByLink()->session('pl_1')->clientKey);
    }

    public function test_a_session_serializes_to_the_checkout_fields_only(): void
    {
        config(['plorea.adyen_client_key' => 'test_CONFIGURED']);

        Http::fake(['payments.plorea.no/payment-methods/setup/session' => Http::response($this->fixture('payment-method-setup-session'), 201)]);

        $session = Plorea::paymentMethods()->setup('shopper-1', RecurringType::Subscription, 'https://app.test/return')->session();

        $expected = [
            'sessionId' => 'CS_TEST_SESSION',
            'sessionData' => 'test-session-data',
            'clientKey' => 'test_CONFIGURED',
            'environment' => 'test',
        ];

        $this->assertSame($expected, $session->toCheckout());
        $this->assertSame($expected, json_decode((string) json_encode($session), true));
        $this->assertSame($expected, response()->json($session)->getData(true));
    }

    public function test_the_fake_returns_a_client_key_only_for_native_channels(): void
    {
        Plorea::fake();

        $this->assertNull(Plorea::payByLink()->session('pl_1', channel: Channel::Web)->clientKey);
        $this->assertNotNull(Plorea::payByLink()->session('pl_1', channel: Channel::Android)->clientKey);

        $setup = Plorea::paymentMethods()->setup('shopper-1', RecurringType::Subscription, 'https://app.test/return');

        $this->assertNull($setup->session()->clientKey);
        $this->assertNotNull($setup->channel(Channel::IOS)->session()->clientKey);
    }

    public function test_only_ios_and_android_are_native(): void
    {
        $this->assertFalse(Channel::Web->isNative());
        $this->assertTrue(Channel::IOS->isNative());
        $this->assertTrue(Channel::Android->isNative());
    }
}
