<?php

declare(strict_types=1);

namespace App\Application\LogImport;

use App\Contracts\Clock;
use App\Domain\GatewayLog\GatewayLogData;
use App\Domain\GatewayLog\GatewayLogNormalizer;
use App\Domain\GatewayLog\InvalidGatewayLogRecord;
use App\Infrastructure\LogFiles\InvalidNdjsonLine;
use App\Infrastructure\LogFiles\NdjsonLineParser;
use App\Models\LogSource;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class GatewayLogImporter
{
    private const int HASH_READ_CHUNK_SIZE = 1_048_576;

    public function __construct(
        private readonly NdjsonLineParser $parser,
        private readonly GatewayLogNormalizer $normalizer,
        private readonly Clock $clock,
    ) {}

    public function import(string $path, int $batchSize = 1000): ImportResult
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('O tamanho do lote de importação deve ser maior que zero.');
        }

        $canonicalPath = realpath($path);

        if ($canonicalPath === false || ! is_file($canonicalPath) || ! is_readable($canonicalPath)) {
            throw new InvalidLogFile("O arquivo de log [$path] não existe ou não possui permissão de leitura.");
        }

        $handle = @fopen($canonicalPath, 'rb');

        if ($handle === false) {
            throw new InvalidLogFile("O arquivo de log [$canonicalPath] não pôde ser aberto.");
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new ConcurrentLogImport("O arquivo de log [$canonicalPath] já está sendo importado.");
        }

        try {
            return $this->importLockedFile($handle, $canonicalPath, $batchSize);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function importLockedFile($handle, string $path, int $batchSize): ImportResult
    {
        $statistics = fstat($handle);

        if ($statistics === false) {
            throw new InvalidLogFile("Não foi possível inspecionar o arquivo de log [$path].");
        }

        $fileSize = (int) $statistics['size'];
        $fingerprint = hash('sha256', implode("\0", [
            $path,
            (string) $statistics['dev'],
            (string) $statistics['ino'],
        ]));

        $source = LogSource::query()->firstOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'path' => $path,
                'file_size' => $fileSize,
                'processed_prefix_hash' => hash('sha256', ''),
            ],
        );

        $startOffset = $source->last_processed_offset;
        $startLine = $source->last_processed_line;

        if ($fileSize < $startOffset) {
            throw new InvalidLogFile(
                "O arquivo de log [$path] foi truncado do byte [$startOffset] para [$fileSize].",
            );
        }

        $prefixHashContext = $this->verifyProcessedPrefix($handle, $source, $path);

        $batch = [];
        $rejections = [];
        $importedRecords = 0;
        $rejectedRecords = 0;
        $batchRecordCount = 0;
        $currentOffset = $startOffset;
        $currentLine = $startLine;
        $checkpointOffset = $startOffset;

        while ($currentOffset < $fileSize) {
            $lineOffset = $currentOffset;
            $line = fgets($handle);

            if ($line === false) {
                throw new InvalidLogFile("Não foi possível ler o byte [$lineOffset] de [$path].");
            }

            $currentOffset = ftell($handle);

            if ($currentOffset === false) {
                throw new InvalidLogFile("Não foi possível determinar o byte atual em [$path].");
            }

            if ($currentOffset >= $fileSize && ! str_ends_with($line, "\n")) {
                $currentOffset = $lineOffset;

                break;
            }

            hash_update($prefixHashContext, $line);
            $currentLine++;

            try {
                $data = $this->normalizer->normalize($this->parser->parse($line));
            } catch (InvalidNdjsonLine|InvalidGatewayLogRecord $failure) {
                $rejections[] = [
                    'reason' => $failure->getMessage(),
                    'offset' => $lineOffset,
                    'line' => $currentLine,
                ];

                $data = null;
            }

            if ($data !== null) {
                $batch[] = [
                    'data' => $data,
                    'offset' => $lineOffset,
                    'line' => $currentLine,
                ];
            }

            $batchRecordCount++;

            if ($batchRecordCount === $batchSize) {
                $this->persistBatch(
                    source: $source,
                    records: $batch,
                    rejections: $rejections,
                    expectedOffset: $checkpointOffset,
                    nextOffset: $currentOffset,
                    nextLine: $currentLine,
                    fileSize: $fileSize,
                    processedPrefixHash: $this->currentPrefixHash($prefixHashContext),
                );

                $importedRecords += count($batch);
                $rejectedRecords += count($rejections);
                $checkpointOffset = $currentOffset;
                $batch = [];
                $rejections = [];
                $batchRecordCount = 0;
            }
        }

        if ($batchRecordCount > 0) {
            $this->persistBatch(
                source: $source,
                records: $batch,
                rejections: $rejections,
                expectedOffset: $checkpointOffset,
                nextOffset: $currentOffset,
                nextLine: $currentLine,
                fileSize: $fileSize,
                processedPrefixHash: $this->currentPrefixHash($prefixHashContext),
            );

            $importedRecords += count($batch);
            $rejectedRecords += count($rejections);
        }

        if ($batchRecordCount === 0 && $source->processed_prefix_hash === null) {
            $this->persistBatch(
                source: $source,
                records: [],
                rejections: [],
                expectedOffset: $checkpointOffset,
                nextOffset: $currentOffset,
                nextLine: $currentLine,
                fileSize: $fileSize,
                processedPrefixHash: $this->currentPrefixHash($prefixHashContext),
            );
        }

        return new ImportResult(
            sourceId: $source->id,
            path: $path,
            importedRecords: $importedRecords,
            rejectedRecords: $rejectedRecords,
            startOffset: $startOffset,
            endOffset: $currentOffset,
            startLine: $startLine,
            endLine: $currentLine,
            fileSize: $fileSize,
        );
    }

    /**
     * @param  list<array{data: GatewayLogData, offset: int, line: int}>  $records
     * @param  list<array{reason: string, offset: int, line: int}>  $rejections
     */
    private function persistBatch(
        LogSource $source,
        array $records,
        array $rejections,
        int $expectedOffset,
        int $nextOffset,
        int $nextLine,
        int $fileSize,
        string $processedPrefixHash,
    ): void {
        DB::transaction(function () use (
            $source,
            $records,
            $rejections,
            $expectedOffset,
            $nextOffset,
            $nextLine,
            $fileSize,
            $processedPrefixHash,
        ): void {
            $lockedSource = LogSource::query()->lockForUpdate()->findOrFail($source->id);

            if ($lockedSource->last_processed_offset !== $expectedOffset) {
                throw new ConcurrentLogImport(
                    "O checkpoint da fonte de logs [{$source->id}] foi alterado durante a importação.",
                );
            }

            $processedAt = $this->clock->now()->setTimezone('UTC')->format('Y-m-d H:i:s.v');
            $rows = [];
            $rejectionRows = [];

            foreach ($records as $record) {
                $data = $record['data'];
                $rows[] = [
                    'log_source_id' => $source->id,
                    'source_offset' => $record['offset'],
                    'source_line' => $record['line'],
                    'consumer_id' => $data->consumerId,
                    'service_name' => $data->serviceName,
                    'latency_proxy' => $data->latencyProxy,
                    'latency_gateway' => $data->latencyGateway,
                    'latency_request' => $data->latencyRequest,
                    'created_at' => $data->createdAt->format('Y-m-d H:i:s.v'),
                    'processed_at' => $processedAt,
                ];
            }

            foreach ($rejections as $rejection) {
                $rejectionRows[] = [
                    'log_source_id' => $source->id,
                    'source_offset' => $rejection['offset'],
                    'source_line' => $rejection['line'],
                    'reason' => $rejection['reason'],
                    'processed_at' => $processedAt,
                ];
            }

            if ($rows !== []) {
                DB::table('gateway_logs')->insert($rows);
            }

            if ($rejectionRows !== []) {
                DB::table('gateway_log_rejections')->insert($rejectionRows);
            }

            $lockedSource->forceFill([
                'last_processed_offset' => $nextOffset,
                'last_processed_line' => $nextLine,
                'file_size' => $fileSize,
                'processed_prefix_hash' => $processedPrefixHash,
            ])->save();
        });

        $source->forceFill([
            'last_processed_offset' => $nextOffset,
            'last_processed_line' => $nextLine,
            'file_size' => $fileSize,
            'processed_prefix_hash' => $processedPrefixHash,
        ]);
    }

    /**
     * @param  resource  $handle
     */
    private function verifyProcessedPrefix($handle, LogSource $source, string $path): \HashContext
    {
        if (fseek($handle, 0) !== 0) {
            throw new InvalidLogFile("Não foi possível iniciar a verificação de integridade de [$path].");
        }

        $context = hash_init('sha256');
        $remainingBytes = $source->last_processed_offset;

        while ($remainingBytes > 0) {
            $chunk = fread($handle, min(self::HASH_READ_CHUNK_SIZE, $remainingBytes));

            if ($chunk === false || $chunk === '') {
                throw new InvalidLogFile(
                    "Não foi possível verificar o prefixo processado de [$path].",
                );
            }

            hash_update($context, $chunk);
            $remainingBytes -= strlen($chunk);
        }

        $actualHash = $this->currentPrefixHash($context);

        if (
            $source->processed_prefix_hash !== null
            && ! hash_equals($source->processed_prefix_hash, $actualHash)
        ) {
            throw new InvalidLogFile(
                "O arquivo de log [$path] foi alterado antes do checkpoint "
                ."no byte [{$source->last_processed_offset}].",
            );
        }

        return $context;
    }

    private function currentPrefixHash(\HashContext $context): string
    {
        return hash_final(hash_copy($context));
    }
}
