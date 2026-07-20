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
