<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Font;

/**
 * Turns the bytes of a string shown with a composite font into character ids (CIDs).
 */
final class CMap
{
    public const IDENTITY = 1;

    public const IDENTITY_BYTE = 2;

    public const TABLE = 3;

    public const RANGES = 4;

    /** @var array<string, self> */
    private static array $predefined = [];

    /**
     * @param array<int, mixed> $table nested byte => (cid | table) map, as in pdfminer's CMap
     * @param list<array{0: string, 1: string}> $codespaces low/high byte strings of each code space
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $ranges [length, low, high, first cid]
     * @param array<int, array<int, int>> $singles length => code => cid
     */
    public function __construct(
        public readonly int $kind,
        public readonly bool $vertical = false,
        private readonly array $table = [],
        private readonly array $codespaces = [],
        private readonly array $ranges = [],
        private readonly array $singles = [],
        private readonly ?self $base = null,
    ) {
    }

    /**
     * A predefined CMap by name; an unknown name gives a CMap that decodes nothing, as in pdfminer.
     */
    public static function named(string $name): ?self
    {
        $name = ['DLIdent-H' => 'Identity-H', 'DLIdent-V' => 'Identity-V'][$name] ?? $name;
        if (isset(self::$predefined[$name])) {
            return self::$predefined[$name];
        }
        $cmap = match ($name) {
            'Identity-H' => new self(self::IDENTITY),
            'Identity-V' => new self(self::IDENTITY, true),
            'OneByteIdentityH' => new self(self::IDENTITY_BYTE),
            'OneByteIdentityV' => new self(self::IDENTITY_BYTE, true),
            default => null,
        };
        if ($cmap === null) {
            $data = Resources::cmap($name);
            if ($data === null) {
                return null;
            }
            $cmap = new self(self::TABLE, (bool) ($data['IS_VERTICAL'] ?? false), $data['CODE2CID'] ?? []);
        }

        return self::$predefined[$name] = $cmap;
    }

    public static function empty(): self
    {
        return new self(self::TABLE);
    }

    /**
     * @return list<int>
     */
    public function decode(string $bytes): array
    {
        switch ($this->kind) {
            case self::IDENTITY:
                $length = strlen($bytes) & ~1;

                return $length === 0 ? [] : array_values(unpack('n*', substr($bytes, 0, $length)) ?: []);
            case self::IDENTITY_BYTE:
                return $bytes === '' ? [] : array_values(unpack('C*', $bytes) ?: []);
            case self::TABLE:
                $cids = [];
                $node = $this->table;
                for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
                    $next = $node[ord($bytes[$i])] ?? null;
                    if ($next === null) {
                        $node = $this->table;
                    } elseif (is_int($next)) {
                        $cids[] = $next;
                        $node = $this->table;
                    } else {
                        $node = $next;
                    }
                }

                return $cids;
            default:
                return $this->decodeRanges($bytes);
        }
    }

    /**
     * @return list<int>
     */
    private function decodeRanges(string $bytes): array
    {
        $cids = [];
        $length = strlen($bytes);
        $i = 0;
        while ($i < $length) {
            $size = $this->codeLength($bytes, $i);
            if ($size === 0) {
                $i++;

                continue;
            }
            $code = Encodings::unpackInt(substr($bytes, $i, $size));
            $cid = $this->singles[$size][$code] ?? null;
            if ($cid === null) {
                foreach ($this->ranges as [$rangeSize, $low, $high, $first]) {
                    if ($rangeSize === $size && $code >= $low && $code <= $high) {
                        $cid = $first + $code - $low;
                        break;
                    }
                }
            }
            if ($cid === null && $this->base !== null) {
                $found = $this->base->decode(substr($bytes, $i, $size));
                $cid = $found[0] ?? null;
            }
            if ($cid !== null) {
                $cids[] = $cid;
            }
            $i += $size;
        }

        return $cids;
    }

    private function codeLength(string $bytes, int $at): int
    {
        foreach ($this->codespaces as [$low, $high]) {
            $size = strlen($low);
            if ($at + $size > strlen($bytes)) {
                continue;
            }
            $inside = true;
            for ($k = 0; $k < $size; $k++) {
                $byte = $bytes[$at + $k];
                if ($byte < $low[$k] || $byte > $high[$k]) {
                    $inside = false;
                    break;
                }
            }
            if ($inside) {
                return $size;
            }
        }

        return $this->codespaces === [] ? min(2, strlen($bytes) - $at) : 0;
    }
}
