<?php
/**
 * API Informasi Publik Rumah Sakit (PasienKu) - RSUP dr. Ben Mboi Kupang
 * 1. Jadwal Dokter Spesialis & Poliklinik (HFIS BPJS & SIMRS)
 * 2. Ketersediaan Tempat Tidur Rawat Inap Real-time (SIRANAP / SIMRS)
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? 'jadwal_dokter';

try {
    switch ($action) {
        /**
         * 1. JADWAL DOKTER SPESIALIS
         * Sumber: regonline.jadwal_dokter_hfis + regonline.poli_bpjs + master.ruangan
         */
        case 'jadwal_dokter':
            $pdo = PasienConfig::getConnection('master');
            if (!$pdo) {
                echo json_encode(['success' => false, 'message' => 'Gagal terhubung ke database master']);
                exit;
            }

            $filterHari = isset($_GET['hari']) ? (int)$_GET['hari'] : 0;
            $filterPoli = trim($_GET['poli'] ?? '');
            $search     = trim($_GET['search'] ?? '');

            $params = [];
            $whereConditions = [
                "(h.STATUS = 1 OR h.STATUS IS NULL)",
                "(h.LIBUR = 0 OR h.LIBUR IS NULL)"
            ];

            if ($filterHari > 0) {
                $whereConditions[] = "h.HARI = :hari";
                $params[':hari'] = $filterHari;
            }

            if (!empty($filterPoli)) {
                $whereConditions[] = "(h.KD_SUB_SPESIALIS = :poli OR h.KD_POLI = :poli2 OR pb.KDPOLI = :poli3 OR r.ID = :poli4 OR r.DESKRIPSI LIKE :poli5)";
                $params[':poli']  = $filterPoli;
                $params[':poli2'] = $filterPoli;
                $params[':poli3'] = $filterPoli;
                $params[':poli4'] = $filterPoli;
                $params[':poli5'] = '%' . $filterPoli . '%';
            }

            if (!empty($search)) {
                $whereConditions[] = "(h.NM_DOKTER LIKE :q OR pb.NMPOLI LIKE :q2 OR r.DESKRIPSI LIKE :q4)";
                $params[':q']  = '%' . $search . '%';
                $params[':q2'] = '%' . $search . '%';
                $params[':q4'] = '%' . $search . '%';
            }

            $whereSql = implode(' AND ', $whereConditions);

            $sql = "SELECT h.ID,
                           h.KD_DOKTER,
                           h.NM_DOKTER,
                           h.KD_SUB_SPESIALIS,
                           h.KD_POLI,
                           COALESCE(NULLIF(r.DESKRIPSI, ''), NULLIF(pb.NMPOLI, ''), NULLIF(pb.NMSUBSPESIALIS, ''), h.KD_SUB_SPESIALIS) as NAMA_POLI,
                           r.ID as RUANGAN_RS_ID,
                           h.HARI,
                           h.NM_HARI,
                           h.JAM,
                           h.JAM_MULAI,
                           h.JAM_SELESAI,
                           COALESCE(h.KAPASITAS, 50) as KAPASITAS,
                           COALESCE(h.KOUTA_JKN, 40) as KUOTA_JKN,
                           COALESCE(h.KOUTA_NON_JKN, 10) as KUOTA_NON_JKN
                    FROM regonline.jadwal_dokter_hfis h
                    LEFT JOIN master.penjamin_ruangan pr ON (pr.RUANGAN_PENJAMIN = h.KD_SUB_SPESIALIS OR pr.RUANGAN_PENJAMIN = h.KD_POLI) AND pr.PENJAMIN = 2
                    LEFT JOIN master.ruangan r ON r.ID = pr.RUANGAN_RS
                    LEFT JOIN regonline.poli_bpjs pb ON (pb.KDPOLI = h.KD_POLI AND (pb.KDSUBSPESIALIS = '' OR pb.KDSUBSPESIALIS = h.KD_SUB_SPESIALIS))
                    WHERE {$whereSql}
                    GROUP BY h.KD_DOKTER, h.HARI, h.KD_SUB_SPESIALIS
                    ORDER BY NAMA_POLI ASC, h.HARI ASC, h.JAM ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Ambil daftar poliklinik unik untuk filter dropdown (Prioritas Nama Ruangan SIMRS)
            $sqlPoli = "SELECT DISTINCT COALESCE(NULLIF(r.DESKRIPSI, ''), NULLIF(pb.NMPOLI, ''), h.KD_SUB_SPESIALIS) as NAMA_POLI,
                               COALESCE(r.ID, h.KD_SUB_SPESIALIS) as KODE_POLI,
                               r.ID as RUANGAN_RS_ID
                        FROM regonline.jadwal_dokter_hfis h
                        LEFT JOIN master.penjamin_ruangan pr ON (pr.RUANGAN_PENJAMIN = h.KD_SUB_SPESIALIS OR pr.RUANGAN_PENJAMIN = h.KD_POLI) AND pr.PENJAMIN = 2
                        LEFT JOIN master.ruangan r ON r.ID = pr.RUANGAN_RS
                        LEFT JOIN regonline.poli_bpjs pb ON (pb.KDPOLI = h.KD_POLI AND (pb.KDSUBSPESIALIS = '' OR pb.KDSUBSPESIALIS = h.KD_SUB_SPESIALIS))
                        WHERE (h.STATUS = 1 OR h.STATUS IS NULL)
                        GROUP BY NAMA_POLI
                        ORDER BY NAMA_POLI ASC";
            $stmtPoli = $pdo->query($sqlPoli);
            $poliList = $stmtPoli->fetchAll(PDO::FETCH_ASSOC);

            // Format data responsif untuk UI
            $daysNameIndo = [
                1 => 'Senin',
                2 => 'Selasa',
                3 => 'Rabu',
                4 => 'Kamis',
                5 => 'Jumat',
                6 => 'Sabtu',
                7 => 'Minggu'
            ];

            $currentDayNumber = (int)date('N'); // 1 = Senin, ..., 7 = Minggu
            $tzLabel = PasienConfig::getTimezoneLabel();

            $formatted = [];
            foreach ($rows as $r) {
                $hariId = (int)$r['HARI'];
                $isToday = ($hariId === $currentDayNumber);

                $formatted[] = [
                    'id'             => (int)$r['ID'],
                    'kode_dokter'    => (string)$r['KD_DOKTER'],
                    'nama_dokter'    => $r['NM_DOKTER'],
                    'kode_poli'      => $r['KD_SUB_SPESIALIS'] ?: $r['KD_POLI'],
                    'nama_poli'      => $r['NAMA_POLI'] ?: 'Poliklinik Spesialis',
                    'ruangan_rs_id'  => $r['RUANGAN_RS_ID'] ?: '',
                    'hari_id'        => $hariId,
                    'hari_nama'      => $daysNameIndo[$hariId] ?? $r['NM_HARI'],
                    'jam_praktek'    => $r['JAM'] ?: ('08:00 - 12:00 ' . $tzLabel),
                    'kapasitas'      => (int)$r['KAPASITAS'],
                    'kuota_jkn'      => (int)$r['KUOTA_JKN'],
                    'kuota_non_jkn'  => (int)$r['KUOTA_NON_JKN'],
                    'is_today'       => $isToday
                ];
            }

            echo json_encode([
                'success' => true,
                'data'    => [
                    'total_jadwal'       => count($formatted),
                    'current_day_number' => $currentDayNumber,
                    'current_day_name'   => $daysNameIndo[$currentDayNumber] ?? 'Senin',
                    'daftar_poli'        => $poliList,
                    'jadwal'             => $formatted
                ]
            ]);
            break;

        /**
         * 2. KETERSEDIAAN TEMPAT TIDUR RAWAT INAP
         * Sumber: CALL informasi.listRuangKamarTidur('', '0', '0', '0', '')
         */
        case 'tempat_tidur':
            $pdo = PasienConfig::getConnection('master');
            if (!$pdo) {
                echo json_encode(['success' => false, 'message' => 'Gagal terhubung ke database master']);
                exit;
            }

            $filterKelas   = trim($_GET['kelas'] ?? '');
            $filterRuangan = trim($_GET['ruangan'] ?? '');

            $stmt = $pdo->query("CALL informasi.listRuangKamarTidur('', '0', '0', '0', '')");
            $rawList = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalBed = 0;
            $totalTerisi = 0;
            $totalKosong = 0;

            $kelasMap = [];
            $ruanganMap = [];

            foreach ($rawList as $row) {
                $totalBed++;
                $st = trim($row['STATUS'] ?? '');
                $stId = (int)($row['STATUS_ID'] ?? 0);

                // ID 1 = Kosong, ID 2 = Dipesan, ID 3 = Terisi
                $isKosong = (strtolower($st) === 'kosong' || $stId === 1);
                if ($isKosong) {
                    $totalKosong++;
                } else {
                    $totalTerisi++;
                }

                // Agregasi Per Kelas
                $kelasName = trim($row['KELAS'] ?: 'Lainnya');
                $kelasId   = (int)($row['KELAS_ID'] ?? 0);
                if (!isset($kelasMap[$kelasName])) {
                    $kelasMap[$kelasName] = [
                        'kelas_id'  => $kelasId,
                        'kelas_nama'=> $kelasName,
                        'total'     => 0,
                        'terisi'    => 0,
                        'kosong'    => 0
                    ];
                }
                $kelasMap[$kelasName]['total']++;
                if ($isKosong) {
                    $kelasMap[$kelasName]['kosong']++;
                } else {
                    $kelasMap[$kelasName]['terisi']++;
                }

                // Agregasi Per Ruangan
                $ruanganId   = $row['RUANGAN_ID'] ?? '';
                $ruanganName = trim($row['RUANGAN'] ?: 'Ruang Perawatan');
                if (!isset($ruanganMap[$ruanganName])) {
                    $ruanganMap[$ruanganName] = [
                        'ruangan_id'   => $ruanganId,
                        'ruangan_nama' => $ruanganName,
                        'total'        => 0,
                        'terisi'       => 0,
                        'kosong'       => 0,
                        'kelas_list'   => []
                    ];
                }
                $ruanganMap[$ruanganName]['total']++;
                if ($isKosong) {
                    $ruanganMap[$ruanganName]['kosong']++;
                } else {
                    $ruanganMap[$ruanganName]['terisi']++;
                }

                if (!in_array($kelasName, $ruanganMap[$ruanganName]['kelas_list'])) {
                    $ruanganMap[$ruanganName]['kelas_list'][] = $kelasName;
                }
            }

            // Hitung BOR (Bed Occupancy Rate %)
            $borPercentage = ($totalBed > 0) ? round(($totalTerisi / $totalBed) * 100, 1) : 0;

            // Format Kelas List dengan persentase ketersediaan
            $kelasList = array_values($kelasMap);
            usort($kelasList, function($a, $b) {
                return $b['total'] - $a['total'];
            });

            // Format Ruangan List dengan persentase keterisian
            $ruanganList = array_values($ruanganMap);
            usort($ruanganList, function($a, $b) {
                return strcmp($a['ruangan_nama'], $b['ruangan_nama']);
            });

            // Filter jika ada parameter query
            if (!empty($filterKelas)) {
                $kelasList = array_values(array_filter($kelasList, function($k) use ($filterKelas) {
                    return stripos($k['kelas_nama'], $filterKelas) !== false;
                }));
            }

            if (!empty($filterRuangan)) {
                $ruanganList = array_values(array_filter($ruanganList, function($r) use ($filterRuangan) {
                    return stripos($r['ruangan_nama'], $filterRuangan) !== false;
                }));
            }

            $tzLabel = PasienConfig::getTimezoneLabel();
            echo json_encode([
                'success' => true,
                'data'    => [
                    'updated_at'     => date('d M Y, H:i') . ' ' . $tzLabel,
                    'ringkasan'      => [
                        'total_tempat_tidur'   => $totalBed,
                        'tempat_tidur_kosong'  => $totalKosong,
                        'tempat_tidur_terisi'  => $totalTerisi,
                        'bor_persen'           => $borPercentage
                    ],
                    'per_kelas'      => $kelasList,
                    'per_ruangan'    => $ruanganList
                ]
            ]);
            break;

        /**
         * 3. PROFIL & IDENTITAS RUMAH SAKIT
         */
        case 'profil_rs':
        case 'hospital_info':
            $hInfo = PasienConfig::getHospitalInfo();
            echo json_encode([
                'success' => true,
                'data'    => $hInfo
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Action tidak dikenali']);
            break;
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
