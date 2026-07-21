<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\GatewayLog;
use App\Models\LogSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

final class GenerateGatewayLogReportsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_dir($path)) {
                File::deleteDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_it_generates_all_reports_and_prints_the_row_counts(): void
    {
        $this->createLog();
        $directory = $this->temporaryDirectory();

        $this->artisan('gateway-logs:reports', ['output' => $directory])
            ->expectsOutputToContain('Relatórios gerados em')
            ->expectsOutputToContain('requests_by_consumer.csv: 1 linha de dados')
            ->expectsOutputToContain('requests_by_service.csv: 1 linha de dados')
            ->expectsOutputToContain('average_latency_by_service.csv: 1 linha de dados')
            ->expectsOutputToContain('Pico de memória:')
            ->assertSuccessful();

        self::assertFileExists($directory.'/requests_by_consumer.csv');
        self::assertFileExists($directory.'/requests_by_service.csv');
        self::assertFileExists($directory.'/average_latency_by_service.csv');
    }

    #[DataProvider('individualReportProvider')]
    public function test_it_generates_only_the_selected_report(string $report, string $filename): void
    {
        $this->createLog();
        $directory = $this->temporaryDirectory();

        $this->artisan('gateway-logs:reports', [
            'output' => $directory,
            '--only' => $report,
        ])
            ->expectsOutputToContain('Relatório gerado em')
            ->expectsOutputToContain("$filename: 1 linha de dados")
            ->assertSuccessful();

        self::assertSame(
            [$filename],
            array_values(array_diff(scandir($directory) ?: [], ['.', '..'])),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function individualReportProvider(): iterable
    {
        yield 'consumidor' => ['consumer', 'requests_by_consumer.csv'];
        yield 'serviço' => ['service', 'requests_by_service.csv'];
        yield 'latência' => ['latency', 'average_latency_by_service.csv'];
    }

    public function test_it_rejects_an_invalid_individual_report_name(): void
    {
        $directory = $this->temporaryDirectory();

        $this->artisan('gateway-logs:reports', [
            'output' => $directory,
            '--only' => 'desconhecido',
        ])
            ->expectsOutputToContain(
                'Relatório inválido [desconhecido]. Use consumer, service ou latency.',
            )
            ->assertExitCode(Command::INVALID);

        self::assertDirectoryDoesNotExist($directory);
    }

    public function test_it_rejects_the_only_option_without_a_report_name(): void
    {
        $directory = $this->temporaryDirectory();

        $this->artisan('gateway-logs:reports', [
            'output' => $directory,
            '--only' => null,
        ])
            ->expectsOutputToContain('Relatório inválido []. Use consumer, service ou latency.')
            ->assertExitCode(Command::INVALID);

        self::assertDirectoryDoesNotExist($directory);
    }

    public function test_it_returns_failure_when_the_output_path_is_a_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gateway-report-output-');

        if ($path === false) {
            self::fail('Não foi possível criar um arquivo de saída temporário.');
        }

        $this->temporaryPaths[] = $path;

        $this->artisan('gateway-logs:reports', ['output' => $path])
            ->expectsOutputToContain('não é um diretório')
            ->assertExitCode(Command::FAILURE);
    }

    private function createLog(): void
    {
        $source = LogSource::query()->create([
            'fingerprint' => str_repeat('c', 64),
            'path' => '/tmp/command-report-source.ndjson',
            'file_size' => 1000,
        ]);

        GatewayLog::query()->create([
            'log_source_id' => $source->id,
            'source_offset' => 0,
            'source_line' => 1,
            'consumer_id' => '11111111-1111-3111-8111-111111111111',
            'service_name' => 'alpha',
            'latency_proxy' => 50,
            'latency_gateway' => 10,
            'latency_request' => 100,
            'created_at' => CarbonImmutable::parse('2019-08-24 15:26:27', 'UTC'),
            'processed_at' => CarbonImmutable::parse('2026-07-17 18:30:45', 'UTC'),
        ]);
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/gateway-command-reports-'.bin2hex(random_bytes(8));
        $this->temporaryPaths[] = $directory;

        return $directory;
    }
}
