<?php

declare(strict_types=1);

namespace App\Domain\GatewayLog;

use Carbon\CarbonImmutable;

final readonly class GatewayLogData
{
    public function __construct(
        public string $consumerId,
        public string $serviceName,
        public int $latencyProxy,
        public int $latencyGateway,
        public int $latencyRequest,
        public CarbonImmutable $createdAt,
    ) {}
}
