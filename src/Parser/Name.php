<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Parser;

/**
 * A PDF name (/Font). Instances are interned, so names compare with ===.
 */
final class Name
{
    /** @var array<string, Name> */
    private static array $pool = [];

    private function __construct(public readonly string $name)
    {
    }

    public static function of(string $name): self
    {
        return self::$pool[$name] ??= new self($name);
    }
}
