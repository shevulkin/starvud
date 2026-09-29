<?php
declare(strict_types=1);

/** Завантаження та адаптація зображень (GD) */
class Images
{
    public const MAX_SIDE = 1600;      // повний розмір
    public const THUMB_SIDE = 480;     // превʼю

    /** Зберігає завантажене фото; повертає [шлях, ширина, висота, байти] або null */
    public static function saveUpload(array $file, string $prefix = 'img'): ?array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
        if ($file['size'] > 15 * 1024 * 1024) return null;
        return self::saveFile((string)$file['tmp_name'], $prefix);
    }

    /**
     * Те саме для файлу, що вже лежить на диску, — імпорт каталогу (PromImport)
     * проводить фото через цю ж обробку, що й завантаження з адмінки: той самий
     * розмір, webp і превʼю, а не окремі правила для «перенесених» товарів.
     */
    public static function saveFile(string $path, string $prefix = 'img'): ?array
    {
        $info = @getimagesize($path);
        if (!$info) return null;
        [$w, $h, $type] = $info;
        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_GIF  => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if (!$src) return null;

        $dir = cfg('uploads_dir');
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $name = $prefix . '-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);

        // масштабування до MAX_SIDE
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        $nw = (int)round($w * $scale); $nh = (int)round($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $useWebp = function_exists('imagewebp');
        $ext = $useWebp ? 'webp' : 'jpg';
        $full = "$dir/$name.$ext";
        $useWebp ? imagewebp($dst, $full, 85) : imagejpeg($dst, $full, 85);

        // превʼю
        $tScale = min(1, self::THUMB_SIDE / max($nw, $nh));
        $tw = (int)round($nw * $tScale); $th = (int)round($nh * $tScale);
        $thumb = imagecreatetruecolor($tw, $th);
        imagealphablending($thumb, false); imagesavealpha($thumb, true);
        imagecopyresampled($thumb, $dst, 0, 0, 0, 0, $tw, $th, $nw, $nh);
        $useWebp ? imagewebp($thumb, "$dir/$name-thumb.$ext", 82) : imagejpeg($thumb, "$dir/$name-thumb.$ext", 82);

        imagedestroy($src); imagedestroy($dst); imagedestroy($thumb);
        $bytes = filesize($full) ?: 0;
        return ["uploads/$name.$ext", $nw, $nh, $bytes];
    }

    public static function thumbPath(string $path): string
    {
        return preg_replace('/\.(webp|jpg|png)$/', '-thumb.$1', $path) ?? $path;
    }

    /** Шлях до маленького превью для сіток (каталог, галерея); якщо превью немає — повертає оригінал */
    public static function displayThumb(string $path): string
    {
        $thumb = self::thumbPath($path);
        return is_file(BOFU_ROOT . '/assets/' . $thumb) ? $thumb : $path;
    }

    public static function delete(string $path): void
    {
        $abs = BOFU_ROOT . '/assets/' . $path;
        @unlink($abs);
        @unlink(BOFU_ROOT . '/assets/' . self::thumbPath($path));
    }
}
