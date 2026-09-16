<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use MemberFlow\Plorea\Data\Amount;
use MemberFlow\Plorea\Enums\Channel;
use MemberFlow\Plorea\Enums\RecurringType;
use MemberFlow\Plorea\Exceptions\PloreaException;
use MemberFlow\Plorea\Exceptions\RequestException;
use MemberFlow\Plorea\Facades\Plorea;

/*
 * Opens real sessions against Plorea's TEST environment for every channel and
 * prints each response's shape with the values redacted. Raw responses go to
 * build/plorea-probe/ (git-ignored), to be anonymised into tests/Fixtures.
 */
Artisan::command('plorea:probe
    {--only= : "payment" or "setup"}
    {--app-return-url : Also try PLOREA_WORKBENCH_APP_RETURN_URL on native channels}', function (): int {
    /** @var Command $this */
    if (config('plorea.environment') !== 'test') {
        $this->error('Refusing to run: PLOREA_ENVIRONMENT must be "test".');

        return 1;
    }

    $dumpPath = dirname(__DIR__, 2).'/build/plorea-probe/'.now()->format('Ymd-His');
    File::ensureDirectoryExists($dumpPath);

    $webReturnUrl = rtrim((string) config('app.url'), '/').'/plorea/return';
    $appReturnUrl = (string) env('PLOREA_WORKBENCH_APP_RETURN_URL', 'plorea-workbench://checkout/return');

    $cases = [];

    foreach (Channel::cases() as $channel) {
        $cases[] = [$channel, $webReturnUrl, 'https'];

        if ($channel->isNative() && $this->option('app-return-url')) {
            $cases[] = [$channel, $appReturnUrl, 'app-url'];
        }
    }

    $redact = static fn (string $key, mixed $value): string => match (true) {
        $value === null => 'null',
        is_bool($value) => $value ? 'true' : 'false',
        in_array($key, ['status', 'environment', 'recurringType', 'channel', 'currency', 'clientType'], true) && is_scalar($value) => (string) $value,
        $key === 'clientKey' && is_string($value) => Str::before($value, '_').'_… ('.strlen($value).' chars)',
        is_string($value) => 'string('.strlen($value).')',
        is_array($value) => 'array('.count($value).')',
        default => get_debug_type($value),
    };

    $probe = function (string $label, Closure $request) use ($dumpPath, $redact): void {
        $file = $dumpPath.'/'.Str::slug($label);

        try {
            $raw = $request();
            File::put("{$file}.json", json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->components->twoColumnDetail("<fg=green>{$label}</>", 'OK');

            foreach ($raw as $key => $value) {
                $this->components->twoColumnDetail("  {$key}", $redact((string) $key, $value));
            }
        } catch (RequestException $e) {
            File::put("{$file}.error.json", json_encode(['status' => $e->status, 'body' => $e->response?->json() ?? $e->response?->body()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->components->twoColumnDetail("<fg=red>{$label}</>", "HTTP {$e->status}: ".Str::limit($e->getMessage(), 100));
        } catch (PloreaException $e) {
            $this->components->twoColumnDetail("<fg=red>{$label}</>", Str::limit($e->getMessage(), 100));
        }
    };

    $only = $this->option('only');

    if ($only === null || $only === 'payment') {
        $link = Plorea::payments()
            ->link('wb-'.Str::lower((string) Str::ulid()), 'Workbench probe', Amount::nok(1000), $webReturnUrl)
            ->merchant(orgNr: (string) env('PLOREA_WORKBENCH_MERCHANT_ORG_NR'))
            ->create();

        $this->info("Payment link {$link->reference}: {$link->url}");

        foreach ($cases as [$channel, $returnUrl, $kind]) {
            $probe("payment {$channel->value} {$kind}", fn (): array => Plorea::payByLink()->session($link->id, $returnUrl, $channel)->raw);
        }
    }

    if ($only === null || $only === 'setup') {
        foreach ($cases as [$channel, $returnUrl, $kind]) {
            $probe("setup {$channel->value} {$kind}", fn (): array => Plorea::paymentMethods()
                ->setup('wb-shopper', RecurringType::Subscription, $returnUrl)
                ->channel($channel)
                ->session()->raw);
        }
    }

    $this->newLine();
    $this->line("Raw responses: {$dumpPath}");
    $this->warn('They hold tenant and shopper data. Anonymise before copying anything into tests/Fixtures.');

    return 0;
})->purpose('Probe Plorea session shapes per channel (test environment only)');
