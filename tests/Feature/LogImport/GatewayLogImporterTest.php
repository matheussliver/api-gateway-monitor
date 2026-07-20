<?php

declare(strict_types=1);

namespace Tests\Feature\LogImport;

use App\Application\LogImport\ConcurrentLogImport;
use App\Application\LogImport\GatewayLogImporter;
use App\Application\LogImport\InvalidLogFile;
use App\Contracts\Clock;
use App\Models\GatewayLog;
use App\Models\GatewayLogRejection;
use App\Models\LogSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class GatewayLogImporterTest extends TestCase
{
    use RefreshDatabase;

    private const int MAX_NDJSON_LINE_BYTES = 1_048_576;

    private GatewayLogImporter $importer;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Clock::class, new readonly class implements Clock
        {
            public function now(): CarbonImmutable
            {
                return CarbonImmutable::parse('2026-07-17 18:30:45.456', 'UTC');
            }
        });

        $this->importer = $this->app->make(GatewayLogImporter::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_it_imports_records_in_batches_and_updates_the_checkpoint(): void
    {
        $path = $this->createLogFile([
            $this->fixture('valid-seconds.ndjson'),
            $this->fixture('valid-milliseconds.ndjson'),
        ]);

        $result = $this->importer->import($path, batchSize: 1);

        self::assertSame(2, $result->importedRecords);
        self::assertSame(0, $result->startOffset);
        self::assertSame(filesize($path), $result->endOffset);
        self::assertSame(0, $result->startLine);
        self::assertSame(2, $result->endLine);

        $source = LogSource::query()->sole();
        self::assertSame(filesize($path), $source->last_processed_offset);
        self::assertSame(2, $source->last_processed_line);
        self::assertSame(filesize($path), $source->file_size);
        self::assertSame(hash_file('sha256', $path), $source->processed_prefix_hash);

        $logs = GatewayLog::query()->orderBy('source_line')->get();
        self::assertCount(2, $logs);
        self::assertSame(0, $logs[0]->source_offset);
        self::assertSame('ritchie', $logs[0]->service_name);
        self::assertSame('2019-08-24 15:26:27.000', $logs[0]->created_at->format('Y-m-d H:i:s.v'));
        self::assertSame('2015-06-02 01:50:22.425', $logs[1]->created_at->format('Y-m-d H:i:s.v'));
        self::assertSame('2026-07-17 18:30:45.456', $logs[1]->processed_at->format('Y-m-d H:i:s.v'));
    }

    public function test_reimporting_an_unchanged_file_does_not_duplicate_records(): void
    {
        $path = $this->createLogFile([
            $this->fixture('valid-seconds.ndjson'),
            $this->fixture('valid-milliseconds.ndjson'),
        ]);

        $firstResult = $this->importer->import($path);
        $secondResult = $this->importer->import($path);

        self::assertSame(2, $firstResult->importedRecords);
        self::assertSame(0, $secondResult->importedRecords);
        self::assertSame($secondResult->startOffset, $secondResult->endOffset);
        self::assertSame($secondResult->startLine, $secondResult->endLine);
        $this->assertDatabaseCount('log_sources', 1);
        $this->assertDatabaseCount('gateway_logs', 2);
    }

    public function test_it_imports_only_lines_appended_after_the_checkpoint(): void
    {
        $firstLine = $this->fixture('valid-seconds.ndjson');
        $path = $this->createLogFile([$firstLine]);

        $this->importer->import($path);
        $this->appendLines($path, [$this->fixture('valid-milliseconds.ndjson')]);

        $result = $this->importer->import($path);

        self::assertSame(1, $result->importedRecords);
        self::assertSame(strlen(rtrim($firstLine, "\r\n").PHP_EOL), $result->startOffset);
        self::assertSame(1, $result->startLine);
        self::assertSame(2, $result->endLine);
        $this->assertDatabaseCount('log_sources', 1);
        $this->assertDatabaseCount('gateway_logs', 2);
    }

    public function test_it_defers_an_unterminated_final_line_until_it_is_completed(): void
    {
        $line = rtrim($this->fixture('valid-seconds.ndjson'), "\r\n");
        $splitOffset = intdiv(strlen($line), 2);
        $path = $this->createRawLogFile(substr($line, 0, $splitOffset));

        $partialResult = $this->importer->import($path);

        self::assertSame(0, $partialResult->importedRecords);
        self::assertSame(0, $partialResult->rejectedRecords);
        self::assertSame(0, $partialResult->endOffset);
        self::assertSame(0, $partialResult->endLine);
        $this->assertDatabaseCount('gateway_logs', 0);
        $this->assertDatabaseCount('gateway_log_rejections', 0);

        $source = LogSource::query()->sole();
        self::assertSame(0, $source->last_processed_offset);
        self::assertSame(0, $source->last_processed_line);
        self::assertSame($splitOffset, $source->file_size);

        file_put_contents($path, substr($line, $splitOffset).PHP_EOL, FILE_APPEND);

        $completedResult = $this->importer->import($path);

        self::assertSame(1, $completedResult->importedRecords);
        self::assertSame(0, $completedResult->rejectedRecords);
        self::assertSame(filesize($path), $completedResult->endOffset);
        self::assertSame(1, $completedResult->endLine);
        $this->assertDatabaseCount('gateway_logs', 1);
        $this->assertDatabaseCount('gateway_log_rejections', 0);

        $source->refresh();
        self::assertSame(filesize($path), $source->last_processed_offset);
        self::assertSame(1, $source->last_processed_line);
    }

    public function test_it_accepts_the_line_size_limit_and_rejects_the_next_byte_without_stopping(): void
    {
        $maximumLine = $this->recordWithEncodedSize(
            self::MAX_NDJSON_LINE_BYTES - strlen(PHP_EOL),
        );
        $oversizedLine = $this->recordWithEncodedSize(
            self::MAX_NDJSON_LINE_BYTES - strlen(PHP_EOL) + 1,
        );
        $path = $this->createLogFile([
            $maximumLine,
            $oversizedLine,
            $this->fixture('valid-milliseconds.ndjson'),
        ]);

        $result = $this->importer->import($path, batchSize: 100);

        self::assertSame(2, $result->importedRecords);
        self::assertSame(1, $result->rejectedRecords);
        $this->assertDatabaseCount('gateway_logs', 2);
        $this->assertDatabaseCount('gateway_log_rejections', 1);

        $rejection = GatewayLogRejection::query()->sole();
        self::assertSame(2, $rejection->source_line);
        self::assertStringContainsString('excede o limite de 1048576 bytes', $rejection->reason);

        $source = LogSource::query()->sole();
        self::assertSame(3, $source->last_processed_line);
        self::assertSame(filesize($path), $source->last_processed_offset);
        self::assertSame(hash_file('sha256', $path), $source->processed_prefix_hash);
    }

    public function test_it_defers_an_oversized_unterminated_line_before_rejecting_it(): void
    {
        $oversizedLine = $this->recordWithEncodedSize((self::MAX_NDJSON_LINE_BYTES * 2) + 1);
        $path = $this->createRawLogFile($oversizedLine);

        $partialResult = $this->importer->import($path);

        self::assertSame(0, $partialResult->importedRecords);
        self::assertSame(0, $partialResult->rejectedRecords);
        self::assertSame(0, $partialResult->endOffset);
        self::assertSame(0, $partialResult->endLine);
        $this->assertDatabaseCount('gateway_logs', 0);
        $this->assertDatabaseCount('gateway_log_rejections', 0);

        file_put_contents(
            $path,
            PHP_EOL.rtrim($this->fixture('valid-milliseconds.ndjson'), "\r\n").PHP_EOL,
            FILE_APPEND,
        );

        $completedResult = $this->importer->import($path);

        self::assertSame(1, $completedResult->importedRecords);
        self::assertSame(1, $completedResult->rejectedRecords);
        self::assertSame(2, $completedResult->endLine);
        $this->assertDatabaseCount('gateway_logs', 1);
        $this->assertDatabaseCount('gateway_log_rejections', 1);
        self::assertStringContainsString(
            'excede o limite de 1048576 bytes',
            GatewayLogRejection::query()->sole()->reason,
        );

        $source = LogSource::query()->sole();
        self::assertSame(filesize($path), $source->last_processed_offset);
        self::assertSame(hash_file('sha256', $path), $source->processed_prefix_hash);
    }

    public function test_it_discards_an_oversized_line_with_bounded_memory(): void
    {
        $oversizedLine = $this->recordWithEncodedSize(self::MAX_NDJSON_LINE_BYTES * 16);
        $path = $this->createLogFile([$oversizedLine]);

        unset($oversizedLine);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $memoryBeforeImport = memory_get_usage();

        $result = $this->importer->import($path);

        $additionalPeakMemory = memory_get_peak_usage() - $memoryBeforeImport;

        self::assertSame(0, $result->importedRecords);
        self::assertSame(1, $result->rejectedRecords);
        self::assertLessThan(8 * 1024 * 1024, $additionalPeakMemory);
        $this->assertDatabaseCount('gateway_logs', 0);
        $this->assertDatabaseCount('gateway_log_rejections', 1);
    }

    public function test_an_invalid_line_is_rejected_without_stopping_later_records(): void
    {
        $firstLine = $this->fixture('valid-seconds.ndjson');
        $path = $this->createLogFile([
            $firstLine,
            $this->fixture('malformed.ndjson'),
            $this->fixture('valid-milliseconds.ndjson'),
        ]);

        $result = $this->importer->import($path, batchSize: 1);

        self::assertSame(2, $result->importedRecords);
        self::assertSame(1, $result->rejectedRecords);
        $this->assertDatabaseCount('gateway_logs', 2);
        $this->assertDatabaseCount('gateway_log_rejections', 1);

        $rejection = GatewayLogRejection::query()->sole();
        self::assertSame(2, $rejection->source_line);
        self::assertSame(strlen(rtrim($firstLine, "\r\n").PHP_EOL), $rejection->source_offset);
        self::assertStringContainsString('JSON inválido', $rejection->reason);
        self::assertSame(
            '2026-07-17 18:30:45.456',
            $rejection->processed_at->format('Y-m-d H:i:s.v'),
        );

        $source = LogSource::query()->sole();
        self::assertSame(3, $source->last_processed_line);
        self::assertSame(filesize($path), $source->last_processed_offset);
    }

    public function test_a_missing_required_field_is_recorded_in_the_same_batch_as_valid_records(): void
    {
        $path = $this->createLogFile([
            $this->fixture('valid-seconds.ndjson'),
            $this->fixture('missing-latency.ndjson'),
            $this->fixture('valid-milliseconds.ndjson'),
        ]);

        $result = $this->importer->import($path, batchSize: 100);

        self::assertSame(2, $result->importedRecords);
        self::assertSame(1, $result->rejectedRecords);
        $this->assertDatabaseCount('gateway_logs', 2);
        $this->assertDatabaseCount('gateway_log_rejections', 1);

        $rejection = GatewayLogRejection::query()->sole();
        self::assertSame(2, $rejection->source_line);
        self::assertStringContainsString('latencies.gateway', $rejection->reason);

        $source = LogSource::query()->sole();
        self::assertSame(3, $source->last_processed_line);
        self::assertSame(filesize($path), $source->last_processed_offset);
    }

    public function test_values_above_the_database_limits_are_rejected_without_aborting_the_batch(): void
    {
        $validLine = $this->fixture('valid-seconds.ndjson');
        $oversizedService = $this->recordFromFixture('valid-seconds.ndjson');
        $oversizedService['service']['name'] = str_repeat('a', 256);
        $oversizedLatency = $this->recordFromFixture('valid-seconds.ndjson');
        $oversizedLatency['latencies']['gateway'] = 4_294_967_296;
        $boundaryRecord = $this->recordFromFixture('valid-seconds.ndjson');
        $boundaryRecord['service']['name'] = str_repeat('á', 255);
        $boundaryRecord['latencies']['proxy'] = 4_294_967_295;
        $boundaryRecord['latencies']['gateway'] = 4_294_967_295;
        $boundaryRecord['latencies']['request'] = 4_294_967_295;

        $path = $this->createLogFile([
            $validLine,
            $this->encodeRecord($oversizedService),
            $this->encodeRecord($oversizedLatency),
            $this->encodeRecord($boundaryRecord),
        ]);

        $result = $this->importer->import($path, batchSize: 100);

        self::assertSame(2, $result->importedRecords);
        self::assertSame(2, $result->rejectedRecords);
        $this->assertDatabaseCount('gateway_logs', 2);
        $this->assertDatabaseCount('gateway_log_rejections', 2);

        $rejections = GatewayLogRejection::query()->orderBy('source_line')->get();
        self::assertSame(2, $rejections[0]->source_line);
        self::assertStringContainsString('service.name', $rejections[0]->reason);
        self::assertStringContainsString('no máximo 255 caracteres', $rejections[0]->reason);
        self::assertSame(3, $rejections[1]->source_line);
        self::assertStringContainsString('latencies.gateway', $rejections[1]->reason);
        self::assertStringContainsString('no máximo 4294967295', $rejections[1]->reason);

        $persistedBoundary = GatewayLog::query()->where('source_line', 4)->sole();
        self::assertSame(str_repeat('á', 255), $persistedBoundary->service_name);
        self::assertSame(4_294_967_295, $persistedBoundary->latency_proxy);
        self::assertSame(4_294_967_295, $persistedBoundary->latency_gateway);
        self::assertSame(4_294_967_295, $persistedBoundary->latency_request);

        $source = LogSource::query()->sole();
        self::assertSame(4, $source->last_processed_line);
        self::assertSame(filesize($path), $source->last_processed_offset);
    }

    public function test_unsupported_timestamps_are_rejected_without_aborting_the_batch(): void
    {
        $ambiguousTimestamp = $this->recordFromFixture('valid-seconds.ndjson');
        $ambiguousTimestamp['started_at'] = 31_536_000_000;
        $extremeTimestamp = $this->recordFromFixture('valid-seconds.ndjson');
        $extremeTimestamp['started_at'] = PHP_INT_MAX;

        $path = $this->createLogFile([
            $this->fixture('valid-seconds.ndjson'),
            $this->encodeRecord($ambiguousTimestamp),
            $this->encodeRecord($extremeTimestamp),
            $this->fixture('valid-milliseconds.ndjson'),
        ]);

        $result = $this->importer->import($path, batchSize: 100);

        self::assertSame(2, $result->importedRecords);
        self::assertSame(2, $result->rejectedRecords);
        $this->assertDatabaseCount('gateway_logs', 2);
        $this->assertDatabaseCount('gateway_log_rejections', 2);

        $rejections = GatewayLogRejection::query()->orderBy('source_line')->get();
        self::assertSame(2, $rejections[0]->source_line);
        self::assertStringContainsString('started_at', $rejections[0]->reason);
        self::assertStringContainsString('entre 2000-01-01 e 2099-12-31 UTC', $rejections[0]->reason);
        self::assertSame(3, $rejections[1]->source_line);
        self::assertStringContainsString('started_at', $rejections[1]->reason);
        self::assertStringContainsString('entre 2000-01-01 e 2099-12-31 UTC', $rejections[1]->reason);

        $source = LogSource::query()->sole();
        self::assertSame(4, $source->last_processed_line);
        self::assertSame(filesize($path), $source->last_processed_offset);
    }

    public function test_reimporting_a_file_with_a_rejection_does_not_duplicate_any_result(): void
    {
        $path = $this->createLogFile([
            $this->fixture('valid-seconds.ndjson'),
            $this->fixture('malformed.ndjson'),
            $this->fixture('valid-milliseconds.ndjson'),
        ]);

        $firstResult = $this->importer->import($path);
        $secondResult = $this->importer->import($path);

        self::assertSame(2, $firstResult->importedRecords);
        self::assertSame(1, $firstResult->rejectedRecords);
        self::assertSame(0, $secondResult->importedRecords);
        self::assertSame(0, $secondResult->rejectedRecords);
        $this->assertDatabaseCount('gateway_logs', 2);
        $this->assertDatabaseCount('gateway_log_rejections', 1);
    }

    public function test_it_rejects_a_file_truncated_before_its_checkpoint(): void
    {
        $firstLine = $this->fixture('valid-seconds.ndjson');
        $path = $this->createLogFile([
            $firstLine,
            $this->fixture('valid-milliseconds.ndjson'),
        ]);

        $this->importer->import($path);
        file_put_contents($path, rtrim($firstLine, "\r\n").PHP_EOL);

        $this->expectException(InvalidLogFile::class);
        $this->expectExceptionMessage('foi truncado');

        $this->importer->import($path);
    }

    public function test_it_rejects_a_file_whose_processed_prefix_was_modified(): void
    {
        $path = $this->createLogFile([
            $this->fixture('valid-seconds.ndjson'),
            $this->fixture('valid-milliseconds.ndjson'),
        ]);

        $this->importer->import($path);

        $originalContents = file_get_contents($path);

        if ($originalContents === false) {
            self::fail('Não foi possível ler o arquivo temporário de teste.');
        }

        $modifiedContents = str_replace('ritchie', 'ritchix', $originalContents, $replacementCount);

        self::assertSame(1, $replacementCount);
        self::assertSame(strlen($originalContents), strlen($modifiedContents));

        file_put_contents($path, $modifiedContents);
        clearstatcache(true, $path);

        try {
            $this->importer->import($path);
            self::fail('A alteração do prefixo processado deveria interromper a importação.');
        } catch (InvalidLogFile $failure) {
            self::assertStringContainsString('foi alterado antes do checkpoint', $failure->getMessage());
        }

        $this->assertDatabaseCount('gateway_logs', 2);
        $this->assertDatabaseCount('gateway_log_rejections', 0);
        self::assertSame('ritchie', GatewayLog::query()->where('source_line', 1)->sole()->service_name);

        $source = LogSource::query()->sole();
        self::assertSame(2, $source->last_processed_line);
        self::assertSame(filesize($path), $source->last_processed_offset);
    }

    public function test_it_initializes_the_prefix_hash_for_a_source_created_before_hash_tracking(): void
    {
        $path = $this->createLogFile([$this->fixture('valid-seconds.ndjson')]);

        $this->importer->import($path);

        $source = LogSource::query()->sole();
        $source->forceFill(['processed_prefix_hash' => null])->save();

        $result = $this->importer->import($path);

        self::assertSame(0, $result->importedRecords);
        self::assertSame(0, $result->rejectedRecords);
        $this->assertDatabaseCount('gateway_logs', 1);

        $source->refresh();
        self::assertSame(hash_file('sha256', $path), $source->processed_prefix_hash);
        self::assertSame(filesize($path), $source->last_processed_offset);
        self::assertSame(1, $source->last_processed_line);
    }

    public function test_it_rejects_a_concurrent_import_of_the_same_file(): void
    {
        $path = $this->createLogFile([$this->fixture('valid-seconds.ndjson')]);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            self::fail('Não foi possível abrir o arquivo temporário de teste.');
        }

        flock($handle, LOCK_EX);

        try {
            $this->expectException(ConcurrentLogImport::class);
            $this->importer->import($path);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function test_it_rejects_an_unreadable_or_missing_path(): void
    {
        $this->expectException(InvalidLogFile::class);

        $this->importer->import('/tmp/a-log-file-that-does-not-exist.ndjson');
    }

    public function test_an_empty_file_can_receive_records_later(): void
    {
        $path = $this->createLogFile([]);

        $emptyResult = $this->importer->import($path);
        $this->appendLines($path, [$this->fixture('valid-seconds.ndjson')]);
        $appendedResult = $this->importer->import($path);

        self::assertSame(0, $emptyResult->importedRecords);
        self::assertSame(1, $appendedResult->importedRecords);
        $this->assertDatabaseCount('log_sources', 1);
        $this->assertDatabaseCount('gateway_logs', 1);
    }

    public function test_it_rejects_a_non_positive_batch_size(): void
    {
        $path = $this->createLogFile([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maior que zero');

        $this->importer->import($path, batchSize: 0);
    }

    private function createLogFile(array $lines): string
    {
        return $this->createRawLogFile($this->linesToContents($lines));
    }

    private function createRawLogFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gateway-logs-');

        if ($path === false) {
            throw new RuntimeException('Não foi possível criar um arquivo de log temporário.');
        }

        $this->temporaryFiles[] = $path;
        file_put_contents($path, $contents);

        return $path;
    }

    private function appendLines(string $path, array $lines): void
    {
        file_put_contents($path, $this->linesToContents($lines), FILE_APPEND);
    }

    /**
     * @return array<string, mixed>
     */
    private function recordFromFixture(string $name): array
    {
        $record = json_decode($this->fixture($name), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($record);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function encodeRecord(array $record): string
    {
        return json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function recordWithEncodedSize(int $targetBytes): string
    {
        $record = $this->recordFromFixture('valid-seconds.ndjson');
        $record['padding'] = '';
        $emptyPaddingRecord = $this->encodeRecord($record);
        $paddingBytes = $targetBytes - strlen($emptyPaddingRecord);

        self::assertGreaterThanOrEqual(0, $paddingBytes);

        $record['padding'] = str_repeat('x', $paddingBytes);
        $encodedRecord = $this->encodeRecord($record);

        self::assertSame($targetBytes, strlen($encodedRecord));

        return $encodedRecord;
    }

    private function linesToContents(array $lines): string
    {
        return implode('', array_map(
            static fn (string $line): string => rtrim($line, "\r\n").PHP_EOL,
            $lines,
        ));
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/Logs/'.$name));

        if ($contents === false) {
            throw new RuntimeException("Não foi possível ler o arquivo de teste [$name].");
        }

        return $contents;
    }
}
