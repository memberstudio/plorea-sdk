<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature\Mcp;

use MemberFlow\Plorea\Data\PaymentLink;
use MemberFlow\Plorea\Data\PaymentMethod;
use MemberFlow\Plorea\Data\PaymentStatus;
use MemberFlow\Plorea\Data\Subscription;
use MemberFlow\Plorea\Data\SubscriptionCharge;
use MemberFlow\Plorea\Mcp\Masker;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionParameter;

class MaskerTest extends McpTestCase
{
    /**
     * A new DTO field must be classified before an agent can see it, and a
     * listed field must exist on the DTO.
     */
    public function test_every_dto_field_is_on_the_allowlist(): void
    {
        foreach (Masker::FIELDS as $class => $fields) {
            $constructor = new ReflectionClass($class)->getConstructor();

            $this->assertNotNull($constructor);

            $properties = array_values(array_diff(
                array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters()),
                ['raw'],
            ));

            $this->assertSame($properties, array_keys($fields), "The allowlist for {$class} does not match its fields.");
        }
    }

    /**
     * @return array<string, array{class-string, string, list<string>}>
     */
    public static function fixtures(): array
    {
        return [
            'payment status' => [PaymentStatus::class, 'payment-status-refund-requested', ['paymentLinkUrl', 'lastRefundReason']],
            'payment status with an order id' => [PaymentStatus::class, 'payment-status-subscription-charge-refunded-cancel-refused', ['orderId', 'cancelReference']],
            'payment link' => [PaymentLink::class, 'pay-page-with-merchant', ['product', 'merchantName', 'merchantOrgNr', 'returnUrl']],
            'subscription' => [Subscription::class, 'subscription-active', ['shopperReference', 'storedPaymentMethodId', 'externalId', 'title', 'description']],
            'payment method' => [PaymentMethod::class, 'payment-method-active', ['shopperReference', 'storedPaymentMethodId', 'cardLast4', 'expiryDate', 'adyenPaymentLinkUrl']],
        ];
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $masked
     */
    #[DataProvider('fixtures')]
    public function test_personal_fields_are_masked(string $class, string $fixture, array $masked): void
    {
        /** @var object $dto */
        $dto = $class::fromArray($this->fixture($fixture));

        $plain = new Masker(false)->present($dto);
        $output = new Masker()->present($dto);
        $json = json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($masked as $field) {
            $this->assertIsString($plain[$field], "The fixture has no {$field}, so the test proves nothing.");
            $this->assertSame(Masker::MASK, $output[$field]);
            $this->assertStringNotContainsString($plain[$field], $json);
        }

        foreach (Masker::FIELDS[$class] as $field => $rule) {
            // A kept list can hold masked fields of its own, e.g. refunds[].reason.
            if ($rule === 'keep' && ! is_array($plain[$field])) {
                $this->assertSame($plain[$field], $output[$field], "{$field} is kept, so masking must not change it.");
            }
        }

        $this->assertArrayNotHasKey('raw', $output);
    }

    /**
     * An app may put an e-mail in any text or id it chooses. This test does
     * not read FIELDS, so a field marked "keep" by mistake fails it.
     */
    public function test_an_email_in_app_chosen_fields_never_appears(): void
    {
        $email = 'member@example.com';
        $refunded = $this->fixture('payment-status-subscription-charge-refunded-cancel-refused');
        $refunded['refunds'][0] = [...$refunded['refunds'][0], 'reason' => $email, 'refundReference' => $email];

        $outputs = [
            new Masker()->present(Subscription::fromArray([...$this->fixture('subscription-active'), 'externalId' => $email, 'title' => $email, 'description' => $email, 'customerId' => $email, 'shopperReference' => $email, 'cancelReason' => $email, 'metadata' => ['email' => $email]])),
            new Masker()->present(PaymentStatus::fromArray([...$refunded, 'platform' => $email, 'store' => $email, 'orderId' => $email, 'doneId' => $email, 'lastRefundReason' => $email, 'lastRefundReference' => $email, 'lastCancelReference' => $email, 'cancelReference' => $email])),
            new Masker()->present(PaymentLink::fromArray([...$this->fixture('pay-page-with-merchant'), 'product' => $email, 'merchantName' => $email, 'returnUrl' => "https://example.com/?{$email}"])),
            new Masker()->present(PaymentMethod::fromArray([...$this->fixture('payment-method-active'), 'customerId' => $email, 'shopperReference' => $email, 'metadata' => ['email' => $email]])),
        ];

        $this->assertStringNotContainsString($email, json_encode($outputs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function test_a_field_outside_the_dto_never_appears(): void
    {
        $output = new Masker(false)->present(PaymentLink::fromArray([...$this->fixture('pay-page-with-merchant'), 'email' => 'member@example.com']));

        $this->assertStringNotContainsString('member@example.com', json_encode($output, JSON_THROW_ON_ERROR));
    }

    public function test_a_refund_reason_is_masked_inside_the_refund_list(): void
    {
        $status = PaymentStatus::fromArray($this->fixture('payment-status-subscription-charge-refunded-cancel-refused'));

        $refund = new Masker()->present($status)['refunds'][0];

        $this->assertSame(Masker::MASK, $refund['reason']);
        $this->assertSame('refunded', $refund['status']);
        $this->assertSame(['value' => 100, 'currency' => 'NOK'], $refund['amount']);
        $this->assertSame('2026-10-19T11:11:40.333Z', $refund['settledAt']);
    }

    /**
     * No capture has the card fields Plorea added on 2026-10-14, so they are
     * added to the payment-method fixture here. The test proves the masking,
     * not the shape.
     */
    public function test_every_card_detail_is_masked(): void
    {
        $method = PaymentMethod::fromArray([...$this->fixture('payment-method-active'), 'last4' => '1111', 'brand' => 'visa', 'expiryMonth' => 3, 'expiryYear' => 2030, 'tokenLast4' => '4321', 'physicalCardLast4' => '1111', 'physicalCardExpiryMonth' => 3, 'physicalCardExpiryYear' => 2030]);

        $output = new Masker()->present($method);

        foreach (['last4', 'expiryMonth', 'expiryYear', 'tokenLast4', 'physicalCardLast4', 'physicalCardExpiryMonth', 'physicalCardExpiryYear'] as $field) {
            $this->assertSame(Masker::MASK, $output[$field], $field);
        }

        $this->assertSame('visa', $output['brand']);
    }

    public function test_a_null_field_stays_null_and_empty_metadata_stays_empty(): void
    {
        $output = new Masker()->present(Subscription::fromArray($this->fixture('subscription-active')));

        $this->assertNull($output['customerId']);
        $this->assertSame([], $output['metadata']);
    }

    public function test_charges_keep_status_amount_and_failure_reason(): void
    {
        $charge = SubscriptionCharge::fromArray($this->fixture('subscription-charges')['items'][0]);

        $output = new Masker()->present($charge);

        $this->assertSame('authorised', $output['status']);
        $this->assertSame(['value' => 29900, 'currency' => 'NOK'], $output['amount']);
        $this->assertSame('scheduled_charge', $output['reason']);
        $this->assertSame('TESTCHARGEPSP002', $output['pspReference']);
    }
}
