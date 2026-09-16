<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Enums\RecurringType;
use MemberFlow\Plorea\Facades\Plorea;

/*
 * A local Drop-in page for Plorea's TEST environment. It proves the client key
 * and origin whitelisting end to end, the way a consuming app would use them.
 */
Route::prefix('plorea')->group(function (): void {
    Route::get('/', fn () => view('plorea', ['clientKeySet' => filled(config('plorea.adyen_client_key'))]));

    Route::get('checkout/{kind}', function (string $kind) {
        abort_unless(config('plorea.environment') === 'test', 403, 'Workbench runs against the test environment only.');

        $returnUrl = url("plorea/return/{$kind}");

        if ($kind === 'payment') {
            $link = Plorea::payments()
                ->link('wb-'.Str::lower((string) Str::ulid()), 'Workbench checkout', Amount::nok(1000), $returnUrl)
                ->merchant(orgNr: (string) env('PLOREA_WORKBENCH_MERCHANT_ORG_NR'))
                ->create();

            $session = Plorea::payByLink()->session($link->id, $returnUrl.'?ref='.$link->reference);
            $lookup = $link->reference;
        } else {
            $session = Plorea::paymentMethods()
                ->setup('wb-shopper', RecurringType::Subscription, $returnUrl)
                ->session();
            $lookup = $session->paymentMethodId;
            $returnUrl .= '?ref='.$lookup;
        }

        return view('plorea-checkout', [
            'kind' => $kind,
            'checkout' => $session,
            'statusUrl' => url("plorea/status/{$kind}/".rawurlencode($lookup)),
        ]);
    })->whereIn('kind', ['payment', 'setup']);

    Route::get('return/{kind}', fn (Request $request, string $kind) => view('plorea-checkout', [
        'kind' => $kind,
        'checkout' => null,
        'statusUrl' => $request->filled('ref') ? url("plorea/status/{$kind}/".rawurlencode((string) $request->query('ref'))) : null,
    ]))->whereIn('kind', ['payment', 'setup']);

    // The server-side re-read a real app does instead of trusting the client.
    Route::get('status/{kind}/{ref}', fn (string $kind, string $ref) => [
        'status' => $kind === 'payment'
            ? Plorea::payments()->status($ref)->status
            : Plorea::paymentMethods()->find($ref)->status,
    ])
        ->whereIn('kind', ['payment', 'setup']);
});
