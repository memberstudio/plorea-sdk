<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Http;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException as IlluminateConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException as IlluminateRequestException;
use Illuminate\Http\Client\Response;
use MemberFlow\Plorea\Contracts\Client;
use MemberFlow\Plorea\Enums\Environment;
use MemberFlow\Plorea\Events\RequestSent;
use MemberFlow\Plorea\Events\ResponseReceived;
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
        private ?Dispatcher $events = null,
    ) {}

    public function get(string $uri, array $query = []): array
    {
        return $this->send('get', $uri, $query);
    }

    public function post(string $uri, array $payload = []): array
    {
        return $this->send('post', $uri, $this->withPlatform($payload));
    }

    public function patch(string $uri, array $payload = []): array
    {
        return $this->send('patch', $uri, $this->withPlatform($payload));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function send(string $method, string $uri, array $data): array
    {
        $this->events?->dispatch(new RequestSent(strtoupper($method), $uri, $data));

        $startedAt = microtime(true);

        try {
            /** @var Response $response */
            $response = $this->request()->{$method}($uri, $data);
        } catch (IlluminateConnectionException $exception) {
            // Redacts the API key from the wrapped Guzzle request before the
            // exception leaves the SDK — see ConnectionException::from().
            throw ConnectionException::from($exception);
        }

        $json = $response->json();
        $json = is_array($json) ? $json : null;

        $this->events?->dispatch(new ResponseReceived(
            strtoupper($method),
            $uri,
            $data,
            $response->status(),
            $json,
            round((microtime(true) - $startedAt) * 1000, 2),
        ));

        if ($response->failed()) {
            throw RequestException::fromResponse($response);
        }

        return $json ?? [];
    }

    /**
     * Plorea tags every request body with the platform that sent it. It is
     * internal logging and reporting on their side with no functional effect
     * (confirmed by Plorea 2026-09-15), which is why it is set here rather
     * than threaded through each resource — one place means no endpoint can
     * be missed.
     *
     * Bodies only. A GET carries no payload, and appending the field to the
     * query string would rewrite the URL of every read endpoint for a value
     * Plorea only ever described as a body field.
     *
     * A value already in the payload wins; that is what
     * PendingPaymentLink::platform() relies on.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withPlatform(array $data): array
    {
        $platform = $this->config['platform'] ?? null;

        if (! is_string($platform) || $platform === '' || array_key_exists('platform', $data)) {
            return $data;
        }

        return [...$data, 'platform' => $platform];
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
