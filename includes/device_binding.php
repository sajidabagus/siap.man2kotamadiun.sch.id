<?php
/**
 * =====================================================================
 * MODUL DEVICE BINDING — SATU SISWA SATU PERANGKAT
 * =====================================================================
 * Modul ini TIDAK menerima input mentah "device_id" dari klien sebagai
 * bukti tunggal. Klien (JS) mengirim sebuah UUID instalasi yang
 * tersimpan persisten di localStorage (dibuat sekali oleh browser).
 * Server meng-hash UUID tersebut (SHA-256) untuk dibandingkan.
 *
 * PENTING: User-Agent SENGAJA TIDAK ikut di-hash. UA berubah otomatis setiap
 * Chrome / Android System WebView ter-update (mis. 154.0.8037.106 -> .107),
 * sehingga hash lama tidak cocok lagi dan perangkat yang sah dianggap
 * "belum terdaftar". UUID acak 122-bit sudah cukup sebagai identitas;
 * UA mudah dipalsukan sehingga tidak menambah keamanan nyata.
 * =====================================================================
 */

require_once __DIR__ . '/functions.php';

/** Versi skema hash. Naikkan bila rumus hash berubah lagi. */
const DEVICE_HASH_VERSI = 'v2';

/**
 * Membentuk device_hash HANYA dari UUID instalasi (kiriman klien) — stabil
 * walau browser/WebView ter-update. TIDAK memakai User-Agent.
 *
 * Self-healing: baris binding lama (skema v1 = UUID + User-Agent) otomatis
 * dimigrasikan ke hash v2 lewat kolom device_uuid, sekali per UUID per
 * request, sehingga perangkat yang sudah terdaftar tidak perlu daftar ulang.
 */
/**
 * C1: validasi format device_secret (hex 32-128 char) yang dikirim klien.
 */
function is_valid_device_secret(string $secret): bool
{
    return (bool) preg_match('/^[0-9a-fA-F]{32,128}$/', $secret);
}

/**
 * C1 — HASH v3 HMAC: SHA-256('v3|' . HMAC-SHA256(uuid, device_secret)).
 * Secret di-generate klien (crypto.getRandomValues) SEKALI per instalasi,
 * disimpan ganda (localStorage + cookie) persis pola UUID, dan ikut
 * dikirim di setiap request perangkat. Akibatnya:
 *   - UUID yang berhasil disalin saja TIDAK cukup untuk memakai identitas
 *     perangkat (harus PLUS secret, yang tidak ikut ke mana-mana dengan
 *     UUID di body request biasa).
 *   - Rotasi/registrasi identitas liar tidak bisa "menciptakan" hash baru.
 */
function build_device_hash_v3(string $installUuid, string $secret): string
{
    return hash('sha256', 'v3|' . hash_hmac('sha256', $installUuid, $secret));
}

/**
 * Hash perangkat utk pengiriman normal. Bila klien mengirim secret yang
 * valid -> kembalikan hash v3. Bila tidak (client-lawan tanpa secret,
 * JS cache lama, alur v2) -> kembali ke skema v2 seperti sebelumnya.
 */
function build_device_hash(string $installUuid, ?string $secret = null): string
{
    if ($secret !== null && is_valid_device_secret($secret)) {
        return build_device_hash_v3($installUuid, $secret);
    }
    $hash = hash('sha256', DEVICE_HASH_VERSI . '|' . $installUuid);
    sinkronkan_hash_perangkat($installUuid, $hash);
    return $hash;
}

/**
 * Semua hash kandidat utk pencocokan binding: v3 (bila secret tersedia)
 * dan v2 (fallback transisi). Dipakai get/cek binding secara tepat.
 */
function device_hash_variants(string $installUuid, ?string $secret = null): array
{
    $varian = ['v2' => hash('sha256', DEVICE_HASH_VERSI . '|' . $installUuid)];
    if ($secret !== null && is_valid_device_secret($secret)) {
        $varian['v3'] = build_device_hash_v3($installUuid, $secret);
    }
    return $varian;
}

/**
 * D1 — Cocokkan binding terhadap SEMUA varian hash klien di KEDUA kolom
 * (legacy `device_hash` & `device_hash_v3`). Dipakai jalur preview
 * (api/scan.php) & check_or_register_device() supaya logika pencocokan
 * konsisten dan tidak lagi membandingkan satu kolom saja.
 */
function binding_cocok(array $binding, array $varian): bool
{
    foreach ($varian as $h) {
        if (!empty($binding['device_hash']) && hash_equals((string) $binding['device_hash'], $h)) return true;
        if (!empty($binding['device_hash_v3']) && hash_equals((string) $binding['device_hash_v3'], $h)) return true;
    }
    return false;
}

/**
 * Kolom device_hash_v3 (store hash v3 terpisah dari kolom device_hash v1/v2
 * legacy) dibuat lazy berpola sama dengan kolom settings/role lain.
 * Return false bila ALTER gagal ( privilege dibatasi) — sistem kemudian
 * berjalan murni v2 seperti sebelumnya (degrade aman, tidak memblokir).
 */
function siapkan_kolom_hash_v3(): bool
{
    static $ada = null;
    if ($ada !== null) return $ada;
    try {
        $db = getDB();
        if ($db->query("SHOW COLUMNS FROM device_bindings LIKE 'device_hash_v3'")->fetch()) {
            return $ada = true;
        }
        $db->exec("ALTER TABLE device_bindings ADD COLUMN device_hash_v3 CHAR(64) DEFAULT NULL AFTER device_hash,
                   ADD INDEX idx_device_hash_v3 (device_hash_v3)");
        return $ada = true;
    } catch (Throwable $e) {
        error_log('siapkan_kolom_hash_v3: ' . $e->getMessage());
        return $ada = false;
    }
}

/**
 * Migrasi otomatis hash lama (v1) -> v2 untuk perangkat dengan UUID ini.
 * Aman dipanggil berulang: hanya mengubah baris yang hash-nya belum v2,
 * dan hanya berjalan sekali per UUID dalam satu request.
 */
function sinkronkan_hash_perangkat(string $installUuid, string $hashBaru): void
{
    static $sudah = [];
    if (isset($sudah[$installUuid]) || !is_valid_install_uuid($installUuid)) return;
    $sudah[$installUuid] = true;
    try {
        // Placeholder terpisah utk SET vs WHERE: PDO native (emulasi mati)
        // menolak satu nama parameter dipakai dua kali dalam satu statement.
        $stmt = getDB()->prepare(
            'UPDATE device_bindings SET device_hash = :baru
             WHERE device_uuid = :uuid AND device_hash <> :baru2'
        );
        $stmt->execute([':baru' => $hashBaru, ':baru2' => $hashBaru, ':uuid' => $installUuid]);
    } catch (PDOException $e) {
        // Bentrok UNIQUE (sangat jarang) -> abaikan; jangan sampai memblokir presensi.
        error_log('sinkronkan_hash_perangkat: ' . $e->getMessage());
    }
}

/**
 * Validasi format UUID v4 sederhana supaya klien tidak bisa mengirim
 * string sembarangan yang terlalu pendek/panjang.
 */
function is_valid_install_uuid(string $uuid): bool
{
    return (bool) preg_match('/^[0-9a-fA-F-]{20,64}$/', $uuid);
}

/**
 * Ambil binding aktif milik seorang siswa (jika ada).
 */
function get_device_binding(int $siswaId): ?array
{
    $stmt = getDB()->prepare('SELECT * FROM device_bindings WHERE siswa_id = :sid LIMIT 1');
    $stmt->execute([':sid' => $siswaId]);
    return $stmt->fetch() ?: null;
}

/**
 * Cari binding berdasarkan device_hash (lintas siswa). Dipakai utk mengecek
 * apakah sebuah perangkat SUDAH dipakai siswa lain sebelum mengizinkannya
 * didaftarkan ke siswa baru — inilah kunci "1 siswa = 1 device" yang
 * sesungguhnya (bukan cuma dicek dari sisi siswa saja).
 *
 * C1: kini menerima hash tunggal ATAU beberapa varian hash (v3 + v2) dan
 * mencocokkannya ke kolom legacy `device_hash` maupun kolom `device_hash_v3`.
 */
function get_device_binding_by_hash(string|array $deviceHash): ?array
{
    $hashes = is_array($deviceHash)
        ? array_values(array_unique(array_filter($deviceHash, fn($h) => is_string($h) && $h !== '')))
        : [$deviceHash];
    if (!$hashes) return null;

    // Placeholder TERPISAH utk tiap klausa IN: PDO (emulasi prepare mati)
    // menolak satu nama parameter dipakai dua kali dalam satu statement.
    $phLegacy = [];
    $params = [];
    foreach ($hashes as $i => $h) {
        $phLegacy[] = ":h$i";
        $params[":h$i"] = $h;
    }

    // Pastikan kolom device_hash_v3 ada sebelum kueri menyebutnya
    // (jika ALTER gagal, pencocokan fallback hanya ke varian v2).
    $kolV3 = '';
    if (siapkan_kolom_hash_v3()) {
        $phV3 = [];
        foreach ($hashes as $i => $h) {
            $phV3[] = ":g$i";
            $params[":g$i"] = $h;
        }
        $kolV3 = ' OR device_hash_v3 IN (' . implode(', ', $phV3) . ')';
    }
    $stmt = getDB()->prepare(
        'SELECT * FROM device_bindings WHERE device_hash IN (' . implode(', ', $phLegacy) . ')' . $kolV3 . ' LIMIT 1'
    );
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

/**
 * INTI ALUR DEVICE BINDING.
 *
 * C1: kini menerima device_secret opsional dari klien (hash v3 HMAC).
 *
 * Return array:
 *  [
 *    'allowed' => bool,
 *    'code'    => 'REGISTERED_NEW' | 'MATCH' | 'MISMATCH' | 'BLOCKED' | 'BINDING_DISABLED' | 'DEVICE_ALREADY_BOUND',
 *    'message' => string,
 *  ]
 */
function check_or_register_device(int $siswaId, string $installUuid, ?string $secret = null): array
{
    $settings = get_settings();
    if (empty($settings['device_binding_aktif'])) {
        return ['allowed' => true, 'code' => 'BINDING_DISABLED', 'message' => 'Device binding tidak aktif.'];
    }
    siapkan_kolom_hash_v3();

    $varian  = device_hash_variants($installUuid, $secret);
    $deviceHash = $varian['v3'] ?? $varian['v2'];   // hash utama (utk log)
    $haV3    = $varian['v3'] ?? null;               // null = klien tanpa secret
    $ua = parse_user_agent($_SERVER['HTTP_USER_AGENT'] ?? '');
    $ip = get_client_ip();

    $binding = get_device_binding($siswaId);

    // --- Belum pernah ada device terdaftar untuk siswa ini ---
    if (!$binding) {
        // PENTING: cek dulu apakah device (hash) ini SUDAH dipakai siswa LAIN.
        // Tanpa cek ini, 1 perangkat bisa didaftarkan ke banyak siswa berbeda —
        // celah utama titip-absen yang device binding harusnya mencegah.
        $sudahDipakaiSiswaLain = get_device_binding_by_hash(array_values($varian));
        if ($sudahDipakaiSiswaLain && (int) $sudahDipakaiSiswaLain['siswa_id'] !== $siswaId) {
            security_log(
                $siswaId, 'DEVICE_MISMATCH', $deviceHash,
                'Percobaan daftar device yang sudah terikat siswa ID ' . $sudahDipakaiSiswaLain['siswa_id'] . '.'
            );
            return [
                'allowed' => false, 'code' => 'DEVICE_ALREADY_BOUND',
                'message' => 'Perangkat ini sudah terdaftar untuk siswa lain. Satu perangkat hanya dapat digunakan oleh satu siswa. Hubungi admin jika ini keliru.',
            ];
        }

        // Mode "Wajib Kode Pasangan" (default aktif, GLM #4): Perangkat baru TIDAK didaftarkan otomatis; harus lewat kode dari admin.
        if (device_wajib_kode_aktif($settings)) {
            return [
                'allowed' => false, 'code' => 'PAIRING_REQUIRED',
                'message' => 'Perangkat ini belum didaftarkan. Minta Kode Pasangan 6 angka ke admin, lalu daftarkan perangkat ini.',
            ];
        }

        try {
            $stmt = getDB()->prepare(
                'INSERT INTO device_bindings
                    (siswa_id, device_uuid, device_hash, device_hash_v3, device_name, browser, os, ip_address, status, registered_at, last_used_at)
                 VALUES
                    (:sid, :uuid, :hash, :hash_v3, :name, :browser, :os, :ip, "ACTIVE", NOW(), NOW())'
            );
            $stmt->execute([
                ':sid'     => $siswaId,
                ':uuid'    => $installUuid,
                ':hash'    => $varian['v2'],
                ':hash_v3' => $haV3,
                ':name'    => $ua['os'] . ' - ' . $ua['browser'],
                ':browser' => $ua['browser'],
                ':os'      => $ua['os'],
                ':ip'      => $ip,
            ]);
        } catch (PDOException $e) {
            // Jaring pengaman terakhir thd race condition (2 request nyaris
            // bersamaan lolos pengecekan di atas sebelum salah satu INSERT).
            // device_hash WAJIB punya UNIQUE index di database (lihat
            // database/database.sql / migration_kunci_device_hash.sql) —
            // tanpa itu, percobaan INSERT kedua bisa lolos begitu saja dan
            // 1 perangkat bisa kepakai 2 siswa berbeda.
            if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                security_log($siswaId, 'DEVICE_MISMATCH', $deviceHash, 'Race condition: device sudah keburu terikat siswa lain.');
                return [
                    'allowed' => false, 'code' => 'DEVICE_ALREADY_BOUND',
                    'message' => 'Perangkat ini sudah terdaftar untuk siswa lain. Satu perangkat hanya dapat digunakan oleh satu siswa.',
                ];
            }
            // Kolom device_hash_v3 mungkin belum ada (ALTER dibatasi hosting)
            // -> coba sekali lagi tanpa kolom v3 (degrade aman ke skema v2).
            if (str_contains($e->getMessage(), 'device_hash_v3')) {
                try {
                    $st2 = getDB()->prepare(
                        'INSERT INTO device_bindings
                            (siswa_id, device_uuid, device_hash, device_name, browser, os, ip_address, status, registered_at, last_used_at)
                         VALUES
                            (:sid, :uuid, :hash, :name, :browser, :os, :ip, "ACTIVE", NOW(), NOW())'
                    );
                    $st2->execute([
                        ':sid' => $siswaId, ':uuid' => $installUuid, ':hash' => $varian['v2'],
                        ':name' => $ua['os'] . ' - ' . $ua['browser'], ':browser' => $ua['browser'],
                        ':os' => $ua['os'], ':ip' => $ip,
                    ]);
                    security_log($siswaId, 'DEVICE_REGISTERED', $deviceHash, 'Perangkat baru didaftarkan (skema v2, kolom v3 tidak tersedia).');
                    return ['allowed' => true, 'code' => 'REGISTERED_NEW', 'message' => 'Perangkat berhasil didaftarkan.'];
                } catch (PDOException $e2) {
                    if ((int) $e2->getCode() === 23000 || str_contains($e2->getMessage(), 'Duplicate entry')) {
                        security_log($siswaId, 'DEVICE_MISMATCH', $deviceHash, 'Race condition: device sudah keburu terikat siswa lain.');
                        return [
                            'allowed' => false, 'code' => 'DEVICE_ALREADY_BOUND',
                            'message' => 'Perangkat ini sudah terdaftar untuk siswa lain. Satu perangkat hanya dapat digunakan oleh satu siswa.',
                        ];
                    }
                    throw $e2;
                }
            }
            throw $e;
        }

        security_log($siswaId, 'DEVICE_REGISTERED', $deviceHash, 'Perangkat baru didaftarkan sebagai perangkat utama.');
        return ['allowed' => true, 'code' => 'REGISTERED_NEW', 'message' => 'Perangkat berhasil didaftarkan.'];
    }

    // --- Perangkat diblokir admin ---
    if ($binding['status'] === 'BLOCKED') {
        security_log($siswaId, 'DEVICE_BLOCKED', $deviceHash, 'Percobaan presensi dari device yang diblokir.');
        return ['allowed' => false, 'code' => 'BLOCKED', 'message' => 'Perangkat ini diblokir. Silakan hubungi administrator sekolah.'];
    }

    // --- Hitung hasil pencocokan (tanpa efek samping) ---
    // D1: cocokkan ke KEDUA kolom lewat semua varian hash klien. Sebelumnya
    // perbandingan hanya ke kolom legacy dengan hash utama (v3 saat secret
    // terkirim) sehingga jalur upgrade transisi v2->v3 tidak pernah tercapai
    // dan pemilik perangkat lama ikut terblokir.
    $punyaV3Stored = !empty($binding['device_hash_v3']);
    $hasilCocok = 'TIDAK_COCOK'; // default: perangkat lain

    if ($haV3 !== null && $punyaV3Stored) {
        // Baris sudah skema v3: wajib hash v3 cocok.
        if (hash_equals((string) $binding['device_hash_v3'], $haV3)) {
            $hasilCocok = 'COCOK';
        } elseif (binding_cocok($binding, $varian)) {
            // UUID (v2) cocok tetapi secret tidak sesuai yang terdaftar.
            $hasilCocok = 'SECRET_SALAH';
        }
    } elseif ($haV3 !== null) {
        // Baris legacy (belum ada v3) + klien kini mengirim secret:
        // jalur upgrade transisi — cukup cocok di varian mana pun.
        if (binding_cocok($binding, $varian)) {
            $hasilCocok = 'COCOK_UPGRADE';
        }
    } elseif ($punyaV3Stored) {
        // Klien TANPA secret pada baris yang sudah v3 — UUID dipakai tanpa
        // secret (indikasi salinan) ; v2 saja tidak lagi cukup.
        $hasilCocok = 'SECRET_HILANG';
    } elseif (hash_equals((string) $binding['device_hash'], $varian['v2'])) {
        $hasilCocok = 'COCOK';
    }

    if ($hasilCocok === 'SECRET_SALAH') {
        security_log($siswaId, 'DEVICE_SECRET_MISMATCH', $deviceHash,
            'Hash v2 cocok tetapi HMAC secret tidak sesuai yang terdaftar (mungkin UUID/secret tersalin terpisah).');
        return [
            'allowed' => false, 'code' => 'MISMATCH',
            'message' => 'Identitas perangkat tidak sah. Gunakan perangkat yang terdaftar atau minta admin mereset perangkat.',
        ];
    }
    if ($hasilCocok === 'SECRET_HILANG') {
        security_log($siswaId, 'DEVICE_SECRET_MISSING', $deviceHash,
            'Binding sudah skema v3 tetapi client tidak mengirim secret (UUID dipakai tanpa secret).');
        return [
            'allowed' => false, 'code' => 'MISMATCH',
            'message' => 'Perangkat tidak sah. Muat ulang halaman sepenuhnya atau hubungi admin apabila berulang.',
        ];
    }
    if ($hasilCocok === 'COCOK_UPGRADE') {
        // Upgrade transisi: perangkat lama (hash v2) yang kini mengirim secret.
        $upg = getDB()->prepare(
            'UPDATE device_bindings SET device_hash_v3 = :v3 WHERE id = :id AND (device_hash_v3 IS NULL OR device_hash_v3 = "")'
        );
        $upg->execute([':v3' => $haV3, ':id' => $binding['id']]);
        security_log($siswaId, 'DEVICE_UPGRADED_V3', $haV3, 'Binding di-upgrade otomatis ke skema hash v3 (HMAC).');
    }

    if ($hasilCocok === 'COCOK' || $hasilCocok === 'COCOK_UPGRADE') {
        $upd = getDB()->prepare(
            'UPDATE device_bindings SET last_used_at = NOW(), ip_address = :ip, status = "ACTIVE" WHERE id = :id'
        );
        $upd->execute([':ip' => $ip, ':id' => $binding['id']]);
        security_log($siswaId, 'DEVICE_MATCH', $deviceHash);
        return ['allowed' => true, 'code' => 'MATCH', 'message' => 'Perangkat sesuai.'];
    }

    // --- Tidak cocok: perangkat lain ---
    security_log($siswaId, 'DEVICE_MISMATCH', $deviceHash, 'Percobaan presensi dari perangkat yang tidak terdaftar.');

    // C2 — DETEKSI ANOMALI ROTASI UUID: if DEVICE_MISMATCH berulang (> 3x / 30 hari)
    // untuk satu siswa, kemungkinan besar identitas perangkat sengaja diganti
    // (UUID baru dibuat untuk mengelak) -> tandai REVIEW utk admin.
    try {
        $c = getDB()->prepare(
            "SELECT COUNT(*) FROM security_logs
             WHERE siswa_id = :sid AND event = 'DEVICE_MISMATCH'
               AND created_at > (NOW() - INTERVAL 30 DAY)"
        );
        $c->execute([':sid' => $siswaId]);
        if ((int) $c->fetchColumn() >= 3) {
            security_log($siswaId, 'DEVICE_ANOMALY_REVIEW', $deviceHash,
                'Anomali: percobaan presensi dari perangkat tak dikenal berulang (> 3x / 30 hari). Perlu review admin / pertimbangkan mode Kode Pasangan.');
        }
    } catch (Throwable $e) {
        error_log('DEVICE_ANOMALY_REVIEW check: ' . $e->getMessage());
    }

    return [
        'allowed' => false,
        'code'    => 'MISMATCH',
        'message' => 'Perangkat tidak terdaftar. Gunakan perangkat yang telah didaftarkan untuk presensi.',
    ];
}

/**
 * Reset device oleh admin. Mengosongkan binding sehingga siswa dapat
 * mendaftarkan perangkat baru pada presensi berikutnya.
 * Membatasi jumlah reset per bulan sesuai pengaturan.
 */
function reset_device(int $siswaId, int $adminId, string $reason): array
{
    $db = getDB();
    $settings = get_settings();
    $maxReset = (int)($settings['maks_reset_device_per_bulan'] ?? 2);

    $count = $db->prepare(
        'SELECT COUNT(*) AS c FROM device_reset_logs
         WHERE siswa_id = :sid AND created_at >= :awal_bulan
           AND (reason IS NULL OR reason NOT LIKE \'[MASSAL]%\')'
    );
    $count->execute([':sid' => $siswaId, ':awal_bulan' => date('Y-m-01 00:00:00')]);
    $used = (int) $count->fetch()['c'];

    if ($used >= $maxReset) {
        return ['success' => false, 'message' => "Batas reset perangkat bulan ini ({$maxReset}x) sudah tercapai."];
    }

    $binding = get_device_binding($siswaId);
    $oldHash = $binding['device_hash'] ?? null;

    $db->beginTransaction();
    try {
        $del = $db->prepare('DELETE FROM device_bindings WHERE siswa_id = :sid');
        $del->execute([':sid' => $siswaId]);

        $log = $db->prepare(
            'INSERT INTO device_reset_logs (siswa_id, old_device_hash, admin_id, reason, created_at)
             VALUES (:sid, :hash, :admin, :reason, NOW())'
        );
        $log->execute([':sid' => $siswaId, ':hash' => $oldHash, ':admin' => $adminId, ':reason' => $reason]);

        $db->commit();
        catat_aktivitas('RESET', 'device', 'Reset perangkat ' . nama_siswa_log($siswaId) . '. Alasan: ' . $reason);
        return ['success' => true, 'message' => 'Perangkat siswa berhasil direset.'];
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Reset device error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Gagal mereset perangkat.'];
    }
}

/**
 * Tentukan sasaran reset massal dari nilai "kelas:X-A", "jenjang:XI", "manual", atau "terpilih".
 * Hanya siswa AKTIF yang punya perangkat terdaftar yang ikut.
 * Return null bila sasaran tidak valid (kelas/jenjang tidak ada).
 * Return: ['label' => 'kelas X-A', 'siswa_ids' => [..], 'diblokir' => n]
 */
function ambil_target_reset_massal(string $sasaran): ?array
{
    $db = getDB();
    $kelasAktif = $db->query("SELECT DISTINCT kelas FROM siswa WHERE status = 'aktif'")->fetchAll(PDO::FETCH_COLUMN);

    if ($sasaran === 'manual' || $sasaran === 'terpilih') {
        // Manual / terpilih dari tabel: semua siswa aktif berperangkat boleh dipilih lewat checklist.
        // Yang benar-benar diproses tetap hanya id yang dicentang (lihat panel/device.php).
        $cocok = fn(string $k): bool => true;
        $label = $sasaran === 'terpilih' ? 'siswa terpilih dari tabel' : 'pilihan manual';
    } elseif (strncmp($sasaran, 'kelas:', 6) === 0) {
        $kelas = trim(substr($sasaran, 6));
        if (!in_array($kelas, $kelasAktif, true)) return null;
        $cocok = fn(string $k): bool => $k === $kelas;
        $label = 'kelas ' . $kelas;
    } elseif (strncmp($sasaran, 'jenjang:', 8) === 0) {
        $jenjang = trim(substr($sasaran, 8));
        if (!in_array($jenjang, array_map('ambil_jenjang', $kelasAktif), true)) return null;
        $cocok = fn(string $k): bool => ambil_jenjang($k) === $jenjang;
        $label = 'jenjang ' . $jenjang;
    } else {
        return null;
    }

    $rows = $db->query(
        "SELECT s.id, s.kelas, db.status
         FROM siswa s JOIN device_bindings db ON db.siswa_id = s.id
         WHERE s.status = 'aktif'"
    )->fetchAll();

    $ids = [];
    $diblokir = 0;
    foreach ($rows as $r) {
        if (!$cocok((string) $r['kelas'])) continue;
        $ids[] = (int) $r['id'];
        if ($r['status'] === 'BLOCKED') $diblokir++;
    }
    return ['label' => $label, 'siswa_ids' => $ids, 'diblokir' => $diblokir];
}

/**
 * Reset perangkat secara massal (aksi administratif).
 * - Tidak memeriksa/menghabiskan batas reset bulanan siswa (log diberi awalan [MASSAL]).
 * - Perangkat berstatus BLOCKED dilewati kecuali $termasukBlokir = true, supaya
 *   pemblokiran yang disengaja tidak terhapus diam-diam.
 * - Semua dalam satu transaksi: gagal sebagian = tidak ada yang berubah.
 * Return: ['success' => bool, 'direset' => n, 'dilewati_blokir' => n, 'message' => '...']
 */
function reset_device_massal(array $siswaIds, int $adminId, string $reason, bool $termasukBlokir = false): array
{
    $siswaIds = array_values(array_unique(array_map('intval', $siswaIds)));
    if (!$siswaIds) {
        return ['success' => false, 'direset' => 0, 'dilewati_blokir' => 0, 'message' => 'Tidak ada perangkat terdaftar pada sasaran ini.'];
    }
    $alasanLog = mb_substr('[MASSAL] ' . $reason, 0, 255);
    $filterStatus = $termasukBlokir ? '' : " AND status <> 'BLOCKED'";

    $db = getDB();
    $direset = 0;
    $dilewati = 0;
    $db->beginTransaction();
    try {
        foreach (array_chunk($siswaIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));

            if (!$termasukBlokir) {
                $st = $db->prepare("SELECT COUNT(*) FROM device_bindings WHERE siswa_id IN ($in) AND status = 'BLOCKED'");
                $st->execute($chunk);
                $dilewati += (int) $st->fetchColumn();
            }

            $log = $db->prepare(
                "INSERT INTO device_reset_logs (siswa_id, old_device_hash, admin_id, reason, created_at)
                 SELECT siswa_id, device_hash, ?, ?, NOW() FROM device_bindings
                 WHERE siswa_id IN ($in)$filterStatus"
            );
            $log->execute(array_merge([$adminId, $alasanLog], $chunk));

            $del = $db->prepare("DELETE FROM device_bindings WHERE siswa_id IN ($in)$filterStatus");
            $del->execute($chunk);
            $direset += $del->rowCount();
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('Reset device massal error: ' . $e->getMessage());
        return ['success' => false, 'direset' => 0, 'dilewati_blokir' => 0, 'message' => 'Gagal mereset perangkat secara massal. Tidak ada data yang diubah.'];
    }

    return ['success' => true, 'direset' => $direset, 'dilewati_blokir' => $dilewati, 'message' => ''];
}

/**
 * Blokir / aktifkan kembali perangkat siswa (dipakai halaman admin).
 */
function set_device_status(int $siswaId, string $status): bool
{
    if (!in_array($status, ['ACTIVE', 'BLOCKED', 'TRUSTED'], true)) return false;
    $stmt = getDB()->prepare('UPDATE device_bindings SET status = :status WHERE siswa_id = :sid');
    $ok = $stmt->execute([':status' => $status, ':sid' => $siswaId]);
    if ($ok) {
        catat_aktivitas('UBAH', 'device', 'Status perangkat ' . nama_siswa_log($siswaId) . " diubah menjadi {$status}");
    }
    return $ok;
}

// =====================================================================
// PASANGAN PERANGKAT: dua cara mendaftarkan perangkat, keduanya dari admin
//   1. QR Pasangan  -> siswa scan QR dengan kamera perangkat (token panjang acak di dalam URL)
//   2. Kode 6 angka -> siswa mengetik kode saat konfirmasi presensi
// Satu pembuatan menghasilkan KEDUANYA; salah satu yang dipakai membuat yang lain hangus.
// Berlaku KODE_PASANGAN_MENIT menit, sekali pakai. Kode terkunci setelah
// KODE_PASANGAN_MAKS_SALAH kali salah. Yang disimpan hanya hash.
// =====================================================================
const KODE_PASANGAN_MENIT = 10;
const KODE_PASANGAN_MAKS_SALAH = 5;

function siapkan_tabel_kode_pasangan(): void
{
    static $sudah = false;
    if ($sudah) return;
    $sudah = true;
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS device_pairing_codes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        siswa_id INT UNSIGNED NOT NULL,
        code_hash CHAR(64) NOT NULL,
        salt VARCHAR(32) DEFAULT NULL,
        token_hash CHAR(64) DEFAULT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        percobaan TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pair_siswa (siswa_id, used_at, expires_at),
        INDEX idx_pair_token (token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Tabel versi lama (hanya kode 6 angka) belum punya kolom token_hash
    if (!$db->query("SHOW COLUMNS FROM device_pairing_codes LIKE 'token_hash'")->fetch()) {
        $db->exec("ALTER TABLE device_pairing_codes ADD COLUMN token_hash CHAR(64) DEFAULT NULL AFTER code_hash, ADD INDEX idx_pair_token (token_hash)");
    }
    // D3: salt acak per baris agar dump DB tidak bisa di-brute-force offline
    // (ruang kode hanya 10^6). Degrade aman bila ALTER dibatasi hosting.
    try {
        if (!$db->query("SHOW COLUMNS FROM device_pairing_codes LIKE 'salt'")->fetch()) {
            $db->exec("ALTER TABLE device_pairing_codes ADD COLUMN salt VARCHAR(32) DEFAULT NULL AFTER code_hash");
        }
    } catch (Throwable $e) {
        error_log('siapkan_tabel_kode_pasangan (salt): ' . $e->getMessage());
    }
}

/** Apakah kolom salt tersedia? (degrade aman ke format hash legacy bila tidak) */
function kolom_salt_kode_pasangan(): bool
{
    static $ada = null;
    if ($ada === null) {
        try {
            $ada = (bool) getDB()->query("SHOW COLUMNS FROM device_pairing_codes LIKE 'salt'")->fetch();
        } catch (Throwable $e) {
            $ada = false;
        }
    }
    return $ada;
}

/**
 * D3: hash kode pasangan — sertakan salt acak per baris bila tersedia:
 * sha256(siswaId|salt|kode|pasangan). Tanpa salt = format lama (kompatibel
 * dengan kode yang dibuat sebelum kolom salt ada).
 */
function hash_kode_pasangan(int $siswaId, string $kode, ?string $salt = null): string
{
    return $salt !== null && $salt !== ''
        ? hash('sha256', $siswaId . '|' . $salt . '|' . $kode . '|pasangan')
        : hash('sha256', $siswaId . '|' . $kode . '|pasangan');
}

function hash_token_pasangan(string $token): string
{
    return hash('sha256', 'token|' . $token);
}

/** Alamat yang dikodekan ke dalam QR Pasangan. */
function url_pasangan(string $token): string
{
    return BASE_URL . '/presensi/daftar_hp.php?t=' . $token;
}

/** Admin membuat pasangan baru (kode + token QR); yang lama belum terpakai dibatalkan. */
function buat_kode_pasangan(int $siswaId, int $adminId): array
{
    siapkan_tabel_kode_pasangan();
    $db = getDB();
    $db->prepare('UPDATE device_pairing_codes SET used_at = NOW() WHERE siswa_id = :s AND used_at IS NULL')->execute([':s' => $siswaId]);

    $kode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $token = bin2hex(random_bytes(16));
    $salt = kolom_salt_kode_pasangan() ? bin2hex(random_bytes(8)) : null;
    if ($salt !== null) {
        $db->prepare(
            'INSERT INTO device_pairing_codes (siswa_id, code_hash, salt, token_hash, expires_at, created_by)
             VALUES (:s, :h, :salt, :t, DATE_ADD(NOW(), INTERVAL ' . (int) KODE_PASANGAN_MENIT . ' MINUTE), :a)'
        )->execute([':s' => $siswaId, ':h' => hash_kode_pasangan($siswaId, $kode, $salt), ':salt' => $salt, ':t' => hash_token_pasangan($token), ':a' => $adminId ?: null]);
    } else {
        $db->prepare(
            'INSERT INTO device_pairing_codes (siswa_id, code_hash, token_hash, expires_at, created_by)
             VALUES (:s, :h, :t, DATE_ADD(NOW(), INTERVAL ' . (int) KODE_PASANGAN_MENIT . ' MINUTE), :a)'
        )->execute([':s' => $siswaId, ':h' => hash_kode_pasangan($siswaId, $kode), ':t' => hash_token_pasangan($token), ':a' => $adminId ?: null]);
    }

    $st = $db->prepare('SELECT expires_at FROM device_pairing_codes WHERE id = :id');
    $st->execute([':id' => $db->lastInsertId()]);
    return ['kode' => $kode, 'token' => $token, 'url' => url_pasangan($token), 'berlaku_sampai' => (string) $st->fetchColumn()];
}

/** Inti pendaftaran: dipakai oleh jalur kode maupun jalur QR (kode/token sudah tervalidasi). C1: menerima secret utk hash v3. */
function _selesaikan_pendaftaran_pasangan(array $row, int $siswaId, string $installUuid, string $via, ?string $secret = null): array
{
    $gagal = fn(string $code, string $msg) => ['success' => false, 'code' => $code, 'message' => $msg];
    $db = getDB();
    siapkan_kolom_hash_v3();
    $varian = device_hash_variants($installUuid, $secret);
    $deviceHash = $varian['v3'] ?? $varian['v2'];

    $lain = get_device_binding_by_hash(array_values($varian));
    if ($lain && (int) $lain['siswa_id'] !== $siswaId) {
        security_log($siswaId, 'DEVICE_MISMATCH', $deviceHash, 'Percobaan daftar (' . $via . ') di device milik siswa ID ' . $lain['siswa_id'] . '.');
        return $gagal('DEVICE_ALREADY_BOUND', 'Perangkat ini sudah terdaftar untuk siswa lain. Satu perangkat hanya untuk satu siswa.');
    }

    // Pakai kode/token secara atomik (cegah dipakai dua kali bersamaan)
    $pakai = $db->prepare('UPDATE device_pairing_codes SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
    $pakai->execute([':id' => $row['id']]);
    if ($pakai->rowCount() !== 1) return $gagal('KODE_INVALID', 'Kode/QR sudah dipakai. Minta admin membuat yang baru.');

    $ua = parse_user_agent($_SERVER['HTTP_USER_AGENT'] ?? '');
    try {
        $db->prepare(
            'INSERT INTO device_bindings
                (siswa_id, device_uuid, device_hash, device_hash_v3, device_name, browser, os, ip_address, status, registered_at, last_used_at)
             VALUES (:sid, :uuid, :hash, :hash_v3, :name, :browser, :os, :ip, "ACTIVE", NOW(), NOW())'
        )->execute([
            ':sid' => $siswaId, ':uuid' => $installUuid,
            ':hash' => $varian['v2'], ':hash_v3' => $varian['v3'] ?? null,
            ':name' => $ua['os'] . ' - ' . $ua['browser'], ':browser' => $ua['browser'], ':os' => $ua['os'],
            ':ip' => get_client_ip(),
        ]);
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
            return $gagal('DEVICE_ALREADY_BOUND', 'Perangkat ini sudah terdaftar untuk siswa lain.');
        }
        // Kolom v3 tidak tersedia (ALTER dibatasi) -> daftar ulang skema v2 murni.
        if (str_contains($e->getMessage(), 'device_hash_v3')) {
            $db->prepare(
                'INSERT INTO device_bindings
                    (siswa_id, device_uuid, device_hash, device_name, browser, os, ip_address, status, registered_at, last_used_at)
                 VALUES (:sid, :uuid, :hash, :name, :browser, :os, :ip, "ACTIVE", NOW(), NOW())'
            )->execute([
                ':sid' => $siswaId, ':uuid' => $installUuid, ':hash' => $varian['v2'],
                ':name' => $ua['os'] . ' - ' . $ua['browser'], ':browser' => $ua['browser'], ':os' => $ua['os'],
                ':ip' => get_client_ip(),
            ]);
        } else {
            throw $e;
        }
    }

    security_log($siswaId, 'DEVICE_REGISTERED', $deviceHash, 'Perangkat didaftarkan memakai ' . $via . '.');
    return ['success' => true, 'code' => 'PAIRED', 'message' => 'Perangkat berhasil didaftarkan.'];
}

/** Jalur 2 — kode 6 angka (siswa sudah teridentifikasi dari scan QR gerbang). C1: menerima secret. */
function daftarkan_dengan_kode(int $siswaId, string $kode, string $installUuid, ?string $secret = null): array
{
    $gagal = fn(string $code, string $msg) => ['success' => false, 'code' => $code, 'message' => $msg];
    $settings = get_settings();
    if (empty($settings['device_binding_aktif'])) {
        return $gagal('BINDING_DISABLED', 'Device binding tidak aktif, tidak perlu mendaftarkan perangkat.');
    }
    if (!is_valid_install_uuid($installUuid)) return $gagal('DEVICE_INVALID', 'Identitas perangkat tidak valid. Muat ulang halaman.');
    if (!preg_match('/^\d{6}$/', $kode)) return $gagal('KODE_FORMAT', 'Kode Pasangan harus 6 angka.');
    if (get_device_binding($siswaId)) {
        return $gagal('ALREADY_BOUND', 'Anda sudah memiliki perangkat terdaftar. Minta admin mereset perangkat bila ingin mengganti perangkat.');
    }

    siapkan_tabel_kode_pasangan();
    $db = getDB();

    // D3: kunci baris (FOR UPDATE) dalam transaksi supaya pola baca -> cek ->
    // increment terserialisasi. Tanpa ini, kiriman PARALEL sama-sama membaca
    // percobaan=0 dan batas 5 tebakan bisa ditembus dalam satu gelombang.
    $db->beginTransaction();
    try {
        $st = $db->prepare(
            'SELECT * FROM device_pairing_codes
             WHERE siswa_id = :s AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $st->execute([':s' => $siswaId]);
        $row = $st->fetch();

        if (!$row || (int) $row['percobaan'] >= KODE_PASANGAN_MAKS_SALAH) {
            $db->rollBack();
            security_log($siswaId, 'DEVICE_PAIRING_FAILED', null, 'Kode pasangan tidak ada, kedaluwarsa, atau terkunci.');
            return $gagal('KODE_INVALID', 'Kode tidak berlaku. Minta admin membuat Kode Pasangan baru.');
        }
        if (!hash_equals($row['code_hash'], hash_kode_pasangan($siswaId, $kode, $row['salt'] ?? null))) {
            $db->prepare('UPDATE device_pairing_codes SET percobaan = percobaan + 1 WHERE id = :id')->execute([':id' => $row['id']]);
            $db->commit(); // kunci dilepas SEBELUM log & return
            $sisa = max(0, KODE_PASANGAN_MAKS_SALAH - ((int) $row['percobaan'] + 1));
            security_log($siswaId, 'DEVICE_PAIRING_FAILED', null, 'Kode pasangan salah (percobaan ke-' . ((int) $row['percobaan'] + 1) . ').');
            return $gagal('KODE_SALAH', $sisa > 0 ? "Kode salah. Sisa percobaan: $sisa." : 'Kode salah dan sudah terkunci. Minta admin membuat kode baru.');
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    return _selesaikan_pendaftaran_pasangan($row, $siswaId, $installUuid, 'Kode Pasangan', $secret);
}

/** Jalur 1 — QR Pasangan (siswa scan QR dari admin; token di URL mengidentifikasi siswa). C1: menerima secret. */
function daftarkan_dengan_token(string $token, string $installUuid, ?string $secret = null): array
{
    $gagal = fn(string $code, string $msg) => ['success' => false, 'code' => $code, 'message' => $msg];
    $settings = get_settings();
    if (empty($settings['device_binding_aktif'])) {
        return $gagal('BINDING_DISABLED', 'Device binding tidak aktif, tidak perlu mendaftarkan perangkat.');
    }
    if (!is_valid_install_uuid($installUuid)) return $gagal('DEVICE_INVALID', 'Identitas perangkat tidak valid. Muat ulang halaman.');
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) return $gagal('TOKEN_INVALID', 'QR Pasangan tidak valid.');

    siapkan_tabel_kode_pasangan();
    $db = getDB();
    $st = $db->prepare(
        'SELECT * FROM device_pairing_codes
         WHERE token_hash = :t AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
    );
    $st->execute([':t' => hash_token_pasangan($token)]);
    $row = $st->fetch();
    if (!$row) {
        security_log(null, 'DEVICE_PAIRING_FAILED', null, 'QR Pasangan tidak valid/kedaluwarsa/sudah dipakai.');
        return $gagal('TOKEN_INVALID', 'QR Pasangan tidak berlaku atau sudah dipakai. Minta admin membuat yang baru.');
    }

    $siswaId = (int) $row['siswa_id'];
    $ss = $db->prepare('SELECT nama, kelas, status FROM siswa WHERE id = :id');
    $ss->execute([':id' => $siswaId]);
    $siswa = $ss->fetch();
    if (!$siswa || $siswa['status'] !== 'aktif') return $gagal('INACTIVE', 'Status siswa tidak aktif. Hubungi admin sekolah.');
    if (get_device_binding($siswaId)) {
        return $gagal('ALREADY_BOUND', 'Siswa ini sudah memiliki perangkat terdaftar. Minta admin mereset perangkat bila ingin mengganti perangkat.');
    }

    $hasil = _selesaikan_pendaftaran_pasangan($row, $siswaId, $installUuid, 'QR Pasangan', $secret);
    if ($hasil['success']) $hasil['data'] = ['nama' => $siswa['nama'], 'kelas' => $siswa['kelas']];
    return $hasil;
}


/**
 * D3: batas maksimum masa berlaku kode massal saat pemanggil meminta "tanpa
 * batas" ($menit = null). Sebelumnya kode ditulis berlaku s/d 9999 — bila
 * bocor (cetakan/screenshot/chat), itu menjadi kunci rebinding abadi.
 */
const KODE_MASSAL_MAKS_MENIT = 10080; // 7 hari

/**
 * Buat Kode Pasangan untuk banyak siswa sekaligus. $menit = null dibatasi ke
 * KODE_MASSAL_MAKS_MENIT (7 hari). Tetap sekali pakai & terkunci setelah
 * KODE_PASANGAN_MAKS_SALAH kali salah.
 * Kode lama yang belum terpakai untuk siswa-siswa tersebut dibatalkan.
 * @param int[] $siswaIds
 * @return array<int,string> siswa_id => kode 6 angka (hanya tampil sekali; di DB hanya hash)
 */
function buat_kode_pasangan_massal(array $siswaIds, int $adminId, ?int $menit): array
{
    $siswaIds = array_values(array_unique(array_map('intval', $siswaIds)));
    if (!$siswaIds) return [];
    siapkan_tabel_kode_pasangan();
    $db = getDB();
    $hasil = [];

    // D3: "tanpa batas" -> 7 hari; nilai lain minimal 1 menit.
    $menit = $menit === null ? KODE_MASSAL_MAKS_MENIT : max(1, $menit);

    $db->beginTransaction();
    try {
        foreach (array_chunk($siswaIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $db->prepare("UPDATE device_pairing_codes SET used_at = NOW() WHERE used_at IS NULL AND siswa_id IN ($in)")->execute($chunk);
        }
        $adaSalt = kolom_salt_kode_pasangan();
        if ($adaSalt) {
            $ins = $db->prepare('INSERT INTO device_pairing_codes (siswa_id, code_hash, salt, token_hash, expires_at, created_by) VALUES (:s, :h, :salt, NULL, DATE_ADD(NOW(), INTERVAL ' . (int) $menit . ' MINUTE), :a)');
        } else {
            $ins = $db->prepare('INSERT INTO device_pairing_codes (siswa_id, code_hash, token_hash, expires_at, created_by) VALUES (:s, :h, NULL, DATE_ADD(NOW(), INTERVAL ' . (int) $menit . ' MINUTE), :a)');
        }
        foreach ($siswaIds as $id) {
            $kode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $salt = $adaSalt ? bin2hex(random_bytes(8)) : null;
            $par = [':s' => $id, ':h' => hash_kode_pasangan($id, $kode, $salt), ':a' => $adminId ?: null];
            if ($adaSalt) $par[':salt'] = $salt;
            $ins->execute($par);
            $hasil[$id] = $kode;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return $hasil;
}