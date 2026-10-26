<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

use RuntimeException;

/**
 * The uploaded file cannot be validated at all: unreadable, or its header row
 * does not belong to the import it was handed to.
 */
class InvalidCsvException extends RuntimeException {}
