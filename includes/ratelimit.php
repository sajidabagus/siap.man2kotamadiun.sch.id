<?php
/**
 * RATE LIMIT (C6) — pembatas laju request pada endpoint API publik.
 * Implementasi sliding-window sederhana via APCu (tersedia di sebagian besar
 * shared hosting). Bila APCu tidak ada, fungsi DEGRADE AMAN: semua request
 * diteruskan (sistem tetap berjalan, penegakan bergantung lapisan lain
 * seperti batas device/uniq_siswa_tanggal di database).
 *
 * Pemakaian di api/*.php (paling atas, sebelum proses apa pun):
 *   rate_limit_gate('scan', 30, 60); // 30 request / menit / IP utk modul scan
 */

/**
 * @param string $modul  nama modul (mis. 'scan', 'presensi', 'cek_perangkat')
 * @param int    $maks   jumlah request maksimum dalam jendela
 * @param int    $jendelaDetik  panjang jendela dalam detik
 * @return bool true = masih BOLEH dilanjutkan; false = HARUS ditolak (429)
 */
function rate_limit_boleh(string $modul, int $maks, int $jendelaDetik): bool
{
    if (function_exists('apcu_store') && apcu_enabled()) {
        $key = 'rl|' . $modul . '|' . get_client_ip();
        $n = apcu_inc($key, 1, $ok, $jendelaDetik);
        if (!$ok) {
            // Entri belum ada (atau kadaluarsa): mulai penghitung baru.
            apcu_store($key, 1, $jendelaDetik);
            return true;
        }
        return $n <= $maks;
    }

    return rate_limit_boleh_file($modul, $maks, $jendelaDetik);
}

/**
 * Fallback TANPA APCu (mis. XAMPP/Laragon dev atau hosting tanpa ext-apcu):
 * penghitung berkas + flock di var/cache/ratelimit. Tetap benar untuk request
 * bersamaan karena baca->putuskan->tulis dilakukan di dalam kunci eksklusif.
 * DEGRADE AMAN: bila direktori tidak bisa dibuat/ditulis, semua request lolos
 * (jangan sampai rate-limit memblokir seluruh aplikasi karena masalah disk).
 */
function rate_limit_boleh_file(string $modul, int $maks, int $jendelaDetik): bool
{
    $dir = dirname(__DIR__) . '/var/cache/ratelimit';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return true;

    $file = $dir . '/' . hash('sha256', $modul . '|' . get_client_ip()) . '.json';
    $fp = @fopen($file, 'c+');
    if (!$fp) return true;

    $boleh = true;
    try {
        if (flock($fp, LOCK_EX)) {
            $now = time();
            $data = json_decode((string) stream_get_contents($fp), true) ?: [];
            if (empty($data['start']) || ($now - (int) $data['start']) >= $jendelaDetik) {
                // Jendela baru.
                $data = ['start' => $now, 'count' => 1];
            } else {
                $data['count'] = (int) $data['count'] + 1;
            }
            $boleh = (int) $data['count'] <= $maks;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
    } finally {
        fclose($fp);
    }

    // Pembersihan oportunistik berkas jendela lama (sekali per ~50 request).
    if (random_int(1, 50) === 1) {
        foreach ((array) @glob($dir . '/*.json') as $f) {
            if (@filemtime($f) < time() - max(3600, $jendelaDetik * 4)) @unlink($f);
        }
    }

    return $boleh;
}

/** Jawab 429 lalu berhenti. JSON jika kemungkinan dipakai API, HTML selainnya. */
function rate_limit_gate(string $modul, int $maks, int $jendelaDetik): void
{
    if (rate_limit_boleh($modul, $maks, $jendelaDetik)) return;
    rate_limit_tolak($jendelaDetik);
}

/**
 * D4: pembatas DUA LAPIS untuk endpoint bervolume tinggi (presensi/scan):
 *   1) per-IP dengan batas LONGGAR  -> jaring anti-abuse dari satu sumber,
 *      tapi tidak menghukum puluhan siswa di belakang SATU NAT/CGNAT;
 *   2) per-IDENTITAS (device UUID) dengan batas KETAT -> menahan satu
 *      perangkat yang disalahgunakan tanpa menyeret perangkat tetangga.
 * UUID tidak disimpan mentah di key cache (dipotong hash-nya).
 */
function rate_limit_identitas_boleh(string $modul, string $identitas, int $maksId, int $maksIp, int $jendelaDetik): bool
{
    if (!rate_limit_boleh($modul . '|ip', $maksIp, $jendelaDetik)) return false;
    if ($identitas === '') return true;
    $id = substr(hash('sha256', $identitas), 0, 16);
    return rate_limit_boleh($modul . '|id|' . $id, $maksId, $jendelaDetik);
}

/** Varian gate (429 + exit). */
function rate_limit_identitas_gate(string $modul, string $identitas, int $maksId, int $maksIp, int $jendelaDetik): void
{
    if (rate_limit_identitas_boleh($modul, $identitas, $maksId, $maksIp, $jendelaDetik)) return;
    rate_limit_tolak($jendelaDetik);
}

/** Kirim respons 429 standar lalu exit (dipakai kedua varian gate). */
function rate_limit_tolak(int $jendelaDetik): void
{
    $wantsJson = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
        || str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json');
    if ($wantsJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: ' . max(5, $jendelaDetik));
        }
        // Pasca-audit: dulu cabang JSON hanya balas 200 + body TOO_MANY — klien
        // yang memeriksa status HTTP tidak mendeteksi penolakan. Kini 429 juga.
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'code'    => 'TOO_MANY',
            'message' => 'Terlalu banyak permintaan. Tunggu sejenak lalu coba lagi.',
        ], JSON_UNESCAPED_UNICODE);
    } else {
        if (!headers_sent()) header('Retry-After: ' . max(5, $jendelaDetik));
        http_response_code(429);
        echo 'Terlalu banyak permintaan. Tunggu sejenak lalu coba lagi.';
    }
    exit;
}
