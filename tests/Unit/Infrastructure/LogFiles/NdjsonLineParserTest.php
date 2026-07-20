<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\LogFiles;

use App\Infrastructure\LogFiles\InvalidNdjsonLine;
use App\Infrastructure\LogFiles\NdjsonLineParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NdjsonLineParserTest extends TestCase
{
    public function test_it_parses_a_json_object_from_one_ndjson_line(): void
    {
        $record = (new NdjsonLineParser)->parse($this->fixture('valid-seconds.ndjson'));

        self::assertSame('ritchie', $record['service']['name']);
        self::assertSame(1566660387, $record['started_at']);
    }

    public function test_it_rejects_an_empty_line(): void
    {
        $this->expectException(InvalidNdjsonLine::class);
        $this->expectExceptionMessage('não pode estar vazia');

        (new NdjsonLineParser)->parse("\n");
    }

    public function test_it_rejects_malformed_json(): void
    {
        $this->expectException(InvalidNdjsonLine::class);
        $this->expectExceptionMessage('JSON inválido: erro de sintaxe');

        (new NdjsonLineParser)->parse($this->fixture('malformed.ndjson'));
    }

    public function test_it_rejects_a_top_level_json_array(): void
    {
        $this->expectException(InvalidNdjsonLine::class);
        $this->expectExceptionMessage('valor JSON no nível superior deve ser um objeto');

        (new NdjsonLineParser)->parse($this->fixture('top-level-array.ndjson'));
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
