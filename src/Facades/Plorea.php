<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Facades;

use Illuminate\Support\Facades\Facade;
use MemberFlow\Plorea\PloreaManager;

/**
 * @method static \MemberFlow\Plorea\Resources\PaymentResource payments()
 * @method static \MemberFlow\Plorea\Resources\PaymentMethodResource paymentMethods()
 * @method static \MemberFlow\Plorea\Resources\SubscriptionResource subscriptions()
 * @method static \MemberFlow\Plorea\Resources\PayByLinkResource payByLink()
 * @method static \MemberFlow\Plorea\Contracts\Client client()
 * @method static \MemberFlow\Plorea\Testing\FakeClient fake(array<string, array<string, mixed>|callable|\Throwable> $stubs = [])
 * @method static bool isFaked()
 * @method static \Illuminate\Support\Collection<int, \MemberFlow\Plorea\Testing\RecordedRequest> recorded()
 * @method static void assertSent(string|\Closure $matcher)
 * @method static void assertNotSent(string|\Closure $matcher)
 * @method static void assertNothingSent()
 * @method static void assertSentCount(int $count)
 *
 * @see PloreaManager
 */
class Plorea extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PloreaManager::class;
    }
}
