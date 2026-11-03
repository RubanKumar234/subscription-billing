<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class BillingPeriod
{
    public static function forMonth(string $month): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException('Month must use YYYY-MM.');
        }

        $start = CarbonImmutable::parse($month.'-01 00:00:00', 'UTC');

        return [$start, $start->addMonth()];
    }
}
