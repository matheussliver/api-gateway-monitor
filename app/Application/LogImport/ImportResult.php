<?php

declare(strict_types=1);

namespace App\Application\LogImport;

final readonly class ImportResult
{
    public function __construct(
        public int $sourceId,
        public string $path,
        public int $importedRecords,
        public int $rejectedRecords,
        public int $startOffset,
        public int $endOffset,
        public int $startLine,
        public int $endLine,
        public int $fileSize,
    ) {}
}
