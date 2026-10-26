<?php

declare(strict_types=1);

namespace LucaCermenati\SimpleCsvImport;

/** How an upload ended; every upload gets an ImportReport. */
enum ImportStatus: string
{
    /** Nothing was imported. */
    case Invalid = 'invalid';

    case Succeeded = 'succeeded';

    /** A chunk threw; earlier chunks stay written. */
    case Failed = 'failed';
}
