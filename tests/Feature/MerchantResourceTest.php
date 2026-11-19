<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MemberFlow\Plorea\Data\Merchant;
use MemberFlow\Plorea\Enums\Environment;
use MemberFlow\Plorea\Exceptions\AuthenticationException;
use MemberFlow\Plorea\Exceptions\NotFoundException;
use MemberFlow\Plorea\Exceptions\PloreaException;
use MemberFlow\Plorea\Exceptions\RequestException;
use MemberFlow\Plorea\Exceptions\ServerException;
use MemberFlow\Plorea\Exceptions\ValidationException;
use MemberFlow\Plorea\Facades\Plorea;
use MemberFlow\Plorea\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class MerchantResourceTest extends TestCase
{
    public function test_it_reads_pages_without_losing_the_cursor_or_deferred_merchants(): void
    {
        Http::fake([
            'payments.plorea.no/partners/merchants?*' => Http::sequence()
                ->push([
                    'tenantId' => 'other-tenant',
                    'count' => 2,
                    'nextCursor' => 'opaque+/=&cursor',
                    'kycDeferred' => 1,
                    'checkedAt' => '2026-11-19T10:30:00Z',
                    'items' => [
                        ['merchantOrgNr' => '999999999', 'steps' => ['kyc' => ['status' => 'approved']]],
                        [
                            'merchantOrgNr' => '888888888',
                            'name' => null,
                            'email' => 'merchant@example.test',
                            'storeId' => null,
                            'steps' => [
                                'kyc' => ['status' => 'deferred'],
                                'store' => ['status' => 'missing'],
                                'cardMethods' => ['status' => 'no_store'],
                            ],
                        ],
                    ],
                ])
                ->push(['tenantId' => 'other-tenant', 'count' => 0, 'nextCursor' => null, 'kycDeferred' => 0, 'items' => []]),
        ]);

        $page = Plorea::merchants()->list(tenantId: 'other-tenant', limit: 2);

        $this->assertSame('other-tenant', $page->tenantId);
        $this->assertSame(2, $page->count);
        $this->assertSame(1, $page->kycDeferred);
        $this->assertSame('2026-11-19 10:30:00', $page->checkedAt?->format('Y-m-d H:i:s'));
        $this->assertCount(2, $page->items);
        $this->assertTrue($page->items[0]->kyc->isApproved());
        $this->assertSame('888888888', $page->items[1]->merchantOrgNr);
        $this->assertSame('merchant@example.test', $page->items[1]->email);
        $this->assertTrue($page->items[1]->kyc->is('deferred'));
        $this->assertNull($page->items[1]->storeId);
        $this->assertSame(['status' => 'missing'], $page->items[1]->steps['store']);
        $this->assertSame(['status' => 'no_store'], $page->items[1]->steps['cardMethods']);

        $last = Plorea::merchants()->list(tenantId: 'other-tenant', limit: 2, cursor: $page->nextCursor);

        $this->assertNull($last->nextCursor);
        $this->assertSame(0, $last->count);
        $this->assertSame(0, $last->kycDeferred);
        $this->assertTrue($last->items->isEmpty());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->data() === [
            'tenantId' => 'other-tenant', 'limit' => 2, 'cursor' => 'opaque+/=&cursor', 'platform' => 'memberflow',
        ]);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_status_reads_live_kyc_instead_of_stale_stored_status(): void
    {
        Http::fake([
            'payments.plorea.no/partners/merchants/999999999?*' => Http::response([
                'merchantOrgNr' => '999999999',
                'tenantId' => 'other-tenant',
                'name' => 'Fake Merchant AS',
                'kycStatus' => 'pending',
                'kycUrl' => 'https://example.test/verify',
                'storeId' => 'ST_FAKE',
                'checkedAt' => '2026-11-19T12:00:00Z',
                'steps' => ['kyc' => [
                    'status' => 'approved', 'legalEntityId' => 'LE_FAKE', 'verificationStatus' => 'valid', 'problems' => [],
                ]],
            ]),
        ]);

        $merchant = Plorea::merchants()->status('999999999', tenantId: 'other-tenant');

        $this->assertTrue($merchant->kyc->isApproved());
        $this->assertSame('LE_FAKE', $merchant->kyc->legalEntityId);
        $this->assertSame('valid', $merchant->kyc->verificationStatus);
        $this->assertSame('pending', $merchant->raw['kycStatus']);
        $this->assertSame('other-tenant', $merchant->tenantId);
        $this->assertSame('Fake Merchant AS', $merchant->name);
        $this->assertSame('ST_FAKE', $merchant->storeId);
        $this->assertSame('https://example.test/verify', $merchant->kycUrl);
        $this->assertNull($merchant->created);
        $this->assertNull($merchant->email);
        $this->assertSame('2026-11-19 12:00:00', $merchant->checkedAt?->format('Y-m-d H:i:s'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->data() === [
            'tenantId' => 'other-tenant', 'platform' => 'memberflow',
        ]);
        Http::assertSentCount(1);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function kycStates(): iterable
    {
        foreach (['pending', 'action_required', 'missing', 'deferred', 'unknown', 'future_status', null] as $status) {
            yield $status ?? 'absent' => [$status];
        }
    }

    #[DataProvider('kycStates')]
    public function test_it_preserves_kyc_states_and_problem_codes_without_inferring_approval(?string $status): void
    {
        Plorea::fake(['GET partners/merchants/*' => [
            'kycStatus' => 'approved',
            'steps' => ['kyc' => [
                'status' => $status,
                'problems' => [
                    ['code' => '1_77', 'type' => 'pendingStatus', 'message' => 'Pending'],
                    ['entityType' => 'Document', 'entityId' => 'DOC_FAKE', 'code' => 'future_code', 'type' => 'future_type', 'message' => 'Provider prose', 'extra' => 'kept'],
                ],
            ]],
        ]]);

        $merchant = Plorea::merchants()->status('999999999');

        $this->assertSame($status, $merchant->kyc->status);
        $this->assertFalse($merchant->kyc->isApproved());
        $this->assertCount(2, $merchant->kyc->problems);
        $this->assertSame('1_77', $merchant->kyc->problems[0]->code);
        $this->assertSame('pendingStatus', $merchant->kyc->problems[0]->type);
        $problem = $merchant->kyc->problems[1];
        $this->assertSame('Document', $problem->entityType);
        $this->assertSame('DOC_FAKE', $problem->entityId);
        $this->assertSame('future_code', $problem->code);
        $this->assertSame('future_type', $problem->type);
        $this->assertSame('Provider prose', $problem->message);
        $this->assertSame('kept', $problem->raw['extra']);
        Plorea::assertNotSent('POST *');
    }

    public function test_missing_steps_do_not_fall_back_to_stored_kyc_status(): void
    {
        Plorea::fake(['GET partners/merchants/*' => ['kycStatus' => 'approved']]);

        $merchant = Plorea::merchants()->status('999999999');

        $this->assertNull($merchant->kyc->status);
        $this->assertFalse($merchant->kyc->isApproved());
        $this->assertSame([], $merchant->kyc->problems);
    }

    public function test_the_fake_has_defaults_and_building_onboarding_sends_nothing(): void
    {
        Plorea::fake();

        $pending = Plorea::merchants()->onboarding('999999999', 'merchant@example.test');
        $this->assertSame([
            'merchantOrgNr' => '999999999', 'email' => 'merchant@example.test', 'tenantId' => 'test-tenant',
        ], $pending->toPayload());
        Plorea::assertNothingSent();

        $page = Plorea::merchants()->list();
        $this->assertCount(1, $page->items);
        $this->assertInstanceOf(Merchant::class, $page->items[0]);
        $this->assertNull($page->nextCursor);
        $this->assertSame('test-tenant', $page->tenantId);
        $this->assertTrue(Plorea::merchants()->status('999999999')->kyc->is('pending'));
        Plorea::assertSent(fn ($request): bool => $request->matches('GET partners/merchants') && $request->data === [
            'tenantId' => 'test-tenant', 'limit' => 25, 'platform' => 'memberflow',
        ]);
        Plorea::assertNotSent('POST *');

        config(['plorea.environment' => Environment::Live]);
        $result = Plorea::merchants()->onboarding('999999999', 'merchant@example.test')->start();
        $this->assertTrue($result->created);
        $this->assertTrue($result->kyc->is('pending'));
        $this->assertSame('999999999', $result->merchantOrgNr);
        Plorea::assertSentCount(3);
        Http::assertNothingSent();
    }

    public function test_tenant_scoped_keys_can_omit_tenant_and_status_ids_are_encoded(): void
    {
        config(['plorea.tenant_id' => null, 'plorea.environment' => 'live']);
        Plorea::fake();

        Plorea::merchants()->list();
        Plorea::merchants()->status('org/with ?#');
        Plorea::merchants()->onboarding('999999999', 'merchant@example.test')->start();

        Plorea::assertSent('GET partners/merchants/org%2Fwith%20%3F%23');
        Plorea::assertSentCount(3);
        foreach (Plorea::recorded() as $request) {
            $this->assertArrayNotHasKey('tenantId', $request->data);
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonLiveEnvironments(): iterable
    {
        yield 'test' => ['test'];
        yield 'test enum' => [Environment::Test];
        yield 'unset' => [null];
        yield 'invalid' => ['production'];
    }

    #[DataProvider('nonLiveEnvironments')]
    public function test_onboarding_requires_explicit_live_configuration_even_with_a_fake(mixed $environment): void
    {
        config(['plorea.environment' => $environment]);
        Plorea::fake();

        try {
            Plorea::merchants()->onboarding('999999999', 'merchant@example.test')->start();
            $this->fail('Expected the live-only guard to refuse onboarding.');
        } catch (PloreaException $exception) {
            $this->assertStringContainsString('starts real verification', $exception->getMessage());
        }

        Plorea::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_onboarding_posts_only_on_start_and_preserves_partial_and_repeat_results(): void
    {
        config(['plorea.environment' => 'live', 'plorea.api_key' => 'plr_live_fake_key']);
        Http::fake(['payments.plorea.no/partners/merchants' => Http::sequence()
            ->push(['merchantOrgNr' => '999999999', 'created' => true, 'steps' => [
                'kyc' => ['status' => 'pending'], 'store' => ['status' => 'missing'],
                'cardMethods' => ['status' => 'partial', 'errors' => ['Order refused']],
            ]], 201)
            ->push(['merchantOrgNr' => '999999999', 'created' => false, 'steps' => ['kyc' => ['status' => 'action_required']]], 200),
        ]);

        $pending = Plorea::merchants()->onboarding('999999999', 'merchant@example.test')
            ->tenant('other-tenant')->name('Fake Merchant AS')->phone('+4700000000')->country('NO')->cardDescriptor('Fake Merchant');
        Http::assertNothingSent();
        $created = $pending->start();
        $this->assertTrue($created->created);
        $this->assertFalse($created->kyc->isApproved());
        $this->assertSame(['status' => 'missing'], $created->steps['store']);
        $this->assertSame(['status' => 'partial', 'errors' => ['Order refused']], $created->steps['cardMethods']);

        $repeat = $pending->start();
        $this->assertFalse($repeat->created);
        $this->assertTrue($repeat->kyc->is('action_required'));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->hasHeader('X-Environment', 'live')
            && $request->data() === [
                'merchantOrgNr' => '999999999', 'email' => 'merchant@example.test', 'tenantId' => 'other-tenant',
                'name' => 'Fake Merchant AS', 'phone' => '+4700000000', 'country' => 'NO',
                'cardDescriptor' => 'Fake Merchant', 'platform' => 'memberflow',
            ]);
    }

    /**
     * @return iterable<string, array{string, int, class-string<RequestException>}>
     */
    public static function errors(): iterable
    {
        foreach (['list', 'status', 'onboarding'] as $operation) {
            $statuses = $operation === 'status' ? [401, 404] : [400, 401, 403, 404, 502];
            if ($operation === 'onboarding') {
                $statuses[] = 409;
            }

            foreach ($statuses as $status) {
                yield $operation.' '.$status => [$operation, $status, match ($status) {
                    400 => ValidationException::class,
                    401, 403 => AuthenticationException::class,
                    404 => NotFoundException::class,
                    502 => ServerException::class,
                    default => RequestException::class,
                }];
            }
        }
    }

    /**
     * @param  class-string<RequestException>  $exceptionClass
     */
    #[DataProvider('errors')]
    public function test_errors_propagate_without_retrying_or_starting_onboarding(string $operation, int $status, string $exceptionClass): void
    {
        config(['plorea.environment' => 'live']);
        $message = $status === 409 ? 'Merchant is onboarded by another partner' : 'Merchant not found';
        Http::fake(['payments.plorea.no/partners/merchants*' => Http::response(['error' => $message], $status)]);

        try {
            match ($operation) {
                'list' => Plorea::merchants()->list(),
                'status' => Plorea::merchants()->status('999999999'),
                default => Plorea::merchants()->onboarding('999999999', 'merchant@example.test')->start(),
            };
            $this->fail('Expected an API exception.');
        } catch (RequestException $exception) {
            $this->assertInstanceOf($exceptionClass, $exception);
            $this->assertSame($status, $exception->status);
            $this->assertSame($message, $exception->getMessage());
        }

        Http::assertSentCount(1);
        if ($operation !== 'onboarding') {
            Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        }
    }
}
