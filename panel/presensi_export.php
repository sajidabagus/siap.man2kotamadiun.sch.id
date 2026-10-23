<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$tanggal = clean($_GET['tanggal'] ?? date('Y-m-d'));
$kelas = clean($_GET['kelas'] ?? '');
$jurusan = clean($_GET['jurusan'] ?? '');
$status = clean($_GET['status'] ?? '');
$type = clean($_GET['type'] ?? 'csv');

$where = ['p.tanggal = :tanggal'];
$params = [':tanggal' => $tanggal];
if ($kelas !== '') { $where[] = 'p.kelas_saat_presensi = :kelas'; $params[':kelas'] = $kelas; }
if ($jurusan !== '') { $where[] = 's.jurusan = :jurusan'; $params[':jurusan'] = $jurusan; }
if ($status !== '') { $where[] = 'p.status = :status'; $params[':status'] = $status; }
$whereSql = implode(' AND ', $where);

$db = getDB();
$stmt = $db->prepare(
    "SELECT COALESCE(NULLIF(s.nisn, ''), '-') AS nisn, s.nama, p.kelas_saat_presensi AS kelas, s.jurusan, p.tanggal, p.jam_masuk, p.status, p.metode
     FROM presensi p JOIN siswa s ON s.id = p.siswa_id WHERE $whereSql ORDER BY p.jam_masuk"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();
foreach ($rows as &$rr) { if (in_array($rr['metode'], ['PENGAJUAN', 'OTOMATIS'], true)) $rr['jam_masuk'] = ''; }   // izin/sakit disetujui: tidak ada jam masuk
unset($rr);
catat_aktivitas('EKSPOR', 'presensi_export', "Ekspor rekap presensi tanggal $tanggal (" . strtoupper($type) . '), ' . count($rows) . ' baris');

$filename = 'rekap_presensi_' . $tanggal;
$header = ['NISN', 'Nama', 'Kelas', 'Jurusan', 'Tanggal', 'Jam Masuk', 'Status', 'Metode'];

if ($type === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    echo "\xEF\xBB\xBF"; // BOM utk Excel
    echo '<table border="1"><tr>';
    foreach ($header as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
    echo '</tr>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($r as $val) echo '<td>' . htmlspecialchars((string) $val) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

// Default: CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF"); // BOM
fputcsv($out, $header);
foreach ($rows as $r) fputcsv($out, $r);
fclose($out);
exit;