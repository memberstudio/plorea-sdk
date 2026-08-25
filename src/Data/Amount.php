<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Number;

/**
 * A monetary amount in minor units (e.g. øre — 4 500 kr is 450000).
 *
 * @implements Arrayable<string, int|string>
 */
final readonly class Amount implements Arrayable
{
    public function __construct(
        public int $value,
        public string $currency = 'NOK',
    ) {}

    public static function nok(int $ore): self
    {
        return new self($ore, 'NOK');
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null || ! isset($data['value']) || ! is_numeric($data['value'])) {
            return null;
        }

        return new self(
            value: (int) $data['value'],
            currency: is_string($data['currency'] ?? null) ? $data['currency'] : 'NOK',
        );
    }

    /**
     * The amount in major units (e.g. 450000 øre is 4500.0 kr).
     */
    public function inMajorUnits(): float
    {
        return $this->value / 100;
    }

    public function formatted(?string $locale = null): string
    {
        return (string) (Number::currency($this->inMajorUnits(), in: $this->currency, locale: $locale) ?: sprintf('%s %.2F', $this->currency, $this->inMajorUnits()));
    }

    /**
     * @return array{value: int, currency: string}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'currency' => $this->currency,
        ];
    }
}
