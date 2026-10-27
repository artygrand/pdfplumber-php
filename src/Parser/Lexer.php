<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Parser;

/**
 * Tokenizer for PDF objects, content streams and CMaps, reading a string held in memory.
 *
 * Tokens: int, float, bool, string (a PDF string's bytes), Name, Keyword (operators and the
 * delimiters [ ] << >> { }), and null at the end of data.
 */
final class Lexer
{
    public const WHITESPACE = " \t\n\r\f\v\0";

    private const DIGITS = '0123456789';

    private const KEYWORD_END = "#/%[]()<>{} \t\n\r\f\v";

    private const NAME_END = "#/%[]()<>{} \t\n\r\f\v";

    private const STRING_ESCAPES = [
        'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f",
        '(' => '(', ')' => ')', '\\' => '\\',
    ];

    public int $pos;

    public readonly int $length;

    private static Keyword $arrayStart;

    private static Keyword $arrayEnd;

    private static Keyword $dictStart;

    private static Keyword $dictEnd;

    private static Keyword $procStart;

    private static Keyword $procEnd;

    public function __construct(public readonly string $data, int $pos = 0)
    {
        $this->pos = $pos;
        $this->length = strlen($data);
        if (! isset(self::$arrayStart)) {
            self::$arrayStart = Keyword::of('[');
            self::$arrayEnd = Keyword::of(']');
            self::$dictStart = Keyword::of('<<');
            self::$dictEnd = Keyword::of('>>');
            self::$procStart = Keyword::of('{');
            self::$procEnd = Keyword::of('}');
        }
    }

    public function next(): mixed
    {
        $data = $this->data;
        $length = $this->length;
        $pos = $this->pos;

        while (true) {
            $pos += strspn($data, self::WHITESPACE, $pos);
            if ($pos >= $length) {
                $this->pos = $length;

                return null;
            }
            $c = $data[$pos];
            switch ($c) {
                case '%':
                    $pos += strcspn($data, "\r\n", $pos);

                    continue 2;
                case '/':
                    return $this->name($pos + 1);
                case '(':
                    return $this->literalString($pos + 1);
                case '<':
                    if ($pos + 1 < $length && $data[$pos + 1] === '<') {
                        $this->pos = $pos + 2;

                        return self::$dictStart;
                    }

                    return $this->hexString($pos + 1);
                case '>':
                    if ($pos + 1 < $length && $data[$pos + 1] === '>') {
                        $this->pos = $pos + 2;

                        return self::$dictEnd;
                    }
                    $pos++;

                    continue 2;
                case '[':
                    $this->pos = $pos + 1;

                    return self::$arrayStart;
                case ']':
                    $this->pos = $pos + 1;

                    return self::$arrayEnd;
                case '{':
                    $this->pos = $pos + 1;

                    return self::$procStart;
                case '}':
                    $this->pos = $pos + 1;

                    return self::$procEnd;
                case '0': case '1': case '2': case '3': case '4':
                case '5': case '6': case '7': case '8': case '9':
                case '-': case '+': case '.':
                    $p = $pos + 1;
                    $isFloat = $c === '.';
                    $p += strspn($data, self::DIGITS, $p);
                    if (! $isFloat && $p < $length && $data[$p] === '.') {
                        $isFloat = true;
                        $p++;
                        $p += strspn($data, self::DIGITS, $p);
                    }
                    $this->pos = $p;
                    $text = substr($data, $pos, $p - $pos);
                    if (strpbrk($text, self::DIGITS) === false) {
                        // A lone sign or dot is not a number; skip it like pdfminer does.
                        $pos = $p;

                        continue 2;
                    }

                    return $isFloat ? (float) $text : (int) $text;
            }

            if (ctype_alpha($c)) {
                $n = strcspn($data, self::KEYWORD_END, $pos);
                $this->pos = $pos + $n;
                $word = substr($data, $pos, $n);
                if ($word === 'true') {
                    return true;
                }
                if ($word === 'false') {
                    return false;
                }

                return Keyword::of($word);
            }

            // Any other byte (' " * and stray characters) is a one-character keyword.
            $this->pos = $pos + 1;

            return Keyword::of($c);
        }
    }

    private function name(int $pos): Name
    {
        $data = $this->data;
        $length = $this->length;
        $name = '';
        while (true) {
            $n = strcspn($data, self::NAME_END, $pos);
            $name .= substr($data, $pos, $n);
            $pos += $n;
            if ($pos < $length && $data[$pos] === '#') {
                $q = $pos + 1;
                $hex = '';
                while (strlen($hex) < 2 && $q < $length && ctype_xdigit($data[$q])) {
                    $hex .= $data[$q];
                    $q++;
                }
                if ($hex !== '') {
                    $name .= chr((int) hexdec($hex));
                }
                $pos = $q;

                continue;
            }
            break;
        }
        $this->pos = $pos;

        return Name::of($name);
    }

    private function literalString(int $pos): string
    {
        $data = $this->data;
        $length = $this->length;
        $depth = 1;
        $out = '';
        while (true) {
            $n = strcspn($data, '()\\', $pos);
            $out .= substr($data, $pos, $n);
            $pos += $n;
            if ($pos >= $length) {
                break;
            }
            $c = $data[$pos];
            if ($c === '\\') {
                $pos++;
                if ($pos >= $length) {
                    break;
                }
                $e = $data[$pos];
                if ($e >= '0' && $e <= '7') {
                    $n = strspn($data, '01234567', $pos, 3);
                    $out .= chr(octdec(substr($data, $pos, $n)) & 0xFF);
                    $pos += $n;
                } elseif (isset(self::STRING_ESCAPES[$e])) {
                    $out .= self::STRING_ESCAPES[$e];
                    $pos++;
                } elseif ($e === "\r") {
                    $pos++;
                    if ($pos < $length && $data[$pos] === "\n") {
                        $pos++;
                    }
                } elseif ($e === "\n") {
                    $pos++;
                } else {
                    // An unknown escape keeps the character and drops the backslash.
                    $out .= $e;
                    $pos++;
                }
            } elseif ($c === '(') {
                $depth++;
                $out .= '(';
                $pos++;
            } else {
                $depth--;
                $pos++;
                if ($depth === 0) {
                    break;
                }
                $out .= ')';
            }
        }
        $this->pos = $pos;

        return $out;
    }

    private function hexString(int $pos): string
    {
        $data = $this->data;
        $n = strspn($data, "0123456789abcdefABCDEF \t\n\r\f\v", $pos);
        $hex = substr($data, $pos, $n);
        $pos += $n;
        if ($pos < $this->length && $data[$pos] === '>') {
            $pos++;
        }
        $this->pos = $pos;
        $hex = str_replace([' ', "\t", "\n", "\r", "\f", "\v"], '', $hex);
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }

        return (string) hex2bin($hex);
    }
}
