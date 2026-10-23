<?php
/**
 * API halaman depan (tanpa login).
 * - Perangkat BELUM dikenali : ringkasan seluruh sekolah, nama DISAMARKAN KETAT ("Abh*** K. A."), kelas hanya jenjang, tanpa NIS.
 * - Perangkat SUDAH terdaftar: data hanya untuk KELAS siswa pemilik perangkat, nama tampil LENGKAP.
 * Body (opsional): {"device_uuid": "..."} ; tanpa body berperilaku seperti perangkat belum dikenali.
 */
require_once __DIR__ . '/../includes/presensi_core.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $db = getDB();
    $settings = get_settings();
    jalankan_alpa_otomatis();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $uuid = clean($input['device_uuid'] ?? '');

    // ---- Kenali perangkat (hanya bila binding aktif & perangkat ACTIVE) ----
    $saya = null;
    if (!empty($settings['device_binding_aktif']) && $uuid !== '' && is_valid_install_uuid($uuid)) {
        $b = get_device_binding_by_hash(build_device_hash($uuid));
        if ($b && $b['status'] === 'ACTIVE') {
            $st = $db->prepare("SELECT id, nama, kelas FROM siswa WHERE id = :id AND status = 'aktif'");
            $st->execute([':id' => $b['siswa_id']]);
            $saya = $st->fetch() ?: null;
        }
    }
    $kelas = $saya ? (string) $saya['kelas'] : '';
    $parK = $kelas !== '' ? [':k' => $kelas] : [];

    $st = $db->prepare("SELECT COUNT(*) FROM siswa WHERE status = 'aktif'" . ($kelas !== '' ? ' AND kelas = :k' : ''));
    $st->execute($parK);
    $total = (int) $st->fetchColumn();

    $st = $db->prepare(
        "SELECT SUM(status = 'HADIR') tepat, SUM(status = 'TERLAMBAT') terlambat,
                SUM(status IN ('IZIN','SAKIT','ALPA')) tidak_hadir
         FROM presensi WHERE tanggal = CURDATE()" . ($kelas !== '' ? ' AND kelas_saat_presensi = :k' : '')
    );
    $st->execute($parK);
    $r = $st->fetch() ?: [];
    $tepat = (int) ($r['tepat'] ?? 0);
    $terlambat = (int) ($r['terlambat'] ?? 0);
    $tidak = (int) ($r['tidak_hadir'] ?? 0);
    $hadir = $tepat + $terlambat;
    $belum = max(0, $total - $hadir - $tidak);

    $st = $db->prepare(
        "SELECT p.id, s.nama, p.kelas_saat_presensi kelas, p.jam_masuk, p.status
         FROM presensi p JOIN siswa s ON s.id = p.siswa_id
         WHERE p.tanggal = CURDATE() AND p.status IN ('HADIR','TERLAMBAT')"
         . ($kelas !== '' ? ' AND p.kelas_saat_presensi = :k' : '') .
        // Perangkat terdaftar (mode kelas): tampilkan SEMUA siswa kelas itu yang sudah presensi hari ini.
        // Tampilan publik (perangkat belum dikenali): tetap dibatasi 15 terbaru.
        " ORDER BY p.jam_masuk DESC, p.id DESC LIMIT " . ($kelas !== '' ? 500 : 15)
    );
    $st->execute($parK);
    $terbaru = array_map(fn($x) => [
        'id' => (int) $x['id'],
        // Tampilan publik: nama disamarkan ketat & kelas hanya jenjang (mis. "X"). Perangkat terdaftar: lengkap.
        'nama' => $saya ? (string) $x['nama'] : samarkan_nama_ketat((string) $x['nama']),
        'kelas' => $saya ? $x['kelas'] : ambil_jenjang((string) $x['kelas']),
        'jam' => substr((string) $x['jam_masuk'], 0, 5), 'status' => $x['status'],
    ], $st->fetchAll());

    $out = [
        'success' => true,
        'mode' => $saya ? 'kelas' : 'sekolah',
        'waktu' => date('H:i:s'),
        'efektif' => is_hari_efektif(),
        'ditutup' => absen_sudah_ditutup(),
        'batas_absen' => substr(batas_absen(), 0, 5),
        'jam_masuk' => substr((string) ($settings['jam_masuk'] ?? ''), 0, 5),
        'batas_terlambat' => substr((string) ($settings['batas_terlambat'] ?? ''), 0, 5),
        'binding_aktif' => !empty($settings['device_binding_aktif']),
        'total' => $total, 'hadir' => $hadir, 'tepat' => $tepat, 'terlambat' => $terlambat,
        'tidak_hadir' => $tidak, 'belum' => $belum,
        'persen' => $total ? (int) round($hadir / $total * 100) : 0,
        'terbaru' => $terbaru,
    ];

    if ($saya) {
        $st = $db->prepare('SELECT status, jam_masuk, metode FROM presensi WHERE siswa_id = :s AND tanggal = CURDATE() LIMIT 1');
        $st->execute([':s' => $saya['id']]);
        $p = $st->fetch();
        $out['kelas'] = $kelas;
        $out['saya'] = [
            'nama' => $saya['nama'],
            // Baris izin/sakit disetujui bukan kehadiran: kirim sebagai 'izin', bukan 'status'.
            'status' => ($p && $p['metode'] !== 'PENGAJUAN') ? $p['status'] : null,
            'izin' => ($p && $p['metode'] === 'PENGAJUAN') ? $p['status'] : null,
            'jam' => ($p && !in_array($p['metode'], ['PENGAJUAN', 'OTOMATIS'], true)) ? substr((string) $p['jam_masuk'], 0, 5) : null,
        ];

        // Ringkasan bulan ini untuk tampilan siswa (perangkat terdaftar): Hadir (termasuk terlambat), Izin (izin+sakit), Alfa.
        $awalBulan = date('Y-m-01');
        $st = $db->prepare('SELECT status, COUNT(*) n FROM presensi WHERE siswa_id = :s AND tanggal >= :a AND tanggal < :b GROUP BY status');
        $st->execute([':s' => $saya['id'], ':a' => $awalBulan, ':b' => date('Y-m-d', strtotime($awalBulan . ' +1 month'))]);
        $rek = ['HADIR' => 0, 'TERLAMBAT' => 0, 'IZIN' => 0, 'SAKIT' => 0, 'ALPA' => 0];
        foreach ($st->fetchAll() as $x) { if (isset($rek[$x['status']])) $rek[$x['status']] = (int) $x['n']; }
        $out['saya']['bulan'] = [
            'hadir' => $rek['HADIR'] + $rek['TERLAMBAT'], 'terlambat' => $rek['TERLAMBAT'],
            'izin' => $rek['IZIN'] + $rek['SAKIT'], 'alfa' => $rek['ALPA'],
        ];
        $out['saya']['alpa_hari_ini'] = ($p && $p['status'] === 'ALPA');
        $out['gps_aktif'] = !empty($settings['gps_aktif']);
    }

    echo json_encode($out);
} catch (Throwable $e) {
    error_log('api/beranda: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan server.']);
}