<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Layout;

use Artygrand\Pdfplumber\Char;
use Artygrand\Pdfplumber\Figure;
use Artygrand\Pdfplumber\PageObject;
use Artygrand\Pdfplumber\Text\WordExtractor;

/**
 * pdfminer's layout analysis (LTLayoutContainer.analyze): chars grouped into text lines, lines into
 * text boxes, boxes ordered by hierarchical grouping.
 *
 * @internal
 */
final class Analyzer
{
    /**
     * @param \SplObjectStorage<PageObject, array{0: float, 1: float}> $positions x0 and x1 of chars and
     *        figures in PDF coordinates (before the MediaBox shift)
     */
    public function __construct(private readonly LAParams $params, private readonly \SplObjectStorage $positions)
    {
    }

    /**
     * The page's objects as a layout tree, analysed.
     *
     * @param list<PageObject> $objects in drawing order, figures holding theirs
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox the page in PDF coordinates
     *
     * @return list<Node>
     */
    public function page(array $objects, array $bbox): array
    {
        return $this->analyze(array_map($this->node(...), $objects), $bbox);
    }

    private function node(PageObject $object): Node
    {
        [$x0, $x1] = $this->positions[$object] ?? [$object->x0, $object->x1];
        $node = Node::leaf($object, [$x0, $object->y0, $x1, $object->y1]);
        if ($object instanceof Figure) {
            $node->items = array_map($this->node(...), $object->items);
        }

        return $node;
    }

    /**
     * @param list<Node> $nodes
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     *
     * @return list<Node>
     */
    private function analyze(array $nodes, array $bbox): array
    {
        $chars = [];
        $others = [];
        foreach ($nodes as $node) {
            if ($node->object instanceof Char) {
                $chars[] = $node;
            } else {
                $others[] = $node;
            }
        }
        foreach ($others as $node) {
            if ($node->object instanceof Figure && $this->params->allTexts) {
                $node->items = $this->analyze($node->items, [$node->x0, $node->y0, $node->x1, $node->y1]);
            }
        }
        if ($chars === []) {
            return $nodes;
        }
        $lines = [];
        $empties = [];
        foreach ($this->groupChars($chars) as $line) {
            if ($line->isEmpty() || WordExtractor::isSpace($line->text())) {
                $empties[] = $line;
            } else {
                $lines[] = $line;
            }
        }
        foreach ($empties as $line) {
            $line->items[] = new Node(Node::ANNO, anno: "\n");
        }
        $boxes = $this->groupLines($lines, $bbox);
        if ($this->params->boxesFlow === null) {
            foreach ($boxes as $box) {
                $this->analyzeTree($box);
            }
            $keys = array_map(
                static fn (Node $b): array => $b->kind === Node::BOX_V ? [0, -$b->x1, -$b->y0] : [1, -$b->y0, $b->x0],
                $boxes,
            );
            $boxes = self::sortBy($boxes, $keys);
        } else {
            $index = 0;
            foreach ($this->groupBoxes($boxes, $bbox) as $group) {
                $this->analyzeTree($group);
                $this->assignIndex($group, $index);
            }
            $boxes = self::sortBy($boxes, array_map(static fn (Node $b): int => $b->index, $boxes));
        }

        return [...$boxes, ...$others, ...$empties];
    }

    /**
     * pdfminer's group_objects: consecutive chars that line up horizontally (or vertically) form lines.
     *
     * @param list<Node> $chars
     *
     * @return list<Node>
     */
    private function groupChars(array $chars): array
    {
        $p = $this->params;
        $lines = [];
        $line = null;
        $previous = null;
        foreach ($chars as $char) {
            if ($previous !== null) {
                $halign = self::vOverlaps($previous, $char)
                    && min($previous->height(), $char->height()) * $p->lineOverlap < self::vOverlap($previous, $char)
                    && self::hDistance($previous, $char) < max($previous->width(), $char->width()) * $p->charMargin;
                $valign = $p->detectVertical
                    && self::hOverlaps($previous, $char)
                    && min($previous->width(), $char->width()) * $p->lineOverlap < self::hOverlap($previous, $char)
                    && self::vDistance($previous, $char) < max($previous->height(), $char->height()) * $p->charMargin;

                if (($halign && $line?->kind === Node::LINE_H) || ($valign && $line?->kind === Node::LINE_V)) {
                    $this->addToLine($line, $char);
                } elseif ($line !== null) {
                    $lines[] = $line;
                    $line = null;
                } elseif ($valign && ! $halign) {
                    $line = new Node(Node::LINE_V);
                    $this->addToLine($line, $previous);
                    $this->addToLine($line, $char);
                } elseif ($halign && ! $valign) {
                    $line = new Node(Node::LINE_H);
                    $this->addToLine($line, $previous);
                    $this->addToLine($line, $char);
                } else {
                    $single = new Node(Node::LINE_H);
                    $this->addToLine($single, $previous);
                    $lines[] = $single;
                }
            }
            $previous = $char;
        }
        if ($line === null) {
            $line = new Node(Node::LINE_H);
            $this->addToLine($line, $previous);
        }
        $lines[] = $line;

        return $lines;
    }

    /**
     * Adds a char to a line, with a space before it when it is a word away from the last one.
     */
    private function addToLine(Node $line, Node $char): void
    {
        $margin = $this->params->wordMargin;
        if ($margin) {
            $gap = $margin * max($char->width(), $char->height());
            $spaced = $line->kind === Node::LINE_H
                ? $line->edge < $char->x0 - $gap
                : $char->y1 + $gap < $line->edge;
            if ($spaced) {
                $line->items[] = new Node(Node::ANNO, anno: ' ');
            }
        }
        $line->edge = $line->kind === Node::LINE_H ? $char->x1 : $char->y0;
        $line->add($char);
    }

    /**
     * pdfminer's group_textlines: lines near each other with the same size and alignment form boxes.
     *
     * @param list<Node> $lines
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     *
     * @return list<Node>
     */
    private function groupLines(array $lines, array $bbox): array
    {
        $plane = new Plane($bbox);
        foreach ($lines as $line) {
            $plane->add($line);
        }
        /** @var array<int, Node> $boxes box by line id */
        $boxes = [];
        $ratio = $this->params->lineMargin;
        foreach ($lines as $line) {
            $members = [$line];
            foreach ($this->neighbors($line, $plane, $ratio) as $neighbor) {
                $members[] = $neighbor;
                if (isset($boxes[$neighbor->id])) {
                    array_push($members, ...$boxes[$neighbor->id]->items);
                    unset($boxes[$neighbor->id]);
                }
            }
            $box = new Node($line->kind === Node::LINE_H ? Node::BOX_H : Node::BOX_V);
            $seen = [];
            foreach ($members as $member) {
                if (! isset($seen[$member->id])) {
                    $seen[$member->id] = true;
                    $box->add($member);
                    $boxes[$member->id] = $box;
                }
            }
        }
        $found = [];
        $done = [];
        foreach ($lines as $line) {
            $box = $boxes[$line->id] ?? null;
            if ($box === null || isset($done[$box->id])) {
                continue;
            }
            $done[$box->id] = true;
            if (! $box->isEmpty()) {
                $found[] = $box;
            }
        }

        return $found;
    }

    /**
     * @return list<Node>
     */
    private function neighbors(Node $line, Plane $plane, float $ratio): array
    {
        if ($line->kind === Node::LINE_H) {
            $d = $ratio * $line->height();
            $candidates = $plane->find([$line->x0, $line->y0 - $d, $line->x1, $line->y1 + $d]);

            return array_values(array_filter($candidates, static fn (Node $o): bool => $o->kind === Node::LINE_H
                && abs($o->height() - $line->height()) <= $d
                && (abs($o->x0 - $line->x0) <= $d
                    || abs($o->x1 - $line->x1) <= $d
                    || abs(($o->x0 + $o->x1) / 2 - ($line->x0 + $line->x1) / 2) <= $d)));
        }
        $d = $ratio * $line->width();
        $candidates = $plane->find([$line->x0 - $d, $line->y0, $line->x1 + $d, $line->y1]);

        return array_values(array_filter($candidates, static fn (Node $o): bool => $o->kind === Node::LINE_V
            && abs($o->width() - $line->width()) <= $d
            && (abs($o->y0 - $line->y0) <= $d
                || abs($o->y1 - $line->y1) <= $d
                || abs(($o->y0 + $o->y1) / 2 - ($line->y0 + $line->y1) / 2) <= $d)));
    }

    /**
     * pdfminer's group_textboxes: merges the closest pair of boxes or groups again and again, skipping
     * pairs with something between them while there are others.
     *
     * @param list<Node> $boxes
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     *
     * @return list<Node>
     */
    private function groupBoxes(array $boxes, array $bbox): array
    {
        $plane = new Plane($bbox);
        // Entries are pdfminer's (skip, distance, id1, id2) packed into strings that sort the same
        // way byte by byte: a box page holds n² of them, and arrays took several times the memory.
        $heap = new class () extends \SplMinHeap {
            protected function compare(mixed $value1, mixed $value2): int
            {
                return strcmp($value2, $value1);
            }
        };
        $nodes = [];
        foreach ($boxes as $box) {
            $nodes[$box->id] = $box;
        }
        $count = count($boxes);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $heap->insert(self::entry(self::distance($boxes[$i], $boxes[$j]), $boxes[$i]->id, $boxes[$j]->id));
            }
        }
        foreach ($boxes as $box) {
            $plane->add($box);
        }
        $done = [];
        while (! $heap->isEmpty()) {
            $entry = $heap->extract();
            ['id1' => $id1, 'id2' => $id2] = unpack('Jid1/Jid2', $entry, 9);
            if (isset($done[$id1]) || isset($done[$id2])) {
                continue;
            }
            $a = $nodes[$id1];
            $b = $nodes[$id2];
            if ($entry[0] === "\0" && $this->anyBetween($plane, $a, $b)) {
                $heap->insert("\1".substr($entry, 1));

                continue;
            }
            $vertical = in_array($a->kind, [Node::BOX_V, Node::GROUP_TBRL], true)
                || in_array($b->kind, [Node::BOX_V, Node::GROUP_TBRL], true);
            $group = new Node($vertical ? Node::GROUP_TBRL : Node::GROUP_LRTB);
            $group->add($a);
            $group->add($b);
            $plane->remove($a);
            $plane->remove($b);
            $done[$id1] = $done[$id2] = true;
            unset($nodes[$id1], $nodes[$id2]);
            $nodes[$group->id] = $group;
            foreach ($plane->all() as $other) {
                $heap->insert(self::entry(self::distance($group, $other), $group->id, $other->id));
            }
            $plane->add($group);
        }

        return $plane->all();
    }

    /**
     * A heap entry with skip off: a big-endian double turned sortable (negatives inverted, the sign
     * bit set on the rest), then both ids.
     */
    private static function entry(float $distance, int $id1, int $id2): string
    {
        $bytes = pack('E', $distance == 0.0 ? 0.0 : $distance);
        $bytes = $distance < 0 ? ~$bytes : (chr(ord($bytes[0]) | 0x80).substr($bytes, 1));

        return "\0".$bytes.pack('J2', $id1, $id2);
    }

    private function anyBetween(Plane $plane, Node $a, Node $b): bool
    {
        $found = $plane->find([min($a->x0, $b->x0), min($a->y0, $b->y0), max($a->x1, $b->x1), max($a->y1, $b->y1)]);
        foreach ($found as $node) {
            if ($node !== $a && $node !== $b) {
                return true;
            }
        }

        return false;
    }

    /**
     * pdfminer's analyze on boxes and groups: lines end with a newline, a box's lines go top to
     * bottom, a group's members in reading order.
     */
    private function analyzeTree(Node $node): void
    {
        foreach ($node->items as $item) {
            if ($item->kind >= Node::LINE_H) {
                $this->analyzeTree($item);
            }
        }
        $flow = (float) $this->params->boxesFlow;
        switch ($node->kind) {
            case Node::LINE_H:
            case Node::LINE_V:
                $node->items[] = new Node(Node::ANNO, anno: "\n");
                break;
            case Node::BOX_H:
                $node->items = self::sortBy($node->items, array_map(static fn (Node $l): float => -$l->y1, $node->items));
                break;
            case Node::BOX_V:
                $node->items = self::sortBy($node->items, array_map(static fn (Node $l): float => -$l->x1, $node->items));
                break;
            case Node::GROUP_LRTB:
                $node->items = self::sortBy($node->items, array_map(
                    static fn (Node $o): float => (1 - $flow) * $o->x0 - (1 + $flow) * ($o->y0 + $o->y1),
                    $node->items,
                ));
                break;
            case Node::GROUP_TBRL:
                $node->items = self::sortBy($node->items, array_map(
                    static fn (Node $o): float => -(1 + $flow) * ($o->x0 + $o->x1) - (1 - $flow) * $o->y1,
                    $node->items,
                ));
                break;
        }
    }

    private function assignIndex(Node $node, int &$index): void
    {
        if ($node->kind === Node::BOX_H || $node->kind === Node::BOX_V) {
            $node->index = $index++;
        } elseif ($node->kind === Node::GROUP_LRTB || $node->kind === Node::GROUP_TBRL) {
            foreach ($node->items as $item) {
                $this->assignIndex($item, $index);
            }
        }
    }

    private static function distance(Node $a, Node $b): float
    {
        $x0 = min($a->x0, $b->x0);
        $y0 = min($a->y0, $b->y0);
        $x1 = max($a->x1, $b->x1);
        $y1 = max($a->y1, $b->y1);

        return ($x1 - $x0) * ($y1 - $y0) - $a->width() * $a->height() - $b->width() * $b->height();
    }

    /**
     * A stable sort by precomputed keys.
     *
     * @template T
     *
     * @param list<T> $items
     * @param list<mixed> $keys
     *
     * @return list<T>
     */
    private static function sortBy(array $items, array $keys): array
    {
        $order = array_keys($items);
        usort($order, static fn (int $a, int $b): int => $keys[$a] <=> $keys[$b]);

        return array_map(static fn (int $i): mixed => $items[$i], $order);
    }

    private static function hOverlaps(Node $a, Node $b): bool
    {
        return $b->x0 <= $a->x1 && $a->x0 <= $b->x1;
    }

    private static function vOverlaps(Node $a, Node $b): bool
    {
        return $b->y0 <= $a->y1 && $a->y0 <= $b->y1;
    }

    private static function hDistance(Node $a, Node $b): float
    {
        return self::hOverlaps($a, $b) ? 0 : min(abs($a->x0 - $b->x1), abs($a->x1 - $b->x0));
    }

    private static function vDistance(Node $a, Node $b): float
    {
        return self::vOverlaps($a, $b) ? 0 : min(abs($a->y0 - $b->y1), abs($a->y1 - $b->y0));
    }

    private static function hOverlap(Node $a, Node $b): float
    {
        return self::hOverlaps($a, $b) ? min(abs($a->x0 - $b->x1), abs($a->x1 - $b->x0)) : 0;
    }

    private static function vOverlap(Node $a, Node $b): float
    {
        return self::vOverlaps($a, $b) ? min(abs($a->y0 - $b->y1), abs($a->y1 - $b->y0)) : 0;
    }
}
