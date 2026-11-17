<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Http;

use InvalidArgumentException;

/**
 * Builds the Idempotency-Key header for the two routes that accept one:
 * POST subscriptions/{id}/charge and POST payments/refund.
 *
 * The OpenAPI spec caps the key at 128 characters and refuses a longer one
 * with 400. The SDK checks it before sending, so a bad key fails before
 * anything is charged or refunded.
 */
final class IdempotencyKey
{
    public const string HEADER = 'Idempotency-Key';

    public const int MAX_LENGTH = 128;

    /**
     * @return array<string, string> No header when no key is given.
     */
    public static function headers(?string $key): array
    {
        if ($key === null) {
            return [];
        }

        if (trim($key) === '') {
            throw new InvalidArgumentException('The idempotency key must not be empty.');
        }

        if (strlen($key) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The idempotency key must be at most %d characters.',
                self::MAX_LENGTH,
            ));
        }

        return [self::HEADER => $key];
    }
}
