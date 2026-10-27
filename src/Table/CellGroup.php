<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Table;

/**
 * A row or a column of a table: its cells (null where the table has no cell at that position)
 * and the box around them.
 */
final class CellGroup
{
    /** @var array{0: float, 1: float, 2: float, 3: float} */
    public readonly array $bbox;

    /**
     * @param list<array{0: float, 1: float, 2: float, 3: float}|null> $cells
     */
    public function __construct(public readonly array $cells)
    {
        $present = array_filter($cells);
        $this->bbox = [
            min(array_column($present, 0)),
            min(array_column($present, 1)),
            max(array_column($present, 2)),
            max(array_column($present, 3)),
        ];
    }
}
