<?php

declare(strict_types=1);

namespace App\Contracts;

use Carbon\CarbonImmutable;

interface Clock
{
    public function now(): CarbonImmutable;
}
