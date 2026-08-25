<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Resources;

use MemberFlow\Plorea\Contracts\Client;
use MemberFlow\Plorea\Exceptions\PloreaException;

abstract class Resource
{
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

    protected function platform(): ?string
    {
        $platform = $this->config['platform'] ?? null;

        return is_string($platform) && $platform !== '' ? $platform : null;
    }
}
