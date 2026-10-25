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
define('BASE_URL', 'http://localhost/siap.man2kotamadiun.sch.id');

// Zona waktu server — WAJIB konsisten agar validasi jam presensi akurat.
date_default_timezone_set('Asia/Jakarta');

/**
 * Kirim halaman error statis (404.html / 403.html). Tombol "Ke Beranda" diarahkan ke halaman utama
 * aplikasi sesuai BASE_URL (bukan "/" mentah, yang salah bila aplikasi berada di subfolder).
 */
function kirim_halaman_error(string $nama): void
{
    $f = __DIR__ . '/../' . $nama;
    $html = is_file($f) ? (string) file_get_contents($f) : '';
    if ($html === '') {
        echo 'Halaman tidak tersedia.';
        return;
    }
    $beranda = htmlspecialchars(rtrim(BASE_URL, '/') . '/', ENT_QUOTES, 'UTF-8');
    echo str_replace('href="/" data-beranda', 'href="' . $beranda . '" data-beranda', $html);
}

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
            tampilkan_error_database($e);
        }
    }
    return $pdo;
}

/**
 * Koneksi database gagal -> tampilkan halaman 503 yang rapi (503.html), BUKAN teks polos.
 * - Detail galat (host, user, dsb.) hanya masuk error_log server, tidak pernah ke browser.
 * - Halaman memuat "kode kejadian" acak yang sama dengan yang ditulis ke log,
 *   sehingga administrator bisa mencocokkan laporan pengguna dengan baris lognya.
 * - Permintaan API/AJAX (yang mengharapkan JSON) dijawab JSON 503, bukan HTML.
 */
function tampilkan_error_database(Throwable $e): void
{
    $kode = 'DB-' . strtoupper(bin2hex(random_bytes(3)));
    error_log('DB Connection Error [' . $kode . ']: ' . $e->getMessage());

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Koneksi database gagal [$kode]\n");
        exit(1);
    }

    // Buang keluaran halaman yang sempat tertulis supaya tidak bercampur dengan halaman error.
    while (ob_get_level() > 0) ob_end_clean();

    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 30');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex');
    }

    // Popup formulir (?modal=1): isinya disisipkan ke dalam halaman induk lewat innerHTML,
    // jadi cukup potongan kecil — halaman penuh akan merusak tampilan halaman induk.
    if (!empty($_REQUEST['modal'])) {
        if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
        echo '<div class="alert alert-danger mb-0">Layanan sedang tidak dapat diakses. '
            . 'Silakan tutup jendela ini lalu coba lagi beberapa saat lagi. '
            . '<small class="d-block mt-1 opacity-75">Kode kejadian: ' . $kode . '</small></div>';
        exit;
    }

    $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $json = strpos($uri, '/api/') !== false
        || isset($_GET['data'])
        || stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') === 0
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

    if ($json) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'      => false,
            'success' => false,
            'code'    => 'DB_UNAVAILABLE',
            'message' => 'Layanan sedang tidak dapat diakses. Silakan coba lagi beberapa saat lagi.',
            'ref'     => $kode,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    $file = __DIR__ . '/../503.html';
    $html = is_file($file) ? (string) file_get_contents($file) : '';
    if ($html === '') {
        // Cadangan bila 503.html terhapus: tetap bukan teks polos tanpa konteks.
        $html = '<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>503</title><body style="font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px;text-align:center">'
            . '<main><h1>Layanan sedang tidak dapat diakses</h1><p>Silakan coba lagi beberapa saat lagi.</p><p><small>Kode kejadian: {{KODE}}</small></p></main>';
    }
    echo str_replace(['{{KODE}}', '{{BERANDA}}'], [$kode, rtrim(BASE_URL, '/') . '/'], $html);
    exit;
}
