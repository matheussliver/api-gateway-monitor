<?php

declare(strict_types=1);

namespace Tests\Benchmark;

use App\Application\LogImport\GatewayLogImporter;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class GatewayLogBatchPerformanceTest extends TestCase
{
    private const string DEFAULT_BATCH_SIZES = '1,100,1000,5000,10000,100000';

    private const string DEFAULT_REPORT_PATH = 'storage/app/benchmarks/batch-import-performance.csv';

    /** @var array<string, int> */
    private static array $lineCounts = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $reportPath = self::reportPath();
        $reportDirectory = dirname($reportPath);

        if (! is_dir($reportDirectory) && ! mkdir($reportDirectory, 0775, true) && ! is_dir($reportDirectory)) {
            throw new RuntimeException("Não foi possível criar o diretório do benchmark [$reportDirectory].");
        }

        if (getenv('BENCHMARK_APPEND_RESULTS') === '1' && is_file($reportPath)) {
            return;
        }

        $stream = fopen($reportPath, 'wb');

        if ($stream === false) {
            throw new RuntimeException("Não foi possível criar o relatório do benchmark [$reportPath].");
        }

        fputcsv($stream, [
            'batch_size',
            'total_lines',
            'imported_records',
            'rejected_records',
            'elapsed_seconds',
            'records_per_second',
            'peak_memory_mb',
            'incremental_peak_mb',
            'status',
            'error',
        ], ',', '"', '', "\n");

        fclose($stream);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function batchSizeProvider(): iterable
    {
        $configuredBatchSizes = getenv('BENCHMARK_BATCH_SIZES') ?: self::DEFAULT_BATCH_SIZES;

        foreach (explode(',', $configuredBatchSizes) as $configuredBatchSize) {
            $batchSize = filter_var(
                trim($configuredBatchSize),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );

            if ($batchSize === false) {
                throw new RuntimeException(
                    "Valor inválido em BENCHMARK_BATCH_SIZES [$configuredBatchSize].",
                );
            }

            yield "lote $batchSize" => [$batchSize];
        }
    }

    #[DataProvider('batchSizeProvider')]
    public function test_import_performance_for_batch_size(int $batchSize): void
    {
        $path = $this->benchmarkLogPath();
        $totalLines = $this->countLines($path);

        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

        gc_collect_cycles();
        memory_reset_peak_usage();

        $baselineMemory = memory_get_usage(true);
        $startedAt = hrtime(true);

        try {
            $result = $this->app->make(GatewayLogImporter::class)->import($path, $batchSize);
        } catch (Throwable $exception) {
            $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
            [$importedRecords, $rejectedRecords] = $this->persistedRecordCounts();

            $this->appendResult(
                batchSize: $batchSize,
                totalLines: $totalLines,
                importedRecords: $importedRecords,
                rejectedRecords: $rejectedRecords,
                elapsedSeconds: $elapsedSeconds,
                baselineMemory: $baselineMemory,
                status: 'failed',
                error: $this->rootCauseMessage($exception),
            );

            throw $exception;
        }

        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        $this->appendResult(
            batchSize: $batchSize,
            totalLines: $totalLines,
            importedRecords: $result->importedRecords,
            rejectedRecords: $result->rejectedRecords,
            elapsedSeconds: $elapsedSeconds,
            baselineMemory: $baselineMemory,
            status: 'passed',
            error: '',
        );

        self::assertSame($totalLines, $result->endLine - $result->startLine);
        self::assertSame($result->importedRecords, DB::table('gateway_logs')->count());
        self::assertSame(
            $result->rejectedRecords,
            DB::table('gateway_log_rejections')->count(),
        );
        self::assertSame($totalLines, $result->importedRecords + $result->rejectedRecords);
        self::assertSame(filesize($path), $result->endOffset);
    }

    private function benchmarkLogPath(): string
    {
        $configuredPath = getenv('BENCHMARK_LOG_PATH');
        $path = $configuredPath === false || trim($configuredPath) === ''
            ? base_path('logs.txt')
            : base_path($configuredPath);
        $canonicalPath = realpath($path);

        if ($canonicalPath === false || ! is_file($canonicalPath) || ! is_readable($canonicalPath)) {
            self::fail("O arquivo de log do benchmark [$path] não existe ou não possui permissão de leitura.");
        }

        return $canonicalPath;
    }

    private function countLines(string $path): int
    {
        if (isset(self::$lineCounts[$path])) {
            return self::$lineCounts[$path];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Não foi possível contar as linhas de [$path].");
        }

        $lineCount = 0;

        while (fgets($handle) !== false) {
            $lineCount++;
        }

        fclose($handle);

        return self::$lineCounts[$path] = $lineCount;
    }

    private function appendResult(
        int $batchSize,
        int $totalLines,
        ?int $importedRecords,
        ?int $rejectedRecords,
        float $elapsedSeconds,
        int $baselineMemory,
        string $status,
        string $error,
    ): void {
        $peakMemory = memory_get_peak_usage(true);
        $peakMemoryMb = $peakMemory / 1024 / 1024;
        $incrementalPeakMb = max(0, $peakMemory - $baselineMemory) / 1024 / 1024;
        $processedRecords = ($importedRecords ?? 0) + ($rejectedRecords ?? 0);
        $recordsPerSecond = $elapsedSeconds > 0 ? $processedRecords / $elapsedSeconds : 0;
        $reportPath = self::reportPath();
        $stream = fopen($reportPath, 'ab');

        if ($stream === false) {
            throw new RuntimeException("Não foi possível acrescentar dados ao relatório do benchmark [$reportPath].");
        }

        fputcsv($stream, [
            (string) $batchSize,
            (string) $totalLines,
            $importedRecords === null ? '' : (string) $importedRecords,
            $rejectedRecords === null ? '' : (string) $rejectedRecords,
            number_format($elapsedSeconds, 6, '.', ''),
            number_format($recordsPerSecond, 2, '.', ''),
            number_format($peakMemoryMb, 2, '.', ''),
            number_format($incrementalPeakMb, 2, '.', ''),
            $status,
            $error,
        ], ',', '"', '', "\n");

        fclose($stream);

        $statusDescription = match ($status) {
            'passed' => 'aprovado',
            'failed' => 'falhou',
            default => $status,
        };

        fwrite(STDOUT, sprintf(
            "\nBENCHMARK lote=%d situação=%s tempo=%.3fs taxa=%.2f registros/s pico=%.2fMB diferença=%.2fMB\n",
            $batchSize,
            $statusDescription,
            $elapsedSeconds,
            $recordsPerSecond,
            $peakMemoryMb,
            $incrementalPeakMb,
        ));
    }

    /**
     * @return array{int, int}
     */
    private function persistedRecordCounts(): array
    {
        try {
            return [
                DB::table('gateway_logs')->count(),
                DB::table('gateway_log_rejections')->count(),
            ];
        } catch (Throwable) {
            return [0, 0];
        }
    }

    private function rootCauseMessage(Throwable $exception): string
    {
        $rootCause = $exception;

        while ($rootCause->getPrevious() instanceof Throwable) {
            $rootCause = $rootCause->getPrevious();
        }

        return substr(
            str_replace(["\r", "\n"], ' ', $rootCause::class.': '.$rootCause->getMessage()),
            0,
            1000,
        );
    }

    private static function reportPath(): string
    {
        $configuredPath = getenv('BENCHMARK_REPORT_PATH');
        $relativePath = $configuredPath === false || trim($configuredPath) === ''
            ? self::DEFAULT_REPORT_PATH
            : trim($configuredPath);

        return dirname(__DIR__, 2).'/'.$relativePath;
    }
}
