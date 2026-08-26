<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Data\Concerns;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

trait ParsesResponseData
{
    protected static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidFormatException) {
            // Response shapes are not fully documented; treat an unparsable
            // date like any other unexpected value instead of throwing.
            return null;
        }
    }

    protected static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    protected static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected static function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    protected static function bool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function metadata(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
