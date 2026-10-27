<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Parser;

use Artygrand\Pdfplumber\Exception;

/**
 * A parsed PDF file: cross-reference tables, the trailer, objects on demand and decryption.
 *
 * Damaged files are read where possible: when the cross-reference data is missing or points to
 * the wrong place, objects are found by scanning the file.
 */
final class Document
{
    private const INHERITED = ['Resources', 'MediaBox', 'CropBox', 'Rotate'];

    public readonly Dict $trailer;

    public readonly Dict $catalog;

    /** @var array<int, array{0: int, 1: int, 2: int}> id => [1, offset, gen] or [2, stream id, index] */
    private array $xref = [];

    /** @var array<int, mixed> */
    private array $objects = [];

    /** @var array<int, true> */
    private array $loading = [];

    /** @var array<int, array{0: array<int, int>, 1: string}|null> stream id => [id => offset, data] */
    private array $objectStreams = [];

    /** @var array<int, int>|null */
    private ?array $scanned = null;

    private ?SecurityHandler $security = null;

    /** @var array<int, list<int>>|null */
    private ?array $scannedStreams = null;

    private object $missing;

    public function __construct(public readonly string $data, string $password = '')
    {
        $this->missing = new \stdClass();
        if (! str_contains(substr($data, 0, 1024), '%PDF') && ! str_contains($data, 'obj')) {
            throw new Exception('Not a PDF file');
        }

        $trailer = $this->readCrossReference();
        if (! $this->resolve($trailer->get('Root')) instanceof Dict) {
            $trailer = $this->rebuildCrossReference($trailer);
        }
        $this->trailer = $trailer;

        $encrypt = $this->resolve($trailer->get('Encrypt'));
        if ($encrypt instanceof Dict) {
            $ids = $this->resolve($trailer->get('ID'));
            $documentId = is_array($ids) && is_string($ids[0] ?? null) ? $ids[0] : '';
            $resolved = new Dict(array_map(fn ($value) => $this->resolve($value), $encrypt->entries));
            $this->security = SecurityHandler::create($resolved, $documentId, $password);
            // Objects read before the password was known (the catalog among them) are read again.
            $this->objects = [];
            $this->objectStreams = [];
        }

        $catalog = $this->resolve($trailer->get('Root'));
        if (! $catalog instanceof Dict) {
            throw new Exception('The PDF has no document catalog');
        }
        $this->catalog = $catalog;
    }

    public function get(int $id): mixed
    {
        if (array_key_exists($id, $this->objects)) {
            return $this->objects[$id];
        }
        if (isset($this->loading[$id])) {
            return null;
        }
        $this->loading[$id] = true;
        try {
            $value = $this->load($id);
        } finally {
            unset($this->loading[$id]);
        }

        return $this->objects[$id] = $value === $this->missing ? null : $value;
    }

    public function resolve(mixed $value): mixed
    {
        $depth = 0;
        while ($value instanceof Ref && $depth++ < 32) {
            $value = $this->get($value->id);
        }

        return $value instanceof Ref ? null : $value;
    }

    public function decodeStream(Stream $stream, string $raw): string
    {
        if ($this->security !== null && $stream->id > 0 && ! $this->isXrefStream($stream->dict)) {
            $raw = $this->security->decryptStream($stream->id, $stream->gen, $raw, $stream->dict);
        }

        return Filters::decode($raw, $stream->dict, $this);
    }

    /**
     * Page dictionaries in document order, with inherited attributes copied in.
     *
     * @return list<array{0: int, 1: Dict}>
     */
    public function pages(): array
    {
        $pages = [];
        $visited = [];
        $walk = function (mixed $node, array $inherited) use (&$walk, &$pages, &$visited): void {
            $id = $node instanceof Ref ? $node->id : 0;
            if ($id > 0) {
                if (isset($visited[$id])) {
                    return;
                }
                $visited[$id] = true;
            }
            $dict = $this->resolve($node);
            if ($dict instanceof Stream) {
                $dict = $dict->dict;
            }
            if (! $dict instanceof Dict) {
                return;
            }
            $entries = $dict->entries;
            foreach ($inherited as $key => $value) {
                if (! isset($entries[$key])) {
                    $entries[$key] = $value;
                }
            }
            $type = $this->resolve($entries['Type'] ?? $entries['type'] ?? null);
            $type = $type instanceof Name ? $type->name : '';
            $kids = $this->resolve($entries['Kids'] ?? null);
            if ($type === 'Pages' && is_array($kids)) {
                $next = array_intersect_key($entries, array_flip(self::INHERITED));
                foreach ($kids as $kid) {
                    $walk($kid, $next);
                }
            } elseif ($type === 'Page') {
                $pages[] = [$id, new Dict($entries)];
            }
        };
        $root = $this->catalog->get('Pages');
        if ($root !== null) {
            $walk($root, array_intersect_key($this->catalog->entries, array_flip(self::INHERITED)));
        }

        if ($pages === []) {
            $ids = array_keys($this->xref + $this->scan());
            sort($ids);
            foreach ($ids as $id) {
                $object = $this->get($id);
                $type = $object instanceof Dict ? $this->resolve($object->get('Type')) : null;
                if ($type instanceof Name && $type->name === 'Page') {
                    $pages[] = [$id, $object];
                }
            }
        }

        return $pages;
    }

    private function load(int $id): mixed
    {
        $entry = $this->xref[$id] ?? null;
        if ($entry !== null) {
            $value = $entry[0] === 2
                ? $this->fromObjectStream($entry[1], $id)
                : $this->parseAt($entry[1], $id);
            if ($value !== $this->missing) {
                return $value;
            }
        }
        $offset = $this->scan()[$id] ?? null;
        if ($offset !== null && ($entry === null || $entry[0] === 2 || $offset !== $entry[1])) {
            $value = $this->parseAt($offset, $id);
            if ($value !== $this->missing) {
                return $value;
            }
        }
        if ($entry === null || $entry[0] !== 2) {
            foreach ($this->scannedObjectStreams() as $streamId => $ids) {
                if (in_array($id, $ids, true)) {
                    return $this->fromObjectStream($streamId, $id);
                }
            }
        }

        return $this->missing;
    }

    private function parseAt(int $offset, int $id): mixed
    {
        if ($offset < 0 || $offset >= strlen($this->data)) {
            return $this->missing;
        }
        $lexer = new Lexer($this->data, $offset);
        $number = $lexer->next();
        $gen = $lexer->next();
        $keyword = $lexer->next();
        if ($number !== $id || ! is_int($gen) || ! $keyword instanceof Keyword || $keyword->name !== 'obj') {
            return $this->missing;
        }
        $parser = new Parser($lexer);
        $value = $parser->read();
        if ($value instanceof Dict) {
            $before = $lexer->pos;
            $next = $lexer->next();
            if ($next instanceof Keyword && $next->name === 'stream') {
                return $this->readStream($value, $lexer->pos, $id, $gen);
            }
            $lexer->pos = $before;
        }
        if ($value instanceof Keyword && $value->name === 'endobj') {
            $value = null;
        }
        if ($this->security !== null) {
            $value = $this->decryptStrings($value, $id, $gen);
        }

        return $value;
    }

    private function readStream(Dict $dict, int $afterKeyword, int $id, int $gen): Stream
    {
        $data = $this->data;
        $total = strlen($data);
        // The data starts after the end of the line holding the "stream" keyword.
        $start = $afterKeyword + strcspn($data, "\r\n", $afterKeyword);
        if ($start < $total && $data[$start] === "\r") {
            $start++;
        }
        if ($start < $total && $data[$start] === "\n") {
            $start++;
        }

        $length = $this->resolve($dict->get('Length'));
        $length = is_int($length) || is_float($length) ? (int) $length : -1;
        $valid = $length >= 0 && $start + $length <= $total
            && preg_match('/\G\s*endstream/', $data, $match, 0, $start + $length) === 1;
        if (! $valid) {
            $end = strpos($data, 'endstream', $start);
            $end = $end === false ? $total : $end;
            if ($end > $start && $data[$end - 1] === "\n") {
                $end--;
            }
            if ($end > $start && $data[$end - 1] === "\r") {
                $end--;
            }
            $length = $end - $start;
        }

        return new Stream($dict, substr($data, $start, $length), $this, $id, $gen);
    }

    private function fromObjectStream(int $streamId, int $id): mixed
    {
        if (! array_key_exists($streamId, $this->objectStreams)) {
            $this->objectStreams[$streamId] = $this->loadObjectStream($streamId);
        }
        $objectStream = $this->objectStreams[$streamId];
        if ($objectStream === null || ! isset($objectStream[0][$id])) {
            return $this->missing;
        }
        $parser = new Parser(new Lexer($objectStream[1], $objectStream[0][$id]));

        return $parser->read();
    }

    /**
     * @return array{0: array<int, int>, 1: string}|null
     */
    private function loadObjectStream(int $streamId): ?array
    {
        $stream = $this->get($streamId);
        if (! $stream instanceof Stream) {
            return null;
        }
        $data = $stream->data();
        $count = $this->resolve($stream->get('N'));
        $first = $this->resolve($stream->get('First'));
        if (! is_int($count) || ! is_int($first)) {
            return null;
        }
        $lexer = new Lexer($data);
        $offsets = [];
        for ($i = 0; $i < $count; $i++) {
            $id = $lexer->next();
            $offset = $lexer->next();
            if (! is_int($id) || ! is_int($offset)) {
                break;
            }
            // The first object listed under a number wins, as in the cross-reference stream.
            $offsets[$id] ??= $first + $offset;
        }

        return [$offsets, $data];
    }

    private function decryptStrings(mixed $value, int $id, int $gen): mixed
    {
        if (is_string($value)) {
            return $this->security?->decryptString($id, $gen, $value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->decryptStrings($item, $id, $gen);
            }
        } elseif ($value instanceof Dict) {
            foreach ($value->entries as $key => $item) {
                $value->entries[$key] = $this->decryptStrings($item, $id, $gen);
            }
        }

        return $value;
    }

    private function isXrefStream(Dict $dict): bool
    {
        $type = $dict->get('Type');

        return $type instanceof Name && $type->name === 'XRef';
    }

    private function readCrossReference(): Dict
    {
        $data = $this->data;
        $at = strrpos($data, 'startxref');
        if ($at === false || preg_match('/\G\s*(\d+)/', $data, $match, 0, $at + 9) !== 1) {
            return new Dict();
        }

        $sections = [];
        $visited = [];
        $pending = [(int) $match[1]];
        try {
            while ($pending !== []) {
                $offset = array_shift($pending);
                if (isset($visited[$offset])) {
                    continue;
                }
                $visited[$offset] = true;
                $section = $this->readXrefSection($offset);
                if ($section === null) {
                    return new Dict();
                }
                $sections[] = $section;
                $trailer = $section[1];
                $next = [];
                foreach (['XRefStm', 'Prev'] as $key) {
                    $value = $trailer->get($key);
                    if (is_int($value) || is_float($value)) {
                        $next[] = (int) $value;
                    }
                }
                $pending = [...$next, ...$pending];
            }
        } catch (\Throwable) {
            return new Dict();
        }

        // The newest section comes first; older sections fill what newer ones lack.
        $trailer = [];
        foreach (array_reverse($sections) as [$entries, $sectionTrailer]) {
            foreach ($entries as $id => $entry) {
                $this->xref[$id] = $entry;
            }
            $trailer = $sectionTrailer->entries + $trailer;
        }

        return new Dict($trailer);
    }

    /**
     * @return array{0: array<int, array{0: int, 1: int, 2: int}>, 1: Dict}|null
     */
    private function readXrefSection(int $offset): ?array
    {
        $data = $this->data;
        $pos = $offset + strspn($data, Lexer::WHITESPACE, $offset);
        if (substr($data, $pos, 4) === 'xref') {
            return $this->readXrefTable($pos + 4);
        }

        $lexer = new Lexer($data, $pos);
        $id = $lexer->next();
        if (! is_int($id)) {
            return null;
        }
        $stream = $this->parseAt($pos, $id);
        if (! $stream instanceof Stream || ! $this->isXrefStream($stream->dict)) {
            return null;
        }
        $this->objects[$id] = $stream;

        return [$this->xrefStreamEntries($stream), $stream->dict];
    }

    /**
     * @return array{0: array<int, array{0: int, 1: int, 2: int}>, 1: Dict}|null
     */
    private function readXrefTable(int $pos): ?array
    {
        $data = $this->data;
        $entries = [];
        while (true) {
            $pos += strspn($data, Lexer::WHITESPACE, $pos);
            if (substr($data, $pos, 7) === 'trailer') {
                $parser = new Parser(new Lexer($data, $pos + 7));
                $trailer = $parser->read();

                return [$entries, $trailer instanceof Dict ? $trailer : new Dict()];
            }
            if (preg_match('/\G(\d+)[ \t]+(\d+)[ \t]*[\r\n]/', $data, $match, 0, $pos) !== 1) {
                return null;
            }
            $start = (int) $match[1];
            $count = (int) $match[2];
            $pos += strlen($match[0]);
            for ($i = 0; $i < $count; $i++) {
                if (preg_match('/\G\s*(\d+)\s+(\d+)\s+([nf])/', $data, $match, 0, $pos) !== 1) {
                    break;
                }
                $pos += strlen($match[0]);
                if ($match[3] === 'n') {
                    $entries[$start + $i] = [1, (int) $match[1], (int) $match[2]];
                }
            }
        }
    }

    /**
     * @return array<int, array{0: int, 1: int, 2: int}>
     */
    private function xrefStreamEntries(Stream $stream): array
    {
        $widths = $this->resolve($stream->get('W'));
        if (! is_array($widths) || count($widths) < 3) {
            return [];
        }
        [$w1, $w2, $w3] = array_map(fn ($width) => (int) $this->resolve($width), array_slice($widths, 0, 3));
        $size = (int) $this->resolve($stream->get('Size', 0));
        $index = $this->resolve($stream->get('Index')) ?? [0, $size];
        $data = $stream->data();
        $entryLength = $w1 + $w2 + $w3;
        if ($entryLength <= 0) {
            return [];
        }
        $entries = [];
        $pos = 0;
        $total = strlen($data);
        for ($i = 0; $i + 1 < count($index); $i += 2) {
            $first = (int) $this->resolve($index[$i]);
            $count = (int) $this->resolve($index[$i + 1]);
            for ($n = 0; $n < $count && $pos + $entryLength <= $total; $n++, $pos += $entryLength) {
                $type = $w1 === 0 ? 1 : self::unpackInt($data, $pos, $w1);
                $field2 = self::unpackInt($data, $pos + $w1, $w2);
                $field3 = self::unpackInt($data, $pos + $w1 + $w2, $w3);
                if ($type === 1 || $type === 2) {
                    $entries[$first + $n] = [$type, $field2, $field3];
                }
            }
        }

        return $entries;
    }

    private static function unpackInt(string $data, int $pos, int $length): int
    {
        $value = 0;
        for ($i = 0; $i < $length; $i++) {
            $value = ($value << 8) | ord($data[$pos + $i]);
        }

        return $value;
    }

    /**
     * Rebuilds the cross-reference from a scan of the file when it is missing or broken.
     */
    private function rebuildCrossReference(Dict $trailer): Dict
    {
        $this->xref = [];
        $this->objects = [];
        foreach ($this->scan() as $id => $offset) {
            $this->xref[$id] = [1, $offset, 0];
        }
        $entries = $trailer->entries;

        $data = $this->data;
        preg_match_all('/trailer/', $data, $found, PREG_OFFSET_CAPTURE);
        foreach (array_reverse($found[0]) as [, $at]) {
            $candidate = (new Parser(new Lexer($data, $at + 7)))->read();
            if ($candidate instanceof Dict && $candidate->has('Root')) {
                $entries = $candidate->entries + $entries;
                break;
            }
        }
        if (! $this->resolve($entries['Root'] ?? null) instanceof Dict) {
            unset($entries['Root']);
            foreach (array_keys($this->xref) as $id) {
                $object = $this->get($id);
                $dict = $object instanceof Stream ? $object->dict : $object;
                if (! $dict instanceof Dict) {
                    continue;
                }
                $type = $dict->get('Type');
                if ($type instanceof Name && $type->name === 'XRef' && $dict->has('Root')) {
                    $entries = $dict->entries + $entries;
                }
                if ($type instanceof Name && $type->name === 'Catalog' && ! isset($entries['Root'])) {
                    $entries['Root'] = new Ref($id);
                }
            }
        }
        if (! isset($entries['Root'])) {
            throw new Exception('The PDF has no document catalog');
        }

        return new Dict($entries);
    }

    /**
     * Offsets of "N G obj" headers found by scanning the file; later definitions win.
     *
     * @return array<int, int>
     */
    private function scan(): array
    {
        if ($this->scanned !== null) {
            return $this->scanned;
        }
        $this->scanned = [];
        preg_match_all('/(?<![0-9])(\d+)\s+\d+\s+obj\b/', $this->data, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as [$id, $offset]) {
            $this->scanned[(int) $id] = $offset;
        }

        return $this->scanned;
    }

    /**
     * Object streams found by the scan and the object numbers each one holds.
     *
     * @return array<int, list<int>>
     */
    private function scannedObjectStreams(): array
    {
        if ($this->scannedStreams !== null) {
            return $this->scannedStreams;
        }
        $streams = [];
        foreach ($this->scan() as $id => $offset) {
            if (preg_match('/\G\d+\s+\d+\s+obj\s*<<[^>]*\/Type\s*\/ObjStm/', $this->data, $m, 0, $offset) !== 1) {
                continue;
            }
            $objectStream = $this->objectStreams[$id] ??= $this->loadObjectStream($id);
            if ($objectStream !== null) {
                $streams[$id] = array_keys($objectStream[0]);
            }
        }

        return $this->scannedStreams = $streams;
    }
}
