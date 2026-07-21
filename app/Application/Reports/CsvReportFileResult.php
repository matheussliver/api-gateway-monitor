<?php

declare(strict_types=1);

namespace App\Application\Reports;

final readonly class CsvReportFileResult
{
    public function __construct(
        public string $outputDirectory,
        public string $filename,
        public string $path,
        public int $rows,
    ) {}
}
