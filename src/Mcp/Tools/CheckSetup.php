<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Mcp\Tools;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use MemberFlow\Plorea\Enums\Environment;
use MemberFlow\Plorea\Exceptions\AuthenticationException;
use MemberFlow\Plorea\Exceptions\ConnectionException;
use MemberFlow\Plorea\Exceptions\NotFoundException;
use MemberFlow\Plorea\Exceptions\PloreaException;
use MemberFlow\Plorea\PloreaManager;
use ReflectionClass;
use Throwable;

/**
 * Checks the Plorea configuration against the go-live list.
 *
 * Every result names a check, a status and a fix. No result carries a
 * configured value: the key prefix and the environment name are the only
 * parts of the configuration that appear, because neither is a secret. Both
 * come from a fixed list, never from the raw value, so a secret pasted into
 * the wrong variable is not echoed.
 */
#[IsReadOnly]
class CheckSetup extends Tool
{
    /**
     * A reference that never exists. A 404 for it proves the key and the
     * environment are accepted, without reading or creating anything.
     */
    public const string PING_REFERENCE = 'plorea-mcp-ping';

    /**
     * Laravel's CSRF middleware classes, by exact name. See isCsrfMiddleware().
     */
    protected const array CSRF_MIDDLEWARE = [
        self::LARAVEL_MIDDLEWARE.'PreventRequestForgery',
        self::LARAVEL_MIDDLEWARE.'ValidateCsrfToken',
        self::LARAVEL_MIDDLEWARE.'VerifyCsrfToken',
    ];

    protected const string LARAVEL_MIDDLEWARE = 'Illuminate\\Foundation\\Http\\Middleware\\';

    protected string $description = 'Check the Plorea SDK configuration: API key and environment match, tenant, platform, webhook secret, signature verification, webhook route and CSRF, retries, and the Adyen client key. Reports pass, warn, fail or n/a per item with a fix. Never prints a key or a secret. Pass ping=true to make one read-only API call that proves the key and the environment are accepted.';

    public function __construct(
        protected Repository $config,
        protected Router $router,
        protected Kernel $kernel,
        protected PloreaManager $plorea,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ping' => $schema->boolean()
                ->description('Make one read-only API call (a status lookup for a reference that does not exist) to prove the key and the environment are accepted. Default false.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $checks = [
            $this->apiKey(),
            $this->environment(),
            $this->tenant(),
            $this->platform(),
            ...$this->webhooks(),
            $this->retries(),
            $this->adyenClientKey(),
        ];

        if ($request->get('ping') === true) {
            $checks[] = $this->ping();
        }

        $counts = array_count_values(array_column($checks, 'status'));

        return Response::json([
            'summary' => [
                'pass' => $counts['pass'] ?? 0,
                'warn' => $counts['warn'] ?? 0,
                'fail' => $counts['fail'] ?? 0,
                'n/a' => $counts['n/a'] ?? 0,
            ],
            'checks' => $checks,
        ]);
    }

    /**
     * @return array{check: string, status: string, detail: string, fix: string|null}
     */
    protected function apiKey(): array
    {
        if ($this->string('plorea.api_key') === '') {
            return $this->fail('api_key', 'No API key is configured.', 'Set PLOREA_API_KEY.');
        }

        if ($this->keyEnvironment() === null) {
            return $this->warn('api_key', 'The API key does not start with plr_test_ or plr_live_.', 'Check that PLOREA_API_KEY is a Plorea API key, not the webhook secret or the tenant id.');
        }

        return $this->pass('api_key', 'An API key is configured.');
    }

    /**
     * @return array{check: string, status: string, detail: string, fix: string|null}
     */
    protected function environment(): array
    {
        $environment = $this->configuredEnvironment()?->value;

        if ($environment === null) {
            return $this->fail('environment', 'PLOREA_ENVIRONMENT is neither "test" nor "live" (the SDK compares the exact value, so spaces and capitals count). Every API call throws.', 'Set PLOREA_ENVIRONMENT to test or live.');
        }

        $keyEnvironment = $this->keyEnvironment();

        if ($keyEnvironment !== null && $keyEnvironment !== $environment) {
            return $this->fail(
                'environment',
                "The API key is a plr_{$keyEnvironment}_ key, but PLOREA_ENVIRONMENT is \"{$environment}\". Since 2026-10-13 Plorea answers 403 on every call when the two do not match.",
                "Set PLOREA_ENVIRONMENT={$keyEnvironment}. Do not rotate the key: it is not the problem.",
            );
        }

        return $this->pass('environment', "The environment is \"{$environment}\".");
    }

    /**
     * @return array{check: string, status: string, detail: string, fix: string|null}
     */
    protected function tenant(): array
    {
        if ($this->string('plorea.tenant_id') === '') {
            return $this->fail('tenant_id', 'No tenant id is configured.', 'Set PLOREA_TENANT_ID to the tenant id Plorea issued.');
        }

        return $this->pass('tenant_id', 'A tenant id is configured.');
    }

    /**
     * @return array{check: string, status: string, detail: string, fix: string|null}
     */
    protected function platform(): array
    {
        if ($this->string('plorea.platform') === '') {
            return $this->warn('platform', 'No platform identifier is configured. Plorea asks for one on every request.', 'Set PLOREA_PLATFORM to your own identifier. It has no default.');
        }

        return $this->pass('platform', 'A platform identifier is configured.');
    }

    /**
     * @return list<array{check: string, status: string, detail: string, fix: string|null}>
     */
    protected function webhooks(): array
    {
        if (! (bool) $this->config->get('plorea.webhooks.enabled', true)) {
            return [
                $this->notApplicable('webhooks', 'The SDK webhook route is disabled (PLOREA_WEBHOOKS_ENABLED=false). The webhook checks are skipped.'),
            ];
        }

        return [
            $this->webhookVerification(),
            $this->webhookSecret(),
            $this->webhookRoute(),
            $this->guarded('webhook_csrf', $this->webhookCsrf(...)),
        ];
    }

    /**
     * @return array{check: string, status: string, detail: string, fix: string|null}
     */
    protected function webhookVerification(): array
    {
        if ($this->config->get('plorea.webhooks.verify', true) !== false) {
            return $this->pass('webhook_verify', 'Webhook signatures are verified.');
        }

        if ($this->configuredEnvironment() === Environment::Live) {
            return $this->fail('webhook_verify', 'Webhook verification is off in the live environment. Anyone who knows the URL can post fake events.', 'Remove PLOREA_WEBHOOK_VERIFY=false and set PLOREA_WEBHOOK_SECRET.');
        }

        return $this->warn('webhook_verify', 'Webhook verification is off. This is a stopgap for a test setup without the signing secret.', 'Set PLOREA_WEBHOOK_SECRET and remove PLOREA_WEBHOOK_VERIFY=false before you go live.');
    }

    /**
     * @return array{check: string, status: string, detail: string, fix: string|null}
     */
    protected function webhookSecret(): array
    {
        if ($this->string('plorea.webhooks.secret') !== '') {
            return $this->pass('webhook_secret', 'A webhook signing secret is configured.');
        }

        if ($this->config->get('plorea.webhooks.verify', true) === false) {
            return $this->warn('webhook_secret', 'No webhook signing secret is configured. Deliveries are accepted only because verification is off.', 'Ask Plorea for the signing secret and set PLOREA_WEBHOOK_SECRET.');
        }

        return $this->fail('webhook_secret', 'No webhook signing secret is configured. The webhook route rejects every delivery, and Plorea never redelivers a rejected one.', 'Ask Plorea for the signing secret and set PLOREA_WEBHOOK_SECRET. It is not the API key and not the tenant id.');
    }

    /**
     * @return array{check: string, status: string, detail: string, fix: string|null}
     */
    protected function webhookRoute(): array
    {
        if (! $this->route() instanceof Route) {
            return $this->fail('webhook_route', 'The plorea.webhook route is not registered.', 'Run php artisan route:clear, or rebuild the route cache after you install the SDK.');
        }

        return $this->pass('webhook_route', 'The plorea.webhook route is registered.');
    }

    /**
     * @return array{check: string, status: string, detail: string, fix: string|null}
     */
    protected function webhookCsrf(): array
    {
        $route = $this->route();

        if (! $route instanceof Route) {
            return $this->notApplicable('webhook_csrf', 'The webhook route is not registered.');
        }

        $global = method_exists($this->kernel, 'getGlobalMiddleware') ? $this->kernel->getGlobalMiddleware() : [];

        $csrf = array_filter(
            [...$this->router->gatherRouteMiddleware($route), ...$global],
            fn (mixed $name): bool => is_string($name) && $this->isCsrfMiddleware($name),
        );

        if ($csrf !== []) {
            return $this->warn('webhook_csrf', 'The webhook route runs the web middleware group or a CSRF middleware. Unless its path is excluded from CSRF, every delivery gets 419.', 'Remove "web" from plorea.webhooks.middleware, or exclude the webhook path from CSRF.');
        }

        return $this->pass('webhook_csrf', 'The webhook route runs no CSRF middleware.');
    }

    /**
     * @return array{check: string, status: string, detail: string, fix: string|null}
     */
    protected function retries(): array
    {
        $times = (int) $this->config->get('plorea.http.retry.times', 0);

        if ($times > 0) {
            return $this->warn('retries', 'PLOREA_RETRY_TIMES is above 0. Payment link creation, subscription creation and charges have no idempotency, so a retry can bill twice.', 'Set PLOREA_RETRY_TIMES=0 unless every write you retry is idempotent.');
        }

        return $this->pass('retries', 'Automatic retries are off.');
    }

    /**
     * @return array{check: string, status: string, detail: string, fix: string|null}
     */
    protected function adyenClientKey(): array
    {
        $key = $this->string('plorea.adyen_client_key');

        if ($key === '') {
            return $this->notApplicable('adyen_client_key', 'No Adyen client key is configured. It is needed only for embedded or native checkout.');
        }

        $environment = $this->configuredEnvironment()?->value;
        $keyEnvironment = match (true) {
            str_starts_with($key, 'test_') => 'test',
            str_starts_with($key, 'live_') => 'live',
            default => null,
        };

        if ($keyEnvironment === null) {
            return $this->warn('adyen_client_key', 'The Adyen client key does not start with test_ or live_.', 'Check PLOREA_ADYEN_CLIENT_KEY against the key Plorea issued.');
        }

        if ($environment === null) {
            return $this->notApplicable('adyen_client_key', 'The environment is invalid, so the Adyen client key cannot be compared with it. Fix the environment check first.');
        }

        if ($keyEnvironment !== $environment) {
            return $this->fail('adyen_client_key', "The Adyen client key is a {$keyEnvironment}_ key, but PLOREA_ENVIRONMENT is \"{$environment}\". Embedded checkout will not load.", "Use the {$environment} Adyen client key from Plorea.");
        }

        return $this->pass('adyen_client_key', "The Adyen client key matches the \"{$environment}\" environment.");
    }

    /**
     * @return array{check: string, status: string, detail: string, fix: string|null}
     */
    protected function ping(): array
    {
        try {
            $this->plorea->payments()->status(self::PING_REFERENCE);
        } catch (NotFoundException $e) {
            if ($e->response?->json('error') !== 'Payment not found') {
                return $this->warn('ping', 'The ping got a 404 that is not Plorea\'s "Payment not found" answer. A proxy or a wrong base URL can answer 404 before Plorea checks the key.', 'Check PLOREA_BASE_URL. It must be the API host without a path.');
            }

            return $this->pass('ping', 'Plorea accepted the key and the environment (its "Payment not found" answer for the ping reference is the expected one).');
        } catch (AuthenticationException $e) {
            return $this->fail('ping', "Plorea answered {$e->status}. The key, the environment or the tenant is wrong.", 'Check the environment check above first. A key and an environment that do not match give 403.');
        } catch (ConnectionException) {
            return $this->fail('ping', 'Plorea could not be reached.', 'Check PLOREA_BASE_URL and the network.');
        } catch (PloreaException $e) {
            return $this->warn('ping', 'Plorea gave an unexpected answer: '.$e::class.'.', 'Run field-note with the exception class.');
        } catch (Throwable $e) {
            // The message can carry the request, and so the API key: name the class only.
            return $this->fail('ping', 'The ping could not be sent: '.$e::class.'. The message is not shown, because it can contain the API key.', 'Check PLOREA_API_KEY and PLOREA_BASE_URL for stray spaces, quotes or line breaks.');
        }

        return $this->warn('ping', 'Plorea answered 200 for a reference that should not exist.', 'Nothing to fix in the setup. Someone created a payment with the ping reference.');
    }

    protected function keyEnvironment(): ?string
    {
        $key = $this->string('plorea.api_key');

        return match (true) {
            str_starts_with($key, 'plr_test_') => 'test',
            str_starts_with($key, 'plr_live_') => 'live',
            default => null,
        };
    }

    /**
     * The environment the way the client resolves it: an Environment
     * instance, or the exact string, with "test" when nothing is set.
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
     * Whether a resolved middleware class is Laravel's CSRF check, or an
     * app's subclass of it.
     *
     * Laravel 12 ships VerifyCsrfToken and ValidateCsrfToken; Laravel 13
     * adds PreventRequestForgery and makes the other two deprecated
     * subclasses of it. Matching by exact name, not by class constant,
     * covers both versions without touching a deprecated class.
     */
    protected function isCsrfMiddleware(string $name): bool
    {
        $class = ltrim(Str::before($name, ':'), '\\');

        if (! class_exists($class)) {
            return false;
        }

        // Reflection gives the declared name: it undoes a leading backslash,
        // another letter case and a class_alias(), which a string compare misses.
        for ($reflection = new ReflectionClass($class); $reflection instanceof ReflectionClass; $reflection = $reflection->getParentClass()) {
            if (in_array($reflection->getName(), self::CSRF_MIDDLEWARE, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Runs one check and turns any error into a fixed warning.
     *
     * Resolving middleware can autoload classes the app names, and an
     * autoload error can quote a configured value. The message is never shown.
     *
     * @param  callable(): array{check: string, status: string, detail: string, fix: string|null}  $check
     * @return array{check: string, status: string, detail: string, fix: string|null}
     */
    protected function guarded(string $name, callable $check): array
    {
        try {
            return $check();
        } catch (Throwable $e) {
            return $this->warn($name, 'The check could not run: '.$e::class.'. The message is not shown, because it can contain a configured value.', 'Run php artisan route:list --name=plorea.webhook to see the middleware the webhook route runs.');
        }
    }

    protected function route(): ?Route
    {
        return $this->router->getRoutes()->getByName('plorea.webhook');
    }

    protected function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return array{check: string, status: string, detail: string, fix: string|null}
     */
    protected function pass(string $check, string $detail): array
    {
        return ['check' => $check, 'status' => 'pass', 'detail' => $detail, 'fix' => null];
    }

    /**
     * @return array{check: string, status: string, detail: string, fix: string|null}
     */
    protected function warn(string $check, string $detail, string $fix): array
    {
        return ['check' => $check, 'status' => 'warn', 'detail' => $detail, 'fix' => $fix];
    }

    /**
     * @return array{check: string, status: string, detail: string, fix: string|null}
     */
    protected function fail(string $check, string $detail, string $fix): array
    {
        return ['check' => $check, 'status' => 'fail', 'detail' => $detail, 'fix' => $fix];
    }

    /**
     * @return array{check: string, status: string, detail: string, fix: string|null}
     */
    protected function notApplicable(string $check, string $detail): array
    {
        return ['check' => $check, 'status' => 'n/a', 'detail' => $detail, 'fix' => null];
    }
}
