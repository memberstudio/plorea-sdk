<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Enums;

/**
 * The Adyen channel a checkout session is created for.
 *
 * `Web` covers the browser Drop-in and a WebView on the hosted pay page.
 * `IOS` and `Android` are for Adyen's native SDKs, which also need the app's
 * bundle id / package name whitelisted by Plorea.
 */
enum Channel: string
{
    case Web = 'Web';
    case IOS = 'iOS';
    case Android = 'Android';

    public function isNative(): bool
    {
        return $this !== self::Web;
    }
}
