<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Contracts;

use MemberFlow\Plorea\Exceptions\PloreaException;

interface Client
{
    /**
     * Send a GET request to the Plorea API.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws PloreaException
     */
    public function get(string $uri, array $query = []): array;

    /**
     * Send a POST request to the Plorea API.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws PloreaException
     */
    public function post(string $uri, array $payload = []): array;

    /**
     * Send a PATCH request to the Plorea API.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws PloreaException
     */
    public function patch(string $uri, array $payload = []): array;
}
