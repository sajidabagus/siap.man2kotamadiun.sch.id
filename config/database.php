<?php

/**
 * Konfigurasi koneksi database (PDO)
 * Ganti nilai di bawah sesuai environment hosting/XAMPP/aaPanel Anda.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'siapabsen');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Base URL aplikasi (dipakai untuk membuat isi QR Code siswa).
// Contoh production: https://domainsekolah.sch.id
// define('BASE_URL', 'https://siap.man2kotamadiun.sch.id');
define('BASE_URL', 'http://localhost/siap');

// Zona waktu server — WAJIB konsisten agar validasi jam presensi akurat.
date_default_timezone_set('Asia/Jakarta');

/**
 * SECURITY: daftar IP proxy tepercaya yang boleh menyisipkan header
 * X-Forwarded-For / CF-Connecting-IP. String kosong berarti tidak ada proxy
 * (aman utk XAMPP/localhost): get_client_ip() hanya memakai REMOTE_ADDR,
 * sehingga header XFF yang dipalsukan klien TIDAK dipercaya — ini menutup
 * celah rotasi-IP palsu pada rate-limit login & pencemaran security_logs.
 * Contoh production (Nginx di mesin sama):  '127.0.0.1, ::1'
 */
define('TRUSTED_PROXIES', '');

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Samakan timezone session MySQL dengan PHP (Asia/Jakarta) agar
            // fungsi SQL seperti NOW()/CURDATE() selalu konsisten dengan
            // date()/time() di PHP -- mencegah bug batas hari di sekitar tengah malam.
            $pdo->exec("SET time_zone = '+07:00'");
        } catch (PDOException $e) {
            // Jangan bocorkan detail koneksi ke publik.
            error_log('DB Connection Error: ' . $e->getMessage());
            http_response_code(500);
            die('Koneksi database gagal. Hubungi administrator.');
        }
    }
    return $pdo;
}
