<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Resources;

use MemberFlow\Plorea\Concerns\FiltersNullValues;
use MemberFlow\Plorea\Contracts\Client;
use MemberFlow\Plorea\Enums\Environment;
use MemberFlow\Plorea\Exceptions\PloreaException;

abstract class Resource
{
    use FiltersNullValues;

    /**
     * @param  array<string, mixed>  $config  The "plorea" configuration array.
     */
    public function __construct(
        protected readonly Client $client,
        protected readonly array $config,
    ) {}

    /**
     * The tenant identifier from the package configuration.
     */
    protected function tenantId(): string
    {
        $tenantId = $this->config['tenant_id'] ?? null;

        if (! is_string($tenantId) || $tenantId === '') {
            throw new PloreaException(
                'No Plorea tenant is configured. Set the PLOREA_TENANT_ID environment variable or pass a tenant explicitly.',
            );
        }

        return $tenantId;
    }

    /**
     * The Adyen client key the consuming app was issued, for mounting Drop-in.
     */
    protected function adyenClientKey(): ?string
    {
        $clientKey = $this->config['adyen_client_key'] ?? null;

        if (! is_string($clientKey) || $clientKey === '') {
            return null;
        }

        // Adyen prefixes client keys with the environment they belong to. A
        // test key against a live session (or the reverse) fails late, inside
        // Drop-in, so refuse the mismatch here where the cause is obvious.
        $environment = $this->environment() ?? 'test';

        if (! str_starts_with($clientKey, "{$environment}_")) {
            throw new PloreaException(
                "The Adyen client key does not match the Plorea environment [{$environment}]. Set PLOREA_ADYEN_CLIENT_KEY to the {$environment}_ key for this deployment.",
            );
        }

        return $clientKey;
    }

    protected function environment(): ?string
    {
        $environment = $this->config['environment'] ?? null;

        if ($environment instanceof Environment) {
            return $environment->value;
        }

        return is_string($environment) && $environment !== '' ? $environment : null;
    }

    protected function platform(): ?string
    {
        $platform = $this->config['platform'] ?? null;

        return is_string($platform) && $platform !== '' ? $platform : null;
    }
}
