<?php

declare(strict_types=1);

namespace App\Application\LogImport;

use RuntimeException;

final class ConcurrentLogImport extends RuntimeException {}
