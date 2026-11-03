<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Mcp\Tools;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use MemberFlow\Plorea\Enums\Environment;
use MemberFlow\Plorea\Exceptions\AuthenticationException;
use MemberFlow\Plorea\Exceptions\ConnectionException;
use MemberFlow\Plorea\Exceptions\NotFoundException;
use MemberFlow\Plorea\Http\PloreaClient;
use MemberFlow\Plorea\Mcp\Masker;
use MemberFlow\Plorea\PloreaManager;
use Throwable;

/**
 * Reads one payment, payment link, subscription, charge history or payment
 * method from Plorea, and shows it through the Masker.
 *
 * Every read is a GET. The tool refuses when the configuration points at
 * live, unless PLOREA_MCP_ALLOW_LIVE is true. Masking is on unless
 * PLOREA_MCP_MASK is false. A value that is not clearly true or false keeps
 * the safe setting: live stays blocked and masking stays on.
 */
#[IsReadOnly]
#[IsOpenWorld]
class Lookup extends Tool
{
    /**
     * The most rows one list answer returns.
     */
    protected const int LIMIT = 50;

    /**
     * @var list<string>
     */
    protected const array KINDS = [
        'payment',
        'payment_link',
        'subscription',
        'subscription_charges',
        'subscriptions_for_external_id',
        'payment_method',
    ];

    protected string $description = 'Read one Plorea object with a GET request: a payment status by reference (payment), a pay page by payment link id (payment_link), a subscription by id (subscription), the charges of a subscription by subscription id (subscription_charges), the subscriptions for an external id (subscriptions_for_external_id) or a payment method by id (payment_method). Personal data is masked as ****. The tool refuses in live unless the app allows it. It never creates, charges, refunds or cancels anything.';

    public function __construct(
        protected Repository $config,
        protected PloreaManager $plorea,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()
                ->enum(self::KINDS)
                ->description('What to read.')
                ->required(),
            'id' => $schema->string()
                ->description('The reference, payment link id, subscription id, external id or payment method id.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $kind = (string) $request->get('kind', '');
        $id = trim((string) $request->get('id', ''));

        if (! in_array($kind, self::KINDS, true)) {
            return Response::error('Pass kind: one of '.implode(', ', self::KINDS).'.');
        }

        if ($id === '') {
            return Response::error('Pass the id to read.');
        }

        if (($refusal = $this->refusal()) !== null) {
            return Response::error($refusal);
        }

        try {
            return Response::json(['kind' => $kind, 'masked' => $this->masking(), ...$this->read($kind, $id)]);
        } catch (NotFoundException) {
            return Response::json([
                'kind' => $kind,
                'found' => false,
                'detail' => 'Plorea answered 404. The id is unknown, or it belongs to the other environment (test or live).',
            ]);
        } catch (AuthenticationException) {
            return Response::error('Plorea refused the API key (401 or 403). Run check-setup.');
        } catch (ConnectionException) {
            return Response::error('Plorea could not be reached. Nothing was read.');
        } catch (Throwable $e) {
            return Response::error('The read failed: '.$e::class.'. The message is not shown, because it can contain a configured value or customer data.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function read(string $kind, string $id): array
    {
        $masker = new Masker($this->masking());

        if ($kind === 'subscription_charges' || $kind === 'subscriptions_for_external_id') {
            $items = $kind === 'subscription_charges'
                ? $this->plorea->subscriptions()->charges($id)->sortByDesc('createdAt')->values()
                : $this->plorea->subscriptions()->forExternalId($id);

            return [
                'found' => $items->isNotEmpty(),
                'count' => $items->count(),
                'items' => $items->take(self::LIMIT)->map($masker->present(...))->all(),
            ];
        }

        $dto = match ($kind) {
            'payment' => $this->plorea->payments()->status($id),
            'payment_link' => $this->plorea->payByLink()->find($id),
            'subscription' => $this->plorea->subscriptions()->find($id),
            default => $this->plorea->paymentMethods()->find($id),
        };

        return ['found' => true, 'data' => $masker->present($dto)];
    }

    /**
     * Why the tool must not read, or null when it may.
     *
     * Live counts when either the environment or the key prefix says live:
     * since 2026-10-13 the key decides the environment, so a live key with
     * PLOREA_ENVIRONMENT unset still reads live data. The client keeps the
     * configuration it was built with, so the client is asked too: the app
     * config can say test while the client still sends a live key.
     */
    protected function refusal(): ?string
    {
        $environment = $this->config->get('plorea.environment') ?? 'test';
        $environment = $environment instanceof Environment ? $environment : Environment::tryFrom(is_string($environment) ? $environment : '');

        if (! $environment instanceof Environment) {
            return 'PLOREA_ENVIRONMENT is not "test" or "live". Run check-setup.';
        }

        $key = $this->config->get('plorea.api_key');
        $client = $this->plorea->client();
        $isLive = $environment === Environment::Live
            || (is_string($key) && str_starts_with($key, 'plr_live_'))
            || ($client instanceof PloreaClient && $client->targetsLive());

        if ($isLive && ! $this->flag('plorea.mcp.allow_live', false)) {
            return 'The configuration points at live. lookup reads live data only when PLOREA_MCP_ALLOW_LIVE=true.';
        }

        return null;
    }

    protected function masking(): bool
    {
        return $this->flag('plorea.mcp.mask', true);
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
