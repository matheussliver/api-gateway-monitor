<?php

declare(strict_types=1);

namespace App\Domain\GatewayLog;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class GatewayLogNormalizer
{
    private const int MILLISECOND_TIMESTAMP_THRESHOLD = 100_000_000_000;

    /**
     * @param  array<string, mixed>  $record
     */
    public function normalize(array $record): GatewayLogData
    {
        [$consumerId, $consumerIdField] = $this->requireConsumerId($record);

        if (! Str::isUuid($consumerId)) {
            throw InvalidGatewayLogRecord::forField(
                field: $consumerIdField,
                reason: 'deve ser um UUID válido',
            );
        }

        $serviceName = $this->requireNonEmptyString(
            record: $record,
            path: 'service.name',
        );
        $latencyProxy = $this->requireNonNegativeInteger($record, 'latencies.proxy');
        $latencyGateway = $this->requireNonNegativeInteger($record, 'latencies.gateway');
        $latencyRequest = $this->requireNonNegativeInteger($record, 'latencies.request');
        $startedAt = $this->requireNonNegativeInteger($record, 'started_at');

        $createdAt = $startedAt >= self::MILLISECOND_TIMESTAMP_THRESHOLD
            ? CarbonImmutable::createFromTimestampMs($startedAt, 'UTC')
            : CarbonImmutable::createFromTimestamp($startedAt, 'UTC');

        return new GatewayLogData(
            consumerId: $consumerId,
            serviceName: $serviceName,
            latencyProxy: $latencyProxy,
            latencyGateway: $latencyGateway,
            latencyRequest: $latencyRequest,
            createdAt: $createdAt,
        );
    }

    /**
     * The supplied log file wraps the UUID in an object, while the reference
     * payload in the specification represents consumer_id as a string.
     *
     * @param  array<string, mixed>  $record
     * @return array{string, string}
     */
    private function requireConsumerId(array $record): array
    {
        $field = 'authenticated_entity.consumer_id';
        $value = $this->valueAtPath($record, $field);

        if (is_array($value)) {
            $field .= '.uuid';
            $value = $value['uuid'] ?? null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw InvalidGatewayLogRecord::forField($field, 'deve ser uma string não vazia');
        }

        return [trim($value), $field];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function requireNonEmptyString(array $record, string $path): string
    {
        $value = $this->valueAtPath($record, $path);

        if (! is_string($value) || trim($value) === '') {
            throw InvalidGatewayLogRecord::forField($path, 'deve ser uma string não vazia');
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function requireNonNegativeInteger(array $record, string $path): int
    {
        $value = $this->valueAtPath($record, $path);

        if (! is_int($value) || $value < 0) {
            throw InvalidGatewayLogRecord::forField($path, 'deve ser um número inteiro não negativo');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function valueAtPath(array $record, string $path): mixed
    {
        $value = $record;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
