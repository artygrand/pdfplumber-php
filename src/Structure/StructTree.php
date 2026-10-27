<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Structure;

use Artygrand\Pdfplumber\Exception;
use Artygrand\Pdfplumber\Font\Encodings;
use Artygrand\Pdfplumber\Geometry;
use Artygrand\Pdfplumber\Page;
use Artygrand\Pdfplumber\Parser\Dict;
use Artygrand\Pdfplumber\Parser\Document;
use Artygrand\Pdfplumber\Parser\Name;
use Artygrand\Pdfplumber\Parser\Ref;

/**
 * The logical structure of a tagged PDF, as pdfplumber's PDFStructTree: the elements that reach
 * marked content, for one page or for the whole document.
 */
final class StructTree
{
    use Findable;

    /** @var list<StructElement> */
    public array $children = [];

    private readonly Dict $root;

    private readonly Dict $roleMap;

    private readonly Dict $classMap;

    /**
     * @param array<int, int> $pageNumbers page number by page object id, for a document tree
     * @param array<int, Page> $pages pages by number
     * @param Page|null $page the page to read the tree of, through the ParentTree; null for the whole document
     *
     * @throws Exception when the PDF has no structure tree
     */
    public function __construct(
        private readonly Document $document,
        private readonly array $pageNumbers,
        private readonly array $pages,
        private readonly ?Page $page = null,
        private readonly ?int $pageObjectId = null,
        private readonly ?Dict $pageAttributes = null,
    ) {
        $root = $document->resolve($document->catalog->get('StructTreeRoot'));
        if (! $document->catalog->has('StructTreeRoot') || ! $root instanceof Dict) {
            throw new Exception('The PDF has no structure tree');
        }
        $this->root = $root;
        $roleMap = $document->resolve($root->get('RoleMap'));
        $this->roleMap = $roleMap instanceof Dict ? $roleMap : new Dict();
        $classMap = $document->resolve($root->get('ClassMap'));
        $this->classMap = $classMap instanceof Dict ? $classMap : new Dict();

        if ($page === null) {
            $this->parseTree();

            return;
        }
        $parentTree = $root->get('ParentTree');
        if ($parentTree === null) {
            $this->parseTree();

            return;
        }
        $parentId = $pageAttributes?->get('StructParents');
        if ($parentId === null) {
            return;
        }
        $parentId = $document->resolve($parentId);
        foreach ($this->numberTree($parentTree) as [$number, $array]) {
            if ($number === $parentId) {
                $array = $document->resolve($array);
                $this->parseParentTree(is_array($array) ? $array : []);

                return;
            }
        }
    }

    /**
     * Elements as pdfplumber's structure_tree: arrays without empty values.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (StructElement $e): array => $e->toArray(), $this->children);
    }

    /**
     * The box around an element ([x0, top, x1, bottom]): its BBox attribute, or the objects its
     * marked content covers.
     *
     * @throws \OutOfBoundsException when no object of the element is on the page
     */
    public function elementBbox(StructElement $element): array
    {
        $page = $this->page ?? ($element->pageNumber === null ? null : ($this->pages[$element->pageNumber] ?? null));
        $box = $element->attributes['BBox'] ?? null;
        if ($page !== null && is_array($box) && count($box) === 4) {
            [$x0, $x1] = $box[0] <= $box[2] ? [$box[0], $box[2]] : [$box[2], $box[0]];
            [$y0, $y1] = $box[1] <= $box[3] ? [$box[1], $box[3]] : [$box[3], $box[1]];
            $height = $page->mediabox[3] - $page->mediabox[1];
            $bbox = [$x0, $height - $y1, $x1, $height - $y0];
            if ($page !== $page->root) {
                $overlap = Geometry::overlap($bbox, $page->bbox);
                if ($overlap === null) {
                    throw new \OutOfBoundsException('The element is no longer on the page');
                }

                return $overlap;
            }

            return $bbox;
        }
        $boxes = [];
        foreach ($element->allMcids() as [$pageNumber, $mcid]) {
            $source = $pageNumber === null ? $page : ($this->pages[$pageNumber] ?? null);
            foreach ($source === null ? [] : $source->objects() as $objects) {
                foreach ($objects as $object) {
                    if ($object->mcid === $mcid) {
                        $boxes[] = [$object->x0, $object->top, $object->x1, $object->bottom];
                    }
                }
            }
        }
        if ($boxes === []) {
            throw new \OutOfBoundsException('No objects found');
        }

        return [
            min(array_column($boxes, 0)), min(array_column($boxes, 1)),
            max(array_column($boxes, 2)), max(array_column($boxes, 3)),
        ];
    }

    /**
     * The tree read from the root, keeping only elements on the pages read and with content.
     */
    private function parseTree(): void
    {
        $root = $this->rootKids();
        $queue = $root;
        $seen = [];
        while ($queue !== []) {
            $ref = array_shift($queue);
            if (isset($seen[self::key($ref)])) {
                continue;
            }
            $object = $this->document->resolve($ref);
            if ($object instanceof Dict && $object->has('Obj')) {
                if (! $this->onParsedPage($object)) {
                    continue;
                }
                $ref = $object->get('Obj');
                $object = $this->document->resolve($ref);
            }
            if (! $object instanceof Dict) {
                continue;
            }
            [$element, $children] = $this->element($object);
            $seen[self::key($ref)] = [$element, $children];
            foreach ($children as $child) {
                $object = $this->document->resolve($child);
                if ($object instanceof Dict) {
                    if (! $this->onParsedPage($object)) {
                        continue;
                    }
                    if ($object->has('Obj')) {
                        $child = $object->get('Obj');
                    } elseif ($object->has('MCID')) {
                        continue;
                    }
                }
                if ($child instanceof Ref) {
                    $queue[] = $child;
                }
            }
        }

        $prune = function (array $elements) use (&$prune, &$seen): array {
            $kept = [];
            foreach ($elements as $ref) {
                $object = $this->document->resolve($ref);
                if (is_int($ref)) {
                    $kept[] = $ref;

                    continue;
                }
                if ($object instanceof Dict) {
                    if (! $this->onParsedPage($object)) {
                        continue;
                    }
                    if ($object->has('MCID')) {
                        $kept[] = $object->get('MCID');

                        continue;
                    }
                    if ($object->has('Obj')) {
                        $ref = $object->get('Obj');
                    }
                }
                $key = self::key($ref);
                if (! isset($seen[$key])) {
                    continue;
                }
                [$element, $children] = $seen[$key];
                $children = $prune($children);
                if ($children === []) {
                    unset($seen[$key]);
                } else {
                    $seen[$key] = [$element, $children];
                    $kept[] = $ref;
                }
            }

            return $kept;
        };
        $prune($root);
        $this->resolveChildren($seen);
    }

    /**
     * The tree read from the leaves the page's ParentTree entry lists up to the root.
     *
     * @param list<mixed> $parents
     */
    private function parseParentTree(array $parents): void
    {
        $queue = $parents;
        $seen = [];
        while ($queue !== []) {
            $ref = array_shift($queue);
            if ($ref === null || isset($seen[self::key($ref)])) {
                continue;
            }
            $object = $this->document->resolve($ref);
            if (! $object instanceof Dict) {
                continue;
            }
            $type = $this->document->resolve($object->get('Type'));
            if ($type instanceof Name && Encodings::text($type->name) === 'StructTreeRoot') {
                continue;
            }
            [$element, $children] = $this->element($object);
            $seen[self::key($ref)] = [$element, $children];
            $queue[] = $object->get('P');
        }
        $this->resolveChildren($seen);
    }

    /**
     * @param array<string, array{0: StructElement, 1: list<mixed>}> $seen
     */
    private function resolveChildren(array $seen): void
    {
        $parsedRoot = [];
        foreach ($this->rootKids() as $ref) {
            $object = $this->document->resolve($ref);
            if ($object instanceof Dict && $object->has('Obj')) {
                if (! $this->onParsedPage($object)) {
                    continue;
                }
                $ref = $object->get('Obj');
            }
            if (isset($seen[self::key($ref)])) {
                $parsedRoot[] = $ref;
            }
        }
        $queue = $parsedRoot;
        while ($queue !== []) {
            $ref = array_shift($queue);
            [$element, $children] = $seen[self::key($ref)];
            foreach ($children as $child) {
                $object = $this->document->resolve($child);
                if (is_int($object)) {
                    $element->mcids[] = $object;
                } elseif ($object instanceof Dict) {
                    if (! $this->onParsedPage($object)) {
                        continue;
                    }
                    if ($object->has('MCID')) {
                        $mcid = $this->document->resolve($object->get('MCID'));
                        if (is_int($mcid)) {
                            $element->mcids[] = $mcid;
                        }
                    } elseif ($object->has('Obj')) {
                        $child = $object->get('Obj');
                    }
                }
                if ($child instanceof Ref && isset($seen[self::key($child)])) {
                    $element->children[] = $seen[self::key($child)][0];
                    $queue[] = $child;
                }
            }
        }
        $this->children = array_map(static fn (mixed $ref): StructElement => $seen[self::key($ref)][0], $parsedRoot);
    }

    /**
     * @return array{0: StructElement, 1: list<mixed>}
     */
    private function element(Dict $object): array
    {
        $pageNumber = null;
        $pg = $object->get('Pg');
        if ($this->page === null && $pg instanceof Ref) {
            $pageNumber = $this->pageNumbers[$pg->id] ?? null;
        }
        $type = '';
        $s = $this->document->resolve($object->get('S'));
        if ($s instanceof Name) {
            $type = Encodings::text($s->name);
            $role = $this->document->resolve($this->roleMap->get($type));
            if ($role instanceof Name) {
                $type = Encodings::text($role->name);
            }
        }
        $children = $object->has('K') ? $this->document->resolve($object->get('K')) : [];
        if (is_int($children)) {
            $children = [$children];
        } elseif ($children instanceof Dict) {
            $children = [$object->get('K')];
        } elseif (! is_array($children)) {
            $children = [];
        }
        $revision = $this->document->plain($object->get('R'));
        $text = function (string $key) use ($object): ?string {
            $value = $this->document->resolve($object->get($key));

            return is_string($value) ? Encodings::text($value) : ($value instanceof Name ? $value->name : null);
        };

        return [
            new StructElement(
                $type,
                $revision,
                $text('ID'),
                $text('Lang'),
                $text('Alt'),
                $text('ActualText'),
                $text('T'),
                $pageNumber,
                $this->attributes($object, $revision),
            ),
            $children,
        ];
    }

    /**
     * The element's attributes from its /C classes and /A objects, /A winning, revisions honoured.
     *
     * @return array<string, mixed>
     */
    private function attributes(Dict $object, mixed $revision): array
    {
        $list = [];
        foreach (['C', 'A'] as $key) {
            if (! $object->has($key)) {
                continue;
            }
            $value = $this->document->resolve($object->get($key));
            if (is_array($value)) {
                array_push($list, ...$value);
            } else {
                $list[] = $value;
            }
        }
        $objects = [];
        $previous = null;
        foreach ($list as $item) {
            if (is_int($item)) {
                if ($item === $revision && $previous !== null) {
                    $objects[] = $previous;
                }
                $previous = null;
            } else {
                if ($previous !== null) {
                    $objects[] = $previous;
                }
                $previous = $this->document->resolve($item);
            }
        }
        if ($previous !== null) {
            $objects[] = $previous;
        }
        $attributes = [];
        foreach ($objects as $attributeObject) {
            if ($attributeObject instanceof Name) {
                $attributeObject = $this->document->resolve($this->classMap->get(Encodings::text($attributeObject->name)));
            }
            if (! $attributeObject instanceof Dict) {
                continue;
            }
            foreach ($attributeObject->entries as $key => $value) {
                $attributes[$key] = $value instanceof Name ? Encodings::text($value->name) : $this->document->plain($value);
            }
        }

        return $attributes;
    }

    private function onParsedPage(Dict $object): bool
    {
        $pg = $object->get('Pg');
        if (! $object->has('Pg')) {
            return true;
        }
        $id = $pg instanceof Ref ? $pg->id : -1;
        if ($this->page === null) {
            return isset($this->pageNumbers[$id]);
        }

        return $id === $this->pageObjectId;
    }

    /**
     * @return list<mixed>
     */
    private function rootKids(): array
    {
        $kids = $this->document->resolve($this->root->get('K'));
        if ($kids instanceof Dict) {
            return [$this->root->get('K')];
        }

        return is_array($kids) ? $kids : [];
    }

    /**
     * @return list<array{0: int, 1: mixed}>
     */
    private function numberTree(mixed $node, int $depth = 0): array
    {
        $node = $this->document->resolve($node);
        if (! $node instanceof Dict || $depth > 32) {
            return [];
        }
        $items = [];
        $nums = $this->document->resolve($node->get('Nums'));
        if (is_array($nums)) {
            for ($i = 0; $i + 1 < count($nums); $i += 2) {
                $key = $this->document->resolve($nums[$i]);
                if (is_int($key)) {
                    $items[] = [$key, $nums[$i + 1]];
                }
            }
        }
        $kids = $this->document->resolve($node->get('Kids'));
        foreach (is_array($kids) ? $kids : [] as $kid) {
            array_push($items, ...$this->numberTree($kid, $depth + 1));
        }
        usort($items, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $items;
    }

    private static function key(mixed $ref): string
    {
        return match (true) {
            $ref instanceof Ref => 'r'.$ref->id,
            is_object($ref) => 'o'.spl_object_id($ref),
            default => 'v'.serialize($ref),
        };
    }
}
