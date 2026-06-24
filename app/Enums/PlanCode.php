<?php

declare(strict_types=1);

namespace App\Enums;

enum PlanCode: string
{
    case Starter = 'starter';
    case Growth = 'growth';
    case Business = 'business';
    case Enterprise = 'enterprise';
}
