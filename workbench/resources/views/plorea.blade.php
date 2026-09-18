<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plorea workbench</title>
    @include('plorea-style')
</head>
<body>
<main>
    <h1>Plorea workbench</h1>
    <p>Test environment only. Mounts Adyen Drop-in on this origin ({{ request()->getSchemeAndHttpHost() }}), which Plorea must have whitelisted for your client key.</p>
    @unless ($clientKeySet)
        <p class="warn">PLOREA_ADYEN_CLIENT_KEY is not set in workbench/.env — Drop-in cannot mount.</p>
    @endunless
    <ul>
        <li><a href="{{ url('plorea/checkout/payment') }}">Pay a 10 kr link with Drop-in</a></li>
        <li><a href="{{ url('plorea/checkout/setup') }}">Store a card with Drop-in</a></li>
    </ul>
    <p>Native sessions: <code>vendor/bin/testbench plorea:probe --app-return-url</code></p>
</main>
</body>
</html>
