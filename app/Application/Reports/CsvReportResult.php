<?php

declare(strict_types=1);

namespace App\Application\Reports;

final readonly class CsvReportResult
{
    public function __construct(
        public string $outputDirectory,
        public string $requestsByConsumerPath,
        public string $requestsByServicePath,
        public string $averageLatencyByServicePath,
        public int $consumerRows,
        public int $serviceRows,
        public int $latencyRows,
    ) {}
}
