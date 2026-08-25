<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Enums;

enum Environment: string
{
    case Test = 'test';
    case Live = 'live';

    public function isLive(): bool
    {
        return $this === self::Live;
    }
}
