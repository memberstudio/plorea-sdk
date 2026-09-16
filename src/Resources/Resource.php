<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Resources;

use MemberFlow\Plorea\Concerns\FiltersNullValues;
use MemberFlow\Plorea\Contracts\Client;
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
    protected function clientKey(): ?string
    {
        $clientKey = $this->config['client_key'] ?? null;

        return is_string($clientKey) && $clientKey !== '' ? $clientKey : null;
    }

    protected function environment(): ?string
    {
        $environment = $this->config['environment'] ?? null;

        return is_string($environment) && $environment !== '' ? $environment : null;
    }

    protected function platform(): ?string
    {
        $platform = $this->config['platform'] ?? null;

        return is_string($platform) && $platform !== '' ? $platform : null;
    }
}
