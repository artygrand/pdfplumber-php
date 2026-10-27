<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Layout;

use Artygrand\Pdfplumber\PageObject;

/**
 * An item of pdfminer's layout tree during analysis: a char or other object (a leaf), a space or
 * newline added between chars, a text line, box or group, in PDF coordinates.
 *
 * @internal
 */
final class Node
{
    public const LEAF = 0;

    public const ANNO = 1;

    public const LINE_H = 2;

    public const LINE_V = 3;

    public const BOX_H = 4;

    public const BOX_V = 5;

    public const GROUP_LRTB = 6;

    public const GROUP_TBRL = 7;

    private static int $sequence = 0;

    public readonly int $id;

    public float $x0 = INF;

    public float $y0 = INF;

    public float $x1 = -INF;

    public float $y1 = -INF;

    /** @var list<Node> */
    public array $items = [];

    public int $index = -1;

    /** the right edge of the last char added to a horizontal line, the bottom of a vertical one's */
    public float $edge;

    public function __construct(
        public readonly int $kind,
        public readonly ?PageObject $object = null,
        public readonly string $anno = '',
    ) {
        $this->id = ++self::$sequence;
        $this->edge = $kind === self::LINE_V ? -INF : INF;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     */
    public static function leaf(PageObject $object, array $bbox): self
    {
        $node = new self(self::LEAF, $object);
        [$node->x0, $node->y0, $node->x1, $node->y1] = $bbox;

        return $node;
    }

    public function width(): float
    {
        return $this->x1 - $this->x0;
    }

    public function height(): float
    {
        return $this->y1 - $this->y0;
    }

    /**
     * Adds a child and grows the box around it, as pdfminer's LTExpandableContainer.
     */
    public function add(Node $child): void
    {
        $this->items[] = $child;
        $this->x0 = min($this->x0, $child->x0);
        $this->y0 = min($this->y0, $child->y0);
        $this->x1 = max($this->x1, $child->x1);
        $this->y1 = max($this->y1, $child->y1);
    }

    public function text(): string
    {
        if ($this->kind === self::LEAF) {
            return $this->object instanceof \Artygrand\Pdfplumber\Char ? $this->object->text : '';
        }
        if ($this->kind === self::ANNO) {
            return $this->anno;
        }
        $text = '';
        foreach ($this->items as $item) {
            $text .= $item->text();
        }

        return $text;
    }

    public function isEmpty(): bool
    {
        return $this->width() <= 0 || $this->height() <= 0;
    }
}
