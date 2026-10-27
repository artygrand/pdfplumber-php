<?php

declare(strict_types=1);

namespace Artygrand\Pdfplumber;

/**
 * pdfplumber's repair: rewrites a damaged PDF with Ghostscript, which must be installed.
 */
final class Repair
{
    public const SETTINGS = ['default', 'prepress', 'printer', 'ebook', 'screen'];

    /**
     * Returns the repaired PDF, or writes it to $outfile when one is given.
     *
     * @throws Exception when Ghostscript is missing or fails
     */
    public static function run(
        string $path,
        string $password = '',
        ?string $gsPath = null,
        string $setting = 'default',
        ?string $outfile = null,
    ): string {
        if (! in_array($setting, self::SETTINGS, true)) {
            throw new \InvalidArgumentException('The repair setting is one of '.implode(', ', self::SETTINGS));
        }
        $executable = $gsPath ?? self::which(['gs', 'gswin32c', 'gswin64c']);
        if ($executable === null) {
            throw new Exception('Cannot find Ghostscript, which is required for repairs: https://www.ghostscript.com/');
        }
        $command = [$executable, '-sstdout=%stderr', '-o', '-', '-sDEVICE=pdfwrite', "-dPDFSETTINGS=/{$setting}"];
        if ($password !== '') {
            $command[] = "-sPDFPassword={$password}";
        }
        $command[] = (string) realpath($path);

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new Exception("Cannot run {$executable}");
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new Exception(trim($stderr) ?: "{$executable} failed");
        }
        if ($outfile !== null) {
            file_put_contents($outfile, $stdout);
        }

        return $stdout;
    }

    /**
     * @param list<string> $names
     */
    public static function which(array $names): ?string
    {
        foreach ($names as $name) {
            foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
                foreach (['', '.exe'] as $suffix) {
                    $candidate = $directory.DIRECTORY_SEPARATOR.$name.$suffix;
                    if ($directory !== '' && is_file($candidate) && is_executable($candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }
}
