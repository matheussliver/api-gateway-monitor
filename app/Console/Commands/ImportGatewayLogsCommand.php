<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\LogImport\ConcurrentLogImport;
use App\Application\LogImport\GatewayLogImporter;
use App\Application\LogImport\InvalidLogFile;
use App\Models\GatewayLogRejection;
use Illuminate\Console\Command;

final class ImportGatewayLogsCommand extends Command
{
    private const int MAX_BATCH_SIZE = 5_000;

    protected $signature = 'gateway-logs:import
                            {path : Caminho do arquivo de log NDJSON}
                            {--batch=1000 : Número de registros persistidos por transação}';

    protected $description = 'Importa incrementalmente logs do API Gateway de um arquivo NDJSON';

    public function handle(GatewayLogImporter $importer): int
    {
        $path = (string) $this->argument('path');
        $batchSize = filter_var(
            value: $this->option('batch'),
            filter: FILTER_VALIDATE_INT,
            options: ['options' => ['min_range' => 1, 'max_range' => self::MAX_BATCH_SIZE]],
        );

        if ($batchSize === false) {
            $this->error(
                'A opção --batch deve ser um número inteiro entre 1 e '.self::MAX_BATCH_SIZE.'.',
            );

            return self::INVALID;
        }

        $startedAt = hrtime(true);
        $this->info("Importando logs do gateway de [$path]...");

        try {
            $result = $importer->import($path, $batchSize);
        } catch (ConcurrentLogImport|InvalidLogFile $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        if ($result->importedRecords === 0 && $result->rejectedRecords === 0) {
            $this->comment('Nenhum registro novo foi encontrado.');
        } elseif ($result->rejectedRecords > 0) {
            $this->warn('Importação concluída com registros rejeitados.');
        } else {
            $this->info('Importação concluída com sucesso.');
        }

        $this->line("Registros importados: {$result->importedRecords}");
        $this->line("Registros rejeitados: {$result->rejectedRecords}");
        $this->line("Linhas: {$result->startLine} -> {$result->endLine}");
        $this->line("Bytes: {$result->startOffset} -> {$result->endOffset}");
        $this->line("Tamanho do arquivo: {$result->fileSize} bytes");
        $this->line('Tempo decorrido: '.number_format($elapsedSeconds, 3, '.', '').' segundos');
        $this->line('Pico de memória: '.number_format(
            memory_get_peak_usage(true) / 1024 / 1024,
            2,
            '.',
            '',
        ).' MB');

        if ($result->rejectedRecords > 0) {
            $this->newLine();
            $this->warn('Detalhes dos registros rejeitados:');

            $rejections = GatewayLogRejection::query()
                ->where('log_source_id', $result->sourceId)
                ->where('source_line', '>', $result->startLine)
                ->where('source_line', '<=', $result->endLine)
                ->orderBy('source_line')
                ->cursor();

            foreach ($rejections as $rejection) {
                $this->warn(
                    "Linha {$rejection->source_line}, byte {$rejection->source_offset}: {$rejection->reason}",
                );
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
