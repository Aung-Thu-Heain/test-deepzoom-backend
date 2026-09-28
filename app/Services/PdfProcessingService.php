<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class PdfProcessingService
{
    /**
     * Wraps Poppler (pdftoppm / pdfinfo) and libvips (dzsave / thumbnail).
     *
     * Demo settings:
     *   - 200 DPI rendering
     *   - Deep Zoom tile size 512, overlap 0, JPEG output
     */

    private int $dpi = 200;

    private int $tileSize = 512;

    public function pageCount(string $pdfPath): int
    {
        $result = Process::run(['pdfinfo', $pdfPath]);

        if (! $result->successful()) {
            throw new RuntimeException('pdfinfo failed: '.$result->errorOutput());
        }

        if (preg_match('/^Pages:\s+(\d+)/m', $result->output(), $matches) !== 1) {
            throw new RuntimeException('Could not determine PDF page count.');
        }

        return (int) $matches[1];
    }

    public function renderPage(string $pdfPath, string $outputPrefix, int $pageNumber): string
    {
        // pdftoppm -png -r 200 -f N -l N -singlefile writes exactly <prefix>.png
        $result = Process::run([
            'pdftoppm',
            '-png',
            '-r',
            (string) $this->dpi,
            '-f',
            (string) $pageNumber,
            '-l',
            (string) $pageNumber,
            '-singlefile',
            $pdfPath,
            $outputPrefix,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException("pdftoppm failed for page {$pageNumber}: ".$result->errorOutput());
        }

        return $outputPrefix.'.png';
    }

    /** @return array{int, int} [width, height] of the rendered image in pixels */
    public function imageDimensions(string $imagePath): array
    {
        $size = getimagesize($imagePath);

        if ($size === false) {
            throw new RuntimeException("Could not read image dimensions: {$imagePath}");
        }

        return [(int) $size[0], (int) $size[1]];
    }

    /** @throws RuntimeException */
    public function buildDeepZoom(string $imagePath, string $dzBase): void
    {
        // vips dzsave input outputBase --tile-size 512 --overlap 0 --suffix .jpg
        // produces <dzBase>.dzi and <dzBase>_files/<level>/<col>_<row>.jpg
        $result = Process::run([
            'vips',
            'dzsave',
            $imagePath,
            $dzBase,
            '--tile-size',
            (string) $this->tileSize,
            '--overlap',
            '0',
            '--suffix',
            '.jpg',
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('vips dzsave failed: '.$result->errorOutput());
        }
    }

    /** @throws RuntimeException */
    public function makeThumbnail(string $imagePath, string $thumbnailPath, int $size = 512): void
    {
        // Fits the image inside a size x size box, preserving aspect ratio.
        $result = Process::run([
            'vips',
            'thumbnail',
            $imagePath,
            $thumbnailPath,
            (string) $size,
            (string) $size,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException('vips thumbnail failed: '.$result->errorOutput());
        }
    }
}