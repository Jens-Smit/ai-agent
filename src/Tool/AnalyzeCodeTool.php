<?php

namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Finder\Finder;

#[AsTool(
    name: 'analyze_code',
    description: 'Analyze project files and return metrics and per-file details (path, lines, size, textual content truncated).'
)]
final class AnalyzeCodeTool
{
    private string $projectDir;

    /** maximale Bytes, die vollständig eingelesen werden (200 KB) */
    private const MAX_READ_BYTES = 200 * 1024;

    /** maximale Länge des zurückgegebenen Inhalts (Chars) — wenn größer: truncated=true */
    private const MAX_CONTENT_CHARS = 100_000;

    /** welche Verzeichnisse standardmäßig scannen */
    private array $defaultPaths = ['src', 'config'];

    public function __construct(KernelInterface $kernel)
    {
        $this->projectDir = $kernel->getProjectDir();
    }

    /**
     * @param bool $includeContents Wenn true, werden Dateiinhalte zurückgegeben (subject to limits)
     * @return array{
     *   projectDir:string,
     *   scannedPaths:string[],
     *   phpFilesCount:int,
     *   totalLinesOfCode:int,
     *   files: list<array{relativePath:string, absolutePath:string, size:int, lines:int, isText:bool, truncated:bool, content?:string}>
     * }
     */
    public function __invoke(bool $includeContents = true): array
    {
        $finder = new Finder();
        $paths = [];
        foreach ($this->defaultPaths as $p) {
            $full = $this->projectDir . DIRECTORY_SEPARATOR . $p;
            if (is_dir($full)) {
                $paths[] = $full;
            }
        }

        // fallback: gesamtes Projekt, falls keine default paths
        if (empty($paths)) {
            $paths = [$this->projectDir];
        }

        $finder->files()
            ->in($paths)
            ->name('*.php')
            ->ignoreUnreadableDirs()
            ->exclude(['vendor', 'node_modules', 'var', '.git', 'public', 'migrations']);

        $phpFilesCount = 0;
        $totalLinesOfCode = 0;
        $files = [];

        foreach ($finder as $file) {
            $phpFilesCount++;
            $path = $file->getRealPath();
            if ($path === false) {
                continue;
            }

            $size = $file->getSize() ?? 0;
            $isText = $this->isProbablyTextFile($path);
            $lines = 0;

            // lines counting in memory-friendly way
            $handle = @fopen($path, 'rb');
            if ($handle !== false) {
                while (!feof($handle)) {
                    fgets($handle);
                    $lines++;
                }
                fclose($handle);
            }

            $totalLinesOfCode += $lines;

            $fileEntry = [
                'relativePath' => ltrim(str_replace($this->projectDir, '', $path), DIRECTORY_SEPARATOR),
                'absolutePath' => $path,
                'size' => $size,
                'lines' => $lines,
                'isText' => $isText,
                'truncated' => false,
            ];

            if ($includeContents && $isText && $size <= self::MAX_READ_BYTES) {
                $content = @file_get_contents($path);
                if ($content !== false) {
                    // normalize line endings
                    $content = str_replace("\r\n", "\n", $content);
                    $truncated = false;
                    if (mb_strlen($content) > self::MAX_CONTENT_CHARS) {
                        $content = mb_substr($content, 0, self::MAX_CONTENT_CHARS);
                        $truncated = true;
                    }
                    $fileEntry['content'] = $content;
                    $fileEntry['truncated'] = $truncated;
                }
            } else {
                // wenn Datei zu groß oder nicht-text, we geben keinen Inhalt zurück
                if ($includeContents && $isText && $size > self::MAX_READ_BYTES) {
                    $fileEntry['note'] = 'skipped_read_due_to_size';
                } elseif ($includeContents && !$isText) {
                    $fileEntry['note'] = 'skipped_non_text';
                }
            }

            $files[] = $fileEntry;
        }

        return [
            'projectDir' => $this->projectDir,
            'scannedPaths' => array_map(function ($p) {
                return ltrim(str_replace($this->projectDir, '', $p), DIRECTORY_SEPARATOR);
            }, $paths),
            'phpFilesCount' => $phpFilesCount,
            'totalLinesOfCode' => $totalLinesOfCode,
            'files' => $files,
        ];
    }

    /**
     * Heuristik: prüft die ersten Bytes, ob es sich um Text handelt (keine Null-Bytes etc.)
     */
    private function isProbablyTextFile(string $path, int $checkBytes = 512): bool
    {
        if (!is_readable($path) || !is_file($path)) {
            return false;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $bytes = @fread($handle, $checkBytes);
        fclose($handle);

        if ($bytes === false || $bytes === '') {
            return true; // leere Datei => treat as text
        }

        // if contains null byte, treat as binary
        if (strpos($bytes, "\0") !== false) {
            return false;
        }

        // otherwise treat as text
        return true;
    }
}
