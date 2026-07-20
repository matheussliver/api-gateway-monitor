<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Reports\GatewayLogReportGenerator;
use App\Application\Reports\ReportGenerationException;
use Illuminate\Console\Command;

final class GenerateGatewayLogReportsCommand extends Command
{
    protected $signature = 'gateway-logs:reports
                            {output=storage/app/reports : Diretório onde os arquivos CSV serão gravados}';

    protected $description = 'Gera os relatórios CSV agregados do API Gateway';

    public function handle(GatewayLogReportGenerator $generator): int
    {
        $outputDirectory = (string) $this->argument('output');
        $startedAt = hrtime(true);

        try {
            $result = $generator->generate($outputDirectory);
        } catch (ReportGenerationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        $this->info("Relatórios gerados em [{$result->outputDirectory}].");
        $this->line('requests_by_consumer.csv: '.$this->dataRowsDescription($result->consumerRows));
        $this->line('requests_by_service.csv: '.$this->dataRowsDescription($result->serviceRows));
        $this->line('average_latency_by_service.csv: '.$this->dataRowsDescription($result->latencyRows));
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
