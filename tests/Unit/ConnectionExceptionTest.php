<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Unit;

use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\ConnectionException as IlluminateConnectionException;
use MemberFlow\Plorea\Exceptions\ConnectionException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;

class ConnectionExceptionTest extends TestCase
{
    private function outboundRequest(): Psr7Request
    {
        return new Psr7Request('POST', 'https://payments.plorea.no/payments/link', [
            'Authorization' => 'Bearer plr_secret_key',
            'X-Environment' => 'test',
        ]);
    }

    /**
     * Illuminate builds exactly this chain in
     * PendingRequest::marshalTransportException: its own ConnectionException
     * wrapping Guzzle's, which still holds the outbound request and therefore
     * the API key. Two getPrevious() calls used to reach the header.
     */
    public function test_it_redacts_the_api_key_from_the_wrapped_request(): void
    {
        $guzzle = new GuzzleConnectException('cURL error 28: timed out', $this->outboundRequest());

        $exception = ConnectionException::from(
            new IlluminateConnectionException($guzzle->getMessage(), 0, $guzzle),
        );

        $previous = $exception->getPrevious()?->getPrevious();

        $this->assertInstanceOf(NetworkExceptionInterface::class, $previous);
        $this->assertSame('[redacted]', $previous->getRequest()->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString('plr_secret_key', serialize($exception));
    }

    public function test_it_keeps_the_rest_of_the_request_intact(): void
    {
        $guzzle = new GuzzleConnectException('cURL error 28: timed out', $this->outboundRequest());

        $exception = ConnectionException::from(
            new IlluminateConnectionException($guzzle->getMessage(), 0, $guzzle),
        );

        $previous = $exception->getPrevious()?->getPrevious();

        $this->assertInstanceOf(NetworkExceptionInterface::class, $previous);
        $this->assertSame('test', $previous->getRequest()->getHeaderLine('X-Environment'));
        $this->assertSame(
            'https://payments.plorea.no/payments/link',
            (string) $previous->getRequest()->getUri(),
        );
    }

    public function test_it_keeps_the_chain_when_no_credential_is_present(): void
    {
        $guzzle = new GuzzleConnectException(
            'cURL error 6: could not resolve host',
            new Psr7Request('GET', 'https://payments.plorea.no/pay/pl_1'),
        );

        $exception = ConnectionException::from(
            new IlluminateConnectionException($guzzle->getMessage(), 0, $guzzle),
        );

        $this->assertSame($guzzle, $exception->getPrevious()?->getPrevious());
        $this->assertStringContainsString('could not resolve host', $exception->getMessage());
    }

    /**
     * A chain the redaction cannot reach must not leave the SDK at all — a
     * lost stack trace is cheaper than a leaked credential.
     */
    public function test_it_drops_a_chain_it_cannot_redact(): void
    {
        $unreachable = new class('unreachable', new Psr7Request('GET', 'https://payments.plorea.no/x', ['Authorization' => 'Bearer plr_secret_key'])) extends \RuntimeException implements NetworkExceptionInterface
        {
            public function __construct(string $message, private readonly Psr7Request $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): Psr7Request
            {
                return $this->request;
            }
        };

        $exception = ConnectionException::from(
            new IlluminateConnectionException('unreachable', 0, $unreachable),
        );

        // The unredactable exception is not reachable from ours at all, so
        // nothing that walks the chain can find the key. (An anonymous class
        // cannot be serialized, so the chain is asserted directly.)
        $this->assertNull($exception->getPrevious());
        $this->assertStringNotContainsString('plr_secret_key', $exception->getMessage());
    }
}
