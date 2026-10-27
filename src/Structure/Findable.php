<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Structure;

/**
 * find() and findAll() over the elements below a tree or an element.
 */
trait Findable
{
    /**
     * Matching elements below, depth first. $matcher is an element type ('P', 'Table'), a regular
     * expression with delimiters ('/^H\d$/'), or a function taking a StructElement.
     *
     * @param string|callable(StructElement): bool $matcher
     *
     * @return list<StructElement>
     */
    public function findAll(string|callable $matcher): array
    {
        $test = match (true) {
            is_callable($matcher) && ! is_string($matcher) => $matcher,
            @preg_match($matcher, '') !== false => static fn (StructElement $e): bool => preg_match($matcher, $e->type) === 1,
            default => static fn (StructElement $e): bool => $e->type === $matcher,
        };
        $found = [];
        $queue = $this->children;
        while ($queue !== []) {
            $element = array_shift($queue);
            if ($test($element)) {
                $found[] = $element;
            }
            array_unshift($queue, ...$element->children);
        }

        return $found;
    }

    /**
     * The first matching element below, or null.
     *
     * @param string|callable(StructElement): bool $matcher
     */
    public function find(string|callable $matcher): ?StructElement
    {
        return $this->findAll($matcher)[0] ?? null;
    }
}
