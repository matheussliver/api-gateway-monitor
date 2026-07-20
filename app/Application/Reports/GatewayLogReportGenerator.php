<?php

declare(strict_types=1);

namespace App\Application\Reports;

use Illuminate\Support\Facades\DB;
use Throwable;

final class GatewayLogReportGenerator
{
    private const string REQUESTS_BY_CONSUMER = 'requests_by_consumer.csv';

    private const string REQUESTS_BY_SERVICE = 'requests_by_service.csv';

    private const string AVERAGE_LATENCY_BY_SERVICE = 'average_latency_by_service.csv';

    public function generate(string $outputDirectory): CsvReportResult
    {
        $directory = $this->prepareOutputDirectory($outputDirectory);
        $requestsByConsumerPath = $directory.DIRECTORY_SEPARATOR.self::REQUESTS_BY_CONSUMER;
        $requestsByServicePath = $directory.DIRECTORY_SEPARATOR.self::REQUESTS_BY_SERVICE;
        $averageLatencyByServicePath = $directory.DIRECTORY_SEPARATOR.self::AVERAGE_LATENCY_BY_SERVICE;

        $consumerRows = $this->writeCsv(
            path: $requestsByConsumerPath,
            header: ['consumer_id', 'total_requests'],
            rows: $this->requestsByConsumerRows(),
        );
        $serviceRows = $this->writeCsv(
            path: $requestsByServicePath,
            header: ['service_name', 'total_requests'],
            rows: $this->requestsByServiceRows(),
        );
        $latencyRows = $this->writeCsv(
            path: $averageLatencyByServicePath,
            header: [
                'service_name',
                'average_request_latency',
                'average_proxy_latency',
                'average_gateway_latency',
            ],
            rows: $this->averageLatencyByServiceRows(),
        );

        return new CsvReportResult(
            outputDirectory: $directory,
            requestsByConsumerPath: $requestsByConsumerPath,
            requestsByServicePath: $requestsByServicePath,
            averageLatencyByServicePath: $averageLatencyByServicePath,
            consumerRows: $consumerRows,
            serviceRows: $serviceRows,
            latencyRows: $latencyRows,
        );
    }

    private function prepareOutputDirectory(string $outputDirectory): string
    {
        if (trim($outputDirectory) === '' || str_contains($outputDirectory, "\0")) {
            throw new ReportGenerationException('O diretório de saída dos relatórios é inválido.');
        }

        if (file_exists($outputDirectory) && ! is_dir($outputDirectory)) {
            throw new ReportGenerationException(
                "O caminho de saída dos relatórios [$outputDirectory] não é um diretório.",
            );
        }

        if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0775, true) && ! is_dir($outputDirectory)) {
            throw new ReportGenerationException(
                "Não foi possível criar o diretório de saída dos relatórios [$outputDirectory].",
            );
        }

        $canonicalDirectory = realpath($outputDirectory);

        if ($canonicalDirectory === false || ! is_writable($canonicalDirectory)) {
            throw new ReportGenerationException(
                "O diretório de saída dos relatórios [$outputDirectory] não possui permissão de escrita.",
            );
        }

        return $canonicalDirectory;
    }

    /**
     * @return iterable<array{string, string}>
     */
    private function requestsByConsumerRows(): iterable
    {
        $rows = DB::table('gateway_logs')
            ->select('consumer_id')
            ->selectRaw('COUNT(*) AS total_requests')
            ->groupBy('consumer_id')
            ->orderBy('consumer_id')
            ->cursor();

        foreach ($rows as $row) {
            yield [(string) $row->consumer_id, (string) $row->total_requests];
        }
    }

    /**
     * @return iterable<array{string, string}>
     */
    private function requestsByServiceRows(): iterable
    {
        $rows = DB::table('gateway_logs')
            ->select('service_name')
            ->selectRaw('COUNT(*) AS total_requests')
            ->groupBy('service_name')
            ->orderBy('service_name')
            ->cursor();

        foreach ($rows as $row) {
            yield [(string) $row->service_name, (string) $row->total_requests];
        }
    }

    /**
     * @return iterable<array{string, string, string, string}>
     */
    private function averageLatencyByServiceRows(): iterable
    {
        $rows = DB::table('gateway_logs')
            ->select('service_name')
            ->selectRaw('AVG(latency_request) AS average_request_latency')
            ->selectRaw('AVG(latency_proxy) AS average_proxy_latency')
            ->selectRaw('AVG(latency_gateway) AS average_gateway_latency')
            ->groupBy('service_name')
            ->orderBy('service_name')
            ->cursor();

        foreach ($rows as $row) {
            yield [
                (string) $row->service_name,
                $this->formatAverage($row->average_request_latency),
                $this->formatAverage($row->average_proxy_latency),
                $this->formatAverage($row->average_gateway_latency),
            ];
        }
    }

    private function formatAverage(string|int|float $average): string
    {
        return number_format((float) $average, 2, '.', '');
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<list<string>>  $rows
     */
    private function writeCsv(string $path, array $header, iterable $rows): int
    {
        $temporaryPath = tempnam(dirname($path), '.gateway-report-');

        if ($temporaryPath === false) {
            throw new ReportGenerationException("Não foi possível criar um relatório temporário para [$path].");
        }

        $stream = @fopen($temporaryPath, 'wb');

        if ($stream === false) {
            @unlink($temporaryPath);

            throw new ReportGenerationException("Não foi possível abrir o relatório temporário de [$path].");
        }

        try {
            $this->writeCsvRow($stream, $header, $path);
            $rowCount = 0;

            foreach ($rows as $row) {
                $this->writeCsvRow($stream, $row, $path);
                $rowCount++;
            }

            if (! fflush($stream)) {
                throw new ReportGenerationException("Não foi possível concluir a escrita do relatório [$path].");
            }
        } catch (Throwable $exception) {
            fclose($stream);
            @unlink($temporaryPath);

            if ($exception instanceof ReportGenerationException) {
                throw $exception;
            }

            throw new ReportGenerationException(
                message: "Não foi possível gerar o relatório [$path].",
                code: 0,
                previous: $exception,
            );
        }

        fclose($stream);

        if (! @rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new ReportGenerationException("Não foi possível publicar o relatório [$path].");
        }

        return $rowCount;
    }

    /**
     * @param  resource  $stream
     * @param  list<string>  $fields
     */
    private function writeCsvRow($stream, array $fields, string $path): void
    {
        if (fputcsv($stream, $fields, ',', '"', '', "\n") === false) {
            throw new ReportGenerationException("Não foi possível escrever no relatório [$path].");
        }
    }
}
