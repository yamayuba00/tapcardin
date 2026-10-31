<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * TRD §10.2 — GD: validasi MIME dengan finfo, tolak > 8 MB, buang EXIF dengan re-encode.
 * Hasil: WebP 400 px (profil) & JPEG 256 px (< 40 KB, untuk vCard).
 */
final class ImageResizer
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    public static function load(UploadedFile $file): GdImage
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['photo' => 'Ukuran foto maksimal 8 MB.']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if (! in_array($mime, self::ALLOWED, true)) {
            throw ValidationException::withMessages(['photo' => 'Format foto harus JPG, PNG, atau WebP.']);
        }
        $img = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $img) {
            throw ValidationException::withMessages(['photo' => 'Foto tidak bisa dibaca.']);
        }
        if ($mime === 'image/jpeg') {
            $img = self::applyExifOrientation($img, $file->getRealPath());
        }

        return $img;
    }

    /** Potong persegi di tengah lalu skala ke $size. */
    public static function square(GdImage $src, int $size): GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $dst = imagecreatetruecolor($size, $size);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $size, $size, $side, $side);

        return $dst;
    }

    /** Perkecil agar sisi terpanjang ≤ $max (tidak memperbesar), rasio tetap. */
    public static function fit(GdImage $src, int $max): GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $max / max($w, $h));
        if ($scale === 1) {
            return $src;
        }
        $dst = imagecreatetruecolor((int) round($w * $scale), (int) round($h * $scale));
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);

        return $dst;
    }

    /** PNG lossless — untuk gambar yang harus tetap bisa di-scan (QRIS). */
    public static function png(GdImage $img): string
    {
        ob_start();
        imagepng($img, null, 9);

        return (string) ob_get_clean();
    }

    public static function webp(GdImage $img, int $quality = 82): string
    {
        ob_start();
        imagewebp($img, null, $quality);

        return (string) ob_get_clean();
    }

    /** JPEG di bawah batas ukuran (vCard PHOTO). Latar transparan diratakan ke putih. */
    public static function jpeg(GdImage $img, int $maxBytes = 40_000): string
    {
        $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
        for ($q = 85; $q >= 40; $q -= 10) {
            ob_start();
            imagejpeg($flat, null, $q);
            $data = (string) ob_get_clean();
            if (strlen($data) <= $maxBytes) {
                break;
            }
        }

        return $data;
    }

    private static function applyExifOrientation(GdImage $img, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $img;
        }
        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);

        return match ($orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
    }
}
