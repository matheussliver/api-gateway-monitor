<?php

declare(strict_types=1);

namespace App\Domain\GatewayLog;

use InvalidArgumentException;

final class InvalidGatewayLogRecord extends InvalidArgumentException
{
    public static function forField(string $field, string $reason): self
    {
        return new self("Campo inválido no log do gateway [$field]: $reason.");
    }
}
