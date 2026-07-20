<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Application\Reports\GatewayLogReportGenerator;
use App\Models\GatewayLog;
use App\Models\LogSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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
