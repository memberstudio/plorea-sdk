<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MemberFlow\Plorea\Http\Controllers\WebhookController;
use MemberFlow\Plorea\Http\Middleware\VerifyWebhookSignature;

Route::post((string) config('plorea.webhooks.path', 'plorea/webhook'), WebhookController::class)
    ->middleware([
        ...(array) config('plorea.webhooks.middleware', []),
        VerifyWebhookSignature::class,
    ])
    ->name('plorea.webhook');
