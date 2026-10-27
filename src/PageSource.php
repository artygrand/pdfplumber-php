<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

use Artygrand\Pdfplumber\Structure\StructTree;

/**
 * What a page needs from its document beyond its own objects: its annotations, its part of the
 * structure tree and a rendering. Shared by a page and the pages derived from it.
 *
 * @internal
 */
final class PageSource
{
    /**
     * @param \Closure(): list<array<string, mixed>> $annotations the page's annotation dictionaries, resolved
     * @param \Closure(Page): StructTree $structure
     * @param \Closure(float, bool): string $render the page as PNG at a resolution, antialiased or not
     */
    public function __construct(
        public readonly \Closure $annotations,
        public readonly \Closure $structure,
        public readonly \Closure $render,
        public readonly bool $raiseUnicodeErrors = true,
    ) {
    }
}
