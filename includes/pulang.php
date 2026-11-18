<?php
/** Kebijakan jendela scan bersama. Jam selalu berasal dari server pemanggil. */
function jam_absensi_validasi(array $in): array
{
    $galat = [];
    foreach (['jam_masuk', 'batas_absen', 'pulang_buka', 'pulang_tutup'] as $key) {
        if (!is_string($in[$key] ?? null) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $in[$key])) $galat[] = "Jam $key wajib berformat 24 jam (JJ:MM).";
    }
    $late = $in['batas_terlambat'] ?? '';
    if (!is_string($late)) { $galat[] = 'Batas terlambat harus berupa jam.'; return $galat; }
    if ($late !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $late)) $galat[] = 'Batas terlambat tidak valid.';
    if ($galat) return $galat;
    if ($in['jam_masuk'] >= $in['batas_absen']) $galat[] = 'Buka datang harus sebelum tutup datang.';
    if ($late !== '' && ($late < $in['jam_masuk'] || $late >= $in['batas_absen'])) $galat[] = 'Batas terlambat harus di dalam jendela datang (sebelum tutup).';
    if ($in['pulang_buka'] >= $in['pulang_tutup']) $galat[] = 'Buka pulang harus sebelum tutup pulang.';
    if ($in['pulang_buka'] <= $in['batas_absen']) $galat[] = 'Jendela pulang harus dimulai setelah datang ditutup.';
    return $galat;
}

/** Tutup eksklusif, konsisten dengan cutoff Alpa existing; batas terlambat inklusif. */
function presensi_sesi(array $jd, ?string $jam = null): ?string
{
    $jam = $jam ?? date('H:i:s');
    if (!$jd['efektif']) return null;
    if ($jam >= $jd['jam_buka'] && $jam < $jd['batas_absen']) return 'datang';
    // Agenda boleh mengganti jam datang; jangan pernah membiarkan dua sesi beririsan.
    if ($jd['pulang_buka'] > $jd['batas_absen'] && $jam >= $jd['pulang_buka'] && $jam < $jd['pulang_tutup']) return 'pulang';
    return null;
}

function presensi_status_datang(array $jd, string $jam): string
{
    return $jd['batas_terlambat'] === '' || $jam <= $jd['batas_terlambat'] ? 'HADIR' : 'TERLAMBAT';
}

/** Kosong berarti lolos. Partial jam tidak membebaskan datang atau menggantikan scan pulang. */
function presensi_hambatan_pulang(int $sid, string $tanggal, ?array $p): string
{
    if (!$p || empty($p['jam_masuk']) || $p['jam_masuk'] === '00:00:00' || !in_array($p['status'], ['HADIR', 'TERLAMBAT'], true) || in_array($p['metode'], ['PENGAJUAN', 'OTOMATIS'], true)) {
        return 'Absen pulang hanya untuk siswa yang sudah absen datang hari ini (bukan izin/sakit/Alpa).';
    }
    if (!empty($p['jam_pulang'])) return 'Anda sudah absen pulang pukul ' . substr($p['jam_pulang'], 0, 5) . '.';
    try {
    $q = getDB()->prepare("SELECT 1 FROM izin_pengajuan WHERE siswa_id=:s AND tanggal_mulai<=:a AND tanggal_selesai>=:b
        AND ((jenis IN ('izin','sakit') AND status IN ('menunggu','disetujui'))
          OR (jenis='dispensasi' AND status='disetujui' AND mode_durasi IN ('sehari','beberapa_hari'))) LIMIT 1");
    $q->execute([':s' => $sid, ':a' => $tanggal, ':b' => $tanggal]);
    return $q->fetchColumn() ? 'Absen pulang tidak tersedia selama izin/sakit aktif atau dispensasi penuh disetujui hari ini.' : '';
    } catch (Throwable $e) {
        error_log('presensi_pulang: pemeriksaan izin/dispensasi tidak tersedia.');
        return 'Pemeriksaan izin/dispensasi belum tersedia. Hubungi petugas untuk memeriksa migrasi database.';
    }
}

/** Eligibility UI/preflight; final POST memeriksa lagi dan UPDATE bersyarat mencegah ganda. */
function presensi_scan_aturan(int $sid, ?array $p, array $jd): array
{
    $sesi = presensi_sesi($jd);
    if (!$jd['efektif']) return ['aktif' => false, 'sesi' => null, 'alasan' => 'Presensi ditutup hari ini (hari non-efektif/libur).'];
    if (!$sesi) return ['aktif' => false, 'sesi' => null, 'alasan' => 'Di luar jendela scan. Datang ' . substr($jd['jam_buka'], 0, 5) . '–' . substr($jd['batas_absen'], 0, 5) . '; pulang ' . substr($jd['pulang_buka'], 0, 5) . '–' . substr($jd['pulang_tutup'], 0, 5) . '.'];
    if ($sesi === 'pulang') {
        $alasan = presensi_hambatan_pulang($sid, date('Y-m-d'), $p);
        return ['aktif' => $alasan === '', 'sesi' => $sesi, 'alasan' => $alasan];
    }
    return ['aktif' => !$p, 'sesi' => $sesi, 'alasan' => $p ? 'Anda sudah memiliki catatan presensi hari ini.' : ''];
}

function presensi_jumlah_pulang(int $sid, string $bulan): int
{
    $q = getDB()->prepare("SELECT COUNT(*) FROM presensi WHERE siswa_id=:s AND tanggal>=:a AND tanggal<:b AND jam_pulang IS NOT NULL AND status IN ('HADIR','TERLAMBAT')");
    $q->execute([':s' => $sid, ':a' => $bulan . '-01', ':b' => date('Y-m-d', strtotime($bulan . '-01 +1 month'))]);
    return (int) $q->fetchColumn();
}
