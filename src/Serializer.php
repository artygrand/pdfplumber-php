<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

use Artygrand\Pdfplumber\Parser\Ref;
use Artygrand\Pdfplumber\Parser\Stream;

/**
 * Turns pages and objects into JSON or CSV the way pdfplumber's Serializer does: floats rounded on
 * request, booleans as 0/1, streams as base64 raw data, attributes picked by name.
 */
final class Serializer
{
    private const REQUIRED = ['object_type'];

    private const FIRST_COLUMNS = ['page_number', 'x0', 'x1', 'y0', 'y1', 'doctop', 'top', 'bottom', 'width', 'height'];

    /**
     * @param list<string>|null $includeAttrs object attributes to keep (object_type is always kept)
     * @param list<string>|null $excludeAttrs object attributes to drop
     */
    public function __construct(
        private readonly ?int $precision = null,
        private readonly ?array $includeAttrs = null,
        private readonly ?array $excludeAttrs = null,
    ) {
        if ($includeAttrs !== null && $excludeAttrs !== null) {
            throw new \InvalidArgumentException('Cannot specify includeAttrs and excludeAttrs at the same time.');
        }
        if (array_intersect($excludeAttrs ?? [], self::REQUIRED) !== []) {
            throw new \InvalidArgumentException('Cannot exclude these required properties: object_type');
        }
    }

    public function allows(string $attr): bool
    {
        if ($this->includeAttrs !== null) {
            return in_array($attr, [...self::REQUIRED, ...$this->includeAttrs], true);
        }

        return ! in_array($attr, $this->excludeAttrs ?? [], true);
    }

    public function serialize(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_int($value) => $value,
            is_float($value) => $this->precision === null ? $value : round($value, $this->precision),
            is_bool($value) => (int) $value,
            is_string($value) => mb_check_encoding($value, 'UTF-8') ? $value : null,
            is_array($value) => $this->serializeArray($value),
            $value instanceof PageObject => $this->serialize($value->toArray()),
            $value instanceof Stream => ['rawdata' => ($raw = $value->raw()) === null || $raw === '' ? null : base64_encode($raw)],
            $value instanceof Ref => "<PDFObjRef:{$value->id}>",
            $value instanceof Page => "<Page:{$value->pageNumber}>",
            $value instanceof \Stringable => (string) $value,
            default => get_debug_type($value),
        };
    }

    public function json(mixed $data, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR
            | ($pretty ? JSON_PRETTY_PRINT : 0);

        return (string) json_encode($this->serialize($data), $flags);
    }

    /**
     * One row per object of the given types on each page; columns are object_type, the position
     * columns, then the other attributes in alphabetical order.
     *
     * @param list<Page> $pages
     * @param list<string> $types
     */
    public function csv(array $pages, array $types): string
    {
        $rows = [];
        $fields = [];
        foreach ($pages as $page) {
            foreach ($types as $type) {
                $objects = $page->objectsOf($type);
                if ($objects === []) {
                    continue;
                }
                foreach ($objects as $object) {
                    $rows[] = $this->serialize($object);
                }
                foreach ($objects[0] as $key => $value) {
                    if (! is_array($value) || array_is_list($value)) {
                        $fields[$key] = true;
                    }
                }
            }
        }
        $others = array_diff(array_keys($fields), self::REQUIRED, self::FIRST_COLUMNS);
        sort($others, SORT_STRING);
        $columns = [
            ...self::REQUIRED,
            ...array_values(array_filter([...self::FIRST_COLUMNS, ...$others], $this->allows(...))),
        ];

        $out = fopen('php://temp', 'r+');
        fputcsv($out, $columns, ',', '"', '', "\r\n");
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $line[] = match (true) {
                    $value === null => '',
                    is_float($value) => self::number($value),
                    is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                    default => (string) $value,
                };
            }
            fputcsv($out, $line, ',', '"', '', "\r\n");
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private static function number(float $value): string
    {
        return json_encode($value, JSON_PRESERVE_ZERO_FRACTION) ?: (string) $value;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private function serializeArray(array $value): array
    {
        $object = array_key_exists('object_type', $value);
        $serialized = [];
        foreach ($value as $key => $item) {
            if ($object && ! $this->allows((string) $key)) {
                continue;
            }
            $serialized[$key] = $this->serialize($item);
        }

        return $serialized;
    }
}
