<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Mcp\Concerns;

use MemberFlow\Plorea\Enums\Environment;
use MemberFlow\Plorea\Http\PloreaClient;

/**
 * Tells a tool which Plorea environment the app targets, and reads the
 * boolean MCP settings.
 *
 * The using class has a Repository $config and a PloreaManager $plorea.
 */
trait ReadsPloreaTarget
{
    /**
     * The configured environment, or null when the value is not "test" or
     * "live". The SDK compares the exact value, so spaces and capitals count.
     */
    protected function configuredEnvironment(): ?Environment
    {
        $value = $this->config->get('plorea.environment') ?? 'test';

        if ($value instanceof Environment) {
            return $value;
        }

        return is_string($value) ? Environment::tryFrom($value) : null;
    }

    /**
     * Whether the app reaches live Plorea data.
     *
     * Live counts when either the environment or the key prefix says live:
     * since 2026-10-13 the key decides the environment, so a live key with
     * PLOREA_ENVIRONMENT unset still reads live data. The client keeps the
     * configuration it was built with, so the client is asked too: the app
     * config can say test while the client still sends a live key.
     */
    protected function targetsLive(Environment $environment): bool
    {
        $key = $this->config->get('plorea.api_key');
        $client = $this->plorea->client();

        return $environment === Environment::Live
            || (is_string($key) && str_starts_with($key, 'plr_live_'))
            || ($client instanceof PloreaClient && $client->targetsLive());
    }

    /**
     * Reads a boolean setting. A value that is not clearly true or false
     * gives the default.
     */
    protected function flag(string $key, bool $default): bool
    {
        $value = $this->config->get($key);

        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default);
    }
}
