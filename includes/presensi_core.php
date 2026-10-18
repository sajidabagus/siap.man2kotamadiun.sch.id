<?php
/**
 * Logika inti proses presensi. Dipakai oleh halaman /presensi dan /api
 * agar validasi TIDAK PERNAH hanya bergantung pada JavaScript di browser.
 *
 * URUTAN VALIDASI SERVER (lihat juga fungsi proses_simpan_presensi):
 *  1. QR Token gerbang (dinamis)      -> validate_qr_dinamis()
 *  2. Siswa ditemukan?                -> cari via qr_token
 *  3. Status siswa aktif?
 *  4. Device Binding (cocok/baru)     -> check_or_register_device()
 *  5. Status perangkat (BLOCKED?)
 *  6. Presensi ganda hari ini?
 *  7. Tanggal server
 *  8. Jam server -> tentukan status HADIR/TERLAMBAT
 *  9. Geolocation (jika aktif di pengaturan)
 * 10. Simpan presensi
 * 11. Security log
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/device_binding.php';
require_once __DIR__ . '/qr_dinamis.php';

/**
 * Cari siswa berdasarkan qr_token pribadi (dari kartu/QR cetak siswa).
 */
function identify_siswa_by_token(string $qrToken): ?array
{
    $stmt = getDB()->prepare('SELECT * FROM siswa WHERE qr_token = :t LIMIT 1');
    $stmt->execute([':t' => $qrToken]);
    return $stmt->fetch() ?: null;
}

/**
 * Cari siswa yang device-nya sudah terdaftar dan aktif.
 * Dipakai untuk auto-identify tanpa perlu scan QR pribadi setiap hari.
 * C1: pencocokan mencakup varian hash v3 (HMAC uuid+secret) dan v2 legacy.
 */
function identify_siswa_by_device(string $installUuid, ?string $secret = null): ?array
{
    if (!is_valid_install_uuid($installUuid)) return null;
    $adaV3 = siapkan_kolom_hash_v3();
    $hashes = array_values(device_hash_variants($installUuid, $secret));

    // Placeholder TERPISAH utk tiap klausa IN: PDO (emulasi prepare mati)
    // menolak satu nama parameter dipakai dua kali dalam satu statement.
    $phLegacy = [];
    $params = [];
    foreach ($hashes as $i => $h) {
        $phLegacy[] = ":h$i";
        $params[":h$i"] = $h;
    }
    $sql = 'SELECT s.*, db.status AS device_status, db.device_hash
            FROM device_bindings db
            JOIN siswa s ON s.id = db.siswa_id
            WHERE db.device_hash IN (' . implode(', ', $phLegacy) . ')';
    if ($adaV3) {
        $phV3 = [];
        foreach ($hashes as $i => $h) {
            $phV3[] = ":g$i";
            $params[":g$i"] = $h;
        }
        $sql .= ' OR db.device_hash_v3 IN (' . implode(', ', $phV3) . ')';
    }
    $sql .= ' LIMIT 1';

    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

/**
 * Sudah presensi hari ini?
 * Tanggal SELALU dihitung dari PHP (bukan CURDATE() SQL) agar konsisten
 * dengan zona waktu yang di-set di config/database.php, walau di hosting
 * yang membatasi perintah SET time_zone sekalipun.
 */
function sudah_presensi_hari_ini(int $siswaId): ?array
{
    $stmt = getDB()->prepare('SELECT * FROM presensi WHERE siswa_id = :sid AND tanggal = :tgl LIMIT 1');
    $stmt->execute([':sid' => $siswaId, ':tgl' => date('Y-m-d')]);
    return $stmt->fetch() ?: null;
}

/**
 * Ringkasan presensi 1 siswa dalam bulan berjalan (berdasarkan tanggal acuan).
 * Dipakai di halaman "Presensi Berhasil" utk menampilkan rekap bulanan.
 */
function get_ringkasan_bulanan_siswa(int $siswaId, string $tanggalAcuan): array
{
    $bulan = (int) date('n', strtotime($tanggalAcuan));
    $tahun = (int) date('Y', strtotime($tanggalAcuan));

    $stmt = getDB()->prepare(
        "SELECT
            SUM(status='HADIR') AS hadir,
            SUM(status='TERLAMBAT') AS terlambat,
            SUM(status IN ('ALPA','IZIN','SAKIT')) AS tidak_hadir,
            COUNT(*) AS total_hari
         FROM presensi
         WHERE siswa_id = :sid AND MONTH(tanggal) = :bulan AND YEAR(tanggal) = :tahun"
    );
    $stmt->execute([':sid' => $siswaId, ':bulan' => $bulan, ':tahun' => $tahun]);
    $r = $stmt->fetch();

    $tepatWaktu = (int) ($r['hadir'] ?? 0);
    $terlambat = (int) ($r['terlambat'] ?? 0);
    $tidakHadir = (int) ($r['tidak_hadir'] ?? 0);
    $totalHari = (int) ($r['total_hari'] ?? 0);
    // TERLAMBAT tetap dihitung sebagai HADIR (siswa tetap datang, hanya telat).
    // 'hadir' = tepat waktu + terlambat; 'terlambat' adalah bagian dari 'hadir'.
    $hadir = $tepatWaktu + $terlambat;
    $persentase = $totalHari > 0 ? round($hadir / $totalHari * 100, 1) : 0;

    return [
        'hadir' => $hadir, 'tepat_waktu' => $tepatWaktu, 'terlambat' => $terlambat, 'tidak_hadir' => $tidakHadir,
        'total_hari' => $totalHari, 'persentase' => $persentase,
    ];
}

/**
 * PROSES UTAMA: validasi menyeluruh + simpan presensi.
 * $installUuid WAJIB dikirim dari klien (localStorage), tidak pernah dipercaya mentah tanpa hashing.
 *
 * Return: ['success'=>bool, 'code'=>string, 'message'=>string, 'data'=>array|null]
 */
function proses_simpan_presensi(int $siswaId, string $gateToken, string $installUuid, ?float $lat, ?float $lng, ?string $deviceSecret = null): array
{
    $db = getDB();

    // 1. Validasi token gerbang/kelas (dinamis)
    $qrRow = get_active_qr_dinamis($gateToken);
    if (!$qrRow) {
        security_log($siswaId, 'PRESENCE_REJECTED', null, 'Token QR gerbang/kelas tidak valid/kedaluwarsa.');
        return ['success' => false, 'code' => 'GATE_INVALID', 'message' => 'Sesi QR sudah kedaluwarsa. Silakan scan ulang QR di sekolah.'];
    }

    // 2. Siswa ditemukan?
    $stmt = $db->prepare('SELECT * FROM siswa WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $siswaId]);
    $siswa = $stmt->fetch();
    if (!$siswa) {
        security_log(null, 'PRESENCE_REJECTED', null, 'Siswa tidak ditemukan (id tidak valid).');
        return ['success' => false, 'code' => 'NOT_FOUND', 'message' => 'Data siswa tidak ditemukan.'];
    }

    // 3. Status siswa aktif?
    if ($siswa['status'] !== 'aktif') {
        security_log($siswaId, 'PRESENCE_REJECTED', null, 'Siswa berstatus nonaktif.');
        return ['success' => false, 'code' => 'INACTIVE', 'message' => 'Status siswa tidak aktif. Hubungi admin sekolah.'];
    }

    // 3b. Kalau QR ini QR KELAS (kelas_target terisi), pastikan siswa memang dari kelas tsb.
    if (!empty($qrRow['kelas_target']) && $qrRow['kelas_target'] !== $siswa['kelas']) {
        security_log(
            $siswaId, 'PRESENCE_REJECTED', null,
            "QR khusus kelas {$qrRow['kelas_target']}, siswa dari kelas {$siswa['kelas']}."
        );
        return [
            'success' => false, 'code' => 'KELAS_MISMATCH',
            'message' => "QR ini khusus untuk presensi kelas {$qrRow['kelas_target']}. Anda terdaftar di kelas {$siswa['kelas']} — gunakan QR kelas Anda sendiri atau QR gerbang.",
        ];
    }

    // 4 & 5. Device binding (cocokkan / daftarkan / cek blokir)
    if (!is_valid_install_uuid($installUuid)) {
        return ['success' => false, 'code' => 'DEVICE_INVALID', 'message' => 'Identitas perangkat tidak valid. Muat ulang halaman.'];
    }
    $deviceResult = check_or_register_device($siswaId, $installUuid, $deviceSecret);
    if (!$deviceResult['allowed']) {
        security_log($siswaId, 'PRESENCE_REJECTED', device_hash_variants($installUuid, $deviceSecret)[$deviceSecret !== null && is_valid_device_secret($deviceSecret) ? 'v3' : 'v2'], 'Ditolak: ' . $deviceResult['code']);
        return ['success' => false, 'code' => $deviceResult['code'], 'message' => $deviceResult['message']];
    }

    // 6. Presensi ganda hari ini?
    $existing = sudah_presensi_hari_ini($siswaId);
    if ($existing) {
        security_log($siswaId, 'PRESENCE_REJECTED', null, 'Sudah presensi hari ini.');
        return [
            'success' => false, 'code' => 'ALREADY_PRESENT',
            'message' => 'Anda sudah melakukan presensi hari ini.',
            'data' => ['jam' => $existing['jam_masuk'], 'status' => $existing['status']],
        ];
    }

    // 7 & 8. Tanggal & jam server (SUMBER WAKTU SELALU DARI SERVER, bukan dari klien)
    $tanggal = date('Y-m-d');
    $jam = date('H:i:s');
    $settings = get_settings();

    // 8a. Hari non-efektif (mis. Sabtu/Minggu/libur) -> presensi ditutup.
    if (!is_hari_efektif($tanggal)) {
        security_log($siswaId, 'PRESENCE_REJECTED', null, 'Presensi di hari non-efektif (' . NAMA_HARI[(int) date('N')] . ').');
        return [
            'success' => false, 'code' => 'HARI_LIBUR',
            'message' => 'Presensi ditutup hari ini (' . NAMA_HARI[(int) date('N')] . '). Hari efektif presensi: ' . label_hari_efektif() . '.',
        ];
    }

    // 8b. Belum masuk jam presensi (pengaturan "Jam Masuk")? Mencegah
    // presensi jauh sebelum jam sekolah dimulai (mis. tengah malam), yang
    // bisa jadi indikasi penyalahgunaan QR/device di luar jam wajar.
    $jamBuka = $settings['jam_masuk'] ?? null;
    if ($jamBuka && $jam < $jamBuka) {
        security_log($siswaId, 'PRESENCE_REJECTED', null, "Presensi sebelum jam buka ($jamBuka).");
        return [
            'success' => false, 'code' => 'BELUM_WAKTUNYA',
            'message' => 'Presensi belum dibuka. Presensi dimulai pukul ' . substr($jamBuka, 0, 5) . '.',
        ];
    }

    $statusPresensi = tentukan_status_presensi($jam);

    // 9. Geolocation. Wajib bila gps_aktif menyala. D2: QR KELAS (token
    // permanen — bisa difoto lalu dipakai dari rumah) SELALU mewajibkan bukti
    // lokasi. Validasi RADIUS hanya saat gps_aktif menyala (pasca-audit: jangan
    // validasi radius terhadap koordinat contoh bawaan installer).
    $qrKelas = !empty($qrRow['kelas_target']);
    if (!empty($settings['gps_aktif'])) {
        if ($lat === null || $lng === null) {
            security_log($siswaId, 'PRESENCE_REJECTED', null, 'Lokasi perangkat tidak terdeteksi (GPS wajib).');
            return ['success' => false, 'code' => 'GPS_REQUIRED', 'message' => 'Lokasi perangkat tidak terdeteksi. Aktifkan GPS dan izinkan akses lokasi.'];
        }
        $jarak = distance_meter((float)$settings['latitude_sekolah'], (float)$settings['longitude_sekolah'], $lat, $lng);
        if ($jarak > (float)$settings['radius_meter']) {
            security_log($siswaId, 'PRESENCE_REJECTED', null, 'Di luar radius sekolah (' . round($jarak) . 'm).');
            return ['success' => false, 'code' => 'OUT_OF_RANGE', 'message' => 'Presensi hanya dapat dilakukan di area sekolah.'];
        }
    } elseif ($qrKelas) {
        // D2 dipersempit pasca-audit: cukup WAJIB ADA lokasi (anti-replay).
        // Radius tidak dicek di sini — radius hanya berlaku saat admin
        // mengaktifkan gps_aktif (lihat cabang di atas), sehingga koordinat
        // contoh bawaan tidak menolak siswa yang lokasinya benar.
        if ($lat === null || $lng === null) {
            security_log($siswaId, 'PRESENCE_REJECTED', null, 'QR kelas tanpa bukti lokasi.');
            return ['success' => false, 'code' => 'GPS_REQUIRED', 'message' => 'Presensi melalui QR kelas wajib menyertakan lokasi. Aktifkan GPS / izin lokasi lalu coba lagi.'];
        }
    }

    // 10. Simpan presensi (kelas_saat_presensi = SNAPSHOT kelas siswa SAAT
    // INI, bukan referensi dinamis -- supaya kalau siswa naik/pindah kelas
    // nanti, riwayat presensi ini TETAP tercatat sebagai kejadian di kelas
    // yang benar, tidak ikut "pindah" ke kelas siswa yg baru di laporan).
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt = $db->prepare(
        'INSERT INTO presensi (siswa_id, kelas_saat_presensi, tanggal, jam_masuk, status, metode, device_info, ip_address, latitude, longitude, created_at)
         VALUES (:sid, :kelas, :tgl, :jam, :status, "QR", :device_info, :ip, :lat, :lng, NOW())'
    );
    $params = [
        ':sid' => $siswaId, ':kelas' => $siswa['kelas'], ':tgl' => $tanggal, ':jam' => $jam, ':status' => $statusPresensi,
        ':device_info' => substr($ua, 0, 255), ':ip' => get_client_ip(),
        ':lat' => $lat, ':lng' => $lng,
    ];
    try {
        $stmt->execute($params);
    } catch (PDOException $e) {
        // RACE CONDITION (fix C5): dua request nyaris bersamaan dari satu siswa
        // (mis. double-tap tombol Konfirmasi) sama-sama lolos pengecekan di atas.
        // uniq_siswa_tanggal menolak INSERT kedua (`23000`) — dulu ini meletup
        // jadi HTTP 500 padahal presensi pertama sukses. Sekarang dijadikan
        // jawaban "sudah presensi" yang normal, dengan data baris yang menang.
        if (((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) && ($existing = sudah_presensi_hari_ini($siswaId))) {
            security_log($siswaId, 'PRESENCE_DUPLICATE_RACE', null, 'INSERT presensi ganda dicegah UNIQUE (siswa_id, tanggal).');
            return [
                'success' => false, 'code' => 'ALREADY_PRESENT',
                'message' => 'Anda sudah melakukan presensi hari ini.',
                'data' => ['jam' => $existing['jam_masuk'], 'status' => $existing['status']],
            ];
        }
        throw $e;
    }

    // 11. Security log
    security_log($siswaId, 'PRESENCE_SUCCESS', device_hash_variants($installUuid, $deviceSecret)[$deviceSecret !== null && is_valid_device_secret($deviceSecret) ? 'v3' : 'v2'], "Presensi $statusPresensi jam $jam");

    return [
        'success' => true,
        'code' => 'OK',
        'message' => $settings['pesan_sukses'] ?? 'Presensi berhasil.',
        'data' => [
            'siswa_id' => $siswaId, 'nama' => $siswa['nama'], 'nis' => $siswa['nis'],
            'kelas' => $siswa['kelas'], 'foto' => $siswa['foto'], 'status_siswa' => $siswa['status'],
            'tanggal' => $tanggal, 'jam' => $jam, 'status' => $statusPresensi,
            'device_new' => ($deviceResult['code'] === 'REGISTERED_NEW'),
        ],
    ];
}