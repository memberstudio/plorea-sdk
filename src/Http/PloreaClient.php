<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Http;

use Illuminate\Http\Client\ConnectionException as IlluminateConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException as IlluminateRequestException;
use Illuminate\Http\Client\Response;
use MemberFlow\Plorea\Contracts\Client;
use MemberFlow\Plorea\Enums\Environment;
use MemberFlow\Plorea\Exceptions\ConnectionException;
use MemberFlow\Plorea\Exceptions\PloreaException;
use MemberFlow\Plorea\Exceptions\RequestException;

final readonly class PloreaClient implements Client
{
    /**
     * @param  array<string, mixed>  $config  The "plorea" configuration array.
     */
    public function __construct(
        private Factory $http,
        private array $config,
    ) {}

    public function get(string $uri, array $query = []): array
    {
        return $this->send('get', $uri, $query);
    }

    public function post(string $uri, array $payload = []): array
    {
        return $this->send('post', $uri, $payload);
    }

    public function patch(string $uri, array $payload = []): array
    {
        return $this->send('patch', $uri, $payload);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function send(string $method, string $uri, array $data): array
    {
        try {
            /** @var Response $response */
            $response = $this->request()->{$method}($uri, $data);
        } catch (IlluminateConnectionException $exception) {
            throw new ConnectionException("Could not connect to the Plorea API: {$exception->getMessage()}", $exception->getCode(), previous: $exception);
        }

        if ($response->failed()) {
            throw RequestException::fromResponse($response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function request(): PendingRequest
    {
        $apiKey = $this->config['api_key'] ?? null;

        if (! is_string($apiKey) || $apiKey === '') {
            throw new PloreaException(
                'No Plorea API key is configured. Set the PLOREA_API_KEY environment variable.',
            );
        }

        $http = $this->config['http'] ?? [];

        $request = $this->http
            ->baseUrl(rtrim((string) ($this->config['base_url'] ?? 'https://payments.plorea.no'), '/'))
            ->withToken($apiKey)
            ->withHeaders(['X-Environment' => $this->environment()->value])
            ->acceptJson()
            ->asJson()
            ->timeout((int) ($http['timeout'] ?? 30))
            ->connectTimeout((int) ($http['connect_timeout'] ?? 10));

        $retryTimes = (int) ($http['retry']['times'] ?? 0);

        if ($retryTimes > 0) {
            return $request->retry(
                $retryTimes,
                (int) ($http['retry']['sleep'] ?? 100),
                fn ($exception): bool => $exception instanceof IlluminateConnectionException
                    || ($exception instanceof IlluminateRequestException && $exception->response->serverError()),
                throw: false,
            );
        }

        return $request;
    }

    private function environment(): Environment
    {
        $value = $this->config['environment'] ?? 'test';

        if ($value instanceof Environment) {
            return $value;
        }

        return Environment::tryFrom((string) $value) ?? throw new PloreaException(
            "Invalid Plorea environment [{$value}]. Supported values are \"test\" and \"live\".",
        );
    }
}
