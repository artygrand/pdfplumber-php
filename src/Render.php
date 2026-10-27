<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

/**
 * Renders a page to PNG with Ghostscript (the "gs" command, or the path in the
 * PDFPLUMBER_GS environment variable), which pdfplumber does with pdfium.
 *
 * @internal
 */
final class Render
{
    /**
     * @param int $pageNumber 1-based
     *
     * @throws Exception when Ghostscript is missing or fails
     */
    public static function page(string $pdf, int $pageNumber, float $resolution, bool $antialias, string $password = ''): string
    {
        $gs = getenv('PDFPLUMBER_GS') ?: Repair::which(['gs', 'gswin64c', 'gswin32c']);
        if ($gs === null) {
            throw new Exception('Rendering a page needs Ghostscript (gs): https://www.ghostscript.com/');
        }
        $file = tempnam(sys_get_temp_dir(), 'pdfplumber');
        if ($file === false) {
            throw new Exception('Cannot create a temporary file');
        }
        try {
            file_put_contents($file, $pdf);
            $alpha = $antialias ? 4 : 1;
            $command = [
                $gs, '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-sDEVICE=png16m', '-dUseCropBox',
                '-r'.$resolution, "-dFirstPage={$pageNumber}", "-dLastPage={$pageNumber}",
                "-dTextAlphaBits={$alpha}", "-dGraphicsAlphaBits={$alpha}", '-sOutputFile=-',
            ];
            if ($password !== '') {
                $command[] = "-sPDFPassword={$password}";
            }
            $command[] = $file;
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($process)) {
                throw new Exception("Cannot run {$gs}");
            }
            $png = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0 || ! str_starts_with($png, "\x89PNG")) {
                throw new Exception('Ghostscript could not render the page: '.(trim($errors) ?: 'no output'));
            }

            return $png;
        } finally {
            @unlink($file);
        }
    }
}
