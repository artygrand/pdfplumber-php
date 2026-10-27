<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Parser;

/**
 * An indirect reference (12 0 R), resolved by the document on demand.
 */
final class Ref
{
    public function __construct(public readonly int $id, public readonly int $gen = 0)
    {
    }
}
