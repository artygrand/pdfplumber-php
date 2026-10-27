<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Parser;

/**
 * A stream object: its dictionary and the raw bytes, decrypted and decoded on first use.
 */
final class Stream
{
    private ?string $decoded = null;

    public function __construct(
        public readonly Dict $dict,
        private ?string $raw,
        private readonly ?Document $document = null,
        public readonly int $id = 0,
        public readonly int $gen = 0,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->dict->entries[$key] ?? $default;
    }

    public function data(): string
    {
        if ($this->decoded === null) {
            $raw = $this->raw ?? '';
            $this->decoded = $this->document === null
                ? Filters::decode($raw, $this->dict, null)
                : $this->document->decodeStream($this, $raw);
            $this->raw = null;
        }

        return $this->decoded;
    }
}
