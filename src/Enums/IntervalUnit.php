<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Enums;

enum IntervalUnit: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
}
