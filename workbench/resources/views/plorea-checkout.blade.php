<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plorea workbench · {{ $kind }}</title>
    @include('plorea-style')
    @php($adyen = 'https://checkoutshopper-test.cdn.adyen.com/checkoutshopper/sdk/'.env('PLOREA_WORKBENCH_ADYEN_WEB_VERSION', '6.45.0'))
    <link rel="stylesheet" href="{{ $adyen }}/adyen.css">
    <script src="{{ $adyen }}/adyen.js"></script>
</head>
<body>
<main>
    <p><a href="{{ url('plorea') }}">← Workbench</a></p>
    <h1>{{ $kind === 'payment' ? 'Pay a link' : 'Store a card' }}</h1>
    <div id="dropin"></div>
    <p>Server status: <strong id="status">—</strong></p>
    <pre id="log"></pre>
</main>
<script>
    const log = (...lines) => document.getElementById('log').textContent += lines.join(' ') + '\n';
    const statusUrl = @json($statusUrl);
    const checkout = @json($checkout);
    const params = new URLSearchParams(location.search);

    async function refreshStatus() {
        if (!statusUrl) return;
        const body = await fetch(statusUrl).then((r) => r.json());
        document.getElementById('status').textContent = body.status;
    }

    const handlers = {
        onPaymentCompleted: (result) => { log('onPaymentCompleted', result.resultCode); refreshStatus(); },
        onPaymentFailed: (result) => { log('onPaymentFailed', result?.resultCode); refreshStatus(); },
        onError: (error) => log('onError', error.name, error.message),
    };

    (async () => {
        const { AdyenCheckout, Dropin } = window.AdyenWeb;

        if (checkout) {
            log('session', checkout.sessionId, 'environment', checkout.environment, 'clientKey', checkout.clientKey ? 'set' : 'MISSING');
            const instance = await AdyenCheckout({
                clientKey: checkout.clientKey,
                environment: checkout.environment,
                session: { id: checkout.sessionId, sessionData: checkout.sessionData },
                ...handlers,
            });
            new Dropin(instance).mount('#dropin');
        } else if (params.get('redirectResult') && params.get('sessionId')) {
            log('returned with redirectResult');
            const instance = await AdyenCheckout({
                clientKey: @json(config('plorea.adyen_client_key')),
                environment: @json(config('plorea.environment')),
                session: { id: params.get('sessionId') },
                ...handlers,
            });
            instance.submitDetails({ details: { redirectResult: params.get('redirectResult') } });
        }

        refreshStatus();
    })().catch((error) => log('failed', error.message));
</script>
</body>
</html>
