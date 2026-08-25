<?php

declare(strict_types=1);

namespace MemberFlow\Plorea\Enums;

enum RecurringType: string
{
    case Subscription = 'Subscription';
    case CardOnFile = 'CardOnFile';
    case UnscheduledCardOnFile = 'UnscheduledCardOnFile';
}
