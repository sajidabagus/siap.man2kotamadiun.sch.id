<?php
/**
 * Cadangan bila ekstensi PHP tertentu TIDAK aktif di server (mis. aaPanel tanpa mbstring/fileinfo).
 * Hanya mendefinisikan fungsi yang belum ada; bila ekstensinya aktif, fungsi aslinya yang dipakai.
 */

if (!function_exists('mb_strlen')) {
    if (!defined('MB_CASE_UPPER')) define('MB_CASE_UPPER', 0);
    if (!defined('MB_CASE_LOWER')) define('MB_CASE_LOWER', 1);
    if (!defined('MB_CASE_TITLE')) define('MB_CASE_TITLE', 2);
    function mb_str_split(string $s, int $len = 1, ?string $enc = null): array
    {
        $c = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        if ($c === false) $c = str_split($s);            // bukan UTF-8 valid: per byte
        return $len > 1 ? array_map('implode', array_chunk($c, $len)) : $c;
    }
    function mb_strlen(string $s, ?string $enc = null): int { return count(mb_str_split($s)); }
    function mb_substr(string $s, int $start, ?int $length = null, ?string $enc = null): string
    {
        return implode('', array_slice(mb_str_split($s), $start, $length));
    }
    function mb_strtolower(string $s, ?string $enc = null): string { return strtolower($s); }   // ASCII saja
    function mb_strtoupper(string $s, ?string $enc = null): string { return strtoupper($s); }
    function mb_convert_case(string $s, int $mode, ?string $enc = null): string
    {
        if ($mode === MB_CASE_UPPER) return strtoupper($s);
        if ($mode === MB_CASE_LOWER) return strtolower($s);
        return ucwords(strtolower($s), " \t\r\n\f\v-'");
    }
}

/**
 * MIME asli sebuah file dari ISINYA (bukan dari ekstensi/nama). Urutan: finfo -> mime_content_type -> magic bytes.
 * Hasil finfo/mime_content_type tetap dicocokkan dengan magic bytes untuk jenis yang dikenali aplikasi
 * (JPEG/PNG/WEBP/PDF); bila bertentangan, dianggap tidak dikenal.
 */
function deteksi_mime_file(string $path): string
{
    $magic = deteksi_mime_magic($path);
    $mime = '';
    try {
        if (class_exists('finfo') && defined('FILEINFO_MIME_TYPE')) {
            $fi = @new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) @$fi->file($path);
        }
    } catch (\Throwable $e) { $mime = ''; }          // finfo dinonaktifkan/rusak di server
    if ($mime === '' && function_exists('mime_content_type')) {
        try { $mime = (string) @mime_content_type($path); } catch (\Throwable $e) { $mime = ''; }
    }
    if ($mime === '' || $mime === 'application/octet-stream') return $magic !== '' ? $magic : 'application/octet-stream';
    $dikenal = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    if (in_array($mime, $dikenal, true) && $mime !== $magic) return 'application/octet-stream';
    return $mime;
}

/** Deteksi JPEG/PNG/WEBP/PDF dari byte awal file. '' bila bukan salah satunya. */
function deteksi_mime_magic(string $path): string
{
    $h = @file_get_contents($path, false, null, 0, 16);
    if (!is_string($h) || strlen($h) < 4) return '';
    if (strncmp($h, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
    if (strncmp($h, "\x89PNG\r\n\x1A\n", 8) === 0) return 'image/png';
    if (strlen($h) >= 12 && substr($h, 0, 4) === 'RIFF' && substr($h, 8, 4) === 'WEBP') return 'image/webp';
    if (strncmp($h, '%PDF-', 5) === 0) return 'application/pdf';
    return '';
}
