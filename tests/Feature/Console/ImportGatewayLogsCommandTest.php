<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

final class ImportGatewayLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_it_imports_a_file_and_prints_a_summary(): void
    {
        $path = $this->createLogFile([
            $this->fixture('valid-seconds.ndjson'),
            $this->fixture('valid-milliseconds.ndjson'),
        ]);

        $this->artisan('gateway-logs:import', ['path' => $path, '--batch' => 1])
            ->expectsOutputToContain('Importação concluída com sucesso.')
            ->expectsOutputToContain('Registros importados: 2')
            ->expectsOutputToContain('Registros rejeitados: 0')
            ->expectsOutputToContain('Linhas: 0 -> 2')
            ->expectsOutputToContain('Bytes: 0 -> '.filesize($path))
            ->expectsOutputToContain('Pico de memória:')
            ->assertSuccessful();

        $this->assertDatabaseCount('log_sources', 1);
        $this->assertDatabaseCount('gateway_logs', 2);
    }

    public function test_it_reports_when_an_unchanged_file_has_no_new_records(): void
    {
        $path = $this->createLogFile([$this->fixture('valid-seconds.ndjson')]);

        $this->artisan('gateway-logs:import', ['path' => $path])->assertSuccessful();

        $this->artisan('gateway-logs:import', ['path' => $path])
            ->expectsOutputToContain('Nenhum registro novo foi encontrado.')
            ->expectsOutputToContain('Registros importados: 0')
            ->expectsOutputToContain('Registros rejeitados: 0')
            ->expectsOutputToContain('Linhas: 1 -> 1')
            ->assertSuccessful();

        $this->assertDatabaseCount('gateway_logs', 1);
    }

    public function test_it_returns_invalid_for_a_non_numeric_batch_size(): void
    {
        $path = $this->createLogFile([]);

        $this->artisan('gateway-logs:import', ['path' => $path, '--batch' => 'many'])
            ->expectsOutputToContain('deve ser um número inteiro entre 1 e 5000')
            ->assertExitCode(Command::INVALID);

        $this->assertDatabaseCount('log_sources', 0);
    }

    public function test_it_returns_invalid_for_a_batch_size_above_the_limit(): void
    {
        $path = $this->createLogFile([]);

        $this->artisan('gateway-logs:import', ['path' => $path, '--batch' => 5_001])
            ->expectsOutputToContain('deve ser um número inteiro entre 1 e 5000')
            ->assertExitCode(Command::INVALID);
    }

    public function test_it_accepts_the_maximum_batch_size(): void
    {
        $path = $this->createLogFile([$this->fixture('valid-seconds.ndjson')]);

        $this->artisan('gateway-logs:import', ['path' => $path, '--batch' => 5_000])
            ->assertSuccessful();

        $this->assertDatabaseCount('gateway_logs', 1);
    }

    public function test_it_returns_failure_for_a_missing_file(): void
    {
        $this->artisan('gateway-logs:import', [
            'path' => '/tmp/a-log-file-that-does-not-exist.ndjson',
        ])
            ->expectsOutputToContain('não existe ou não possui permissão de leitura')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_it_processes_later_records_and_returns_failure_with_rejection_details(): void
    {
        $firstLine = $this->fixture('valid-seconds.ndjson');
        $path = $this->createLogFile([
            $firstLine,
            $this->fixture('malformed.ndjson'),
            $this->fixture('valid-milliseconds.ndjson'),
        ]);
        $invalidLineOffset = strlen(rtrim($firstLine, "\r\n").PHP_EOL);

        $this->artisan('gateway-logs:import', ['path' => $path, '--batch' => 100])
            ->expectsOutputToContain('Importação concluída com registros rejeitados.')
            ->expectsOutputToContain('Registros importados: 2')
            ->expectsOutputToContain('Registros rejeitados: 1')
            ->expectsOutputToContain(
                "Linha 2, byte $invalidLineOffset: A linha NDJSON contém JSON inválido: erro de sintaxe.",
            )
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('gateway_logs', 2);
        $this->assertDatabaseCount('gateway_log_rejections', 1);
    }

    private function createLogFile(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gateway-command-');

        if ($path === false) {
            throw new RuntimeException('Não foi possível criar um arquivo de log temporário.');
        }

        $this->temporaryFiles[] = $path;
        file_put_contents($path, implode('', array_map(
            static fn (string $line): string => rtrim($line, "\r\n").PHP_EOL,
            $lines,
        )));

        return $path;
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/Logs/'.$name));

        if ($contents === false) {
            throw new RuntimeException("Não foi possível ler o arquivo de teste [$name].");
        }

        return $contents;
    }
}
