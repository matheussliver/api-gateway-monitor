<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $database = getenv('DB_DATABASE');
        $testingDatabase = getenv('DB_TEST_DATABASE') ?: 'gateway_logs_test';

        if ($database !== $testingDatabase) {
            throw new LogicException(
                "Execução recusada: os testes de integração devem usar o banco [$testingDatabase].",
            );
        }

        parent::setUp();
    }
}
