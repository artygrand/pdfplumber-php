<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber\Font;

/**
 * Data shipped with the package: predefined CMaps, CID to Unicode tables and core font metrics.
 */
final class Resources
{
    /** @var array<string, array<string, mixed>|null> */
    private static array $cmaps = [];

    /** @var array<string, array{0: array<string, mixed>, 1: array<array-key, int|float>}>|null */
    private static ?array $metrics = null;

    /**
     * @return array<string, mixed>|null
     */
    public static function cmap(string $name): ?array
    {
        if (array_key_exists($name, self::$cmaps)) {
            return self::$cmaps[$name];
        }
        $name = str_replace("\0", '', $name);
        $path = __DIR__.'/../../resources/cmap/'.$name.'.json.gz';
        if (preg_match('/^[A-Za-z0-9_.\-]+$/', $name) !== 1 || ! is_file($path)) {
            return self::$cmaps[$name] = null;
        }
        $json = gzdecode((string) file_get_contents($path));
        $data = $json === false ? null : json_decode($json, true);

        return self::$cmaps[$name] = is_array($data) ? $data : null;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<array-key, int|float>}|null
     */
    public static function metrics(string $fontName): ?array
    {
        self::$metrics ??= require __DIR__.'/../../resources/fontmetrics.php';

        return self::$metrics[$fontName] ?? null;
    }
}
