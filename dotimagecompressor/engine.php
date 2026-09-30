<?php
/** Local-only image optimizer. No network calls or shell execution. */
if (!defined('ZBP_PATH') && !defined('DIC_TESTING')) { exit; }

final class DIC_Engine
{
    public const MAX_BYTES = 16777216;
    public const MAX_PIXELS = 20000000;
    public const MAX_SIDE = 12000;
    public const BACKUP_QUOTA = 536870912;
    public const GUARD = "<?php http_response_code(404); exit; ?>\n";

    public static function capabilities(): array
    {
        return [
            'gd' => extension_loaded('gd'),
            'jpeg' => function_exists('imagecreatefromjpeg') && function_exists('imagejpeg'),
            'png' => function_exists('imagecreatefrompng') && function_exists('imagepng'),
            'webp' => function_exists('imagecreatefromwebp') && function_exists('imagewebp'),
            'exif' => function_exists('exif_read_data'),
            'memory_limit' => (string) ini_get('memory_limit'),
        ];
    }

    public static function settings(array $s): array
    {
        return ['enabled' => !isset($s['enabled']) || (bool) $s['enabled'],
            'max_edge' => max(320, min(4096, (int) ($s['max_edge'] ?? 1600))),
            'quality' => max(60, min(95, (int) ($s['quality'] ?? 82))),
            'webp' => !isset($s['webp']) || (bool) $s['webp']];
    }

    private static function memoryBytes(string $s): int
    {
        if ($s === '-1') { return 536870912; }
        $v = (int) $s;
        $unit = strtolower(substr(trim($s), -1));
        if ($unit === 'g') { $v *= 1073741824; }
        elseif ($unit === 'm') { $v *= 1048576; }
        elseif ($unit === 'k') { $v *= 1024; }
        return min($v, 536870912);
    }

    /** Return a validated candidate; caller owns deletion. The source is never changed here. */
    public static function candidate(string $path, string $extension, array $settings): array
    {
        $fail = static function ($reason) { return ['ok' => false, 'reason' => $reason]; };
        $s = self::settings($settings);
        if (!$s['enabled']) { return $fail('disabled'); }
        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) { return $fail('no_gd'); }
        if (!self::safeFilePath($path) || !is_file($path) || !is_readable($path)) { return $fail('invalid_path'); }
        $size = filesize($path);
        if (!$size || $size > self::MAX_BYTES) { return $fail('file_limit'); }
        $ext = strtolower($extension);
        $allowed = ['jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP];
        if (!isset($allowed[$ext])) { return $fail('unsupported_type'); }
        $info = @getimagesize($path);
        if (!$info || $info[2] !== $allowed[$ext]) { return $fail('type_mismatch'); }
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || $w > self::MAX_SIDE || $h > self::MAX_SIDE || $w * $h > self::MAX_PIXELS) { return $fail('pixel_limit'); }
        // Four GD surfaces (orientation + resize), file buffers and encoder headroom.
        $need = $w * $h * 20 + $size * 4 + 16777216;
        if (memory_get_usage(true) + $need > self::memoryBytes((string) ini_get('memory_limit'))) { return $fail('memory_limit'); }
        $bytes = @file_get_contents($path);
        if ($bytes === false || strlen($bytes) !== $size) { return $fail('read_failed'); }
        $orientation = 1;
        if ($info[2] === IMAGETYPE_PNG) {
            $p = 8;
            while ($p + 12 <= $size) {
                $length = unpack('N', substr($bytes, $p, 4))[1];
                $type = substr($bytes, $p + 4, 4);
                if ($length > $size - $p - 12) { return $fail('invalid_png'); }
                if ($type === 'acTL') { return $fail('animated'); }
                if ($type === 'eXIf' || $type === 'iCCP') { return $fail('profile_or_orientation'); }
                $p += $length + 12;
                if ($type === 'IEND') { break; }
            }
        } elseif ($info[2] === IMAGETYPE_WEBP) {
            $p = 12;
            while ($p + 8 <= $size) {
                $type = substr($bytes, $p, 4);
                $length = unpack('V', substr($bytes, $p + 4, 4))[1];
                if ($length > $size - $p - 8) { return $fail('invalid_webp'); }
                if ($type === 'ANIM' || $type === 'ANMF') { return $fail('animated'); }
                if ($type === 'EXIF' || $type === 'ICCP') { return $fail('profile_or_orientation'); }
                $p += 8 + $length + ($length % 2);
            }
        } else {
            if (strpos($bytes, 'ICC_PROFILE' . "\0") !== false) { return $fail('profile_or_orientation'); }
            if (strpos($bytes, "Exif\0\0") !== false) {
                if (!function_exists('exif_read_data')) { return $fail('no_exif'); }
                $exif = @exif_read_data($path, 'IFD0', true, false);
                if (!is_array($exif)) { return $fail('invalid_exif'); }
                $orientation = (int) ($exif['IFD0']['Orientation'] ?? 1);
                if ($orientation < 1 || $orientation > 8) { return $fail('invalid_exif'); }
            }
        }
        unset($bytes);
        $decode = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'][$info[2]];
        if (!function_exists($decode)) { return $fail('no_decoder'); }
        $im = null; $out = null; $tmp = null;
        try {
            $im = @$decode($path);
            if (!$im) { return $fail('decode_failed'); }
            if ($orientation === 2) { imageflip($im, IMG_FLIP_HORIZONTAL); }
            if ($orientation === 4) { imageflip($im, IMG_FLIP_VERTICAL); }
            $angle = [3 => 180, 5 => -90, 6 => -90, 7 => -90, 8 => 90][$orientation] ?? 0;
            if ($angle) {
                $rotated = @imagerotate($im, $angle, 0);
                if (!$rotated) { return $fail('rotate_failed'); }
                imagedestroy($im); $im = $rotated;
                if ($orientation === 5) { imageflip($im, IMG_FLIP_HORIZONTAL); }
                if ($orientation === 7) { imageflip($im, IMG_FLIP_VERTICAL); }
            }
            $w = imagesx($im); $h = imagesy($im);
            $scale = min(1, $s['max_edge'] / max($w, $h));
            $nw = max(1, (int) round($w * $scale)); $nh = max(1, (int) round($h * $scale));
            $out = imagecreatetruecolor($nw, $nh);
            if (!$out) { return $fail('allocate_failed'); }
            imagealphablending($out, false); imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
            if (!imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h)) { return $fail('resize_failed'); }
            $format = $s['webp'] && function_exists('imagewebp') ? 'webp' : ($ext === 'jpeg' ? 'jpg' : $ext);
            $encoder = ['jpg' => 'imagejpeg', 'png' => 'imagepng', 'webp' => 'imagewebp'][$format];
            if (!function_exists($encoder)) { return $fail('no_encoder'); }
            $tmp = tempnam(dirname($path), '.dic-');
            if (!$tmp) { return $fail('temp_failed'); }
            $ok = @$encoder($out, $tmp, $format === 'png' ? 9 : $s['quality']);
            clearstatcache(true, $tmp);
            $newSize = is_file($tmp) ? filesize($tmp) : 0;
            $expectedType = ['jpg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP][$format];
            $newInfo = @getimagesize($tmp);
            if (!$ok || !$newSize || !$newInfo || $newInfo[2] !== $expectedType || $newInfo[0] !== $nw || $newInfo[1] !== $nh) { return $fail('encode_failed'); }
            // Verify the encoded output fully decodes, rather than trusting its header.
            $checker = ['jpg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'][$format];
            if (!function_exists($checker)) { return $fail('no_decoder'); }
            $check = @$checker($tmp);
            if (!$check) { return $fail('verify_failed'); }
            imagedestroy($check);
            if ($newSize >= $size || $size - $newSize < max(256, (int) ($size * 0.01))) { return $fail('not_smaller'); }
            $result = ['ok' => true, 'path' => $tmp, 'before' => $size, 'after' => $newSize, 'width' => $nw, 'height' => $nh, 'ext' => $format, 'mime' => image_type_to_mime_type($expectedType)];
            $tmp = null;
            return $result;
        } catch (Throwable $e) { return $fail('processing_failed'); }
        finally {
            if ($im) { imagedestroy($im); }
            if ($out) { imagedestroy($out); }
            if ($tmp && is_file($tmp)) { @unlink($tmp); }
        }
    }

    /** A denied symlink target must fail closed, not reach Z-Blog's global error page. */
    public static function pathInfo(string $path): ?array
    {
        $previous = null;
        $previous = set_error_handler(static function ($level, $message, $file, $line) use (&$previous) {
            // Only catch the expected restriction warning for these scoped filesystem probes.
            if ($level === E_WARNING && strpos($message, 'open_basedir restriction in effect') !== false) {
                throw new RuntimeException('Filesystem boundary denied');
            }
            return is_callable($previous) ? $previous($level, $message, $file, $line) : false;
        });
        try {
            return ['link' => is_link($path), 'exists' => file_exists($path), 'directory' => is_dir($path), 'file' => is_file($path)];
        } catch (Throwable $e) { return null; }
        finally { restore_error_handler(); }
    }

    public static function safeFilePath(string $path): bool
    {
        $state = self::pathInfo($path);
        return $state !== null && !$state['link'] && (!$state['exists'] || $state['file']);
    }

    /** Reject symlinks without probing ancestors outside PHP's allowed filesystem boundary. */
    public static function safeDirectory(string $dir, bool $create = false): bool
    {
        if ($dir === '' || $dir[0] !== '/' || strpos($dir, "\0") !== false) { return false; }
        $parts = explode('/', trim($dir, '/'));
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') { return false; }
        }
        $dir = '/' . implode('/', $parts);
        $anchor = '';
        $restriction = (string) ini_get('open_basedir');
        if ($restriction !== '') {
            $matched = false;
            foreach (explode(PATH_SEPARATOR, $restriction) as $allowed) {
                if ($allowed === '') { continue; }
                $allowed = rtrim($allowed, '/');
                if ($allowed === '') { $allowed = '/'; }
                // Relative/dot entries are not a trustworthy anchor; fail closed if no absolute match.
                if ($allowed[0] !== '/' || preg_match('~(?:^|/)\.\.?(?:/|$)~', $allowed)) { continue; }
                if ($allowed === '/' || $dir === $allowed || strpos($dir, $allowed . '/') === 0) {
                    if (!$matched || strlen($allowed) > strlen($anchor)) { $anchor = $allowed; }
                    $matched = true;
                }
            }
            if (!$matched) { return false; }
        }
        if ($anchor !== '' && $anchor !== '/') {
            // This is the first path probed. Its parents can be outside open_basedir.
            $state = self::pathInfo($anchor);
            if ($state === null || $state['link'] || !$state['directory']) { return false; }
            $relative = ltrim(substr($dir, strlen($anchor)), '/');
            $path = $anchor;
        } else {
            $relative = ltrim($dir, '/');
            $path = '';
        }
        foreach ($relative === '' ? [] : explode('/', $relative) as $part) {
            $path .= '/' . $part;
            $state = self::pathInfo($path);
            if ($state === null || $state['link'] || ($state['exists'] && !$state['directory'])) { return false; }
        }
        if (!is_dir($dir) && (!$create || !@mkdir($dir, 0700, true))) { return false; }
        return is_dir($dir) && !is_link($dir);
    }

    /** Original bytes are base64 data after an unconditional PHP exit, never executable input. */
    public static function backup(string $dir, string $source, string $name, string $mime): ?string
    {
        if (!self::safeDirectory($dir, true) || !is_writable($dir)) { return null; }
        if (!self::safeFilePath($dir . '/quota.php')) { return null; }
        $lock = @fopen($dir . '/quota.php', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) { if ($lock) { fclose($lock); } return null; }
        $tmp = null;
        try {
            if (filesize($dir . '/quota.php') === 0) { fwrite($lock, self::GUARD); fflush($lock); }
            $total = 0;
            foreach (glob($dir . '/*.php') ?: [] as $f) { $total += is_file($f) ? filesize($f) : 0; }
            $size = filesize($source);
            if (!$size || $size > self::MAX_BYTES || $total + $size * 1.4 + 2048 > self::BACKUP_QUOTA) { return null; }
            $bytes = @file_get_contents($source);
            if ($bytes === false || strlen($bytes) !== $size) { return null; }
            $id = bin2hex(random_bytes(16));
            $json = json_encode(['name' => basename(str_replace('\\', '/', $name)), 'mime' => $mime, 'size' => $size, 'sha256' => hash('sha256', $bytes), 'created' => time(), 'data' => base64_encode($bytes)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (!$json) { return null; }
            $tmp = $dir . '/.new-' . $id . '.php';
            $handle = @fopen($tmp, 'x');
            if (!$handle) { $tmp = null; return null; }
            $written = fwrite($handle, self::GUARD . $json);
            fflush($handle); fclose($handle);
            if ($written !== strlen(self::GUARD . $json)) { return null; }
            @chmod($tmp, 0600);
            if (!@rename($tmp, $dir . '/' . $id . '.php')) { return null; }
            $tmp = null;
            return $id;
        } finally { if ($tmp) { @unlink($tmp); } flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function readBackup(string $dir, string $id, bool $withData = true): ?array
    {
        if (!self::safeDirectory($dir) || !preg_match('/\A[a-f0-9]{32}\z/D', $id)) { return null; }
        $path = $dir . '/' . $id . '.php';
        if (!self::safeFilePath($path) || !is_file($path) || filesize($path) > self::MAX_BYTES * 1.4 + 4096) { return null; }
        $raw = @file_get_contents($path);
        if ($raw === false || substr($raw, 0, strlen(self::GUARD)) !== self::GUARD) { return null; }
        $item = json_decode(substr($raw, strlen(self::GUARD)), true);
        if (!is_array($item) || !isset($item['data'], $item['sha256'], $item['name'], $item['mime'], $item['size'])) { return null; }
        if ($withData) {
            $bytes = base64_decode($item['data'], true);
            if ($bytes === false || strlen($bytes) !== (int) $item['size'] || !hash_equals($item['sha256'], hash('sha256', $bytes))) { return null; }
            $item['bytes'] = $bytes;
        }
        unset($item['data']);
        return $item;
    }
}
