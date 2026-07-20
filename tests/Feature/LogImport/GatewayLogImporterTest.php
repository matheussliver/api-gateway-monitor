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
        $path = tempnam(sys_get_temp_dir(), 'gateway-logs-');

        if ($path === false) {
            throw new RuntimeException('Não foi possível criar um arquivo de log temporário.');
        }

        $this->temporaryFiles[] = $path;
        file_put_contents($path, $this->linesToContents($lines));

        return $path;
    }

    private function appendLines(string $path, array $lines): void
    {
        file_put_contents($path, $this->linesToContents($lines), FILE_APPEND);
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
