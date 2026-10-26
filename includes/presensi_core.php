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
 * Cari siswa yang device-nya (uuid -> hash) sudah terdaftar dan aktif.
 * Dipakai untuk auto-identify tanpa perlu scan QR pribadi setiap hari.
 */
function identify_siswa_by_device(string $installUuid): ?array
{
    if (!is_valid_install_uuid($installUuid)) return null;
    $hash = build_device_hash($installUuid);

    $stmt = getDB()->prepare(
        'SELECT s.*, db.status AS device_status, db.device_hash
         FROM device_bindings db
         JOIN siswa s ON s.id = db.siswa_id
         WHERE db.device_hash = :hash LIMIT 1'
    );
    $stmt->execute([':hash' => $hash]);
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
    // Baris izin/sakit yang DISETUJUI (metode PENGAJUAN) bukan presensi kehadiran: siswa yang tetap
    // datang boleh presensi, dan baris itu digantikan oleh presensi HADIR (lihat proses_simpan_presensi).
    $stmt = getDB()->prepare("SELECT * FROM presensi WHERE siswa_id = :sid AND tanggal = :tgl AND metode NOT IN ('PENGAJUAN','OTOMATIS') LIMIT 1");
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
        'hadir' => $hadir,
        'tepat_waktu' => $tepatWaktu,
        'terlambat' => $terlambat,
        'tidak_hadir' => $tidakHadir,
        'total_hari' => $totalHari,
        'persentase' => $persentase,
    ];
}

/**
 * PROSES UTAMA: validasi menyeluruh + simpan presensi.
 * $installUuid WAJIB dikirim dari klien (localStorage), tidak pernah dipercaya mentah tanpa hashing.
 *
 * Return: ['success'=>bool, 'code'=>string, 'message'=>string, 'data'=>array|null]
 */
function proses_simpan_presensi(int $siswaId, string $gateToken, string $installUuid, ?float $lat, ?float $lng): array
{
    $db = getDB();

    // 1. Validasi token gerbang/kelas (dinamis)
    $qrRow = get_qr_dinamis_sesi($gateToken);
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
            $siswaId,
            'PRESENCE_REJECTED',
            null,
            "QR khusus kelas {$qrRow['kelas_target']}, siswa dari kelas {$siswa['kelas']}."
        );
        return [
            'success' => false,
            'code' => 'KELAS_MISMATCH',
            'message' => "QR ini khusus untuk presensi kelas {$qrRow['kelas_target']}. Anda terdaftar di kelas {$siswa['kelas']} — gunakan QR kelas Anda sendiri atau QR gerbang.",
        ];
    }

    // 4 & 5. Device binding (cocokkan / daftarkan / cek blokir)
    if (!is_valid_install_uuid($installUuid)) {
        return ['success' => false, 'code' => 'DEVICE_INVALID', 'message' => 'Identitas perangkat tidak valid. Muat ulang halaman.'];
    }
    $deviceResult = check_or_register_device($siswaId, $installUuid);
    if (!$deviceResult['allowed']) {
        security_log($siswaId, 'PRESENCE_REJECTED', build_device_hash($installUuid), 'Ditolak: ' . $deviceResult['code']);
        return ['success' => false, 'code' => $deviceResult['code'], 'message' => $deviceResult['message']];
    }

    // 6. Presensi ganda hari ini?
    $existing = sudah_presensi_hari_ini($siswaId);
    if ($existing) {
        security_log($siswaId, 'PRESENCE_REJECTED', null, 'Sudah presensi hari ini.');
        return [
            'success' => false,
            'code' => 'ALREADY_PRESENT',
            'message' => 'Anda sudah melakukan presensi hari ini.',
            'data' => ['jam' => $existing['jam_masuk'], 'status' => $existing['status']],
        ];
    }

    // 6a. Sudah tercatat ALFA hari ini (absen ditutup)? Tidak boleh absen lagi; koreksi hanya lewat wali kelas/petugas.
    $alpaCek = $db->prepare("SELECT 1 FROM presensi WHERE siswa_id = :s AND tanggal = :t AND status = 'ALPA' LIMIT 1");
    $alpaCek->execute([':s' => $siswaId, ':t' => date('Y-m-d')]);
    if ($alpaCek->fetchColumn()) {
        security_log($siswaId, 'PRESENCE_REJECTED', null, 'Sudah tercatat Alfa hari ini.');
        return [
            'success' => false,
            'code' => 'ALPA',
            'message' => 'Anda tercatat Alfa hari ini; absen sudah ditutup. Bila Anda hadir, hubungi wali kelas/petugas.',
        ];
    }

    // 6b. Sudah mengajukan izin/sakit (menunggu atau disetujui) untuk hari ini? Tidak boleh absen.
    try {
        $iz = $db->prepare("SELECT jenis, status FROM izin_pengajuan WHERE siswa_id = :s AND status IN ('menunggu','disetujui')
                            AND tanggal_mulai <= :t AND tanggal_selesai >= :t LIMIT 1");
        $iz->execute([':s' => $siswaId, ':t' => date('Y-m-d')]);
        $izRow = $iz->fetch();
    } catch (Throwable $e) {
        $izRow = null;
    } // tabel izin belum ada
    if ($izRow) {
        $lbl = $izRow['jenis'] === 'sakit' ? 'sakit' : 'izin';
        security_log($siswaId, 'PRESENCE_REJECTED', null, 'Sudah mengajukan ' . $lbl . ' hari ini.');
        return [
            'success' => false,
            'code' => 'HAS_LEAVE',
            'message' => 'Anda sudah mengajukan ' . $lbl . ' untuk hari ini (' . ($izRow['status'] === 'disetujui' ? 'disetujui' : 'menunggu persetujuan') . '), jadi tidak bisa absen. Batalkan pengajuan dulu bila ternyata masuk sekolah.'
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
            'success' => false,
            'code' => 'HARI_LIBUR',
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
            'success' => false,
            'code' => 'BELUM_WAKTUNYA',
            'message' => 'Presensi belum dibuka. Presensi dimulai pukul ' . substr($jamBuka, 0, 5) . '.',
        ];
    }

    // 8c. Lewat batas absen masuk -> scan ditutup (siswa yang belum absen dicatat ALPA otomatis).
    if (absen_sudah_ditutup($jam)) {
        security_log($siswaId, 'PRESENCE_REJECTED', null, 'Presensi setelah batas absen (' . substr(batas_absen(), 0, 5) . ').');
        return [
            'success' => false,
            'code' => 'CLOSED',
            'message' => 'Absen masuk hari ini sudah ditutup pukul ' . substr(batas_absen(), 0, 5) . '. Siswa yang belum absen tercatat Alpa. Bila Anda hadir, hubungi wali kelas/petugas.',
        ];
    }

    $statusPresensi = tentukan_status_presensi($jam);

    // 9. Geolocation jika aktif
    if (!empty($settings['gps_aktif'])) {
        if ($lat === null || $lng === null) {
            return ['success' => false, 'code' => 'GPS_REQUIRED', 'message' => 'Lokasi perangkat tidak terdeteksi. Aktifkan GPS dan izinkan akses lokasi.'];
        }
        if (!is_finite($lat) || !is_finite($lng) || abs($lat) > 90 || abs($lng) > 180) {
            security_log($siswaId, 'PRESENCE_REJECTED', null, 'Koordinat GPS tidak valid.');
            return ['success' => false, 'code' => 'GPS_REQUIRED', 'message' => 'Lokasi perangkat tidak valid. Aktifkan GPS lalu coba lagi.'];
        }
        $jarak = distance_meter((float)$settings['latitude_sekolah'], (float)$settings['longitude_sekolah'], $lat, $lng);
        if ($jarak > (float)$settings['radius_meter']) {
            security_log($siswaId, 'PRESENCE_REJECTED', null, 'Di luar radius sekolah (' . round($jarak) . 'm).');
            return ['success' => false, 'code' => 'OUT_OF_RANGE', 'message' => 'Presensi hanya dapat dilakukan di area sekolah.'];
        }
    }

    // 10. Simpan presensi (kelas_saat_presensi = SNAPSHOT kelas siswa SAAT
    // INI, bukan referensi dinamis -- supaya kalau siswa naik/pindah kelas
    // nanti, riwayat presensi ini TETAP tercatat sebagai kejadian di kelas
    // yang benar, tidak ikut "pindah" ke kelas siswa yg baru di laporan).
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $nilai = [
        ':sid' => $siswaId,
        ':kelas' => $siswa['kelas'],
        ':tgl' => $tanggal,
        ':jam' => $jam,
        ':status' => $statusPresensi,
        ':device_info' => substr($ua, 0, 255),
        ':ip' => get_client_ip(),
        ':lat' => $lat,
        ':lng' => $lng,
    ];
    // Bila hari ini sudah ada baris izin/sakit yang disetujui tetapi siswa ternyata hadir, presensi HADIR
    // yang berlaku: baris itu diganti (bukan ditambah), dan izin hari itu ditandai "dipakai_hadir".
    $ganti = $db->prepare(
        "UPDATE presensi SET kelas_saat_presensi = :kelas, jam_masuk = :jam, status = :status, metode = 'QR',
                device_info = :device_info, ip_address = :ip, latitude = :lat, longitude = :lng, created_at = NOW()
         WHERE siswa_id = :sid AND tanggal = :tgl AND metode IN ('PENGAJUAN','OTOMATIS')"
    );
    $ganti->execute($nilai);
    if ($ganti->rowCount() > 0) {
        try {
            $db->prepare("UPDATE izin_hari h JOIN izin_pengajuan p ON p.id = h.pengajuan_id SET h.hasil = 'dipakai_hadir'
                          WHERE p.siswa_id = :sid AND h.tanggal = :tgl AND h.hasil = 'diterapkan'")
                ->execute([':sid' => $siswaId, ':tgl' => $tanggal]);
        } catch (PDOException $e) { /* tabel pengajuan belum ada: tidak masalah */
        }
    } else {
        $stmt = $db->prepare(
            'INSERT INTO presensi (siswa_id, kelas_saat_presensi, tanggal, jam_masuk, status, metode, device_info, ip_address, latitude, longitude, created_at)
             VALUES (:sid, :kelas, :tgl, :jam, :status, "QR", :device_info, :ip, :lat, :lng, NOW())'
        );
        try {
            $stmt->execute($nilai);
        } catch (PDOException $e) {
            // Dua permintaan bersamaan (dobel-tap / jaringan lambat): UNIQUE(siswa_id, tanggal) menolak yang kedua.
            if ($e->getCode() !== '23000') throw $e;
            $existing = sudah_presensi_hari_ini($siswaId);
            return [
                'success' => false,
                'code' => 'ALREADY_PRESENT',
                'message' => 'Anda sudah melakukan presensi hari ini.',
                'data' => $existing ? ['jam' => $existing['jam_masuk'], 'status' => $existing['status']] : null,
            ];
        }
    }

    // 11. Security log
    security_log($siswaId, 'PRESENCE_SUCCESS', build_device_hash($installUuid), "Presensi $statusPresensi jam $jam");

    return [
        'success' => true,
        'code' => 'OK',
        'message' => $settings['pesan_sukses'] ?? 'Presensi berhasil.',
        'data' => [
            'siswa_id' => $siswaId,
            'nama' => $siswa['nama'],
            'nis' => $siswa['nis'],
            'kelas' => $siswa['kelas'],
            'foto' => $siswa['foto'],
            'status_siswa' => $siswa['status'],
            'tanggal' => $tanggal,
            'jam' => $jam,
            'status' => $statusPresensi,
            'device_new' => ($deviceResult['code'] === 'REGISTERED_NEW'),
        ],
    ];
}
