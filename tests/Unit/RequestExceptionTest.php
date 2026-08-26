<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Unit;

use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\Response;
use MemberFlow\Plorea\Exceptions\RequestException;
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
}
