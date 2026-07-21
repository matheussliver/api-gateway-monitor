<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Reports\GatewayLogReport;
use App\Application\Reports\GatewayLogReportGenerator;
use App\Application\Reports\ReportGenerationException;
use Illuminate\Console\Command;

final class GenerateGatewayLogReportsCommand extends Command
{
    protected $signature = 'gateway-logs:reports
                            {output=storage/app/reports : Diretório onde os arquivos CSV serão gravados}
                            {--only= : Gera somente consumer, service ou latency}';

    protected $description = 'Gera todos ou apenas um dos relatórios CSV agregados do API Gateway';

    public function handle(GatewayLogReportGenerator $generator): int
    {
        $outputDirectory = (string) $this->argument('output');
        $only = $this->option('only');
        $report = null;

        if ($this->input->hasParameterOption('--only')) {
            $report = is_string($only) ? GatewayLogReport::tryFrom($only) : null;

            if ($report === null) {
                $invalidValue = is_scalar($only) ? (string) $only : '';
                $this->error("Relatório inválido [$invalidValue]. Use consumer, service ou latency.");

                return self::INVALID;
            }
        }

        $startedAt = hrtime(true);

        try {
            if ($report === null) {
                $result = $generator->generate($outputDirectory);
            } else {
                $result = $generator->generateReport($outputDirectory, $report);
            }
        } catch (ReportGenerationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        if ($report === null) {
            $this->info("Relatórios gerados em [{$result->outputDirectory}].");
            $this->line('requests_by_consumer.csv: '.$this->dataRowsDescription($result->consumerRows));
            $this->line('requests_by_service.csv: '.$this->dataRowsDescription($result->serviceRows));
            $this->line('average_latency_by_service.csv: '.$this->dataRowsDescription($result->latencyRows));
        } else {
            $this->info("Relatório gerado em [{$result->outputDirectory}].");
            $this->line($result->filename.': '.$this->dataRowsDescription($result->rows));
        }

        $this->line('Tempo decorrido: '.number_format($elapsedSeconds, 3, '.', '').' segundos');
        $this->line('Pico de memória: '.number_format(
            memory_get_peak_usage(true) / 1024 / 1024,
            2,
            '.',
            '',
        ).' MB');

        return self::SUCCESS;
    }

    private function dataRowsDescription(int $rowCount): string
    {
        return $rowCount === 1 ? '1 linha de dados' : "$rowCount linhas de dados";
    }
}
