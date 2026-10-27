<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Layout;

/**
 * pdfminer's Plane: nodes indexed on a 50-point grid to find those in an area, in the order
 * pdfminer finds them.
 *
 * @internal
 */
final class Plane
{
    private const GRID = 50;

    /** @var list<Node> */
    private array $sequence = [];

    /** @var array<int, Node> */
    private array $members = [];

    /** @var array<string, list<Node>> */
    private array $grid = [];

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     */
    public function __construct(private readonly array $bbox)
    {
    }

    public function add(Node $node): void
    {
        foreach ($this->cells([$node->x0, $node->y0, $node->x1, $node->y1]) as $cell) {
            $this->grid[$cell][] = $node;
        }
        $this->sequence[] = $node;
        $this->members[$node->id] = $node;
    }

    public function remove(Node $node): void
    {
        foreach ($this->cells([$node->x0, $node->y0, $node->x1, $node->y1]) as $cell) {
            foreach ($this->grid[$cell] ?? [] as $i => $member) {
                if ($member === $node) {
                    array_splice($this->grid[$cell], $i, 1);
                    break;
                }
            }
        }
        unset($this->members[$node->id]);
    }

    /**
     * Members in the order they were added.
     *
     * @return list<Node>
     */
    public function all(): array
    {
        return array_values(array_filter($this->sequence, fn (Node $n): bool => isset($this->members[$n->id])));
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     *
     * @return list<Node>
     */
    public function find(array $bbox): array
    {
        [$x0, $y0, $x1, $y1] = $bbox;
        $done = [];
        $found = [];
        foreach ($this->cells($bbox) as $cell) {
            foreach ($this->grid[$cell] ?? [] as $node) {
                if (isset($done[$node->id])) {
                    continue;
                }
                $done[$node->id] = true;
                if ($node->x1 <= $x0 || $x1 <= $node->x0 || $node->y1 <= $y0 || $y1 <= $node->y0) {
                    continue;
                }
                $found[] = $node;
            }
        }

        return $found;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     *
     * @return list<string>
     */
    private function cells(array $bbox): array
    {
        [$x0, $y0, $x1, $y1] = $bbox;
        [$px0, $py0, $px1, $py1] = $this->bbox;
        if ($x1 <= $px0 || $px1 <= $x0 || $y1 <= $py0 || $py1 <= $y0) {
            return [];
        }
        [$x0, $y0, $x1, $y1] = [max($px0, $x0), max($py0, $y0), min($px1, $x1), min($py1, $y1)];
        $cells = [];
        for ($gy = self::div($y0); $gy < self::div($y1 + self::GRID); $gy++) {
            for ($gx = self::div($x0); $gx < self::div($x1 + self::GRID); $gx++) {
                $cells[] = $gx.','.$gy;
            }
        }

        return $cells;
    }

    /**
     * Python's int(v) // 50: truncation, then floor division.
     */
    private static function div(float $value): int
    {
        return (int) floor(((int) $value) / self::GRID);
    }
}
