<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Application\Reports\GatewayLogReportGenerator;
use App\Models\GatewayLog;
use App\Models\LogSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GatewayLogReportGeneratorTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_it_generates_the_three_reports_with_deterministic_content(): void
    {
        $source = $this->createSource();
        $this->createLog($source, 0, '11111111-1111-3111-8111-111111111111', 'alpha', 50, 10, 100);
        $this->createLog($source, 100, '11111111-1111-3111-8111-111111111111', 'alpha', 70, 20, 200);
        $this->createLog($source, 200, '22222222-2222-3222-8222-222222222222', 'beta', 90, 30, 300);
        $directory = $this->temporaryDirectory();

        $result = $this->generator()->generate($directory);

        self::assertSame(2, $result->consumerRows);
        self::assertSame(2, $result->serviceRows);
        self::assertSame(2, $result->latencyRows);
        self::assertSame(<<<'CSV'
consumer_id,total_requests
11111111-1111-3111-8111-111111111111,2
22222222-2222-3222-8222-222222222222,1

CSV, file_get_contents($result->requestsByConsumerPath));
        self::assertSame(<<<'CSV'
service_name,total_requests
alpha,2
beta,1

CSV, file_get_contents($result->requestsByServicePath));
        self::assertSame(<<<'CSV'
service_name,average_request_latency,average_proxy_latency,average_gateway_latency
alpha,150.00,60.00,15.00
beta,300.00,90.00,30.00

CSV, file_get_contents($result->averageLatencyByServicePath));
    }

    public function test_empty_reports_contain_only_their_headers(): void
    {
        $result = $this->generator()->generate($this->temporaryDirectory());

        self::assertSame(0, $result->consumerRows);
        self::assertSame(0, $result->serviceRows);
        self::assertSame(0, $result->latencyRows);
        self::assertSame("consumer_id,total_requests\n", file_get_contents($result->requestsByConsumerPath));
        self::assertSame("service_name,total_requests\n", file_get_contents($result->requestsByServicePath));
        self::assertSame(
            "service_name,average_request_latency,average_proxy_latency,average_gateway_latency\n",
            file_get_contents($result->averageLatencyByServicePath),
        );
    }

    public function test_it_escapes_commas_and_quotes_according_to_csv_rules(): void
    {
        $source = $this->createSource();
        $this->createLog(
            source: $source,
            offset: 0,
            consumerId: '11111111-1111-3111-8111-111111111111',
            serviceName: 'billing, "legacy"',
            proxy: 50,
            gateway: 10,
            request: 100,
        );

        $result = $this->generator()->generate($this->temporaryDirectory());

        self::assertSame(
            "service_name,total_requests\n\"billing, \"\"legacy\"\"\",1\n",
            file_get_contents($result->requestsByServicePath),
        );
    }

    public function test_it_groups_service_names_case_sensitively(): void
    {
        $source = $this->createSource();
        $this->createLog($source, 0, '11111111-1111-3111-8111-111111111111', 'Billing', 50, 10, 100);
        $this->createLog($source, 100, '11111111-1111-3111-8111-111111111111', 'billing', 90, 30, 300);
        $this->createLog($source, 200, '11111111-1111-3111-8111-111111111111', 'bílling', 70, 20, 200);

        $result = $this->generator()->generate($this->temporaryDirectory());

        self::assertSame(3, $result->serviceRows);
        self::assertSame(3, $result->latencyRows);
        self::assertSame(<<<'CSV'
service_name,total_requests
billing,1
Billing,1
bílling,1

CSV, file_get_contents($result->requestsByServicePath));
        self::assertSame(<<<'CSV'
service_name,average_request_latency,average_proxy_latency,average_gateway_latency
billing,300.00,90.00,30.00
Billing,100.00,50.00,10.00
bílling,200.00,70.00,20.00

CSV, file_get_contents($result->averageLatencyByServicePath));
    }

    #[DataProvider('spreadsheetFormulaProvider')]
    public function test_it_neutralizes_spreadsheet_formulas_in_text_fields(string $serviceName): void
    {
        $source = $this->createSource();
        $this->createLog(
            $source,
            0,
            '11111111-1111-3111-8111-111111111111',
            $serviceName,
            50,
            10,
            100,
        );

        $result = $this->generator()->generate($this->temporaryDirectory());
        $rows = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', ''),
            file($result->requestsByServicePath, FILE_IGNORE_NEW_LINES) ?: [],
        );

        self::assertSame(['service_name', 'total_requests'], $rows[0]);
        self::assertSame("'$serviceName", $rows[1][0]);
        self::assertSame('1', $rows[1][1]);
        self::assertSame($serviceName, GatewayLog::query()->sole()->service_name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function spreadsheetFormulaProvider(): iterable
    {
        yield 'equals sign' => ['=1+1'];
        yield 'plus sign' => ['+1+1'];
        yield 'minus sign' => ['-1+1'];
        yield 'at sign' => ['@SUM(A1:A2)'];
        yield 'leading tab' => ["\ttexto"];
        yield 'formula after spaces' => ['  =1+1'];
    }

    public function test_regeneration_atomically_replaces_existing_report_files(): void
    {
        $source = $this->createSource();
        $this->createLog($source, 0, '11111111-1111-3111-8111-111111111111', 'alpha', 50, 10, 100);
        $directory = $this->temporaryDirectory();

        $this->generator()->generate($directory);
        $this->createLog($source, 100, '11111111-1111-3111-8111-111111111111', 'alpha', 70, 20, 200);
        $result = $this->generator()->generate($directory);

        self::assertSame("service_name,total_requests\nalpha,2\n", file_get_contents($result->requestsByServicePath));
        self::assertSame(
            [
                'average_latency_by_service.csv',
                'requests_by_consumer.csv',
                'requests_by_service.csv',
            ],
            array_values(array_diff(scandir($directory) ?: [], ['.', '..'])),
        );
    }

    public function test_all_reports_use_the_same_snapshot_during_a_concurrent_insert(): void
    {
        $source = $this->createSource();
        $this->createLog($source, 0, '11111111-1111-3111-8111-111111111111', 'alpha', 50, 10, 100);
        $directory = $this->temporaryDirectory();
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();

        self::assertNotNull($dispatcher);

        $connection->commit();
        $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

        try {
            $concurrentConnectionName = 'mysql_report_concurrent';
            $concurrentConfiguration = config('database.connections.mysql');

            self::assertIsArray($concurrentConfiguration);
            config(["database.connections.$concurrentConnectionName" => $concurrentConfiguration]);

            $concurrentConnection = DB::connection($concurrentConnectionName);
            $concurrentInsertCompleted = false;

            $dispatcher->listen(
                QueryExecuted::class,
                function (QueryExecuted $query) use (
                    &$concurrentInsertCompleted,
                    $concurrentConnection,
                    $source,
                ): void {
                    if (
                        $concurrentInsertCompleted
                        || $query->connectionName !== 'mysql'
                        || ! str_contains($query->sql, 'COUNT(*) AS total_requests')
                        || ! str_contains($query->sql, '`consumer_id`')
                    ) {
                        return;
                    }

                    $concurrentInsertCompleted = true;
                    $concurrentConnection->table('gateway_logs')->insert([
                        'log_source_id' => $source->id,
                        'source_offset' => 100,
                        'source_line' => 2,
                        'consumer_id' => '22222222-2222-3222-8222-222222222222',
                        'service_name' => 'beta',
                        'latency_proxy' => 90,
                        'latency_gateway' => 30,
                        'latency_request' => 300,
                        'created_at' => '2019-08-24 15:27:27.000',
                        'processed_at' => '2026-07-17 18:31:45.456',
                    ]);
                },
            );

            $result = $this->generator()->generate($directory);

            self::assertTrue($concurrentInsertCompleted);
            self::assertSame(1, $result->consumerRows);
            self::assertSame(1, $result->serviceRows);
            self::assertSame(1, $result->latencyRows);
            self::assertStringNotContainsString('beta', file_get_contents($result->requestsByServicePath));
            self::assertStringNotContainsString('beta', file_get_contents($result->averageLatencyByServicePath));
            $this->assertDatabaseCount('gateway_logs', 2);
        } finally {
            $dispatcher->forget(QueryExecuted::class);
            DB::purge('mysql_report_concurrent');
            config()->offsetUnset('database.connections.mysql_report_concurrent');
            $connection->table('gateway_logs')->delete();
            $connection->table('log_sources')->delete();
            $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $connection->beginTransaction();
        }
    }

    private function generator(): GatewayLogReportGenerator
    {
        return $this->app->make(GatewayLogReportGenerator::class);
    }

    private function createSource(): LogSource
    {
        return LogSource::query()->create([
            'fingerprint' => str_repeat('b', 64),
            'path' => '/tmp/report-source.ndjson',
            'file_size' => 1000,
        ]);
    }

    private function createLog(
        LogSource $source,
        int $offset,
        string $consumerId,
        string $serviceName,
        int $proxy,
        int $gateway,
        int $request,
    ): GatewayLog {
        return GatewayLog::query()->create([
            'log_source_id' => $source->id,
            'source_offset' => $offset,
            'source_line' => intdiv($offset, 100) + 1,
            'consumer_id' => $consumerId,
            'service_name' => $serviceName,
            'latency_proxy' => $proxy,
            'latency_gateway' => $gateway,
            'latency_request' => $request,
            'created_at' => CarbonImmutable::parse('2019-08-24 15:26:27', 'UTC'),
            'processed_at' => CarbonImmutable::parse('2026-07-17 18:30:45', 'UTC'),
        ]);
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/gateway-reports-'.bin2hex(random_bytes(8));
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
