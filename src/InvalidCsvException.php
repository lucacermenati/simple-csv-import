<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

use RuntimeException;

/** The file cannot be validated at all: unreadable, or the wrong header row. */
class InvalidCsvException extends RuntimeException {}
