<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Unit;

use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\Response;
use MemberFlow\Plorea\Exceptions\MerchantOnboardedByAnotherPartnerException;
use MemberFlow\Plorea\Exceptions\PaymentAlreadyCapturedException;
use MemberFlow\Plorea\Exceptions\PaymentLinkCancelledException;
use MemberFlow\Plorea\Exceptions\RequestException;
use MemberFlow\Plorea\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

class RequestExceptionTest extends TestCase
{
    public function test_it_strips_transfer_stats_carrying_the_authorization_header(): void
    {
        $response = new Response(new Psr7Response(500, [], 'boom'));
        $response->transferStats = new TransferStats(
            new Psr7Request('GET', 'https://payments.plorea.no/payments/status/ref', [
                'Authorization' => 'Bearer plr_secret_key',
            ]),
        );

        $exception = RequestException::fromResponse($response);

        $this->assertNull($exception->response?->transferStats);
        $this->assertStringNotContainsString('plr_secret_key', $exception->getMessage());
    }

    /**
     * The spec's 409 balance-account body is unobserved. A 409 that carries
     * `status` and `refundedTotal` but is not about a capture must not
     * claim the payment was captured.
     */
    public function test_a_409_that_is_not_about_a_capture_stays_generic(): void
    {
        $exception = RequestException::fromResponse($this->response(409, [
            'error' => 'Balance account cannot be resolved',
            'status' => 'authorised',
            'refundedTotal' => 0,
        ]));

        $this->assertNotInstanceOf(PaymentAlreadyCapturedException::class, $exception);
        $this->assertSame(409, $exception->status);

        $this->assertInstanceOf(PaymentAlreadyCapturedException::class, RequestException::fromResponse($this->response(409, [
            'error' => 'Charge has been captured; use refund',
            'status' => 'authorised',
            'refundedTotal' => null,
        ])));
    }

    /**
     * Only the captured live body is typed. A 409 that names an org number
     * but not another partner, or the message without an org number, stays
     * generic.
     */
    public function test_only_the_another_partner_409_is_typed(): void
    {
        $this->assertInstanceOf(MerchantOnboardedByAnotherPartnerException::class, RequestException::fromResponse($this->response(409, [
            'error' => 'Merchant is onboarded by another partner',
            'merchantOrgNr' => '999999995',
        ])));

        foreach ([
            ['error' => 'Balance account cannot be resolved', 'merchantOrgNr' => '999999995'],
            ['error' => 'Merchant is onboarded by another partner'],
            ['error' => 'Merchant is onboarded by another partner', 'merchantOrgNr' => null],
        ] as $body) {
            $exception = RequestException::fromResponse($this->response(409, $body));

            $this->assertSame(RequestException::class, $exception::class);
            $this->assertSame(409, $exception->status);
        }

        $this->assertNotInstanceOf(MerchantOnboardedByAnotherPartnerException::class, RequestException::fromResponse($this->response(400, [
            'error' => 'Merchant is onboarded by another partner',
            'merchantOrgNr' => '999999995',
        ])));
    }

    public function test_a_400_with_an_empty_cancelled_at_is_not_a_withdrawn_link(): void
    {
        $exception = RequestException::fromResponse($this->response(400, ['error' => 'Invalid returnUrl', 'cancelledAt' => null]));

        $this->assertNotInstanceOf(PaymentLinkCancelledException::class, $exception);
        $this->assertInstanceOf(ValidationException::class, $exception);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function response(int $status, array $body): Response
    {
        return new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body)));
    }
}
