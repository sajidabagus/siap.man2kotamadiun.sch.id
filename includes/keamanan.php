<?php
/**
 * Pengaturan keamanan dasar: header HTTP + cookie sesi.
 * Dimuat otomatis oleh auth.php dan functions.php (sekali saja, require_once),
 * sehingga berlaku untuk semua halaman, termasuk /presensi dan /api.
 */

/** Apakah koneksi pengguna memakai HTTPS (termasuk di belakang reverse proxy)? */
function https_aktif(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') return true;

    // D6: X-Forwarded-Proto adalah header yang dikendalikan klien — hanya
    // dipercaya bila request benar-benar datang dari proxy terdaftar di
    // TRUSTED_PROXIES (pola sama dengan get_client_ip() di functions.php).
    // Tanpa ini, XFP palsu bisa mengubah keputusan cookie Secure & HSTS.
    $trusted = defined('TRUSTED_PROXIES')
        ? array_filter(array_map('trim', explode(',', (string) TRUSTED_PROXIES)))
        : [];
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if ($trusted && in_array($remote, $trusted, true)) {
        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
    return false;
}

/**
 * Header keamanan. CSP sengaja TIDAK dipasang: aplikasi memakai script/style inline
 * dan CDN (Bootstrap), sehingga CSP yang ketat akan merusak tampilan/fungsi.
 */
function kirim_header_keamanan(): void
{
    if (headers_sent()) return;
    header_remove('X-Powered-By');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Kamera (scan QR) & lokasi (validasi GPS presensi) hanya untuk situs ini.
    header('Permissions-Policy: camera=(self), geolocation=(self), microphone=(), payment=(), usb=()');
    if (https_aktif()) {
        header('Strict-Transport-Security: max-age=15552000'); // 180 hari
    }
}

/** Cookie sesi aman: harus dipanggil SEBELUM session_start(). */
function siapkan_cookie_sesi(): void
{
    if (session_status() !== PHP_SESSION_NONE || headers_sent()) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => https_aktif(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

kirim_header_keamanan();
siapkan_cookie_sesi();
