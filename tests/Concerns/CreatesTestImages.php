<?php declare(strict_types=1);

namespace Jackardios\ImageDimensions\Tests\Concerns;

/**
 * Helpers for generating throwaway raster/SVG fixtures inside a test's
 * temporary directory. Files are tracked so callers can clean them up.
 */
trait CreatesTestImages
{
    /** @var list<string> */
    protected array $createdFiles = [];

    /**
     * Create a raster test image with the given dimensions and format.
     */
    protected function createImage(
        string $directory,
        string $filename,
        int $width,
        int $height,
        string $format = 'png'
    ): string {
        $path = $directory . '/' . $filename;
        $image = imagecreatetruecolor($width, $height);

        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);

        switch ($format) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($image, $path, 95);
                break;
            case 'gif':
                imagegif($image, $path);
                break;
            case 'webp':
                imagewebp($image, $path, 95);
                break;
            case 'bmp':
                imagebmp($image, $path);
                break;
            case 'png':
            default:
                imagepng($image, $path);
                break;
        }

        imagedestroy($image);
        $this->createdFiles[] = $path;

        return $path;
    }

    /**
     * Create an SVG fixture. Attributes are rendered onto the root <svg> element.
     *
     * @param array<string, string|int> $attributes
     */
    protected function createSvg(
        string $directory,
        string $filename,
        array $attributes,
        string $content = ''
    ): string {
        $path = $directory . '/' . $filename;

        $attrs = '';
        foreach ($attributes as $key => $value) {
            $attrs .= " {$key}=\"{$value}\"";
        }

        $svg = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $svg .= '<svg xmlns="http://www.w3.org/2000/svg"' . $attrs . '>' . "\n";
        $svg .= $content !== '' ? $content : '<rect width="100%" height="100%" fill="red"/>';
        $svg .= "\n" . '</svg>';

        file_put_contents($path, $svg);
        $this->createdFiles[] = $path;

        return $path;
    }

    /**
     * Remove every file created through this trait and the directory itself.
     */
    protected function cleanupCreatedFiles(?string $directory = null): void
    {
        foreach ($this->createdFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        $this->createdFiles = [];

        if ($directory !== null && is_dir($directory)) {
            @rmdir($directory);
        }
    }
}
