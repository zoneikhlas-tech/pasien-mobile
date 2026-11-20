<?php
/**
 * Portal Pasien (PasienKu) - RSUP dr. Ben Mboi Kupang
 * API Surat & Dokumen Keterangan Medis Pasien Resmi
 * Mengakomodir: Surat Sakit, Surat Sehat, Surat Opname, Surat Kontrol (SKDP), Surat Kelahiran, Surat HD, Surat Kematian
 */

require_once __DIR__ . '/../config.php';

$user = PasienConfig::requireAuth();
$norm = (int)$user['norm'];
$action = $_GET['action'] ?? 'list';

$pdoMR      = PasienConfig::getConnection('medicalrecord');
$pdoDaftar  = PasienConfig::getConnection('pendaftaran');
$pdoLaporan = PasienConfig::getConnection('laporan');
$pdoLayanan = PasienConfig::getConnection('layanan');

function getSuratWebserviceUrl() {
    return PasienConfig::getWebserviceUrl();
}

function downloadDocStoragePdf($wsBaseUrl, $docId) {
    if (empty($docId)) return null;
    $ch = curl_init($wsBaseUrl . '/document-storage/document/requestAccess');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['ID' => $docId]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
    $res = curl_exec($ch);
    if ($res === false) { curl_close($ch); return null; }
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($res, 0, $hs);
    $body = substr($res, $hs);
    curl_close($ch);

    $json = json_decode($body, true);
    if (empty($json['requestAccessNumber'])) return null;
    $cookie = '';
    if (preg_match('/Set-Cookie:\s*([^;]+)/i', $headers, $m)) $cookie = $m[1];

    $ch2 = curl_init($wsBaseUrl . '/document-storage/document/download/' . $json['requestAccessNumber']);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 20);
    if ($cookie !== '') curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Cookie: $cookie"]);
    $pdf = curl_exec($ch2);
    $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    return ($code === 200 && is_string($pdf) && substr($pdf, 0, 4) === '%PDF') ? $pdf : null;
}

function requestJasperPdf($wsBaseUrl, $reportName, $kunjungan, $extraParams = []) {
    $params = array_merge([
        'PKUNJUNGAN'        => (string)$kunjungan,
        'CETAK_HEADER'      => '1',
        'REQUEST_REPORT_ID' => '',
        'PNIP'              => '',
        'TTE'               => ''
    ], $extraParams);

    $payload = [
        'TITLE'             => 'DOKUMEN SURAT - ' . $kunjungan,
        'NAME'              => $reportName,
        'FINAL'             => 0,
        'TYPE'              => 'Pdf',
        'EXT'               => 'pdf',
        'PARAMETER'         => $params,
        'REQUEST_FOR_PRINT' => false
    ];

    $ch = curl_init($wsBaseUrl . '/plugins/request-report');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
    $res = curl_exec($ch);
    if ($res === false) { curl_close($ch); return null; }

    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($res, 0, $hs);
    $body = substr($res, $hs);
    curl_close($ch);

    $json = json_decode($body, true);
    if (empty($json['url'])) return null;

    $cookie = '';
    if (preg_match('/Set-Cookie:\s*([^;]+)/i', $headers, $m)) $cookie = $m[1];

    $ch2 = curl_init($json['url']);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 25);
    if ($cookie !== '') curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Cookie: $cookie"]);
    $pdf = curl_exec($ch2);
    $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    return ($code === 200 && is_string($pdf) && substr($pdf, 0, 4) === '%PDF') ? $pdf : null;
}

function formatIndoDate($dateStr) {
    if (empty($dateStr)) return '-';
    $ts = strtotime($dateStr);
    if (!$ts) return $dateStr;
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $m = (int)date('n', $ts);
    return date('j', $ts) . ' ' . ($bulan[$m] ?? date('M', $ts)) . ' ' . date('Y', $ts);
}

// =========================================================================
// ACTION: LIST SURAT KETERANGAN PASIEN
// =========================================================================
if ($action === 'list') {
    $suratList = [];

    if ($pdoMR !== null) {
        // 1. SURAT SAKIT (Izin Istirahat Medis)
        try {
            $stmt = $pdoMR->prepare("
                SELECT 
                    s.ID,
                    'SURAT_SAKIT' AS JENIS,
                    'Surat Keterangan Istirahat Sakit' AS JENIS_LABEL,
                    s.NOMOR,
                    s.TANGGAL,
                    s.LAMA,
                    s.DESKRIPSI,
                    s.KUNJUNGAN,
                    k.MASUK AS TGL_KUNJUNGAN,
                    r.DESKRIPSI AS RUANGAN,
                    COALESCE(
                        TRIM(CONCAT(IF(COALESCE(peg.GELAR_DEPAN,'') != '', CONCAT(peg.GELAR_DEPAN, '. '), ''), peg.NAMA, IF(COALESCE(peg.GELAR_BELAKANG,'') != '', CONCAT(', ', peg.GELAR_BELAKANG), ''))),
                        'Dokter Pemeriksa'
                    ) AS DOKTER
                FROM medicalrecord.surat_sakit s
                JOIN pendaftaran.kunjungan k ON k.NOMOR = s.KUNJUNGAN
                JOIN pendaftaran.pendaftaran p ON p.NOMOR = k.NOPEN
                LEFT JOIN master.ruangan r ON r.ID = k.RUANGAN
                LEFT JOIN master.dokter dok ON dok.ID = k.DPJP
                LEFT JOIN master.pegawai peg ON peg.NIP = dok.NIP
                WHERE p.NORM = :norm AND s.STATUS = 1
                ORDER BY s.TANGGAL DESC
                LIMIT 30
            ");
            $stmt->execute([':norm' => $norm]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $r) {
                $lamaHari = (int)$r['LAMA'];
                $tglMulai = $r['TANGGAL'];
                $tglSelesai = date('Y-m-d', strtotime($tglMulai . " + " . max(0, $lamaHari - 1) . " days"));
                
                $summary = "Diberikan izin istirahat sakit selama {$lamaHari} hari (" . formatIndoDate($tglMulai);
                if ($lamaHari > 1) {
                    $summary .= " s/d " . formatIndoDate($tglSelesai);
                }
                $summary .= ").";
                if (!empty($r['DESKRIPSI'])) {
                    $cleanDesc = trim(strip_tags(html_entity_decode($r['DESKRIPSI'])));
                    if (!empty($cleanDesc)) {
                        $summary .= " Catatan: " . $cleanDesc;
                    }
                }

                $suratList[] = [
                    'id'          => 'sakit_' . $r['ID'],
                    'db_id'       => $r['ID'],
                    'jenis'       => 'SURAT_SAKIT',
                    'jenis_label' => 'Surat Keterangan Sakit',
                    'nomor'       => $r['NOMOR'] ? 'No. ' . $r['NOMOR'] : 'Surat Sakit Resmi',
                    'raw_tanggal' => $r['TANGGAL'],
                    'tanggal'     => formatIndoDate($r['TANGGAL']),
                    'dokter'      => $r['DOKTER'],
                    'ruangan'     => $r['RUANGAN'] ?: 'Poliklinik Rawat Jalan',
                    'kunjungan'   => $r['KUNJUNGAN'],
                    'summary'     => $summary,
                    'lama_hari'   => $lamaHari,
                    'badge_color' => 'amber',
                    'icon'        => 'bed-single',
                    'download_pdf_url' => "api/surat.php?action=view_pdf&jenis=SURAT_SAKIT&kunjungan={$r['KUNJUNGAN']}&id={$r['ID']}"
                ];
            }
        } catch (Exception $e) {}

        // 2. SURAT SEHAT (Keterangan Berbadan Sehat / Dokter)
        try {
            $stmt = $pdoMR->prepare("
                SELECT 
                    s.ID,
                    'SURAT_SEHAT' AS JENIS,
                    'Surat Keterangan Berbadan Sehat' AS JENIS_LABEL,
                    s.NOMOR,
                    s.TANGGAL,
                    s.ATAS_PERMINTAAN,
                    s.HASIL_PEMERIKSAAN,
                    s.KETERANGAN,
                    s.KUNJUNGAN,
                    r.DESKRIPSI AS RUANGAN,
                    COALESCE(
                        TRIM(CONCAT(IF(COALESCE(peg.GELAR_DEPAN,'') != '', CONCAT(peg.GELAR_DEPAN, '. '), ''), peg.NAMA, IF(COALESCE(peg.GELAR_BELAKANG,'') != '', CONCAT(', ', peg.GELAR_BELAKANG), ''))),
                        'Dokter Pemeriksa'
                    ) AS DOKTER
                FROM medicalrecord.surat_sehat s
                JOIN pendaftaran.kunjungan k ON k.NOMOR = s.KUNJUNGAN
                JOIN pendaftaran.pendaftaran p ON p.NOMOR = k.NOPEN
                LEFT JOIN master.ruangan r ON r.ID = k.RUANGAN
                LEFT JOIN master.dokter dok ON dok.ID = k.DPJP
                LEFT JOIN master.pegawai peg ON peg.NIP = dok.NIP
                WHERE p.NORM = :norm AND s.STATUS = 1
                ORDER BY s.TANGGAL DESC
                LIMIT 30
            ");
            $stmt->execute([':norm' => $norm]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $r) {
                $keperluan = trim(strip_tags($r['ATAS_PERMINTAAN'] ?? ''));
                $hasil = trim(strip_tags($r['HASIL_PEMERIKSAAN'] ?? 'SEHAT'));
                $summary = "Dinyatakan dalam kondisi {$hasil}";
                if (!empty($keperluan)) {
                    $summary .= " untuk keperluan: {$keperluan}.";
                } else {
                    $summary .= ".";
                }

                $suratList[] = [
                    'id'          => 'sehat_' . $r['ID'],
                    'db_id'       => $r['ID'],
                    'jenis'       => 'SURAT_SEHAT',
                    'jenis_label' => 'Surat Keterangan Sehat',
                    'nomor'       => $r['NOMOR'] ? 'No. ' . $r['NOMOR'] : 'Surat Sehat Resmi',
                    'raw_tanggal' => $r['TANGGAL'],
                    'tanggal'     => formatIndoDate($r['TANGGAL']),
                    'dokter'      => $r['DOKTER'],
                    'ruangan'     => $r['RUANGAN'] ?: 'Poliklinik Rawat Jalan',
                    'kunjungan'   => $r['KUNJUNGAN'],
                    'summary'     => $summary,
                    'keperluan'   => $keperluan,
                    'badge_color' => 'emerald',
                    'icon'        => 'heart-handshake',
                    'download_pdf_url' => "api/surat.php?action=view_pdf&jenis=SURAT_SEHAT&kunjungan={$r['KUNJUNGAN']}&id={$r['ID']}"
                ];
            }
        } catch (Exception $e) {}

        // 3. SURAT OPNAME (Keterangan Rawat Inap)
        try {
            $stmt = $pdoMR->prepare("
                SELECT 
                    s.ID,
                    'SURAT_OPNAME' AS JENIS,
                    'Surat Keterangan Rawat Inap (Opname)' AS JENIS_LABEL,
                    s.NOMOR,
                    s.TANGGAL,
                    s.KETERANGAN,
                    s.KUNJUNGAN,
                    k.MASUK AS TGL_MASUK,
                    k.KELUAR AS TGL_KELUAR,
                    r.DESKRIPSI AS RUANGAN,
                    COALESCE(
                        TRIM(CONCAT(IF(COALESCE(peg.GELAR_DEPAN,'') != '', CONCAT(peg.GELAR_DEPAN, '. '), ''), peg.NAMA, IF(COALESCE(peg.GELAR_BELAKANG,'') != '', CONCAT(', ', peg.GELAR_BELAKANG), ''))),
                        'Dokter Penanggung Jawab Rawat Inap'
                    ) AS DOKTER
                FROM medicalrecord.surat_opname s
                JOIN pendaftaran.kunjungan k ON k.NOMOR = s.KUNJUNGAN
                JOIN pendaftaran.pendaftaran p ON p.NOMOR = k.NOPEN
                LEFT JOIN master.ruangan r ON r.ID = k.RUANGAN
                LEFT JOIN master.dokter dok ON dok.ID = k.DPJP
                LEFT JOIN master.pegawai peg ON peg.NIP = dok.NIP
                WHERE p.NORM = :norm AND s.STATUS = 1
                ORDER BY s.TANGGAL DESC
                LIMIT 30
            ");
            $stmt->execute([':norm' => $norm]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $r) {
                $masuk = formatIndoDate($r['TGL_MASUK']);
                $keluar = !empty($r['TGL_KELUAR']) ? formatIndoDate($r['TGL_KELUAR']) : 'Masih dalam perawatan';
                $summary = "Menerangkan bahwa pasien dirawat inap di {$r['RUANGAN']} sejak {$masuk} s/d {$keluar}.";

                $suratList[] = [
                    'id'          => 'opname_' . $r['ID'],
                    'db_id'       => $r['ID'],
                    'jenis'       => 'SURAT_OPNAME',
                    'jenis_label' => 'Surat Keterangan Rawat Inap',
                    'nomor'       => $r['NOMOR'] ? 'No. ' . $r['NOMOR'] : 'Surat Opname Resmi',
                    'raw_tanggal' => $r['TANGGAL'],
                    'tanggal'     => formatIndoDate($r['TANGGAL']),
                    'dokter'      => $r['DOKTER'],
                    'ruangan'     => $r['RUANGAN'] ?: 'Ruang Perawatan Rawat Inap',
                    'kunjungan'   => $r['KUNJUNGAN'],
                    'summary'     => $summary,
                    'badge_color' => 'indigo',
                    'icon'        => 'building-2',
                    'download_pdf_url' => "api/surat.php?action=view_pdf&jenis=SURAT_OPNAME&kunjungan={$r['KUNJUNGAN']}&id={$r['ID']}"
                ];
            }
        } catch (Exception $e) {}

        // 4. SURAT KONTROL / SKDP (Surat Rencana Kontrol)
        try {
            $stmt = $pdoMR->prepare("
                SELECT 
                    jk.ID,
                    'SURAT_KONTROL' AS JENIS,
                    'Surat Rencana Kontrol (SKDP)' AS JENIS_LABEL,
                    jk.NOMOR,
                    jk.NOMOR_REFERENSI,
                    jk.SEP_ASAL,
                    jk.NOMOR_ANTRIAN,
                    jk.NOMOR_BOOKING,
                    jk.TANGGAL AS TGL_KONTROL,
                    jk.JAM AS JAM_KONTROL,
                    jk.DESKRIPSI,
                    jk.KUNJUNGAN,
                    COALESCE(r_tujuan.ID, r_asal.ID, r_kunj.ID) AS ID_POLI,
                    COALESCE(pr.RUANGAN_PENJAMIN, pb.KDPOLI, '') AS KODE_POLI_BPJS,
                    CASE 
                        WHEN jk.TUJUAN NOT IN (1, '1') AND r_tujuan.ID IS NOT NULL AND r_tujuan.JENIS_KUNJUNGAN != 0 THEN r_tujuan.DESKRIPSI
                        WHEN r_asal.ID IS NOT NULL AND r_asal.ID NOT IN (1, '1') THEN r_asal.DESKRIPSI
                        WHEN r_kunj.ID IS NOT NULL AND r_kunj.ID NOT IN (1, '1') THEN r_kunj.DESKRIPSI
                        ELSE COALESCE(r_asal.DESKRIPSI, r_kunj.DESKRIPSI, 'Poliklinik Rawat Jalan')
                    END AS POLI_TUJUAN,
                    COALESCE(r_asal.DESKRIPSI, r_kunj.DESKRIPSI, 'Poliklinik Rawat Jalan') AS RUANGAN_ASAL,
                    jk.DOKTER AS ID_DOKTER,
                    h.KD_DOKTER AS KODE_DOKTER_BPJS,
                    COALESCE(
                        TRIM(CONCAT(IF(COALESCE(peg.GELAR_DEPAN,'') != '', CONCAT(peg.GELAR_DEPAN, '. '), ''), peg.NAMA, IF(COALESCE(peg.GELAR_BELAKANG,'') != '', CONCAT(', ', peg.GELAR_BELAKANG), ''))),
                        'Dokter Spesialis DPJP'
                    ) AS DOKTER,
                    jk.SUDAH_DIGUNAKAN,
                    jk.DIBUAT_TANGGAL,
                    DATEDIFF(jk.TANGGAL, CURDATE()) AS SISA_HARI
                FROM medicalrecord.jadwal_kontrol jk
                JOIN pendaftaran.kunjungan k ON k.NOMOR = jk.KUNJUNGAN
                JOIN pendaftaran.pendaftaran p ON p.NOMOR = k.NOPEN
                LEFT JOIN master.ruangan r_tujuan ON r_tujuan.ID = jk.TUJUAN
                LEFT JOIN master.ruangan r_asal ON r_asal.ID = jk.RUANGAN
                LEFT JOIN master.ruangan r_kunj ON r_kunj.ID = k.RUANGAN
                LEFT JOIN master.penjamin_ruangan pr ON pr.RUANGAN_RS = COALESCE(r_tujuan.ID, r_asal.ID, r_kunj.ID) AND pr.PENJAMIN = 2 AND pr.STATUS = 1
                LEFT JOIN regonline.poli_bpjs pb ON pb.ID = COALESCE(r_tujuan.ID, r_asal.ID, r_kunj.ID) OR pb.NMPOLI = r_tujuan.DESKRIPSI
                LEFT JOIN master.dokter dok ON dok.ID = jk.DOKTER
                LEFT JOIN master.pegawai peg ON peg.NIP = dok.NIP
                LEFT JOIN regonline.jadwal_dokter_hfis h ON (h.NM_DOKTER LIKE CONCAT('%', peg.NAMA, '%') OR peg.NAMA LIKE CONCAT('%', h.NM_DOKTER, '%'))
                WHERE p.NORM = :norm AND jk.STATUS = 1
                GROUP BY jk.ID
                ORDER BY jk.TANGGAL DESC
                LIMIT 30
            ");
            $stmt->execute([':norm' => $norm]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $tzLabel = PasienConfig::getTimezoneLabel();
            foreach ($rows as $r) {
                $noDisplay = !empty($r['NOMOR_REFERENSI']) ? $r['NOMOR_REFERENSI'] : ($r['NOMOR'] ? 'No. ' . $r['NOMOR'] : 'Surat Kontrol Resmi');
                $tglKontrolIndo = formatIndoDate($r['TGL_KONTROL']);
                $jamStr = !empty($r['JAM_KONTROL']) ? substr($r['JAM_KONTROL'], 0, 5) . ' ' . $tzLabel : '';
                
                $summary = "Rencana kontrol ke {$r['POLI_TUJUAN']} pada hari {$tglKontrolIndo}";
                if (!empty($jamStr)) $summary .= " pukul {$jamStr}";
                $summary .= ".";

                $cleanDesc = '';
                if (!empty($r['DESKRIPSI'])) {
                    $cleanDesc = trim(strip_tags(html_entity_decode($r['DESKRIPSI'])));
                    if (!empty($cleanDesc) && $cleanDesc !== '-') {
                        $summary .= " Catatan dokter: " . $cleanDesc;
                    } else {
                        $cleanDesc = '';
                    }
                }

                $hasBooking = !empty($r['NOMOR_BOOKING']);
                $isUpcoming = (int)$r['SISA_HARI'] >= 0;
                $statusAntrean = 'BELUM_AMBIL';
                $statusLabel = 'Belum Ambil Antrean';
                if ($hasBooking) {
                    $statusAntrean = 'SUDAH_AMBIL';
                    $statusLabel = 'Antrean Terbit (' . ($r['NOMOR_ANTRIAN'] ?: 'Booking: ' . $r['NOMOR_BOOKING']) . ')';
                } else if (!$isUpcoming) {
                    $statusAntrean = 'LEWAT';
                    $statusLabel = 'Jadwal Kontrol Terlewat';
                } else if ($r['SUDAH_DIGUNAKAN']) {
                    $statusAntrean = 'SUDAH_DIGUNAKAN';
                    $statusLabel = 'Sudah Digunakan';
                }

                $suratList[] = [
                    'id'                => 'kontrol_' . $r['ID'],
                    'db_id'             => $r['ID'],
                    'jenis'             => 'SURAT_KONTROL',
                    'jenis_label'       => 'Surat Rencana Kontrol (SKDP)',
                    'nomor'             => $noDisplay,
                    'no_surat_rs'       => $r['NOMOR'] ?: '-',
                    'no_surat_kontrol'  => $r['NOMOR_REFERENSI'] ?: ($r['NOMOR'] ?: ''),
                    'no_referensi_bpjs' => $r['NOMOR_REFERENSI'] ?: '',
                    'sep_asal'          => $r['SEP_ASAL'] ?: '',
                    'raw_tanggal'       => substr($r['DIBUAT_TANGGAL'] ?: $r['TGL_KONTROL'], 0, 10),
                    'tanggal'           => formatIndoDate(substr($r['DIBUAT_TANGGAL'] ?: $r['TGL_KONTROL'], 0, 10)),
                    'raw_tgl_kontrol'   => $r['TGL_KONTROL'],
                    'tgl_kontrol'       => $tglKontrolIndo,
                    'jam_kontrol'       => $jamStr,
                    'dokter'            => $r['DOKTER'],
                    'dokter_id'         => $r['ID_DOKTER'],
                    'dokter_bpjs'       => $r['KODE_DOKTER_BPJS'] ?: '',
                    'ruangan'           => $r['POLI_TUJUAN'] ?: 'Poliklinik Spesialis',
                    'ruangan_asal'      => $r['RUANGAN_ASAL'] ?: '',
                    'poli_id'           => $r['ID_POLI'],
                    'poli_bpjs'         => $r['KODE_POLI_BPJS'] ?: '',
                    'kunjungan'         => $r['KUNJUNGAN'],
                    'summary'           => $summary,
                    'catatan_dokter'    => $cleanDesc,
                    'badge_color'       => 'teal',
                    'icon'              => 'calendar-clock',
                    'is_skdp'           => true,
                    'nomor_booking'     => $r['NOMOR_BOOKING'] ?: '',
                    'nomor_antrian'     => $r['NOMOR_ANTRIAN'] ?: '',
                    'has_booking'       => $hasBooking,
                    'is_upcoming'       => $isUpcoming,
                    'sisa_hari'         => (int)$r['SISA_HARI'],
                    'status_antrean'    => $statusAntrean,
                    'status_label'      => $statusLabel,
                    'download_pdf_url'  => "api/surat.php?action=view_pdf&jenis=SURAT_KONTROL&kunjungan={$r['KUNJUNGAN']}&id={$r['ID']}"
                ];
            }
        } catch (Exception $e) {}

        // 5. SURAT KENAL LAHIR (Kelahiran)
        try {
            $stmt = $pdoMR->prepare("
                SELECT 
                    s.ID,
                    'SURAT_KELAHIRAN' AS JENIS,
                    'Surat Keterangan Kenal Lahir' AS JENIS_LABEL,
                    s.NOMOR,
                    s.TANGGAL,
                    s.JAM,
                    s.BERAT,
                    s.PANJANG,
                    s.LINGKAR_KEPALA,
                    s.NORM_IBU,
                    s.KUNJUNGAN,
                    pas.NAMA AS NAMA_BAYI,
                    r.DESKRIPSI AS RUANGAN,
                    COALESCE(
                        TRIM(CONCAT(IF(COALESCE(peg.GELAR_DEPAN,'') != '', CONCAT(peg.GELAR_DEPAN, '. '), ''), peg.NAMA, IF(COALESCE(peg.GELAR_BELAKANG,'') != '', CONCAT(', ', peg.GELAR_BELAKANG), ''))),
                        'Dokter Spesialis Anak / Kebidanan'
                    ) AS DOKTER
                FROM medicalrecord.surat_kelahiran s
                JOIN pendaftaran.kunjungan k ON k.NOMOR = s.KUNJUNGAN
                JOIN pendaftaran.pendaftaran p ON p.NOMOR = k.NOPEN
                JOIN master.pasien pas ON pas.NORM = p.NORM
                LEFT JOIN master.ruangan r ON r.ID = k.RUANGAN
                LEFT JOIN master.dokter dok ON dok.ID = k.DPJP
                LEFT JOIN master.pegawai peg ON peg.NIP = dok.NIP
                WHERE (p.NORM = :norm OR s.NORM_IBU = :norm) AND s.STATUS = 1
                ORDER BY s.TANGGAL DESC
                LIMIT 10
            ");
            $stmt->execute([':norm' => $norm]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $tzLabel = PasienConfig::getTimezoneLabel();
            foreach ($rows as $r) {
                $beratGram = (float)$r['BERAT'];
                $panjangCm = (float)$r['PANJANG'];
                $summary = "Menerangkan kelahiran ananda {$r['NAMA_BAYI']} pada " . formatIndoDate($r['TANGGAL']) . " pukul " . substr($r['JAM'], 0, 5) . " {$tzLabel} (Berat: {$beratGram} g, Panjang: {$panjangCm} cm).";

                $suratList[] = [
                    'id'          => 'lahir_' . $r['ID'],
                    'db_id'       => $r['ID'],
                    'jenis'       => 'SURAT_KELAHIRAN',
                    'jenis_label' => 'Surat Kenal Lahir',
                    'nomor'       => $r['NOMOR'] ? 'No. ' . $r['NOMOR'] : 'Surat Kelahiran Resmi',
                    'raw_tanggal' => $r['TANGGAL'],
                    'tanggal'     => formatIndoDate($r['TANGGAL']),
                    'dokter'      => $r['DOKTER'],
                    'ruangan'     => $r['RUANGAN'] ?: 'Kamar Bersalin (VK) / Perinatologi',
                    'kunjungan'   => $r['KUNJUNGAN'],
                    'summary'     => $summary,
                    'badge_color' => 'rose',
                    'icon'        => 'baby',
                    'download_pdf_url' => "api/surat.php?action=view_pdf&jenis=SURAT_KELAHIRAN&kunjungan={$r['KUNJUNGAN']}&id={$r['ID']}"
                ];
            }
        } catch (Exception $e) {}

        // 6. SURAT KEMATIAN PASIEN
        if ($pdoLayanan !== null) {
            try {
                $stmt = $pdoLayanan->prepare("
                    SELECT 
                        m.ID,
                        'SURAT_KEMATIAN' AS JENIS,
                        'Surat Keterangan Kematian Pasien' AS JENIS_LABEL,
                        m.NOMOR,
                        m.TANGGAL,
                        m.DIAGNOSA,
                        m.KUNJUNGAN,
                        r.DESKRIPSI AS RUANGAN,
                        COALESCE(
                            TRIM(CONCAT(IF(COALESCE(peg.GELAR_DEPAN,'') != '', CONCAT(peg.GELAR_DEPAN, '. '), ''), peg.NAMA, IF(COALESCE(peg.GELAR_BELAKANG,'') != '', CONCAT(', ', peg.GELAR_BELAKANG), ''))),
                            'Dokter Pemeriksa'
                        ) AS DOKTER
                    FROM layanan.pasien_meninggal m
                    JOIN pendaftaran.kunjungan k ON k.NOMOR = m.KUNJUNGAN
                    JOIN pendaftaran.pendaftaran p ON p.NOMOR = k.NOPEN
                    LEFT JOIN master.ruangan r ON r.ID = k.RUANGAN
                    LEFT JOIN master.dokter dok ON dok.ID = m.DOKTER
                    LEFT JOIN master.pegawai peg ON peg.NIP = dok.NIP
                    WHERE p.NORM = :norm AND m.STATUS = 1
                    ORDER BY m.TANGGAL DESC
                    LIMIT 5
                ");
                $stmt->execute([':norm' => $norm]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $r) {
                    $summary = "Menerangkan kematian pasien pada tanggal " . formatIndoDate($r['TANGGAL']) . ".";
                    if (!empty($r['DIAGNOSA'])) {
                        $summary .= " Penyebab/Diagnosa: " . trim(strip_tags($r['DIAGNOSA']));
                    }

                    $suratList[] = [
                        'id'          => 'mati_' . $r['ID'],
                        'db_id'       => $r['ID'],
                        'jenis'       => 'SURAT_KEMATIAN',
                        'jenis_label' => 'Surat Keterangan Kematian',
                        'nomor'       => $r['NOMOR'] ? 'No. ' . $r['NOMOR'] : 'Surat Kematian Resmi',
                        'raw_tanggal' => substr($r['TANGGAL'], 0, 10),
                        'tanggal'     => formatIndoDate($r['TANGGAL']),
                        'dokter'      => $r['DOKTER'],
                        'ruangan'     => $r['RUANGAN'] ?: 'RSUP dr. Ben Mboi',
                        'kunjungan'   => $r['KUNJUNGAN'],
                        'summary'     => $summary,
                        'badge_color' => 'slate',
                        'icon'        => 'file-text',
                        'download_pdf_url' => "api/surat.php?action=view_pdf&jenis=SURAT_KEMATIAN&kunjungan={$r['KUNJUNGAN']}&id={$r['ID']}"
                    ];
                }
            } catch (Exception $e) {}
        }
    }

    // Urutkan semua surat berdasarkan tanggal terbaru
    usort($suratList, function($a, $b) {
        return strcmp($b['raw_tanggal'], $a['raw_tanggal']);
    });

    // Hitung aggregasi untuk filter tabs di UI
    $counts = [
        'all'     => count($suratList),
        'sakit'   => 0,
        'sehat'   => 0,
        'opname'  => 0,
        'kontrol' => 0,
        'lainnya' => 0
    ];

    foreach ($suratList as $it) {
        if ($it['jenis'] === 'SURAT_SAKIT') $counts['sakit']++;
        elseif ($it['jenis'] === 'SURAT_SEHAT') $counts['sehat']++;
        elseif ($it['jenis'] === 'SURAT_OPNAME') $counts['opname']++;
        elseif ($it['jenis'] === 'SURAT_KONTROL') $counts['kontrol']++;
        else $counts['lainnya']++;
    }

    PasienConfig::jsonResponse([
        'success' => true,
        'total'   => count($suratList),
        'counts'  => $counts,
        'data'    => $suratList
    ]);
}

// =========================================================================
// ACTION: VIEW / STREAM PDF SURAT KETERANGAN RESMI
// =========================================================================
if ($action === 'view_pdf') {
    $jenis     = trim($_GET['jenis'] ?? '');
    $kunjungan = trim($_GET['kunjungan'] ?? '');
    $id        = (int)($_GET['id'] ?? 0);

    if (empty($jenis) || empty($kunjungan)) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'Parameter jenis dan kunjungan wajib disertakan.'], 400);
    }

    // Validasi kepemilikan kunjungan oleh pasien yang sedang login (Security Enforcement)
    $hasAccess = false;
    if ($pdoDaftar !== null) {
        try {
            $stmtAuth = $pdoDaftar->prepare("
                SELECT 1
                FROM pendaftaran.kunjungan k
                JOIN pendaftaran.pendaftaran p ON p.NOMOR = k.NOPEN
                WHERE k.NOMOR = :kunj AND p.NORM = :norm
                LIMIT 1
            ");
            $stmtAuth->execute([':kunj' => $kunjungan, ':norm' => $norm]);
            if ($stmtAuth->fetchColumn()) {
                $hasAccess = true;
            }
        } catch (Exception $e) {}
    }

    // Khusus Surat Kelahiran: boleh juga diakses oleh ibu (NORM_IBU = norm)
    if (!$hasAccess && $jenis === 'SURAT_KELAHIRAN' && $pdoMR !== null) {
        try {
            $stmtAuthIbu = $pdoMR->prepare("
                SELECT 1 FROM medicalrecord.surat_kelahiran WHERE KUNJUNGAN = :kunj AND NORM_IBU = :norm LIMIT 1
            ");
            $stmtAuthIbu->execute([':kunj' => $kunjungan, ':norm' => $norm]);
            if ($stmtAuthIbu->fetchColumn()) {
                $hasAccess = true;
            }
        } catch (Exception $e) {}
    }

    if (!$hasAccess) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk membuka dokumen medis ini.'], 403);
    }

    $wsBaseUrl = getSuratWebserviceUrl();
    $rawPdf = null;

    // Pemetaan Direktori Dokumen & Template Jasper Report SIMGOS
    $docDirectoryId = null;
    $jasperReport   = null;
    $filenamePrefix = 'Surat';

    $kodePpk = PasienConfig::getKodePpk();

    switch ($jenis) {
        case 'SURAT_SAKIT':
            $docDirectoryId = 9;
            $jasperReport   = 'mr.' . $kodePpk . '-CetakSuratSakit';
            $filenamePrefix = 'SuratKeteranganSakit';
            break;
        case 'SURAT_SEHAT':
            $docDirectoryId = 9; // Fallback jika tersimpan di dir 9
            $jasperReport   = 'mr.' . $kodePpk . '-SuratSehat';
            $filenamePrefix = 'SuratKeteranganSehat';
            break;
        case 'SURAT_OPNAME':
            $docDirectoryId = 10;
            $jasperReport   = 'mr.' . $kodePpk . '-CetakSuratOpname';
            $filenamePrefix = 'SuratKeteranganOpname';
            break;
        case 'SURAT_KELAHIRAN':
            $docDirectoryId = 21;
            $jasperReport   = 'mr.' . $kodePpk . '-CetakSuratKelahiran';
            $filenamePrefix = 'SuratKenalLahir';
            break;
        case 'SURAT_KEMATIAN':
            $docDirectoryId = 25;
            $jasperReport   = 'mr.' . $kodePpk . '-SuratKeteranganMeninggal(IND)';
            $filenamePrefix = 'SuratKeteranganKematian';
            break;
        case 'SURAT_HD':
            $docDirectoryId = 35;
            $jasperReport   = 'mr.' . $kodePpk . '-CetakSuratKetHD';
            $filenamePrefix = 'SuratTravellingHD';
            break;
        case 'SURAT_KONTROL':
            $jasperReport   = 'bpjs.' . $kodePpk . '-RencanaKontrol';
            $filenamePrefix = 'SuratRencanaKontrol';
            break;
        default:
            PasienConfig::jsonResponse(['success' => false, 'message' => 'Jenis surat tidak dikenali.'], 400);
    }

    // 1. Cek Dokumen Resmi Tersimpan & Terverifikasi di Document Storage
    if ($docDirectoryId !== null && $pdoLaporan !== null) {
        try {
            $stmtRR = $pdoLaporan->prepare("
                SELECT DOCUMENT_STORAGE_ID
                FROM laporan.request_report
                WHERE REF_ID = :kunj
                  AND DOCUMENT_DIRECTORY_ID = :dir
                  AND STATUS = 2
                  AND DOCUMENT_STORAGE_ID != ''
                ORDER BY DIBUAT_TANGGAL DESC
                LIMIT 1
            ");
            $stmtRR->execute([':kunj' => $kunjungan, ':dir' => $docDirectoryId]);
            $docId = $stmtRR->fetchColumn();

            if (!empty($docId)) {
                $rawPdf = downloadDocStoragePdf($wsBaseUrl, $docId);
            }
        } catch (Exception $e) {}
    }

    // 2. Fallback: Live JasperReport Generation
    if ($rawPdf === null && !empty($jasperReport)) {
        $rawPdf = requestJasperPdf($wsBaseUrl, $jasperReport, $kunjungan);
    }

    // 3. Fallback Khusus Surat Kontrol: Jika bpjs.<kodePpk>-RencanaKontrol tidak ada, fallback ke mr.<kodePpk>-KetKontrol
    if ($rawPdf === null && $jenis === 'SURAT_KONTROL') {
        $rawPdf = requestJasperPdf($wsBaseUrl, 'mr.' . $kodePpk . '-KetKontrol', $kunjungan);
    }

    if ($rawPdf !== null) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filenamePrefix . '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $kunjungan) . '.pdf"');
        header('Content-Length: ' . strlen($rawPdf));
        echo $rawPdf;
        exit;
    }

    PasienConfig::jsonResponse([
        'success' => false,
        'message' => 'Dokumen PDF surat keterangan belum dicetak atau ditandatangani oleh dokter pemeriksa di SIMGOS.'
    ], 404);
}

PasienConfig::jsonResponse(['success' => false, 'message' => 'Aksi tidak valid.'], 400);
