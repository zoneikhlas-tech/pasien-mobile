<?php
/**
 * Portal Pasien - RSUP dr. Ben Mboi Kupang
 * API Jadwal Obat Transparan (KPO & E-Resep Pasien)
 */

require_once __DIR__ . '/../config.php';

$user = PasienConfig::requireAuth();
$norm = (int)$user['norm'];
$action = $_GET['action'] ?? 'jadwal_obat';

$pdoLayanan = PasienConfig::getConnection('layanan');
$pdoDaftar  = PasienConfig::getConnection('pendaftaran');

$ruteText = [
    1  => 'Diminum (Oral)',
    2  => 'Suntikan (Parenteral)',
    3  => 'Obat Luar (Topikal)',
    4  => 'Supositoria (Lewat Anus)',
    5  => 'Infus / Injeksi Vena (IV)',
    6  => 'Subarachnoid',
    7  => 'Suntikan Otot (IM)',
    8  => 'Hirup / Nebulizer (Inhalasi)',
    9  => 'Irigasi',
    10 => 'Suntikan Bawah Kulit (SC)'
];

if ($action === 'jadwal_obat') {
    $response = [
        'is_ranap' => false,
        'kunjungan_info' => null,
        'daftar_obat' => [],
        'jadwal_harian' => [
            'pagi' => [],
            'siang' => [],
            'sore_malam' => [],
            'bila_perlu' => []
        ]
    ];

    if ($pdoLayanan !== null && $pdoDaftar !== null) {
        try {
            // 1. Cari kunjungan aktif pasien (ranap diutamakan, jika tidak ada ambil kunjungan terakhir)
            $stmtKunj = $pdoDaftar->prepare("
                SELECT 
                    k.NOMOR AS KUNJUNGAN_ID,
                    r.DESKRIPSI AS RUANGAN,
                    r.JENIS_KUNJUNGAN,
                    DATE_FORMAT(k.MASUK, '%d-%m-%Y') AS TGL_MASUK,
                    COALESCE(
                        TRIM(CONCAT(IF(COALESCE(peg_dok.GELAR_DEPAN,'') != '', CONCAT(peg_dok.GELAR_DEPAN, '. '), ''), peg_dok.NAMA, IF(COALESCE(peg_dok.GELAR_BELAKANG,'') != '', CONCAT(', ', peg_dok.GELAR_BELAKANG), ''))),
                        TRIM(CONCAT(IF(COALESCE(peg_dir.GELAR_DEPAN,'') != '', CONCAT(peg_dir.GELAR_DEPAN, '. '), ''), peg_dir.NAMA, IF(COALESCE(peg_dir.GELAR_BELAKANG,'') != '', CONCAT(', ', peg_dir.GELAR_BELAKANG), ''))),
                        'Dokter DPJP'
                    ) AS DOKTER
                FROM pendaftaran.kunjungan k
                JOIN pendaftaran.pendaftaran p ON p.NOMOR = k.NOPEN
                JOIN master.ruangan r ON r.ID = k.RUANGAN
                LEFT JOIN master.dokter dok ON dok.ID = k.DPJP
                LEFT JOIN master.pegawai peg_dok ON peg_dok.NIP = dok.NIP
                LEFT JOIN master.pegawai peg_dir ON peg_dir.ID = k.DPJP
                WHERE p.NORM = :norm AND k.STATUS != 0
                ORDER BY (r.JENIS_KUNJUNGAN IN (2,3) AND NOT EXISTS(SELECT 1 FROM layanan.pasien_pulang pp WHERE pp.KUNJUNGAN=k.NOMOR AND pp.STATUS=1)) DESC, k.MASUK DESC
                LIMIT 1
            ");
            $stmtKunj->execute([':norm' => $norm]);
            $kunj = $stmtKunj->fetch(PDO::FETCH_ASSOC);

            if ($kunj) {
                $response['kunjungan_info'] = $kunj;
                $response['is_ranap'] = in_array((int)$kunj['JENIS_KUNJUNGAN'], [2, 3]);

                // 2. Ambil obat dari resep pada kunjungan tersebut
                $stmtResep = $pdoLayanan->prepare("
                    SELECT 
                        od.ID AS DETIL_ID,
                        r.NOMOR AS ORDER_NOMOR,
                        DATE_FORMAT(r.TANGGAL, '%d-%m-%Y %H:%i') AS TGL_RESEP,
                        COALESCE(b.NAMA, 'Obat Terapi') AS NAMA_OBAT,
                        COALESCE(s.NAMA, 'Pcs') AS SATUAN,
                        od.JUMLAH,
                        od.DOSIS,
                        od.ATURAN_PAKAI,
                        od.RUTE_PEMBERIAN AS RUTE_ID,
                        od.FREKUENSI,
                        COALESCE(od.KETERANGAN, '-') AS KETERANGAN,
                        CASE 
                            WHEN r.STATUS = 1 THEN 'Dalam Penyiapan Farmasi'
                            WHEN r.STATUS = 2 THEN 'Diterima Ruangan / Perawat'
                            WHEN r.STATUS = 3 THEN 'Siap Diberikan / Aktif'
                            ELSE 'Aktif'
                        END AS STATUS_OBAT
                    FROM layanan.order_resep r
                    JOIN layanan.order_detil_resep od ON od.ORDER_ID = r.NOMOR AND od.STATUS = 1
                    LEFT JOIN inventory.barang b ON b.ID = od.FARMASI
                    LEFT JOIN inventory.satuan s ON s.ID = b.SATUAN
                    WHERE r.KUNJUNGAN = :kunjungan AND r.STATUS != 0
                    ORDER BY od.ID DESC
                ");
                $stmtResep->execute([':kunjungan' => $kunj['KUNJUNGAN_ID']]);
                $resepItems = $stmtResep->fetchAll(PDO::FETCH_ASSOC);

                foreach ($resepItems as $item) {
                    $rId = (int)$item['RUTE_ID'];
                    $item['RUTE_TEKS'] = $ruteText[$rId] ?? 'Diminum (Oral)';

                    // Helper penjelasan aturan pakai
                    $aturan = strtolower($item['ATURAN_PAKAI'] . ' ' . $item['DOSIS']);
                    
                    // Kelompokkan jadwal konsumsi harian
                    if (strpos($aturan, 'k/p') !== false || strpos($aturan, 'prn') !== false || strpos($aturan, 'bila perlu') !== false) {
                        $response['jadwal_harian']['bila_perlu'][] = $item;
                    } elseif (strpos($aturan, '1x') !== false || strpos($aturan, '1 x') !== false) {
                        if (strpos($aturan, 'malam') !== false) {
                            $response['jadwal_harian']['sore_malam'][] = $item;
                        } else {
                            $response['jadwal_harian']['pagi'][] = $item;
                        }
                    } elseif (strpos($aturan, '2x') !== false || strpos($aturan, '2 x') !== false) {
                        $response['jadwal_harian']['pagi'][] = $item;
                        $response['jadwal_harian']['sore_malam'][] = $item;
                    } elseif (strpos($aturan, '3x') !== false || strpos($aturan, '3 x') !== false) {
                        $response['jadwal_harian']['pagi'][] = $item;
                        $response['jadwal_harian']['siang'][] = $item;
                        $response['jadwal_harian']['sore_malam'][] = $item;
                    } else {
                        // Default bila tidak terdeteksi spesifik: masukkan ke pagi dan malam
                        $response['jadwal_harian']['pagi'][] = $item;
                        $response['jadwal_harian']['sore_malam'][] = $item;
                    }

                    $response['daftar_obat'][] = $item;
                }
            }

        } catch (Exception $e) {
            $response['error_db'] = $e->getMessage();
        }
    }

    PasienConfig::jsonResponse([
        'success' => true,
        'data' => $response
    ]);
}
