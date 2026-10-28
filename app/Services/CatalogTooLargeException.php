<?php

namespace App\Services;

use RuntimeException;

/** 049 — katalog melebihi batas varian (config 'catalog.max_variants'); controller menjawab 422. */
class CatalogTooLargeException extends RuntimeException
{
    public function __construct(public readonly int $max)
    {
        parent::__construct("Katalog melebihi {$max} varian.");
    }
}
