<?php

declare(strict_types=1);

namespace App\Infrastructure\LogFiles;

use JsonException;

final class NdjsonLineParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $line): array
    {
        $trimmedLine = trim($line);

        if ($trimmedLine === '') {
            throw new InvalidNdjsonLine('Uma linha NDJSON não pode estar vazia.');
        }

        try {
            $record = json_decode(
                json: $trimmedLine,
                associative: true,
                depth: 512,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidNdjsonLine(
                message: 'A linha NDJSON contém JSON inválido: '.$this->jsonErrorReason($exception).'.',
                code: 0,
                previous: $exception,
            );
        }

        if (! is_array($record) || $trimmedLine[0] !== '{') {
            throw new InvalidNdjsonLine('O valor JSON no nível superior deve ser um objeto.');
        }

        return $record;
    }

    private function jsonErrorReason(JsonException $exception): string
    {
        return match ($exception->getCode()) {
            JSON_ERROR_DEPTH => 'profundidade máxima excedida',
            JSON_ERROR_STATE_MISMATCH => 'JSON malformado ou inválido',
            JSON_ERROR_CTRL_CHAR => 'caractere de controle inesperado',
            JSON_ERROR_SYNTAX => 'erro de sintaxe',
            JSON_ERROR_UTF8 => 'caracteres UTF-8 malformados',
            JSON_ERROR_RECURSION => 'referência recursiva detectada',
            JSON_ERROR_INF_OR_NAN => 'valor infinito ou NaN não permitido',
            JSON_ERROR_UNSUPPORTED_TYPE => 'tipo de dado não suportado',
            JSON_ERROR_INVALID_PROPERTY_NAME => 'nome de propriedade inválido',
            JSON_ERROR_UTF16 => 'caractere UTF-16 malformado',
            JSON_ERROR_NON_BACKED_ENUM => 'enumeração sem valor serializável',
            default => 'falha desconhecida na decodificação',
        };
    }
}
