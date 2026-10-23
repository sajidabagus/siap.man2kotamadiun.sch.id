<?php
// /portal/izin/baru — formulir pengajuan izin/sakit
require_once __DIR__ . '/_izin_form.php';
$akun = portal_wajib_login();
$sid = (int) $akun['siswa_id'];

$in = ['jenis' => '', 'mulai' => '', 'selesai' => '', 'alasan' => ''];
$galat = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $galat['umum'] = 'Ukuran data yang dikirim terlalu besar. Gunakan lampiran maksimal 3 MB.';
    } elseif (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $galat['umum'] = 'Sesi halaman kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } else {
        foreach ($in as $k => $_) $in[$k] = (string) ($_POST[$k] ?? '');
        $hasil = izin_buat($sid, $in, $_FILES['lampiran'] ?? null);
        if ($hasil['ok']) {
            $_SESSION['portal_info'] = 'Pengajuan terkirim. Petugas akan memprosesnya; Anda akan mendapat notifikasi.';
            header('Location: ' . portal_url('izin/' . $hasil['id']));
            exit;
        }
        $galat = $hasil['galat'];
    }
}


portal_app_awal('Ajukan Izin / Sakit', $akun, 'izin', ['kembali' => 'izin']);
?>
<?php portal_izin_form($in, $galat); ?>
<?php portal_app_akhir();
