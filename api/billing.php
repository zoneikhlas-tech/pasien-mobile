<?php
/**
 * Portal Pasien - RSUP dr. Ben Mboi Kupang
 * API Rincian Biaya, Tagihan & Transparansi Jaminan (Billing Pasien)
 */

require_once __DIR__ . '/../config.php';

$user = PasienConfig::requireAuth();
$norm = (int)$user['norm'];
$action = $_GET['action'] ?? 'summary';

$pdoBayar = PasienConfig::getConnection('pembayaran');
$pdoDaftar = PasienConfig::getConnection('pendaftaran');
$pdoLayanan = PasienConfig::getConnection('layanan');

if ($pdoBayar === null || $pdoDaftar === null) {
    PasienConfig::jsonResponse([
        'success' => false,
        'message' => 'Layanan database billing sedang tidak dapat diakses.'
    ], 500);
}

function formatRupiah($num) {
    return 'Rp ' . number_format((float)$num, 0, ',', '.');
}

function formatKelas($k) {
    switch ((int)$k) {
        case 1: return 'Kelas 1';
        case 2: return 'Kelas 2';
        case 3: return 'Kelas 3';
        case 4: return 'VIP';
        case 5: return 'VVIP';
        default: return 'Kelas Standar';
    }
}

if ($action === 'summary' || $action === 'list') {
    try {
        // Ambil riwayat tagihan pasien dari pembayaran.tagihan_pendaftaran dengan filter penggabungan tagihan
        $stmt = $pdoBayar->prepare("
            SELECT 
                t.ID AS TAGIHAN_ID,
                t.TANGGAL AS RAW_TANGGAL,
                DATE_FORMAT(t.TANGGAL, '%d-%m-%Y %H:%i') AS TANGGAL_TAGIHAN,
                t.TOTAL,
                (t.AKOMODASI + t.AKOMODASI_INTENSIF) AS BIAYA_AKOMODASI,
                t.KONSULTASI AS BIAYA_KONSULTASI,
                t.LABORATORIUM AS BIAYA_LABORATORIUM,
                t.RADIOLOGI AS BIAYA_RADIOLOGI,
                (t.PROSEDUR_BEDAH + t.PROSEDUR_NON_BEDAH + t.REHAB_MEDIK) AS BIAYA_TINDAKAN,
                (t.OBAT + t.OBAT_KRONIS + t.OBAT_KEMOTERAPI) AS BIAYA_OBAT,
                (t.ALKES + t.BMHP) AS BIAYA_ALKES,
                t.STATUS AS STATUS_TAGIHAN,
                tp_utama.PENDAFTARAN AS NOPEN,
                DATE_FORMAT(p.TANGGAL, '%d-%m-%Y') AS TANGGAL_PENDAFTARAN,
                COALESCE(ref_pj.DESKRIPSI, 'BPJS / JKN') AS PENJAMIN,
                pj.NOMOR AS NO_SEP,
                pj.KELAS AS KELAS_PENJAMIN,
                COALESCE(r.DESKRIPSI, 'Poliklinik / Ruangan Rawat') AS RUANGAN,
                COALESCE(
                    TRIM(CONCAT(IF(COALESCE(peg.GELAR_DEPAN,'') != '', CONCAT(peg.GELAR_DEPAN, '. '), ''), peg.NAMA, IF(COALESCE(peg.GELAR_BELAKANG,'') != '', CONCAT(', ', peg.GELAR_BELAKANG), ''))),
                    'Dokter DPJP'
                ) AS DOKTER,
                COALESCE(byr.TOTAL_DIBAYAR, 0) AS TOTAL_DIBAYAR,
                COALESCE(pt.TOTAL_NAIK_KELAS, 0) AS SELISIH_NAIK_KELAS,
                (
                    SELECT COUNT(DISTINCT tp_cnt.PENDAFTARAN)
                    FROM pembayaran.tagihan_pendaftaran tp_cnt
                    WHERE tp_cnt.TAGIHAN = t.ID AND tp_cnt.STATUS = 1
                ) AS JML_PENDAFTARAN_GABUNG,
                (
                    SELECT GROUP_CONCAT(DISTINCT r_all.DESKRIPSI ORDER BY tp_all.UTAMA DESC SEPARATOR ' + ')
                    FROM pembayaran.tagihan_pendaftaran tp_all
                    JOIN pendaftaran.pendaftaran p_all ON p_all.NOMOR = tp_all.PENDAFTARAN
                    LEFT JOIN pendaftaran.tujuan_pasien tjp_all ON tjp_all.NOPEN = p_all.NOMOR
                    LEFT JOIN master.ruangan r_all ON r_all.ID = tjp_all.RUANGAN
                    WHERE tp_all.TAGIHAN = t.ID AND tp_all.STATUS = 1
                ) AS RUANGAN_GABUNGAN
            FROM pembayaran.tagihan t
            JOIN (
                SELECT TAGIHAN, PENDAFTARAN
                FROM (
                    SELECT TAGIHAN, PENDAFTARAN, UTAMA, ID,
                           ROW_NUMBER() OVER (PARTITION BY TAGIHAN ORDER BY UTAMA DESC, ID DESC) as rn
                    FROM pembayaran.tagihan_pendaftaran
                    WHERE STATUS = 1
                ) ranked
                WHERE rn = 1
            ) tp_utama ON tp_utama.TAGIHAN = t.ID
            JOIN pendaftaran.pendaftaran p ON p.NOMOR = tp_utama.PENDAFTARAN
            LEFT JOIN pendaftaran.tujuan_pasien tjp ON tjp.NOPEN = p.NOMOR
            LEFT JOIN master.ruangan r ON r.ID = tjp.RUANGAN
            LEFT JOIN master.dokter dok ON dok.ID = tjp.DOKTER
            LEFT JOIN master.pegawai peg ON peg.NIP = dok.NIP
            LEFT JOIN pendaftaran.penjamin pj ON pj.NOPEN = p.NOMOR
            LEFT JOIN master.referensi ref_pj ON ref_pj.JENIS = 10 AND ref_pj.ID = pj.JENIS
            LEFT JOIN pembayaran.penjamin_tagihan pt ON pt.TAGIHAN = t.ID
            LEFT JOIN (
                SELECT TAGIHAN, SUM(TOTAL) AS TOTAL_DIBAYAR 
                FROM pembayaran.pembayaran_tagihan 
                WHERE STATUS = 1
                GROUP BY TAGIHAN
            ) byr ON byr.TAGIHAN = t.ID
            WHERE t.REF = :norm
              AND t.STATUS != 0
              AND NOT EXISTS (
                  SELECT 1 FROM pembayaran.gabung_tagihan gt 
                  WHERE gt.DARI = t.ID AND gt.STATUS = 1
              )
            ORDER BY t.TANGGAL DESC
            LIMIT 15
        ");
        $stmt->execute([':norm' => $norm]);
        $rawBills = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $bills = [];
        $totalManfaat = 0;

        foreach ($rawBills as $b) {
            $totalVal = (float)$b['TOTAL'];
            $isBpjs = stripos($b['PENJAMIN'], 'BPJS') !== false || stripos($b['PENJAMIN'], 'JKN') !== false;
            $isLunas = ((int)$b['STATUS_TAGIHAN'] === 2);

            $statusText = 'Belum Lunas';
            $statusBadge = 'bg-amber-50 text-amber-700 border-amber-200';
            $biayaPasien = $totalVal;

            if ($isBpjs) {
                $totalManfaat += $totalVal;
                $selisih = (float)$b['SELISIH_NAIK_KELAS'];
                if ($isLunas) {
                    if ($selisih > 0) {
                        $statusText = 'Dijamin BPJS + Selisih Kelas';
                        $statusBadge = 'bg-amber-50 text-amber-700 border-amber-200';
                        $biayaPasien = $selisih;
                    } else {
                        $statusText = 'Dijamin Penuh BPJS (Bebas Biaya)';
                        $statusBadge = 'bg-teal-50 text-teal-700 border-teal-200';
                        $biayaPasien = 0;
                    }
                } else {
                    $statusText = 'Proses Verifikasi Klaim BPJS';
                    $statusBadge = 'bg-sky-50 text-sky-700 border-sky-200';
                    $biayaPasien = 0;
                }
            } else {
                if ($isLunas) {
                    $statusText = 'Lunas';
                    $statusBadge = 'bg-emerald-50 text-emerald-700 border-emerald-200';
                    $biayaPasien = 0;
                } else {
                    $biayaPasien = max(0, $totalVal - (float)$b['TOTAL_DIBAYAR']);
                    $statusText = 'Menunggu Pembayaran Kasir';
                    $statusBadge = 'bg-rose-50 text-rose-700 border-rose-200';
                }
            }

            // Ambil rincian tindakan medis untuk tagihan ini (menggabungkan seluruh layanan/pendaftaran yang tergabung)
            $tindakanList = [];
            if ($pdoLayanan !== null) {
                try {
                    $stmtTm = $pdoLayanan->prepare("
                        SELECT 
                            t.NAMA AS NAMA_TINDAKAN,
                            DATE_FORMAT(tm.TANGGAL, '%d-%m-%Y %H:%i') AS WAKTU,
                            COALESCE(r.DESKRIPSI, '') AS RUANGAN
                        FROM layanan.tindakan_medis tm
                        JOIN master.tindakan t ON t.ID = tm.TINDAKAN
                        JOIN pendaftaran.kunjungan k ON k.NOMOR = tm.KUNJUNGAN
                        JOIN pembayaran.tagihan_pendaftaran tp ON tp.PENDAFTARAN = k.NOPEN
                        LEFT JOIN master.ruangan r ON r.ID = k.RUANGAN
                        WHERE tp.TAGIHAN = :tagihan_id AND tm.STATUS = 1
                        ORDER BY tm.TANGGAL ASC
                        LIMIT 8
                    ");
                    $stmtTm->execute([':tagihan_id' => $b['TAGIHAN_ID']]);
                    $tindakanList = $stmtTm->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $tindakanList = [];
                }
            }

            $isGabungan = ((int)($b['JML_PENDAFTARAN_GABUNG'] ?? 1) > 1);
            $ruanganGabungan = !empty($b['RUANGAN_GABUNGAN']) ? $b['RUANGAN_GABUNGAN'] : $b['RUANGAN'];

            $bills[] = [
                'tagihan_id' => $b['TAGIHAN_ID'],
                'nopen' => $b['NOPEN'],
                'tanggal' => $b['TANGGAL_TAGIHAN'],
                'tanggal_indo' => PasienConfig::formatTglIndo($b['RAW_TANGGAL'], true),
                'ruangan' => $b['RUANGAN'],
                'is_gabungan' => $isGabungan,
                'ruangan_gabungan' => $ruanganGabungan,
                'jml_pendaftaran_gabung' => (int)($b['JML_PENDAFTARAN_GABUNG'] ?? 1),
                'dokter' => $b['DOKTER'],
                'penjamin' => $b['PENJAMIN'],
                'no_sep' => $b['NO_SEP'] ?? '-',
                'kelas_penjamin' => formatKelas($b['KELAS_PENJAMIN']),
                'total_num' => $totalVal,
                'total_formatted' => formatRupiah($totalVal),
                'biaya_pasien_num' => $biayaPasien,
                'biaya_pasien_formatted' => formatRupiah($biayaPasien),
                'is_bpjs' => $isBpjs,
                'is_lunas' => $isLunas,
                'status_text' => $statusText,
                'status_badge' => $statusBadge,
                'rincian' => [
                    'akomodasi' => [
                        'label' => 'Akomodasi & Kamar Perawatan',
                        'val' => (float)$b['BIAYA_AKOMODASI'],
                        'formatted' => formatRupiah($b['BIAYA_AKOMODASI'])
                    ],
                    'konsultasi' => [
                        'label' => 'Jasa Konsultasi / Visite Spesialis',
                        'val' => (float)$b['BIAYA_KONSULTASI'],
                        'formatted' => formatRupiah($b['BIAYA_KONSULTASI'])
                    ],
                    'tindakan' => [
                        'label' => 'Tindakan & Prosedur Medis',
                        'val' => (float)$b['BIAYA_TINDAKAN'],
                        'formatted' => formatRupiah($b['BIAYA_TINDAKAN'])
                    ],
                    'laboratorium' => [
                        'label' => 'Pemeriksaan Laboratorium',
                        'val' => (float)$b['BIAYA_LABORATORIUM'],
                        'formatted' => formatRupiah($b['BIAYA_LABORATORIUM'])
                    ],
                    'radiologi' => [
                        'label' => 'Pemeriksaan Radiologi & Imaging',
                        'val' => (float)$b['BIAYA_RADIOLOGI'],
                        'formatted' => formatRupiah($b['BIAYA_RADIOLOGI'])
                    ],
                    'obat' => [
                        'label' => 'Farmasi & Terapi Obat',
                        'val' => (float)$b['BIAYA_OBAT'],
                        'formatted' => formatRupiah($b['BIAYA_OBAT'])
                    ],
                    'alkes' => [
                        'label' => 'Alat Kesehatan & BMHP',
                        'val' => (float)$b['BIAYA_ALKES'],
                        'formatted' => formatRupiah($b['BIAYA_ALKES'])
                    ]
                ],
                'tindakan_list' => $tindakanList
            ];
        }

        PasienConfig::jsonResponse([
            'success' => true,
            'data' => [
                'tagihan_terbaru' => $bills[0] ?? null,
                'riwayat_tagihan' => $bills,
                'total_manfaat_penjamin' => formatRupiah($totalManfaat),
                'total_kunjungan_tercatat' => count($bills)
            ]
        ]);

    } catch (Exception $e) {
        PasienConfig::jsonResponse([
            'success' => false,
            'message' => 'Terjadi kesalahan sistem saat memproses rincian billing: ' . $e->getMessage()
        ], 500);
    }
}

PasienConfig::jsonResponse([
    'success' => false,
    'message' => 'Aksi tidak dikenali.'
], 400);
