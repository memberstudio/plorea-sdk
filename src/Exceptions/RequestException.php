<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Exceptions;

use Illuminate\Http\Client\Response;

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
        $status = $response->status();

        $message = is_string($response->json('error'))
            ? $response->json('error')
            : "Plorea request failed with status {$status}.";

        return match (true) {
            $status === 400 => new ValidationException($message, $status, $response),
            $status === 401, $status === 403 => new AuthenticationException($message, $status, $response),
            $status === 402 => new ChargeFailedException($message, $status, $response),
            $status === 404 => new NotFoundException($message, $status, $response),
            $status >= 500 => new ServerException($message, $status, $response),
            default => new self($message, $status, $response),
        };
    }
}
