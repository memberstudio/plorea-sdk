<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Mcp\Tools;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Foundation\Application as FoundationApplication;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Facade;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use MemberFlow\Plorea\Enums\Environment;
use MemberFlow\Plorea\Events\PaymentStatusUpdated;
use MemberFlow\Plorea\Events\SubscriptionChargeFailed;
use MemberFlow\Plorea\Events\SubscriptionChargeSucceeded;
use MemberFlow\Plorea\Events\WebhookReceived;
use MemberFlow\Plorea\Mcp\Concerns\ReadsPloreaTarget;
use MemberFlow\Plorea\PloreaManager;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\VarDumper\VarDumper;
use Throwable;

/**
 * Signs a fake Plorea webhook and sends it through the app's own webhook
 * route, inside the PHP process.
 *
 * The body comes from resources/webhooks/{type}.json, a copy of a captured
 * delivery with fake ids, plus a fresh eventId and createdAt. The tool signs
 * it with the configured secret the way Plorea does, so the real
 * AuthenticateWebhook, controller and app listeners run. The tool sends
 * nothing over the network: Plorea may share one signing secret between
 * test and live, so a signed body must never leave the app. A listener of
 * the app that forwards deliveries still forwards this one.
 *
 * The delivery runs like a web request: terminable middleware and
 * afterResponse() work run before the tool answers. Output that a listener
 * prints is discarded, because it would corrupt the MCP stdio stream.
 * Afterwards the tool puts back the previous request and the router's
 * current route, and forgets the resolved auth guards.
 *
 * The tool refuses in production, in live and with an invalid environment,
 * with no setting to allow it: the app's listeners act on the event as if
 * Plorea sent it. The answer never holds the signature, the secret or the
 * response body.
 */
#[IsDestructive]
class SendTestWebhook extends Tool
{
    use ReadsPloreaTarget;

    /**
     * The captured types a template exists for, with the events the
     * controller dispatches for each. payment.refunded has no capture, so
     * it has no template.
     *
     * @var array<string, list<class-string>>
     */
    protected const array TYPES = [
        'payment.authorised' => [WebhookReceived::class, PaymentStatusUpdated::class],
        'payment.failed' => [WebhookReceived::class, PaymentStatusUpdated::class],
        'subscription.charge_succeeded' => [WebhookReceived::class, SubscriptionChargeSucceeded::class],
        'subscription.charge_failed' => [WebhookReceived::class, SubscriptionChargeFailed::class],
    ];

    /**
     * The overrides, with the types each one applies to.
     *
     * @var array<string, 'payment'|'subscription'>
     */
    protected const array OVERRIDES = [
        'reference' => 'payment',
        'subscription_id' => 'subscription',
        'charge_id' => 'subscription',
        'external_id' => 'subscription',
    ];

    protected string $description = 'Sign a fake Plorea webhook (payment.authorised, payment.failed, subscription.charge_succeeded or subscription.charge_failed) and send it through the app\'s own webhook route inside PHP, so the app\'s listeners run. Returns the status code and the events the delivery dispatches. Refuses in production and in live. Use it to test a listener, or to prove the webhook secret and the route work. The app\'s listeners act on the event as if Plorea sent it.';

    public function __construct(
        protected Repository $config,
        protected PloreaManager $plorea,
        protected Application $app,
        protected Router $router,
        protected UrlGenerator $url,
        protected Dispatcher $events,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->enum(array_keys(self::TYPES))
                ->description('The event type to send.')
                ->required(),
            'reference' => $schema->string()
                ->description('Payment types only: the payment reference your listener looks up. Default MCP-TEST-0001.'),
            'subscription_id' => $schema->string()
                ->description('Subscription types only: the subscription id. Default sub_mcp_test.'),
            'charge_id' => $schema->string()
                ->description('Subscription types only: the charge id. Default chg_mcp_test.'),
            'external_id' => $schema->string()
                ->description('Subscription types only: the external id your app gave the subscription. Default MCP-TEST-EXT.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $type = (string) $request->get('type', '');

        if (! array_key_exists($type, self::TYPES)) {
            return Response::error('Pass type: one of '.implode(', ', array_keys(self::TYPES)).'.');
        }

        $overrides = [];

        foreach (self::OVERRIDES as $name => $family) {
            $value = $request->get($name);

            if ($value === null || $value === '') {
                continue;
            }

            if (! str_starts_with($type, $family.'.')) {
                return Response::error("{$name} applies to {$family}.* types only.");
            }

            if (! is_string($value) || preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/', $value) !== 1) {
                return Response::error("{$name} must be 1 to 100 letters, digits or . _ : -");
            }

            $overrides[$name] = $value;
        }

        if (($refusal = $this->refusal()) !== null) {
            return Response::error($refusal);
        }

        $payload = $this->payload($type, $overrides);
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $secret = $this->config->get('plorea.webhooks.secret');
        $signed = $this->config->get('plorea.webhooks.verify', true) !== false && is_string($secret) && $secret !== '';

        try {
            $status = $this->deliver($type, (string) $payload['eventId'], $body, $signed ? base64_encode(hash_hmac('sha256', $body, $secret, true)) : null);
        } catch (Throwable $e) {
            return Response::error('The delivery failed before the app answered: '.$e::class.'. The message is not shown, because it can contain a configured value.');
        }

        /** @var array<string, mixed> $data */
        $data = $payload['data'];

        return Response::json([
            'type' => $type,
            'eventId' => $payload['eventId'],
            'reference' => $data['reference'],
            'signed' => $signed,
            'status' => $status['code'],
            'accepted' => $status['accepted'],
            'output_discarded' => $status['output'],
            'events' => array_map(fn (string $event): array => [
                'event' => $event,
                'has_listeners' => $this->events->hasListeners($event),
            ], self::TYPES[$type]),
            'detail' => $this->detail($status['code'], $status['accepted']),
        ]);
    }

    /**
     * Why the tool must not send, or null when it may.
     */
    protected function refusal(): ?string
    {
        $environment = $this->configuredEnvironment();

        if (! $environment instanceof Environment) {
            return 'PLOREA_ENVIRONMENT is not "test" or "live". Run check-setup.';
        }

        if ($this->targetsLive($environment)) {
            return 'The configuration points at live. send-test-webhook runs only in test, because the app\'s listeners act on the event.';
        }

        if ($this->app->isProduction()) {
            return 'APP_ENV is production. send-test-webhook runs only outside production, because the app\'s listeners act on the event.';
        }

        if (! $this->router->has('plorea.webhook')) {
            return 'The webhook route is not registered (PLOREA_WEBHOOKS_ENABLED is false, or the routes are cached without it). Run check-setup.';
        }

        if ($this->config->get('plorea.webhooks.verify', true) !== false) {
            $secret = $this->config->get('plorea.webhooks.secret');

            if (! is_string($secret) || $secret === '') {
                return 'PLOREA_WEBHOOK_SECRET is not set, so the webhook route refuses every delivery. Run check-setup.';
            }
        }

        return null;
    }

    /**
     * The template for the type, with a fresh eventId and createdAt and the
     * overrides applied.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(string $type, array $overrides): array
    {
        /** @var array{eventId: string, createdAt: string, tenantId: string, type: string, data: array<string, mixed>} $payload */
        $payload = json_decode((string) file_get_contents(__DIR__."/../../../resources/webhooks/{$type}.json"), true, flags: JSON_THROW_ON_ERROR);

        $tenantId = $this->config->get('plorea.tenant_id');

        $payload['eventId'] = 'evt_'.bin2hex(random_bytes(16));
        $payload['createdAt'] = now()->utc()->toIso8601ZuluString('millisecond');
        $payload['tenantId'] = is_string($tenantId) && $tenantId !== '' ? $tenantId : $payload['tenantId'];

        if (str_starts_with($type, 'payment.')) {
            $payload['data']['reference'] = $overrides['reference'] ?? $payload['data']['reference'];

            return $payload;
        }

        $payload['data']['subscriptionId'] = $overrides['subscription_id'] ?? $payload['data']['subscriptionId'];
        $payload['data']['chargeId'] = $overrides['charge_id'] ?? $payload['data']['chargeId'];
        $payload['data']['externalId'] = $overrides['external_id'] ?? $payload['data']['externalId'];
        $payload['data']['reference'] = $payload['data']['subscriptionId'].'-'.$payload['data']['chargeId'];

        return $payload;
    }

    /**
     * Sends the body through the HTTP kernel, the way the web server would,
     * and puts the process state back afterwards: the request, the current
     * route, the auth guards, the session, and every scoped() instance, such
     * as the deferred callbacks and the log Context. The delivery gets fresh
     * ones, as a new web request does. Output and
     * dump() calls are discarded, so they cannot reach the MCP stream. When
     * the delivery throws before terminate, its terminating callbacks are
     * dropped, as a crashed web request drops them. A base path in the app
     * URL is passed on, so a subdirectory install matches the route.
     *
     * @return array{code: int, accepted: bool, output: bool}
     */
    protected function deliver(string $type, string $eventId, string $body, ?string $signature): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PLOREA_EVENT' => $type,
            'HTTP_X_PLOREA_EVENT_ID' => $eventId,
        ];

        if ($signature !== null) {
            $server['HTTP_X_PLOREA_SIGNATURE'] = $signature;
        }

        $base = rtrim((string) parse_url($this->url->to('/'), PHP_URL_PATH), '/');

        if ($base !== '') {
            $server['SCRIPT_NAME'] = $base.'/index.php';
            $server['SCRIPT_FILENAME'] = 'index.php';
        }

        $request = HttpRequest::create($this->url->route('plorea.webhook'), 'POST', server: $server, content: $body);
        $previous = $this->app->bound('request') ? $this->app->make('request') : null;
        $router = $this->router;
        [$route, $routeRequest] = (fn (): array => [$this->current, $this->currentRequest])->call($router);
        $boundRoute = $this->app->bound(Route::class) ? $this->app->make(Route::class) : null;
        $guard = $this->config->get('auth.defaults.guard');
        $abstracts = $this->perRequestAbstracts();
        $scoped = $this->app instanceof Container
            ? (fn (): array => array_intersect_key($this->instances, array_flip($abstracts)))->call($this->app)
            : [];
        $sessions = $this->app->resolved('session') ? $this->app->make('session') : null;
        $openSessions = $sessions instanceof SessionManager ? $sessions->getDrivers() : [];
        $callbacks = $this->app instanceof FoundationApplication ? (fn (): int => count($this->terminatingCallbacks))->call($this->app) : 0;
        $dumped = false;
        $dumper = VarDumper::setHandler(function () use (&$dumped): void {
            $dumped = true;
        });
        $level = ob_get_level();
        ob_start();

        try {
            $this->forgetPerRequestInstances();

            if ($sessions instanceof SessionManager) {
                $sessions->forgetDrivers();
            }

            $kernel = $this->app->make(Kernel::class);
            $response = $kernel->handle($request);
            $this->terminate($kernel, $request, $response, $callbacks);
        } finally {
            $output = '';

            while (ob_get_level() > $level) {
                $output .= (string) ob_get_clean();
            }

            VarDumper::setHandler($dumper);

            if ($this->app instanceof FoundationApplication) {
                (fn (): array => array_splice($this->terminatingCallbacks, $callbacks))->call($this->app);
            }

            if ($previous !== null) {
                $this->app->instance('request', $previous);
            }

            Facade::clearResolvedInstance('request');
            (function () use ($route, $routeRequest): void {
                $this->current = $route;
                $this->currentRequest = $routeRequest;
            })->call($router);

            if ($boundRoute !== null) {
                $this->app->instance(Route::class, $boundRoute);
            } elseif ($this->app instanceof Container) {
                $this->app->forgetInstance(Route::class);
            }

            $this->forgetPerRequestInstances();

            if ($sessions instanceof SessionManager) {
                (function () use ($openSessions): void {
                    $this->drivers = $openSessions;
                })->call($sessions);
            }

            if ($this->app instanceof Container) {
                (function () use ($scoped): void {
                    $this->instances = [...$this->instances, ...$scoped];
                })->call($this->app);
            }

            $this->config->set('auth.defaults.guard', $guard);

            if ($this->app->resolved('auth')) {
                $this->app->make('auth')->forgetGuards();
            }

            if ($this->app instanceof Container) {
                $this->app->forgetInstance('auth.driver');
            }
        }

        return [
            'code' => $response->getStatusCode(),
            'accepted' => $response->getContent() === '[accepted]',
            'output' => $output !== '' || $dumped,
        ];
    }

    /**
     * Singletons that hold the state of one request. The session store and
     * the StartSession middleware keep session data, so a delivery that runs
     * session middleware gets fresh ones. The session manager itself stays,
     * because it holds the drivers that the app registered with extend();
     * only its open sessions are reset.
     *
     * @var list<string>
     */
    protected const PER_REQUEST = ['session.store', StartSession::class];

    /**
     * The names of the bindings that a new request gets fresh: every
     * scoped() binding and the PER_REQUEST singletons. A binding registered
     * with a typed closure is named by the closure's return types, as the
     * container does.
     *
     * @return list<string>
     */
    protected function perRequestAbstracts(): array
    {
        if (! $this->app instanceof Container) {
            return [];
        }

        /** @var list<string> */
        return (function (): array {
            $abstracts = SendTestWebhook::PER_REQUEST;

            foreach ($this->scopedInstances as $scoped) {
                array_push($abstracts, ...($scoped instanceof Closure ? $this->closureReturnTypes($scoped) : [$scoped]));
            }

            return array_values(array_unique($abstracts));
        })->call($this->app);
    }

    /**
     * Forgets every per-request instance and the facade cache in front of
     * it, so the next call resolves a fresh one, as Octane does between
     * requests.
     */
    protected function forgetPerRequestInstances(): void
    {
        if (! $this->app instanceof Container) {
            return;
        }

        $abstracts = $this->perRequestAbstracts();

        foreach ($abstracts as $abstract) {
            Facade::clearResolvedInstance($abstract);
        }

        (function () use ($abstracts): void {
            foreach ($abstracts as $abstract) {
                unset($this->instances[$abstract]);
            }
        })->call($this->app);
    }

    /**
     * Runs what a web server runs after it sends the response: terminable
     * middleware, and the terminating callbacks the delivery registered,
     * such as a job dispatched with afterResponse(). Callbacks that existed
     * before the delivery are set aside, so they run once, at the end of
     * the MCP process.
     */
    protected function terminate(Kernel $kernel, HttpRequest $request, SymfonyResponse $response, int $callbacks): void
    {
        if (! $this->app instanceof FoundationApplication) {
            return;
        }

        $app = $this->app;
        $existing = (fn (): array => array_splice($this->terminatingCallbacks, 0, $callbacks))->call($app);

        try {
            $kernel->terminate($request, $response);
        } finally {
            (function () use ($existing): void {
                $this->terminatingCallbacks = $existing;
            })->call($app);
        }
    }

    protected function detail(int $code, bool $accepted): string
    {
        return match (true) {
            $code === 200 && $accepted => 'The webhook route accepted the delivery and dispatched the events. Your listeners ran. A real Plorea delivery is never redelivered, so a listener must not depend on a retry.',
            $code === 403 => 'Most likely AuthenticateWebhook refused the signature: check PLOREA_WEBHOOK_SECRET, and check that no middleware on the route changes the raw body. A listener that aborts with 403 gives the same code, after earlier listeners ran.',
            $code === 419 => 'Most likely the CSRF check refused the delivery: exclude the webhook path from CSRF, or remove "web" from plorea.webhooks.middleware. Run check-setup.',
            $code === 404 || $code === 405 => 'Most likely another route or a fallback catches the path, so the delivery did not reach the webhook route. A listener that aborts with this code gives the same answer, after earlier listeners ran. Run check-setup.',
            $code >= 500 => 'The controller, a middleware or one of your listeners threw. See the app log. Earlier listeners may have run. A real Plorea delivery that fails is never redelivered.',
            default => 'The app answered '.$code.', not 200 [accepted]. A middleware on the route or a listener answered. Earlier listeners may have run. Run check-setup.',
        };
    }
}
