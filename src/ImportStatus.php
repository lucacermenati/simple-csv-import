<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

/**
 * How an upload ended. Every upload gets an ImportReport row when validation
 * rejects it, when its import succeeds and when it fails.
 */
enum ImportStatus: string
{
    /** The file could not be validated or had failing rows; nothing was imported. */
    case Invalid = 'invalid';

    case Succeeded = 'succeeded';

    /** A chunk job threw; the rows before it stay written, the reason is on the report. */
    case Failed = 'failed';
}
