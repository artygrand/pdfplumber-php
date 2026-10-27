<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Text;

/**
 * Options of pdfplumber's text functions, with its defaults. Named like pdfplumber's keyword
 * arguments in camelCase; snake_case keys are accepted too.
 */
final class TextSettings
{
    public const DIRECTIONS = ['ttb', 'btt', 'ltr', 'rtl'];

    public const LIGATURES = [
        'ﬀ' => 'ff', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl', 'ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬆ' => 'st', 'ﬅ' => 'st',
    ];

    public bool $layout = false;

    public float $xTolerance = 3;

    public float $yTolerance = 3;

    public ?float $xToleranceRatio = null;

    public ?float $yToleranceRatio = null;

    public bool $keepBlankChars = false;

    public bool $useTextFlow = false;

    public string $lineDir = 'ttb';

    public string $charDir = 'ltr';

    public ?string $lineDirRotated = null;

    public ?string $charDirRotated = null;

    public ?string $lineDirRender = null;

    public ?string $charDirRender = null;

    /** @var list<string> */
    public array $extraAttrs = [];

    public bool|string $splitAtPunctuation = false;

    public bool $expandLigatures = true;

    public float $xDensity = 7.25;

    public float $yDensity = 13;

    public float $xShift = 0;

    public float $yShift = 0;

    public ?float $layoutWidth = null;

    public ?float $layoutHeight = null;

    public int $layoutWidthChars = 0;

    public int $layoutHeightChars = 0;

    /** @var array{0: float, 1: float, 2: float, 3: float}|null */
    public ?array $layoutBbox = null;

    public bool $presorted = false;

    /**
     * @param array<string, mixed> $options
     */
    public static function from(array $options): self
    {
        $settings = new self();
        foreach ($options as $key => $value) {
            $property = lcfirst(str_replace('_', '', ucwords((string) $key, '_')));
            if (! property_exists($settings, $property)) {
                throw new \InvalidArgumentException("Unknown text option: {$key}");
            }
            if ($property === 'layoutWidthChars' || $property === 'layoutHeightChars') {
                $value = (int) $value;
            } elseif ($property === 'layoutBbox' && is_array($value)) {
                $value = array_map('floatval', array_values($value));
            }
            $settings->{$property} = $value;
        }
        $settings->validate();

        return $settings;
    }

    public function lineDirRotated(): string
    {
        return $this->lineDirRotated ?? $this->charDir;
    }

    public function charDirRotated(): string
    {
        return $this->charDirRotated ?? $this->lineDir;
    }

    public function charDirFor(bool $upright): string
    {
        return $upright ? $this->charDir : $this->charDirRotated();
    }

    private function validate(): void
    {
        self::directions($this->lineDir, $this->charDir, '');
        self::directions($this->lineDirRotated(), $this->charDirRotated(), '_rotated');
        if ($this->lineDirRender !== null || $this->charDirRender !== null) {
            self::directions($this->lineDirRender ?? $this->lineDir, $this->charDirRender ?? $this->charDir, '_render');
        }
    }

    public static function directions(string $lineDir, string $charDir, string $suffix): void
    {
        if (! in_array($lineDir, self::DIRECTIONS, true)) {
            throw new \InvalidArgumentException("line_dir{$suffix} must be one of ttb, btt, ltr, rtl, not {$lineDir}");
        }
        if (! in_array($charDir, self::DIRECTIONS, true)) {
            throw new \InvalidArgumentException("char_dir{$suffix} must be one of ttb, btt, ltr, rtl, not {$charDir}");
        }
        if (count_chars($lineDir, 3) === count_chars($charDir, 3)) {
            throw new \InvalidArgumentException(
                "line_dir{$suffix}={$lineDir} is incompatible with char_dir{$suffix}={$charDir}",
            );
        }
    }
}
