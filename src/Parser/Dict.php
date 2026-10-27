<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Parser;

/**
 * A PDF dictionary. Keys are name strings; values stay unresolved until read through the document.
 */
final class Dict
{
    /**
     * @param array<array-key, mixed> $entries
     */
    public function __construct(public array $entries = [])
    {
    }

    public function has(string $key): bool
    {
        return isset($this->entries[$key]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->entries[$key] ?? $default;
    }
}
