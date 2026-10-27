<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Structure;

/**
 * An element of the document's logical structure (a heading, paragraph, table cell, figure, …)
 * with the marked-content ids of the objects it covers.
 */
final class StructElement
{
    use Findable;

    /**
     * @param array<string, mixed> $attributes
     * @param list<int> $mcids marked-content ids, which match the mcid of chars and graphics
     * @param list<StructElement> $children
     */
    public function __construct(
        public string $type,
        public mixed $revision = null,
        public ?string $id = null,
        public ?string $lang = null,
        public ?string $altText = null,
        public ?string $actualText = null,
        public ?string $title = null,
        public ?int $pageNumber = null,
        public array $attributes = [],
        public array $mcids = [],
        public array $children = [],
    ) {
    }

    /**
     * Every marked-content id in the element and below it, depth first, as [page number, mcid].
     *
     * @return list<array{0: int|null, 1: int}>
     */
    public function allMcids(): array
    {
        $all = [];
        foreach ($this->mcids as $mcid) {
            $all[] = [$this->pageNumber, $mcid];
        }
        $queue = $this->children;
        while ($queue !== []) {
            $element = array_shift($queue);
            foreach ($element->mcids as $mcid) {
                $all[] = [$element->pageNumber, $mcid];
            }
            array_unshift($queue, ...$element->children);
        }

        return $all;
    }

    /**
     * The element as pdfplumber's to_dict(): empty values left out.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $dict = [
            'type' => $this->type,
            'revision' => $this->revision,
            'id' => $this->id,
            'lang' => $this->lang,
            'alt_text' => $this->altText,
            'actual_text' => $this->actualText,
            'title' => $this->title,
            'page_number' => $this->pageNumber,
            'attributes' => $this->attributes,
            'mcids' => $this->mcids,
            'children' => array_map(static fn (StructElement $e): array => $e->toArray(), $this->children),
        ];

        return array_filter($dict, static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
