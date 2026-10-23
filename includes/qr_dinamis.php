<?php
/**
 * QR DINAMIS — GERBANG SEKOLAH & KELAS (Model B)
 * Token berganti berkala. Ditampilkan di layar/monitor (gerbang ATAU kelas),
 * lalu di-scan oleh HP siswa sebagai bukti "sedang berada di lokasi presensi".
 * Token ini TIDAK mengidentifikasi siswa — identitas tetap berasal dari
 * QR pribadi siswa (qr_token) + device binding.
 *
 * kelas_target:
 *  - NULL   -> QR gerbang, boleh dipakai siswa kelas manapun.
 *  - 'X-A'  -> QR khusus kelas X-A, HANYA siswa kelas X-A yang boleh presensi
 *              lewat QR ini (divalidasi di includes/presensi_core.php).
 */

require_once __DIR__ . '/functions.php';

function generate_qr_dinamis(string $lokasi = 'Gerbang Utama', ?string $kelasTarget = null): array
{
    $settings = get_settings();
    $durasi = (int) ($settings['qr_dinamis_durasi_detik'] ?? 120);

    $db = getDB();
    // Nonaktifkan token lama yang masih aktif di lokasi yang sama.
    $db->prepare('UPDATE qr_dinamis SET status = "EXPIRED" WHERE lokasi = :lokasi AND status = "AKTIF"')
       ->execute([':lokasi' => $lokasi]);

    $token = generate_token(24);
    $stmt = $db->prepare(
        'INSERT INTO qr_dinamis (token, lokasi, kelas_target, dibuat_at, kadaluarsa_at, status)
         VALUES (:token, :lokasi, :kelas_target, NOW(), DATE_ADD(NOW(), INTERVAL :durasi SECOND), "AKTIF")'
    );
    $stmt->execute([':token' => $token, ':lokasi' => $lokasi, ':kelas_target' => $kelasTarget, ':durasi' => $durasi]);

    return ['token' => $token, 'lokasi' => $lokasi, 'kelas_target' => $kelasTarget, 'durasi_detik' => $durasi];
}

/**
 * Ambil detail token QR dinamis jika masih valid & belum kedaluwarsa, else null.
 * Dipakai saat proses simpan presensi utk mengecek kelas_target-nya.
 */
function get_active_qr_dinamis(string $token): ?array
{
    $stmt = getDB()->prepare(
        'SELECT * FROM qr_dinamis WHERE token = :token AND status = "AKTIF" AND kadaluarsa_at >= NOW() LIMIT 1'
    );
    $stmt->execute([':token' => $token]);
    return $stmt->fetch() ?: null;
}

/**
 * Validasi token QR dinamis. Return true jika valid & belum kedaluwarsa.
 * (Dipertahankan utk kompatibilitas kode lama yang hanya butuh cek boolean.)
 */
function validate_qr_dinamis(string $token): bool
{
    return get_active_qr_dinamis($token) !== null;
}

/**
 * Masa tenggang token gerbang yang SUDAH diverifikasi di sesi ini (detik).
 * Layar gerbang memutar token tiap qr_dinamis_durasi_detik dan langsung meng-EXPIRED-kan token lama, sehingga
 * siswa yang scan di detik-detik akhir gagal (GATE_INVALID) saat menekan Konfirmasi. Token yang sudah sah
 * saat discan tetap diterima selama masa tenggang ini, HANYA untuk sesi yang benar-benar men-scan-nya.
 */
const QR_TENGGANG_SESI_DETIK = 300;

/**
 * Seperti get_active_qr_dinamis(), tetapi menerima token yang sudah diganti/kedaluwarsa bila token itu
 * sama dengan $_SESSION['gate_token'] dan diverifikasi kurang dari QR_TENGGANG_SESI_DETIK yang lalu.
 */
function get_qr_dinamis_sesi(string $token): ?array
{
    if ($token === '') return null;
    $row = get_active_qr_dinamis($token);
    if ($row) return $row;

    if (session_status() !== PHP_SESSION_ACTIVE) return null;
    $tokenSesi = (string) ($_SESSION['gate_token'] ?? '');
    $waktu = (int) ($_SESSION['gate_verified_at'] ?? 0);
    if ($tokenSesi === '' || !hash_equals($tokenSesi, $token) || $waktu <= 0 || time() - $waktu > QR_TENGGANG_SESI_DETIK) {
        return null;
    }
    $stmt = getDB()->prepare('SELECT * FROM qr_dinamis WHERE token = :token LIMIT 1');
    $stmt->execute([':token' => $token]);
    return $stmt->fetch() ?: null;
}

function validate_qr_dinamis_sesi(string $token): bool
{
    return get_qr_dinamis_sesi($token) !== null;
}

// =====================================================================
// QR KELAS PERMANEN
// Beda dengan QR Gerbang (otomatis berganti tiap sekian detik), QR Kelas
// bersifat TETAP/PATEN — tidak berganti otomatis, supaya bisa dicetak atau
// ditempel di kelas tanpa perlu layar yang terus menyala. Hanya admin yang
// bisa memaksa gantinya lewat tombol "Regenerate" (lihat regenerate_qr_kelas()).
// Keamanannya mengandalkan lapisan lain: device binding (1 siswa = 1 HP) dan
// (jika diaktifkan) validasi radius GPS, bukan dari QR yang cepat kedaluwarsa.
// =====================================================================

/**
 * Buat token QR permanen baru utk 1 kelas (masa berlaku sangat panjang, 20 tahun,
 * supaya tidak perlu mengubah skema kolom kadaluarsa_at jadi nullable).
 * Token lama utk kelas yg sama otomatis dinonaktifkan.
 */
function buat_qr_kelas_permanen(string $kelas): array
{
    $db = getDB();
    $lokasi = "Kelas $kelas";

    $db->prepare('UPDATE qr_dinamis SET status = "EXPIRED" WHERE kelas_target = :kelas AND status = "AKTIF"')
       ->execute([':kelas' => $kelas]);

    $token = generate_token(24);
    $stmt = $db->prepare(
        'INSERT INTO qr_dinamis (token, lokasi, kelas_target, dibuat_at, kadaluarsa_at, status)
         VALUES (:token, :lokasi, :kelas, NOW(), DATE_ADD(NOW(), INTERVAL 20 YEAR), "AKTIF")'
    );
    $stmt->execute([':token' => $token, ':lokasi' => $lokasi, ':kelas' => $kelas]);

    return get_active_qr_dinamis($token);
}

/**
 * Ambil QR permanen kelas yang SEDANG AKTIF, atau buat baru kalau kelas ini
 * belum pernah punya QR sama sekali. TIDAK PERNAH mengganti token yang masih
 * aktif — supaya QR benar-benar paten selama admin tidak menekan Regenerate.
 */
function get_or_create_qr_kelas(string $kelas): array
{
    $stmt = getDB()->prepare(
        'SELECT * FROM qr_dinamis WHERE kelas_target = :kelas AND status = "AKTIF" AND kadaluarsa_at >= NOW()
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([':kelas' => $kelas]);
    $existing = $stmt->fetch();

    return $existing ?: buat_qr_kelas_permanen($kelas);
}

/**
 * Paksa ganti QR kelas (token lama langsung tidak berlaku). HANYA dipanggil
 * dari aksi eksplisit admin (tombol Regenerate + konfirmasi), tidak pernah
 * dipanggil otomatis oleh sistem.
 */
function regenerate_qr_kelas(string $kelas): array
{
    return buat_qr_kelas_permanen($kelas);
}