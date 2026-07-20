<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\GatewayLog;

use App\Domain\GatewayLog\GatewayLogData;
use App\Domain\GatewayLog\GatewayLogNormalizer;
use App\Domain\GatewayLog\InvalidGatewayLogRecord;
use App\Infrastructure\LogFiles\NdjsonLineParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GatewayLogNormalizerTest extends TestCase
{
    public function test_it_normalizes_the_real_consumer_shape_and_a_timestamp_in_seconds(): void
    {
        $data = $this->normalizeFixture('valid-seconds.ndjson');

        self::assertInstanceOf(GatewayLogData::class, $data);
        self::assertSame('72b34d31-4c14-3bae-9cc6-516a0939c9d6', $data->consumerId);
        self::assertSame('ritchie', $data->serviceName);
        self::assertSame(1836, $data->latencyProxy);
        self::assertSame(8, $data->latencyGateway);
        self::assertSame(1058, $data->latencyRequest);
        self::assertSame(1566660387, $data->createdAt->getTimestamp());
        self::assertSame('UTC', $data->createdAt->getTimezone()->getName());
    }

    public function test_it_normalizes_the_reference_payload_consumer_shape(): void
    {
        $record = (new NdjsonLineParser)->parse($this->fixture('valid-seconds.ndjson'));
        $record['authenticated_entity']['consumer_id'] = '80f74eef-31b8-45d5-c525-ae532297ea8e';

        $data = (new GatewayLogNormalizer)->normalize($record);

        self::assertSame('80f74eef-31b8-45d5-c525-ae532297ea8e', $data->consumerId);
    }

    public function test_it_normalizes_a_timestamp_in_milliseconds_without_losing_precision(): void
    {
        $data = $this->normalizeFixture('valid-milliseconds.ndjson');

        self::assertSame('myservice', $data->serviceName);
        self::assertSame(1433209822425, $data->createdAt->getTimestampMs());
        self::assertSame(0, $data->latencyProxy);
        self::assertSame(0, $data->latencyGateway);
        self::assertSame(0, $data->latencyRequest);
    }

    #[DataProvider('supportedTimestampBoundaryProvider')]
    public function test_it_accepts_timestamps_at_the_supported_boundaries(
        int $startedAt,
        int $expectedTimestampMs,
    ): void {
        $record = (new NdjsonLineParser)->parse($this->fixture('valid-seconds.ndjson'));
        $record['started_at'] = $startedAt;

        $data = (new GatewayLogNormalizer)->normalize($record);

        self::assertSame($expectedTimestampMs, $data->createdAt->getTimestampMs());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function supportedTimestampBoundaryProvider(): iterable
    {
        yield 'minimum in seconds' => [946_684_800, 946_684_800_000];
        yield 'maximum in seconds' => [4_102_444_799, 4_102_444_799_000];
        yield 'minimum in milliseconds' => [946_684_800_000, 946_684_800_000];
        yield 'maximum in milliseconds' => [4_102_444_799_999, 4_102_444_799_999];
    }

    #[DataProvider('unsupportedTimestampProvider')]
    public function test_it_rejects_timestamps_outside_the_supported_period(int $startedAt): void
    {
        $record = (new NdjsonLineParser)->parse($this->fixture('valid-seconds.ndjson'));
        $record['started_at'] = $startedAt;

        $this->expectException(InvalidGatewayLogRecord::class);
        $this->expectExceptionMessage('started_at');
        $this->expectExceptionMessage('entre 2000-01-01 e 2099-12-31 UTC');

        (new GatewayLogNormalizer)->normalize($record);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unsupportedTimestampProvider(): iterable
    {
        yield 'before minimum in seconds' => [946_684_799];
        yield 'after maximum in seconds' => [4_102_444_800];
        yield 'ambiguous eleven digit value' => [31_536_000_000];
        yield 'before minimum in milliseconds' => [946_684_799_999];
        yield 'after maximum in milliseconds' => [4_102_444_800_000];
        yield 'maximum PHP integer' => [PHP_INT_MAX];
    }

    public function test_it_accepts_values_at_the_database_limits(): void
    {
        $record = (new NdjsonLineParser)->parse($this->fixture('valid-seconds.ndjson'));
        $record['service']['name'] = str_repeat('á', 255);
        $record['latencies']['proxy'] = 4_294_967_295;
        $record['latencies']['gateway'] = 4_294_967_295;
        $record['latencies']['request'] = 4_294_967_295;

        $data = (new GatewayLogNormalizer)->normalize($record);

        self::assertSame(str_repeat('á', 255), $data->serviceName);
        self::assertSame(4_294_967_295, $data->latencyProxy);
        self::assertSame(4_294_967_295, $data->latencyGateway);
        self::assertSame(4_294_967_295, $data->latencyRequest);
    }

    public function test_it_rejects_a_service_name_above_the_database_limit(): void
    {
        $record = (new NdjsonLineParser)->parse($this->fixture('valid-seconds.ndjson'));
        $record['service']['name'] = str_repeat('a', 256);

        $this->expectException(InvalidGatewayLogRecord::class);
        $this->expectExceptionMessage('service.name');
        $this->expectExceptionMessage('no máximo 255 caracteres');

        (new GatewayLogNormalizer)->normalize($record);
    }

    public function test_it_rejects_a_latency_above_the_database_limit(): void
    {
        $record = (new NdjsonLineParser)->parse($this->fixture('valid-seconds.ndjson'));
        $record['latencies']['request'] = 4_294_967_296;

        $this->expectException(InvalidGatewayLogRecord::class);
        $this->expectExceptionMessage('latencies.request');
        $this->expectExceptionMessage('no máximo 4294967295');

        (new GatewayLogNormalizer)->normalize($record);
    }

    #[DataProvider('invalidRecordProvider')]
    public function test_it_rejects_invalid_or_incomplete_records(string $fixture, string $field): void
    {
        $this->expectException(InvalidGatewayLogRecord::class);
        $this->expectExceptionMessage($field);

        $this->normalizeFixture($fixture);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidRecordProvider(): iterable
    {
        yield 'missing nested consumer UUID' => ['missing-consumer.ndjson', 'authenticated_entity.consumer_id.uuid'];
        yield 'invalid consumer UUID' => ['invalid-uuid.ndjson', 'authenticated_entity.consumer_id.uuid'];
        yield 'blank service name' => ['empty-service.ndjson', 'service.name'];
        yield 'negative latency' => ['negative-latency.ndjson', 'latencies.gateway'];
        yield 'numeric string latency' => ['string-latency.ndjson', 'latencies.request'];
        yield 'missing latency' => ['missing-latency.ndjson', 'latencies.gateway'];
        yield 'missing timestamp' => ['missing-started-at.ndjson', 'started_at'];
        yield 'numeric string timestamp' => ['string-started-at.ndjson', 'started_at'];
    }

    private function normalizeFixture(string $name): GatewayLogData
    {
        $record = (new NdjsonLineParser)->parse($this->fixture($name));

        return (new GatewayLogNormalizer)->normalize($record);
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__.'/../../../Fixtures/Logs/'.$name);

        if ($contents === false) {
            throw new RuntimeException("Não foi possível ler o arquivo de teste [$name].");
        }

        return $contents;
    }
}
