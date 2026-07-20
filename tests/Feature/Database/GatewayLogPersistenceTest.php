<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\GatewayLog;
use App\Models\GatewayLogRejection;
use App\Models\LogSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class GatewayLogPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_the_isolated_mysql_testing_database(): void
    {
        self::assertSame('mysql', DB::connection()->getDriverName());
        self::assertSame(getenv('DB_TEST_DATABASE'), DB::connection()->getDatabaseName());
    }

    public function test_it_persists_a_normalized_log_with_auditable_timestamps(): void
    {
        $source = $this->createSource();

        $log = GatewayLog::query()->create([
            'log_source_id' => $source->id,
            'source_offset' => 1234,
            'source_line' => 2,
            'consumer_id' => '72b34d31-4c14-3bae-9cc6-516a0939c9d6',
            'service_name' => 'ritchie',
            'latency_proxy' => 1836,
            'latency_gateway' => 8,
            'latency_request' => 1058,
            'created_at' => CarbonImmutable::parse('2019-08-24 14:06:27.123', 'UTC'),
            'processed_at' => CarbonImmutable::parse('2026-07-17 18:30:45.456', 'UTC'),
        ])->refresh();

        self::assertSame('2019-08-24 14:06:27.123', $log->created_at->format('Y-m-d H:i:s.v'));
        self::assertSame('2026-07-17 18:30:45.456', $log->processed_at->format('Y-m-d H:i:s.v'));
        self::assertSame(1836, $log->latency_proxy);
        self::assertFalse($log->usesTimestamps());
        self::assertTrue($log->source->is($source));
    }

    public function test_it_prevents_the_same_file_offset_from_being_persisted_twice(): void
    {
        $source = $this->createSource();
        $attributes = $this->validLogAttributes($source, sourceOffset: 1234);

        GatewayLog::query()->create($attributes);

        $this->expectException(QueryException::class);

        GatewayLog::query()->create($attributes);
    }

    public function test_deleting_a_source_cascades_to_its_logs(): void
    {
        $source = $this->createSource();
        $log = GatewayLog::query()->create($this->validLogAttributes($source));
        $rejection = GatewayLogRejection::query()->create([
            'log_source_id' => $source->id,
            'source_offset' => 2000,
            'source_line' => 3,
            'reason' => 'JSON inválido.',
            'processed_at' => CarbonImmutable::parse('2026-07-17 18:30:45.456', 'UTC'),
        ]);

        $source->delete();

        $this->assertDatabaseMissing('gateway_logs', ['id' => $log->id]);
        $this->assertDatabaseMissing('gateway_log_rejections', ['id' => $rejection->id]);
    }

    public function test_mysql_rejects_a_negative_latency(): void
    {
        $source = $this->createSource();
        $attributes = $this->validLogAttributes($source);
        $attributes['latency_gateway'] = -1;

        $this->expectException(QueryException::class);

        GatewayLog::query()->create($attributes);
    }

    private function createSource(): LogSource
    {
        return LogSource::query()->create([
            'fingerprint' => str_repeat('a', 64),
            'path' => '/var/www/html/logs.txt',
            'file_size' => 123654181,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validLogAttributes(LogSource $source, int $sourceOffset = 0): array
    {
        return [
            'log_source_id' => $source->id,
            'source_offset' => $sourceOffset,
            'source_line' => 1,
            'consumer_id' => '72b34d31-4c14-3bae-9cc6-516a0939c9d6',
            'service_name' => 'ritchie',
            'latency_proxy' => 1836,
            'latency_gateway' => 8,
            'latency_request' => 1058,
            'created_at' => CarbonImmutable::parse('2019-08-24 14:06:27', 'UTC'),
            'processed_at' => CarbonImmutable::parse('2026-07-17 18:30:45', 'UTC'),
        ];
    }
}
