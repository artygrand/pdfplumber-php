<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Parser;

/**
 * A bare word or delimiter in a PDF or PostScript stream: operators (BT, Tj), obj, R, [, <<.
 * Instances are interned, so keywords compare with ===.
 */
final class Keyword
{
    /** @var array<string, Keyword> */
    private static array $pool = [];

    private function __construct(public readonly string $name)
    {
    }

    public static function of(string $name): self
    {
        return self::$pool[$name] ??= new self($name);
    }
}
