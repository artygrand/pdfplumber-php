<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Parser;

/**
 * Builds PDF values (arrays, dictionaries, references) from lexer tokens.
 */
final class Parser
{
    private static Keyword $arrayStart;

    private static Keyword $arrayEnd;

    private static Keyword $dictStart;

    private static Keyword $dictEnd;

    private static Keyword $null;

    /**
     * @param bool $refs whether "12 0 R" makes a reference; content streams have none
     */
    public function __construct(public readonly Lexer $lexer, private readonly bool $refs = true)
    {
        if (! isset(self::$arrayStart)) {
            self::$arrayStart = Keyword::of('[');
            self::$arrayEnd = Keyword::of(']');
            self::$dictStart = Keyword::of('<<');
            self::$dictEnd = Keyword::of('>>');
            self::$null = Keyword::of('null');
        }
    }

    public function read(): mixed
    {
        return $this->value($this->lexer->next());
    }

    public function value(mixed $token): mixed
    {
        if ($token instanceof Keyword) {
            if ($token === self::$arrayStart) {
                return $this->array();
            }
            if ($token === self::$dictStart) {
                return $this->dict();
            }
            if ($token === self::$null) {
                return null;
            }

            return $token;
        }
        if ($this->refs && is_int($token) && $token >= 0) {
            return $this->refAfter($token) ?? $token;
        }

        return $token;
    }

    /**
     * @return list<mixed>
     */
    public function array(): array
    {
        $lexer = $this->lexer;
        $items = [];
        while (($token = $lexer->next()) !== null) {
            if ($token === self::$arrayEnd) {
                break;
            }
            $items[] = $this->value($token);
        }

        return $items;
    }

    public function dict(): Dict
    {
        $lexer = $this->lexer;
        $entries = [];
        while (true) {
            $before = $lexer->pos;
            $key = $lexer->next();
            if ($key === null || $key === self::$dictEnd) {
                break;
            }
            if (! $key instanceof Name) {
                if ($key instanceof Keyword && in_array($key->name, ['endobj', 'stream', 'endstream', 'obj'], true)) {
                    $lexer->pos = $before;
                    break;
                }
                $this->value($key);

                continue;
            }
            $token = $lexer->next();
            if ($token === null || $token === self::$dictEnd) {
                break;
            }
            $value = $this->value($token);
            if ($value !== null) {
                $entries[$key->name] = $value;
            }
        }

        return new Dict($entries);
    }

    private function refAfter(int $id): ?Ref
    {
        $lexer = $this->lexer;
        $data = $lexer->data;
        $p = $lexer->pos + strspn($data, Lexer::WHITESPACE, $lexer->pos);
        $n = strspn($data, '0123456789', $p);
        if ($n === 0) {
            return null;
        }
        $q = $p + $n;
        $q += strspn($data, Lexer::WHITESPACE, $q);
        if ($q >= $lexer->length || $data[$q] !== 'R') {
            return null;
        }
        if ($q + 1 < $lexer->length && strpos("#/%[]()<>{} \t\n\r\f\v\0", $data[$q + 1]) === false) {
            return null;
        }
        $lexer->pos = $q + 1;

        return new Ref($id, (int) substr($data, $p, $n));
    }
}
