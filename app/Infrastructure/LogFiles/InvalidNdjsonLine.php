<?php

declare(strict_types=1);

namespace App\Infrastructure\LogFiles;

use UnexpectedValueException;

final class InvalidNdjsonLine extends UnexpectedValueException {}
