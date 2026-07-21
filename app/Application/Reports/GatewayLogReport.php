<?php

declare(strict_types=1);

namespace App\Application\Reports;

enum GatewayLogReport: string
{
    case RequestsByConsumer = 'consumer';
    case RequestsByService = 'service';
    case AverageLatencyByService = 'latency';

    public function filename(): string
    {
        return match ($this) {
            self::RequestsByConsumer => 'requests_by_consumer.csv',
            self::RequestsByService => 'requests_by_service.csv',
            self::AverageLatencyByService => 'average_latency_by_service.csv',
        };
    }
}
