<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Table;

use Artygrand\Pdfplumber\Graphic;

/**
 * pdfplumber's table settings. Unset per-axis tolerances take the shared one.
 */
final class TableSettings
{
    public const STRATEGIES = ['lines', 'lines_strict', 'text', 'explicit'];

    public string $verticalStrategy = 'lines';

    public string $horizontalStrategy = 'lines';

    /** @var list<float|int|Graphic|array<string, mixed>>|null */
    public ?array $explicitVerticalLines = null;

    /** @var list<float|int|Graphic|array<string, mixed>>|null */
    public ?array $explicitHorizontalLines = null;

    public float $snapTolerance = 3;

    public ?float $snapXTolerance = null;

    public ?float $snapYTolerance = null;

    public float $joinTolerance = 3;

    public ?float $joinXTolerance = null;

    public ?float $joinYTolerance = null;

    public float $edgeMinLength = 3;

    public float $edgeMinLengthPrefilter = 1;

    public int $minWordsVertical = 3;

    public int $minWordsHorizontal = 1;

    public float $intersectionTolerance = 3;

    public ?float $intersectionXTolerance = null;

    public ?float $intersectionYTolerance = null;

    /** @var array<string, mixed> text options for words and cell text */
    public array $textSettings = [];

    /**
     * @param array<string, mixed>|self $settings keys in camelCase or snake_case; text options either
     *        in textSettings or with a text_ prefix
     */
    public static function resolve(array|self $settings = []): self
    {
        if ($settings instanceof self) {
            return $settings;
        }
        $resolved = new self();
        foreach ($settings as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, 'text_')) {
                $resolved->textSettings[substr($key, 5)] = $value;
                continue;
            }
            $property = lcfirst(str_replace('_', '', ucwords($key, '_')));
            if (! property_exists($resolved, $property)) {
                throw new \InvalidArgumentException("Unknown table setting: {$key}");
            }
            if ($property === 'textSettings') {
                $resolved->textSettings = [...$resolved->textSettings, ...(array) $value];
            } elseif ($value === null || is_array($value) || is_string($value)) {
                $resolved->{$property} = $value;
            } elseif ($property === 'minWordsVertical' || $property === 'minWordsHorizontal') {
                $resolved->{$property} = (int) $value;
            } else {
                $resolved->{$property} = (float) $value;
            }
        }
        $resolved->validate();

        return $resolved;
    }

    private function validate(): void
    {
        foreach ([
            'snapTolerance', 'snapXTolerance', 'snapYTolerance', 'joinTolerance', 'joinXTolerance',
            'joinYTolerance', 'edgeMinLength', 'edgeMinLengthPrefilter', 'minWordsVertical',
            'minWordsHorizontal', 'intersectionTolerance', 'intersectionXTolerance', 'intersectionYTolerance',
        ] as $setting) {
            if (($this->{$setting} ?? 0) < 0) {
                throw new \InvalidArgumentException("Table setting '{$setting}' cannot be negative");
            }
        }
        foreach (['horizontal' => $this->horizontalStrategy, 'vertical' => $this->verticalStrategy] as $axis => $strategy) {
            if (! in_array($strategy, self::STRATEGIES, true)) {
                throw new \InvalidArgumentException("{$axis}Strategy must be one of ".implode(', ', self::STRATEGIES));
            }
        }

        $tolerance = $this->textSettings['tolerance'] ?? 3;
        unset($this->textSettings['tolerance']);
        foreach (['x_tolerance', 'y_tolerance'] as $key) {
            $camel = lcfirst(str_replace('_', '', ucwords($key, '_')));
            if (! array_key_exists($key, $this->textSettings) && ! array_key_exists($camel, $this->textSettings)) {
                $this->textSettings[$key] = $tolerance;
            }
        }

        $this->snapXTolerance ??= $this->snapTolerance;
        $this->snapYTolerance ??= $this->snapTolerance;
        $this->joinXTolerance ??= $this->joinTolerance;
        $this->joinYTolerance ??= $this->joinTolerance;
        $this->intersectionXTolerance ??= $this->intersectionTolerance;
        $this->intersectionYTolerance ??= $this->intersectionTolerance;
    }
}
