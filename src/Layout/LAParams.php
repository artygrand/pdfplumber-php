<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Layout;

/**
 * pdfminer's layout analysis parameters: how chars are grouped into lines, lines into boxes, and
 * in what order boxes are read.
 */
final class LAParams
{
    /**
     * @param float $lineOverlap chars overlapping by more than this (relative to the lower one) share a line
     * @param float $charMargin chars closer than this (relative to the wider one) share a line
     * @param float $lineMargin lines closer than this (relative to their height) share a box
     * @param float $wordMargin chars further apart than this (relative to the char) get a space between
     * @param float|null $boxesFlow from -1 (only horizontal position matters) to +1 (only vertical) for the
     *        order of boxes; null to order them by their bottom-left corner
     * @param bool $detectVertical also look for vertical lines of text
     * @param bool $allTexts also analyse the text inside figures
     */
    public function __construct(
        public readonly float $lineOverlap = 0.5,
        public readonly float $charMargin = 2.0,
        public readonly float $lineMargin = 0.5,
        public readonly float $wordMargin = 0.1,
        public readonly ?float $boxesFlow = 0.5,
        public readonly bool $detectVertical = false,
        public readonly bool $allTexts = false,
    ) {
        if ($boxesFlow !== null && ($boxesFlow < -1 || $boxesFlow > 1)) {
            throw new \InvalidArgumentException('boxesFlow is null or a number between -1 and +1');
        }
    }

    /**
     * @param array<string, mixed>|self $params keys in camelCase or snake_case
     */
    public static function from(array|self $params): self
    {
        if ($params instanceof self) {
            return $params;
        }
        $named = [];
        foreach ($params as $key => $value) {
            $named[lcfirst(str_replace('_', '', ucwords((string) $key, '_')))] = $value;
        }

        try {
            return new self(...$named);
        } catch (\Error $e) {
            throw new \InvalidArgumentException('Unknown layout parameter: '.$e->getMessage(), 0, $e);
        }
    }
}
