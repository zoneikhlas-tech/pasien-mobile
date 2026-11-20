<?php
/**
 * Portal Pasien (PasienKu) - RSUP dr. Ben Mboi Kupang
 * API Modul Antrean Online & Booking Poliklinik (RegOnline)
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$auth = PasienConfig::requireAuth();
if (!$auth) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Sesi login telah berakhir. Silakan login kembali.'
    ]);
    exit;
}

$norm = (int)$auth['norm'];
$action = $_GET['action'] ?? 'get_my_antrean';
$pdo = PasienConfig::getConnection();

try {
    switch ($action) {
        /**
         * 1. Ambil antrean aktif dan riwayat booking pasien
         */
        case 'get_my_antrean':
            // A. Ambil reservasi online aktif (TANGGALKUNJUNGAN >= hari ini dan STATUS = 1)
            $sqlOnline = "SELECT r.ID as KODE_BOOKING, r.TANGGALKUNJUNGAN, r.JAM, r.JAM_PRAKTEK, r.ESTIMASI_PELAYANAN,
                                 r.POS_ANTRIAN, r.NO as NO_ANTRIAN_LOKET, r.ANTRIAN_POLI, r.NOMOR_ANTRIAN,
                                 r.CARABAYAR, r.NO_KARTU_BPJS, r.NO_REF_BPJS, r.STATUS,
                                 ru.ID as KODE_POLI, ru.DESKRIPSI as NAMA_POLI,
                                 COALESCE(rdok.NAMA, dok_peg.NAMA, r.DOKTER) as NAMA_DOKTER
                          FROM regonline.reservasi r
                          LEFT JOIN master.ruangan ru ON ru.ID = r.POLI
                          LEFT JOIN regonline.dokter rdok ON rdok.KODE = r.DOKTER
                          LEFT JOIN master.dokter dok ON dok.ID = r.DOKTER
                          LEFT JOIN master.pegawai dok_peg ON dok_peg.NIP = dok.NIP
                          WHERE r.NORM = :norm AND r.STATUS = 1 AND r.TANGGALKUNJUNGAN >= CURDATE()
                          ORDER BY r.TANGGALKUNJUNGAN ASC, r.ID ASC";
            $stmt = $pdo->prepare($sqlOnline);
            $stmt->execute([':norm' => $norm]);
            $onlineList = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Perkaya data antrean online hari ini dengan live counter di poli
            $activeTicket = null;
            $upcomingBookings = [];

            foreach ($onlineList as $row) {
                $isToday = ($row['TANGGALKUNJUNGAN'] === date('Y-m-d'));
                
                // Cek live panggilan di ruangan poli hari ini
                $currentCalled = null;
                $sisaAntrean = 0;
                $statusPanggilan = 'Menunggu';

                if ($isToday && !empty($row['KODE_POLI'])) {
                    // Cari nomor yang sedang dipanggil di pendaftaran.antrian_ruangan
                    $stmtCall = $pdo->prepare("SELECT MAX(NOMOR) as TERAKHIR_DIPANGGIL 
                                               FROM pendaftaran.antrian_ruangan 
                                               WHERE RUANGAN = :ruangan AND TANGGAL = CURDATE() AND STATUS = 2");
                    $stmtCall->execute([':ruangan' => $row['KODE_POLI']]);
                    $currentCalled = (int)$stmtCall->fetchColumn();

                    // Hitung sisa antrean sebelum nomor pasien
                    $patientPoliNo = (int)$row['ANTRIAN_POLI'];
                    if ($patientPoliNo > 0) {
                        $stmtWait = $pdo->prepare("SELECT COUNT(*) FROM pendaftaran.antrian_ruangan 
                                                   WHERE RUANGAN = :ruangan AND TANGGAL = CURDATE() AND STATUS = 1 AND NOMOR < :my_no");
                        $stmtWait->execute([':ruangan' => $row['KODE_POLI'], ':my_no' => $patientPoliNo]);
                        $sisaAntrean = (int)$stmtWait->fetchColumn();

                        if ($currentCalled === $patientPoliNo) {
                            $statusPanggilan = 'Sedang Dipanggil Dokter';
                        } elseif ($currentCalled > $patientPoliNo) {
                            $statusPanggilan = 'Sudah Selesai / Terlewat';
                        }
                    }
                }

                $ticketData = [
                    'kode_booking'       => $row['KODE_BOOKING'],
                    'tanggal_kunjungan'  => $row['TANGGALKUNJUNGAN'],
                    'tanggal_format'     => date('d M Y', strtotime($row['TANGGALKUNJUNGAN'])),
                    'hari'               => PasienConfig::formatIndonesianDay($row['TANGGALKUNJUNGAN']),
                    'kode_poli'          => $row['KODE_POLI'],
                    'nama_poli'          => $row['NAMA_POLI'] ?: 'Poliklinik Rawat Jalan',
                    'nama_dokter'        => $row['NAMA_DOKTER'] ?: 'Dokter Poliklinik',
                    'jam_praktek'        => $row['JAM_PRAKTEK'] ?: '08:00 - 12:00 WITA',
                    'estimasi_pelayanan' => $row['ESTIMASI_PELAYANAN'] ?: $row['JAM'],
                    'no_antrian_loket'   => ($row['POS_ANTRIAN'] ? $row['POS_ANTRIAN'] . ($row['CARABAYAR'] == 2 ? '2' : '1') . '-' : '') . str_pad($row['NO_ANTRIAN_LOKET'], 3, '0', STR_PAD_LEFT),
                    'no_antrian_poli'    => $row['ANTRIAN_POLI'] ?: $row['NOMOR_ANTRIAN'],
                    'cara_bayar'         => ($row['CARABAYAR'] == 2) ? 'BPJS Kesehatan' : 'Pasien Umum',
                    'is_bpjs'            => ($row['CARABAYAR'] == 2),
                    'no_kartu_bpjs'      => $row['NO_KARTU_BPJS'],
                    'no_rujukan'         => $row['NO_REF_BPJS'],
                    'is_today'           => $isToday,
                    'nomor_dipanggil'    => $currentCalled ?: 0,
                    'sisa_antrean'       => $sisaAntrean,
                    'status_panggilan'   => $statusPanggilan
                ];

                if ($isToday && !$activeTicket) {
                    $activeTicket = $ticketData;
                } else {
                    $upcomingBookings[] = $ticketData;
                }
            }

            // B. Cek juga apakah pasien mendaftar on-site langsung di RS hari ini (pendaftaran.antrian_ruangan)
            if (!$activeTicket) {
                $sqlLiveRS = "SELECT ar.NOMOR as ANTRIAN_POLI, ar.TANGGAL, ar.STATUS, ar.KODEBOOKING,
                                     ru.ID as KODE_POLI, ru.DESKRIPSI as NAMA_POLI,
                                     peg.NAMA as NAMA_DOKTER
                              FROM pendaftaran.antrian_ruangan ar
                              JOIN pendaftaran.pendaftaran p ON p.NOMOR = ar.REF
                              LEFT JOIN master.ruangan ru ON ru.ID = ar.RUANGAN
                              LEFT JOIN master.dokter dok ON dok.ID = ar.DOKTER
                              LEFT JOIN master.pegawai peg ON peg.NIP = dok.NIP
                              WHERE p.NORM = :norm AND ar.TANGGAL = CURDATE()
                              ORDER BY ar.ID DESC LIMIT 1";
                $stmtRS = $pdo->prepare($sqlLiveRS);
                $stmtRS->execute([':norm' => $norm]);
                $liveRS = $stmtRS->fetch(PDO::FETCH_ASSOC);

                if ($liveRS) {
                    $stmtCall = $pdo->prepare("SELECT MAX(NOMOR) FROM pendaftaran.antrian_ruangan 
                                               WHERE RUANGAN = :ruangan AND TANGGAL = CURDATE() AND STATUS = 2");
                    $stmtCall->execute([':ruangan' => $liveRS['KODE_POLI']]);
                    $currentCalled = (int)$stmtCall->fetchColumn();

                    $activeTicket = [
                        'kode_booking'       => $liveRS['KODEBOOKING'] ?: 'ON-SITE',
                        'tanggal_kunjungan'  => $liveRS['TANGGAL'],
                        'tanggal_format'     => date('d M Y'),
                        'hari'               => PasienConfig::formatIndonesianDay(date('Y-m-d')),
                        'kode_poli'          => $liveRS['KODE_POLI'],
                        'nama_poli'          => $liveRS['NAMA_POLI'],
                        'nama_dokter'        => $liveRS['NAMA_DOKTER'] ?: 'Dokter Poliklinik',
                        'jam_praktek'        => '08:00 - Selesai WITA',
                        'estimasi_pelayanan' => 'Sesuai Panggilan Poli',
                        'no_antrian_loket'   => '-',
                        'no_antrian_poli'    => $liveRS['ANTRIAN_POLI'],
                        'cara_bayar'         => 'Registrasi Loket RS',
                        'is_bpjs'            => false,
                        'no_kartu_bpjs'      => '',
                        'no_rujukan'         => '',
                        'is_today'           => true,
                        'nomor_dipanggil'    => $currentCalled,
                        'sisa_antrean'       => max(0, $liveRS['ANTRIAN_POLI'] - $currentCalled),
                        'status_panggilan'   => ($liveRS['STATUS'] == 2) ? 'Sedang Dipanggil Dokter' : 'Menunggu di Ruang Tunggu'
                    ];
                }
            }

            // C. Riwayat Reservasi Sebelumnya (lampau / selesai)
            $sqlHistory = "SELECT r.ID as KODE_BOOKING, r.TANGGALKUNJUNGAN, r.ANTRIAN_POLI, r.STATUS, r.CARABAYAR,
                                  ru.DESKRIPSI as NAMA_POLI,
                                  COALESCE(rdok.NAMA, dok_peg.NAMA, r.DOKTER) as NAMA_DOKTER
                           FROM regonline.reservasi r
                           LEFT JOIN master.ruangan ru ON ru.ID = r.POLI
                           LEFT JOIN regonline.dokter rdok ON rdok.KODE = r.DOKTER
                           LEFT JOIN master.dokter dok ON dok.ID = r.DOKTER
                           LEFT JOIN master.pegawai dok_peg ON dok_peg.NIP = dok.NIP
                           WHERE r.NORM = :norm AND (r.TANGGALKUNJUNGAN < CURDATE() OR r.STATUS != 1)
                           ORDER BY r.TANGGALKUNJUNGAN DESC, r.ID DESC
                           LIMIT 10";
            $stmtHist = $pdo->prepare($sqlHistory);
            $stmtHist->execute([':norm' => $norm]);
            $historyList = [];
            while ($h = $stmtHist->fetch(PDO::FETCH_ASSOC)) {
                $statusLabel = 'Selesai';
                $statusBadge = 'bg-slate-100 text-slate-600';
                if ($h['STATUS'] == 0) {
                    $statusLabel = 'Dibatalkan';
                    $statusBadge = 'bg-rose-100 text-rose-700';
                } elseif ($h['STATUS'] == 1 && $h['TANGGALKUNJUNGAN'] < date('Y-m-d')) {
                    $statusLabel = 'Kedaluwarsa';
                    $statusBadge = 'bg-amber-100 text-amber-800';
                } elseif ($h['STATUS'] == 2) {
                    $statusLabel = 'Selesai';
                    $statusBadge = 'bg-emerald-100 text-emerald-800';
                }

                $historyList[] = [
                    'kode_booking'      => $h['KODE_BOOKING'],
                    'tanggal_kunjungan' => $h['TANGGALKUNJUNGAN'],
                    'tanggal_format'    => date('d M Y', strtotime($h['TANGGALKUNJUNGAN'])),
                    'hari'              => PasienConfig::formatIndonesianDay($h['TANGGALKUNJUNGAN']),
                    'nama_poli'         => $h['NAMA_POLI'] ?: 'Poliklinik',
                    'nama_dokter'       => $h['NAMA_DOKTER'] ?: '-',
                    'no_antrian_poli'   => $h['ANTRIAN_POLI'],
                    'cara_bayar'        => ($h['CARABAYAR'] == 2 ? 'BPJS' : 'Umum'),
                    'status_raw'        => (int)$h['STATUS'],
                    'status_label'      => $statusLabel,
                    'status_badge'      => $statusBadge
                ];
            }

            // Jika tidak ada antrean hari ini, jadikan booking mendatang terdekat sebagai tiket utama
            if (!$activeTicket && !empty($upcomingBookings)) {
                $activeTicket = array_shift($upcomingBookings);
            }

            echo json_encode([
                'success' => true,
                'data'    => [
                    'active_ticket'     => $activeTicket,
                    'upcoming_bookings' => $upcomingBookings,
                    'history'           => $historyList
                ]
            ]);
            break;

        /**
         * 2. Ambil daftar poliklinik aktif untuk dropdown form booking
         */
        case 'get_poliklinik':
            $sql = "SELECT lr.ID, lr.DESKRIPSI, lr.ANTRIAN as POS, lr.MULAI,
                           pb.KDPOLI as KODE_BPJS
                    FROM regonline.link_ruangan lr
                    LEFT JOIN regonline.poli_bpjs pb ON pb.ID = lr.ID OR pb.NMPOLI = lr.DESKRIPSI
                    WHERE lr.ID LIKE '1010201%' AND lr.STATUS = 1
                    GROUP BY lr.ID
                    ORDER BY lr.DESKRIPSI ASC";
            $stmt = $pdo->query($sql);
            $polikliniks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'data'    => $polikliniks
            ]);
            break;

        /**
         * 3. Ambil jadwal dokter berdasarkan poliklinik dan hari yang dipilih
         */
        case 'get_jadwal_dokter':
            $poliId = $_GET['poli_id'] ?? '';
            $tglKunjungan = $_GET['tanggal'] ?? '';

            if (empty($poliId)) {
                echo json_encode(['success' => false, 'message' => 'Pilih poliklinik terlebih dahulu']);
                exit;
            }

            // Tentukan hari dalam nama (SENIN..MINGGU) dan angka (1=Senin..7=Minggu)
            $dayOfWeek = !empty($tglKunjungan) ? (int)date('N', strtotime($tglKunjungan)) : (int)date('N');
            $daysMap = [
                1 => 'SENIN',
                2 => 'SELASA',
                3 => 'RABU',
                4 => 'KAMIS',
                5 => 'JUMAT',
                6 => 'SABTU',
                7 => 'MINGGU'
            ];
            $nmHari = $daysMap[$dayOfWeek] ?? 'SENIN';

            // Ambil kode BPJS sub-spesialis / ruangan dari penjamin_ruangan & link_ruangan
            $stmtBpjs = $pdo->prepare("SELECT p.RUANGAN_PENJAMIN FROM master.penjamin_ruangan p 
                                       WHERE p.RUANGAN_RS = :poli AND p.PENJAMIN = 2 AND p.STATUS = 1 LIMIT 1");
            $stmtBpjs->execute([':poli' => $poliId]);
            $poliBpjs = $stmtBpjs->fetchColumn() ?: '';

            if (empty($poliBpjs)) {
                $stmtPb = $pdo->prepare("SELECT pb.KDPOLI FROM regonline.poli_bpjs pb 
                                         LEFT JOIN master.ruangan r ON r.ID = :poli
                                         WHERE pb.ID = :poli2 OR pb.NMPOLI = r.DESKRIPSI LIMIT 1");
                $stmtPb->execute([':poli' => $poliId, ':poli2' => $poliId]);
                $poliBpjs = $stmtPb->fetchColumn() ?: '';
            }

            $doctors = [];

            // A. CARA RESMI: Panggil WebService RegOnline getJadwalDokterHfis
            $wsResourceFile = __DIR__ . '/../../RegOnline/app/ResourceWs.php';
            if (file_exists($wsResourceFile) && !empty($poliBpjs) && !empty($tglKunjungan)) {
                try {
                    require_once $wsResourceFile;
                    $token = getApiToken('getToken', 'POST');
                    if (!empty($token) && strlen($token) > 20) {
                        $rawWs = sendRequest("getJadwalDokterHfis?TANGGAL=" . urlencode($tglKunjungan) . "&PENJAMIN=" . urlencode($poliBpjs), "GET", "", $token);
                        $wsResult = json_decode($rawWs, true);
                        if ($wsResult && !empty($wsResult['success']) && !empty($wsResult['data']) && is_array($wsResult['data'])) {
                            foreach ($wsResult['data'] as $row) {
                                $jamWs = $row['JAM'] ?? '08:00 - 12:00 WITA';
                                $doctors[] = [
                                    'DOKTER_ID'      => $row['ID'] ?? ($row['KD_DOKTER'] ?? '0'),
                                    'KD_DOKTER_BPJS' => (string)($row['KD_DOKTER'] ?? ''),
                                    'DOKTER_NAMA'    => $row['NM_DOKTER'] ?? '',
                                    'POLI_NAMA'      => $row['REFERENSI']['POLI']['NMPOLI'] ?? ($row['NM_SUB_SPESIALIS'] ?? ''),
                                    'KODE_BPJS'      => $row['KD_SUB_SPESIALIS'] ?? ($row['KD_POLI'] ?? $poliBpjs),
                                    'JAM_PRAKTEK'    => $jamWs,
                                    'KUOTA'          => (int)($row['KAPASITAS'] ?? 50),
                                    'HARI'           => $nmHari
                                ];
                            }
                        }
                    }
                } catch (Exception $eWs) {}
            }

            // B. FALLBACK DATABASE: Ambil langsung dari tabel regonline.jadwal_dokter_hfis sesuai HARI
            if (empty($doctors)) {
                $sqlHfis = "SELECT h.ID,
                                   h.KD_DOKTER as KD_DOKTER_BPJS,
                                   h.NM_DOKTER as DOKTER_NAMA,
                                   COALESCE(dok.ID, h.KD_DOKTER) as DOKTER_ID,
                                   COALESCE(h.KD_SUB_SPESIALIS, h.KD_POLI, :poliBpjsVal) as KODE_BPJS,
                                   '' as POLI_NAMA,
                                   h.JAM as JAM_PRAKTEK,
                                   COALESCE(h.KAPASITAS, 50) as KUOTA
                            FROM regonline.jadwal_dokter_hfis h
                            LEFT JOIN master.pegawai peg ON (peg.NAMA LIKE CONCAT('%', h.NM_DOKTER, '%') OR h.NM_DOKTER LIKE CONCAT('%', peg.NAMA, '%'))
                            LEFT JOIN master.dokter dok ON dok.NIP = peg.NIP
                            WHERE (h.KD_SUB_SPESIALIS = :poliBpjsA 
                                   OR h.KD_POLI = :poliBpjsB 
                                   OR h.KD_SUB_SPESIALIS IN (SELECT RUANGAN_PENJAMIN FROM master.penjamin_ruangan WHERE RUANGAN_RS = :poliId AND PENJAMIN = 2 AND STATUS = 1))
                              AND (h.NM_HARI = :nmHari OR h.HARI = :hari)
                              AND (h.LIBUR = 0 OR h.LIBUR IS NULL)
                              AND (h.STATUS = 1 OR h.STATUS IS NULL)
                            GROUP BY h.KD_DOKTER
                            ORDER BY h.NM_DOKTER ASC";
                $stmtHfis = $pdo->prepare($sqlHfis);
                $stmtHfis->execute([
                    ':poliBpjsVal' => $poliBpjs,
                    ':poliBpjsA'   => $poliBpjs,
                    ':poliBpjsB'   => $poliBpjs,
                    ':poliId'      => $poliId,
                    ':nmHari'      => $nmHari,
                    ':hari'        => $dayOfWeek
                ]);
                $hfisRows = $stmtHfis->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($hfisRows)) {
                    foreach ($hfisRows as $r) {
                        $doctors[] = [
                            'DOKTER_ID'      => $r['DOKTER_ID'],
                            'KD_DOKTER_BPJS' => (string)$r['KD_DOKTER_BPJS'],
                            'DOKTER_NAMA'    => $r['DOKTER_NAMA'],
                            'POLI_NAMA'      => $r['POLI_NAMA'],
                            'KODE_BPJS'      => $r['KODE_BPJS'],
                            'JAM_PRAKTEK'    => $r['JAM_PRAKTEK'] ?: '08:00 - 12:00 WITA',
                            'KUOTA'          => (int)$r['KUOTA'],
                            'HARI'           => $nmHari
                        ];
                    }
                } else {
                    // Cek apakah poliklinik ini memiliki jadwal di HFIS pada hari-hari lain
                    $stCheck = $pdo->prepare("SELECT COUNT(*) FROM regonline.jadwal_dokter_hfis 
                                             WHERE (KD_SUB_SPESIALIS = :pb1 OR KD_POLI = :pb2 
                                                    OR KD_SUB_SPESIALIS IN (SELECT RUANGAN_PENJAMIN FROM master.penjamin_ruangan WHERE RUANGAN_RS = :poliId AND PENJAMIN = 2 AND STATUS = 1))");
                    $stCheck->execute([':pb1' => $poliBpjs, ':pb2' => $poliBpjs, ':poliId' => $poliId]);
                    $hasAnyHfis = (int)$stCheck->fetchColumn();

                    // Jika poliklinik ini memang tidak pernah disinkronkan ke HFIS (poli non-HFIS / umum), fallback ke master.dokter_ruangan
                    if ($hasAnyHfis === 0) {
                        $sqlDr = "SELECT dr.DOKTER as DOKTER_ID, 
                                         COALESCE(h.KD_DOKTER, dr.DOKTER) as KD_DOKTER_BPJS,
                                         peg.NAMA as DOKTER_NAMA,
                                         r.DESKRIPSI as POLI_NAMA,
                                         :poliBpjs as KODE_BPJS,
                                         '08:00 - 12:00 WITA' as JAM_PRAKTEK,
                                         50 as KUOTA,
                                         :nmHari as HARI
                                  FROM master.dokter_ruangan dr
                                  JOIN master.ruangan r ON r.ID = dr.RUANGAN
                                  JOIN master.dokter d ON d.ID = dr.DOKTER
                                  JOIN master.pegawai peg ON peg.NIP = d.NIP
                                  LEFT JOIN regonline.jadwal_dokter_hfis h ON (h.NM_DOKTER LIKE CONCAT('%', peg.NAMA, '%') OR peg.NAMA LIKE CONCAT('%', h.NM_DOKTER, '%'))
                                  WHERE dr.RUANGAN = :poli AND dr.STATUS = 1
                                  GROUP BY dr.DOKTER
                                  ORDER BY peg.NAMA ASC";
                        $stmtDr = $pdo->prepare($sqlDr);
                        $stmtDr->execute([':poli' => $poliId, ':poliBpjs' => $poliBpjs, ':nmHari' => $nmHari]);
                        $doctors = $stmtDr->fetchAll(PDO::FETCH_ASSOC);
                    }
                    // Jika poliklinik ada di HFIS tapi tidak ada jadwal di hari ini, $doctors tetap [] (kosong)
                    // menandakan poliklinik / dokter memang tidak buka pada hari tersebut.
                }
            }

            echo json_encode([
                'success' => true,
                'hari'    => $nmHari,
                'tanggal' => $tglKunjungan,
                'data'    => $doctors
            ]);
            break;

        /**
         * 4. Ambil nomor rujukan & surat kontrol BPJS milik pasien
         */
        case 'get_bpjs_ref':
            // Cek data pasien
            $stmtP = $pdo->prepare("SELECT NAMA, TANGGAL_LAHIR FROM master.pasien WHERE NORM = :norm");
            $stmtP->execute([':norm' => $norm]);
            $pasienInfo = $stmtP->fetch(PDO::FETCH_ASSOC);

            // Ambil nomor kartu BPJS resmi (13 digit) dari master.kartu_asuransi_pasien (JENIS = 2)
            $stmtNoKartu = $pdo->prepare("SELECT NOMOR FROM master.kartu_asuransi_pasien 
                                         WHERE NORM = :norm AND JENIS = 2 AND NOMOR IS NOT NULL AND TRIM(NOMOR) != '' 
                                           AND TRIM(NOMOR) NOT LIKE '%V%'
                                         LIMIT 1");
            $stmtNoKartu->execute([':norm' => $norm]);
            $noKartuBpjs = trim((string)$stmtNoKartu->fetchColumn());

            // Fallback: cari di penjamin yang panjangnya tepat 13 digit angka murni
            if (empty($noKartuBpjs) || strlen($noKartuBpjs) !== 13) {
                $stmtPj = $pdo->prepare("SELECT pj.NOMOR FROM pendaftaran.penjamin pj 
                                         JOIN pendaftaran.pendaftaran p ON p.NOMOR = pj.NOPEN 
                                         WHERE p.NORM = :norm AND pj.JENIS = 2 
                                           AND TRIM(pj.NOMOR) REGEXP '^[0-9]{13}$'
                                         ORDER BY pj.NOPEN DESC LIMIT 1");
                $stmtPj->execute([':norm' => $norm]);
                $valPj = trim((string)$stmtPj->fetchColumn());
                if (!empty($valPj)) {
                    $noKartuBpjs = $valPj;
                }
            }

            // Cek surat kontrol / SKDP yang aktif dari medicalrecord.jadwal_kontrol
            $sqlKontrol = "SELECT 
                              jk.NOMOR_REFERENSI as noSurat,
                              jk.TANGGAL as tglRencanaKontrol,
                              COALESCE(r.DESKRIPSI, r_asal.DESKRIPSI, 'Poliklinik') as namaPoli,
                              COALESCE(r.ID, r_asal.ID) as poliId,
                              COALESCE(pr.RUANGAN_PENJAMIN, pb.KDPOLI, '') as poliBpjs,
                              jk.DOKTER as dokterId,
                              COALESCE(dok.NIP, '') as nipDokter,
                              peg.NAMA as namaDokter,
                              h.KD_DOKTER as dokterBpjs,
                              jk.NOMOR_BOOKING as noBooking,
                              jk.NOMOR_ANTRIAN as noAntrian,
                              jk.SUDAH_DIGUNAKAN as isUsed,
                              DATEDIFF(jk.TANGGAL, CURDATE()) as sisaHari
                           FROM medicalrecord.jadwal_kontrol jk
                           JOIN pendaftaran.kunjungan k ON k.NOMOR = jk.KUNJUNGAN
                           JOIN pendaftaran.pendaftaran p ON p.NOMOR = k.NOPEN
                           LEFT JOIN master.ruangan r ON r.ID = jk.TUJUAN
                           LEFT JOIN master.ruangan r_asal ON r_asal.ID = jk.RUANGAN
                           LEFT JOIN master.penjamin_ruangan pr ON pr.RUANGAN_RS = COALESCE(r.ID, r_asal.ID) AND pr.PENJAMIN = 2 AND pr.STATUS = 1
                           LEFT JOIN regonline.poli_bpjs pb ON pb.ID = COALESCE(r.ID, r_asal.ID) OR pb.NMPOLI = r.DESKRIPSI
                           LEFT JOIN master.dokter dok ON dok.ID = jk.DOKTER
                           LEFT JOIN master.pegawai peg ON peg.NIP = dok.NIP
                           LEFT JOIN regonline.jadwal_dokter_hfis h ON (h.NM_DOKTER LIKE CONCAT('%', peg.NAMA, '%') OR peg.NAMA LIKE CONCAT('%', h.NM_DOKTER, '%'))
                           WHERE p.NORM = :norm AND jk.STATUS = 1 
                             AND jk.NOMOR_REFERENSI IS NOT NULL AND TRIM(jk.NOMOR_REFERENSI) != ''
                           GROUP BY jk.ID
                           ORDER BY jk.TANGGAL DESC
                           LIMIT 10";
            $stmtK = $pdo->prepare($sqlKontrol);
            $stmtK->execute([':norm' => $norm]);
            $suratKontrol = $stmtK->fetchAll(PDO::FETCH_ASSOC);

            // Cek surat rujukan tersimpan lokal
            $sqlRujuk = "SELECT NOMOR, TANGGAL, DOKTER, DIAGNOSA_MASUK 
                         FROM pendaftaran.surat_rujukan_pasien 
                         WHERE NORM = :norm AND STATUS = 1
                         ORDER BY TANGGAL DESC LIMIT 5";
            $stmtR = $pdo->prepare($sqlRujuk);
            $stmtR->execute([':norm' => $norm]);
            $rujukan = $stmtR->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'       => true,
                'no_kartu'      => $noKartuBpjs,
                'nik'           => $auth['nik'] ?? '',
                'surat_kontrol' => $suratKontrol,
                'rujukan'       => $rujukan
            ]);
            break;

        /**
         * 4B. Cari rujukan langsung ke server BPJS Kesehatan (VClaim via WebService RegOnline)
         */
        case 'cari_rujukan_bpjs':
            $noKartu = trim($_GET['no_kartu'] ?? ($_POST['no_kartu'] ?? ''));
            if (empty($noKartu)) {
                $stK = $pdo->prepare("SELECT NOMOR FROM master.kartu_asuransi_pasien WHERE NORM = :norm AND JENIS = 2 LIMIT 1");
                $stK->execute([':norm' => $norm]);
                $noKartu = trim((string)$stK->fetchColumn());
            }

            if (empty($noKartu)) {
                echo json_encode(['success' => false, 'message' => 'Nomor kartu BPJS tidak ditemukan. Silakan masukkan nomor kartu BPJS (13 digit).']);
                exit;
            }

            $wsResourceFile = __DIR__ . '/../../RegOnline/app/ResourceWs.php';
            $rujukanList = [];
            $errMsg = 'Rujukan BPJS tidak ditemukan atau sudah kedaluwarsa.';

            if (file_exists($wsResourceFile)) {
                try {
                    require_once $wsResourceFile;
                    $token = getApiToken('getToken', 'POST');
                    if (!empty($token) && strlen($token) > 20) {
                        // 1. Cek Faskes 2 (RS / Fasilitas Kesehatan Tingkat 2)
                        $raw2 = sendRequest("getListRujukanKartu?faskes=2&noKartu=" . urlencode($noKartu), "GET", "", $token);
                        $res2 = json_decode($raw2, true);
                        if ($res2 && !empty($res2['success']) && !empty($res2['data']['rujukan'])) {
                            $rujukanList = array_merge($rujukanList, $res2['data']['rujukan']);
                        }

                        // 2. Cek Faskes 1 (Puskesmas / Dokter Keluarga / Faskes Tingkat 1)
                        $raw1 = sendRequest("getListRujukanKartu?faskes=1&noKartu=" . urlencode($noKartu), "GET", "", $token);
                        $res1 = json_decode($raw1, true);
                        if ($res1 && !empty($res1['success']) && !empty($res1['data']['rujukan'])) {
                            $rujukanList = array_merge($rujukanList, $res1['data']['rujukan']);
                        }

                        if ($res2 && isset($res2['message'])) $errMsg = $res2['message'];
                        if ($res1 && isset($res1['message'])) $errMsg = $res1['message'];
                    }
                } catch (Exception $eWs) {
                    $errMsg = 'Gagal terhubung ke WebService BPJS: ' . $eWs->getMessage();
                }
            }

            // Format dan hitung masa berlaku rujukan (90 hari dari tanggal rujukan sesuai regulasi BPJS)
            $formattedRujukan = [];
            foreach ($rujukanList as $r) {
                $noKunjungan = $r['noKunjungan'] ?? '';
                if (empty($noKunjungan)) continue;

                $provNama = $r['provPerujuk']['nama'] ?? ($r['provUmum']['nmProvider'] ?? 'Faskes Perujuk');
                $poliKode = $r['poliRujukan']['kode'] ?? '';
                $poliNama = $r['poliRujukan']['nama'] ?? '';
                $tglRujuk = $r['tglKunjungan'] ?? date('Y-m-d');

                // Hitung masa berlaku rujukan 90 hari
                $tglRujukTime = strtotime($tglRujuk);
                $tglExpiredTime = strtotime('+90 days', $tglRujukTime);
                $isExpired = ($tglExpiredTime < strtotime('today'));
                $sisaHari = (int)ceil(($tglExpiredTime - strtotime('today')) / 86400);

                $formattedRujukan[] = [
                    'no_kunjungan'   => $noKunjungan,
                    'tgl_kunjungan'  => $tglRujuk,
                    'tgl_kadaluarsa' => date('Y-m-d', $tglExpiredTime),
                    'is_expired'     => $isExpired,
                    'sisa_hari'      => $sisaHari,
                    'prov_nama'      => $provNama,
                    'prov_kode'      => $r['provPerujuk']['kode'] ?? '',
                    'poli_kode'      => $poliKode,
                    'poli_nama'      => $poliNama,
                    'diagnosa_kode'  => $r['diagnosa']['kode'] ?? '',
                    'diagnosa_nama'  => $r['diagnosa']['nama'] ?? '',
                    'keluhan'        => $r['keluhan'] ?? '',
                    'peserta_nama'   => $r['peserta']['nama'] ?? '',
                    'peserta_kartu'  => $r['peserta']['noKartu'] ?? $noKartu,
                    'status_peserta' => $r['peserta']['statusPeserta']['keterangan'] ?? 'AKTIF',
                    'asal_faskes'    => ($r['asalFaskes'] ?? '1') == '2' ? 'Faskes 2 (RS)' : 'Faskes 1 (Puskesmas/Klinik)'
                ];
            }

            // Urutkan rujukan: yang masih aktif dan paling baru di atas!
            usort($formattedRujukan, function($a, $b) {
                if ($a['is_expired'] !== $b['is_expired']) {
                    return $a['is_expired'] ? 1 : -1;
                }
                return strcmp($b['tgl_kunjungan'], $a['tgl_kunjungan']);
            });

            if (!empty($formattedRujukan)) {
                echo json_encode([
                    'success'  => true,
                    'no_kartu' => $noKartu,
                    'total'    => count($formattedRujukan),
                    'data'     => $formattedRujukan
                ]);
            } else {
                echo json_encode([
                    'success'  => false,
                    'no_kartu' => $noKartu,
                    'message'  => $errMsg
                ]);
            }
            break;

        /**
         * 5. Buat reservasi booking antrean baru (RegOnline)
         */
        case 'book_antrean':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                exit;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $tglKunjungan = $input['tanggal_kunjungan'] ?? '';
            $poliId       = $input['poli_id'] ?? '';
            $poliBpjs     = trim($input['poli_bpjs'] ?? '');
            $dokterId     = $input['dokter_id'] ?? '';
            $dokterBpjs   = trim($input['dokter_bpjs'] ?? '');
            $caraBayar    = (int)($input['cara_bayar'] ?? 1); // 1 = Umum, 2 = BPJS
            $noKartuBpjs  = trim($input['no_kartu_bpjs'] ?? '');
            $noRujukan    = trim($input['no_rujukan'] ?? '');
            $jamPraktek   = trim($input['jam_praktek'] ?? '08:00-12:00');

            // Deteksi otomatis Jenis Kunjungan: 3 = Kontrol BPJS / SKDP, 1 = Rujukan FKTP / Baru, 2 = Internal
            $jenisKunjunganInput = (int)($input['jenis_kunjungan'] ?? 0);
            if ($jenisKunjunganInput > 0) {
                $jenisKunjungan = $jenisKunjunganInput;
            } else {
                $isSkdp = (!empty($noRujukan) && (stripos($noRujukan, 'K') !== false || strlen($noRujukan) >= 15));
                $jenisKunjungan = $isSkdp ? 3 : (!empty($noRujukan) ? 1 : 2);
            }

            if (empty($tglKunjungan) || empty($poliId)) {
                echo json_encode(['success' => false, 'message' => 'Tanggal kunjungan dan poliklinik wajib dipilih']);
                exit;
            }

            // Validasi tanggal: minimal H+1 dan maksimal H+30
            $tglTime = strtotime($tglKunjungan);
            if ($tglTime < strtotime('today')) {
                echo json_encode(['success' => false, 'message' => 'Tanggal kunjungan tidak boleh di masa lampau']);
                exit;
            }

            // Jangan booking hari Minggu
            if (date('N', $tglTime) == 7) {
                echo json_encode(['success' => false, 'message' => 'Poliklinik tutup pada hari Minggu']);
                exit;
            }

            // Cek apakah pasien sudah punya booking aktif di poli & tanggal yang sama
            $stmtCheck = $pdo->prepare("SELECT ID FROM regonline.reservasi 
                                        WHERE NORM = :norm AND POLI = :poli AND TANGGALKUNJUNGAN = :tgl AND STATUS = 1");
            $stmtCheck->execute([':norm' => $norm, ':poli' => $poliId, ':tgl' => $tglKunjungan]);
            if ($stmtCheck->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Anda sudah memiliki antrean aktif untuk poliklinik dan tanggal tersebut.']);
                exit;
            }

            // Ambil data profil pasien
            $stmtP = $pdo->prepare("SELECT * FROM master.pasien WHERE NORM = :norm");
            $stmtP->execute([':norm' => $norm]);
            $pasien = $stmtP->fetch(PDO::FETCH_ASSOC);

            // Ambil NIK dari kartu_identitas_pasien
            $nikVal = !empty($auth['nik']) && $auth['nik'] !== '-' ? $auth['nik'] : '';
            if (empty($nikVal)) {
                $stNik = $pdo->prepare("SELECT NOMOR FROM master.kartu_identitas_pasien WHERE NORM = :norm AND JENIS = 1 LIMIT 1");
                $stNik->execute([':norm' => $norm]);
                $nikVal = $stNik->fetchColumn() ?: '';
            }

            // Ambil no kontak pasien dari master.kontak_pasien
            $contactVal = !empty($auth['telepon']) ? $auth['telepon'] : '';
            if (empty($contactVal)) {
                $stHp = $pdo->prepare("SELECT NOMOR FROM master.kontak_pasien WHERE NORM = :norm AND JENIS IN (1, 2, 3) AND NOMOR IS NOT NULL AND TRIM(NOMOR) != '' LIMIT 1");
                $stHp->execute([':norm' => $norm]);
                $contactVal = $stHp->fetchColumn() ?: '';
            }

            // Ambil data poliklinik & kode BPJS jika belum ada
            $stmtPoli = $pdo->prepare("SELECT lr.DESKRIPSI, lr.ANTRIAN, lr.MULAI, pb.KDPOLI 
                                       FROM regonline.link_ruangan lr
                                       LEFT JOIN regonline.poli_bpjs pb ON pb.ID = lr.ID OR pb.NMPOLI = lr.DESKRIPSI
                                       WHERE lr.ID = :id LIMIT 1");
            $stmtPoli->execute([':id' => $poliId]);
            $poliInfo = $stmtPoli->fetch(PDO::FETCH_ASSOC);
            $posAntrian = $poliInfo['ANTRIAN'] ?: 'A';
            if (empty($poliBpjs) && !empty($poliInfo['KDPOLI'])) {
                $poliBpjs = $poliInfo['KDPOLI'];
            }
            if (empty($poliBpjs)) {
                $stmtPr = $pdo->prepare("SELECT RUANGAN_PENJAMIN FROM master.penjamin_ruangan WHERE RUANGAN_RS = :poli AND PENJAMIN = 2 AND STATUS = 1 LIMIT 1");
                $stmtPr->execute([':poli' => $poliId]);
                $poliBpjs = $stmtPr->fetchColumn() ?: '';
            }

            // Ambil kode dokter BPJS dari HFIS jika belum ada
            if (empty($dokterBpjs)) {
                $dayOfWeek = (int)date('N', $tglTime);
                $stDrBpjs = $pdo->prepare("SELECT h.KD_DOKTER, h.KD_POLI 
                                           FROM master.dokter d 
                                           JOIN master.pegawai peg ON peg.NIP = d.NIP
                                           JOIN regonline.jadwal_dokter_hfis h ON (h.NM_DOKTER LIKE CONCAT('%', peg.NAMA, '%') OR peg.NAMA LIKE CONCAT('%', h.NM_DOKTER, '%')) AND h.HARI = :hari
                                           WHERE d.ID = :dokter_id LIMIT 1");
                $stDrBpjs->execute([':dokter_id' => $dokterId, ':hari' => $dayOfWeek]);
                $drRow = $stDrBpjs->fetch(PDO::FETCH_ASSOC);
                if ($drRow) {
                    $dokterBpjs = $drRow['KD_DOKTER'];
                    if (empty($poliBpjs) && !empty($drRow['KD_POLI'])) {
                        $poliBpjs = $drRow['KD_POLI'];
                    }
                }
            }

            // =========================================================
            // 1. CARA RESMI: Panggil WebService RegOnline SIMRS RSUP
            // =========================================================
            $wsSuccess = false;
            $wsResult = null;
            $rawWs = false;
            $wsResourceFile = __DIR__ . '/../../RegOnline/app/ResourceWs.php';
            if (file_exists($wsResourceFile)) {
                try {
                    require_once $wsResourceFile;
                    $token = getApiToken('getToken', 'POST');
                    if (!empty($token) && strlen($token) > 20) {
                        $wsData = [
                            'JENIS'            => 1, // 1 = Pasien Lama
                            'NIK'              => $nikVal,
                            'NORM'             => (string)$norm,
                            'NAMA'             => $pasien['NAMA'] ?: $auth['nama'],
                            'TANGGAL_LAHIR'    => $pasien['TANGGAL_LAHIR'] ?: '1990-01-01',
                            'TEMPAT_LAHIR'     => $pasien['TEMPAT_LAHIR'] ?: '-',
                            'CONTACT'          => $contactVal ?: '081234567890',
                            'POLI'             => (string)$poliId,
                            'POLI_BPJS'        => $poliBpjs ?: 'IRM',
                            'CARABAYAR'        => (string)$caraBayar,
                            'JENIS_KUNJUNGAN'  => (string)$jenisKunjungan,
                            'NO_KARTU_BPJS'    => $noKartuBpjs,
                            'NO_REF_BPJS'      => $noRujukan,
                            'TANGGALKUNJUNGAN' => $tglKunjungan,
                            'JENIS_APLIKASI'   => 3, // 3 = Web (RegOnline)
                            'STATUS'           => 1,
                            'DOKTER'           => $dokterBpjs ?: ($dokterId ?: '0'),
                            'JAM_PRAKTEK'      => $jamPraktek
                        ];
                        $rawWs = sendRequest('createAntrian', 'POST', json_encode($wsData), $token);
                        $wsResult = json_decode($rawWs, true);
                        if ($wsResult && (!empty($wsResult['success']) || (isset($wsResult['metadata']['code']) && $wsResult['metadata']['code'] == 200))) {
                            $wsSuccess = true;
                        }
                    }
                } catch (Exception $eWs) {
                    $wsSuccess = false;
                }
            }

            if ($wsSuccess && $wsResult) {
                $res = $wsResult['response'] ?? [];
                echo json_encode([
                    'success'      => true,
                    'message'      => $wsResult['metadata']['message'] ?? 'Pendaftaran antrean online berhasil!',
                    'kode_booking' => $res['kodebooking'] ?? '',
                    'no_antrian'   => $res['antreanpoli'] ?? '',
                    'pos_loket'    => $res['nomorantrean'] ?? '',
                    'tanggal'      => date('d M Y', $tglTime),
                    'poli'         => $res['namapolirs'] ?? ($res['namapoli'] ?? $poliInfo['DESKRIPSI'])
                ]);
                exit;
            }

            // Jika WS aktif dan mengembalikan pesan penolakan bisnis (misal: Kuota Dokter Penuh / Rujukan Tidak Sesuai)
            if ($rawWs !== false && is_array($wsResult) && isset($wsResult['metadata']['code']) && in_array((int)$wsResult['metadata']['code'], [201, 202, 401, 422])) {
                echo json_encode([
                    'success' => false,
                    'message' => $wsResult['metadata']['message'] ?? 'Pendaftaran antrean tidak dapat diproses oleh sistem rumah sakit.'
                ]);
                exit;
            }

            // =========================================================
            // 2. FALLBACK DATABASE DIRECT INSERT (Jika WS offline / return invalid)
            // =========================================================
            $pdo->beginTransaction();

            $stmtGenId = $pdo->prepare("INSERT INTO regonline.idreservasi (TANGGAL) VALUES (:tgl)");
            $stmtGenId->execute([':tgl' => $tglKunjungan]);
            $nomorUrutBooking = (int)$pdo->lastInsertId();
            $kodeBooking = date('ymd', $tglTime) . str_pad($nomorUrutBooking, 4, '0', STR_PAD_LEFT);

            $stmtGenLoket = $pdo->prepare("INSERT INTO regonline.noantrian (TANGGAL, POS) VALUES (:tgl, :pos)");
            $stmtGenLoket->execute([':tgl' => $tglKunjungan, ':pos' => $posAntrian]);
            $noAntrianLoket = (int)$pdo->lastInsertId();

            $stmtGenPoli = $pdo->prepare("INSERT INTO regonline.noantrian_poli (TANGGAL, POLI) VALUES (:tgl, :poli)");
            $stmtGenPoli->execute([':tgl' => $tglKunjungan, ':poli' => $poliId]);
            $noAntrianPoli = (int)$pdo->lastInsertId();

            $jamMulai = $poliInfo['MULAI'] ?: '08:00:00';
            $estimasiMenit = ($noAntrianPoli - 1) * 15;
            $estimasiJam = date('H:i:s', strtotime($jamMulai) + ($estimasiMenit * 60));
            $jamStr = substr($jamPraktek, 0, 5);

            // Simpan ke regonline.reservasi (Semua placeholder parameter harus unik untuk PDO)
            $sqlInsert = "INSERT INTO regonline.reservasi 
                          (ID, TANGGALKUNJUNGAN, TANGGAL_REF, NORM, NIK, NAMA, TEMPAT_LAHIR, TANGGAL_LAHIR, ALAMAT, 
                           POLI, POLI_BPJS, DOKTER, CARABAYAR, JENIS_KUNJUNGAN, NO_KARTU_BPJS, CONTACT, NO, ANTRIAN_POLI, 
                           NOMOR_ANTRIAN, JAM, JAM_PELAYANAN, ESTIMASI_PELAYANAN, POS_ANTRIAN, JENIS, 
                           NO_REF_BPJS, JENIS_APLIKASI, STATUS, JAM_PRAKTEK)
                          VALUES 
                          (:id, :tglKunjungan, NOW(), :norm, :nik, :nama, :tmpLahir, :tglLahir, :alamat,
                           :poli, :poliBpjs, :dokter, :carabayar, :jenisKunjungan, :noKartu, :contact, :noLoket, :noPoli,
                           :noPoliDua, :jamA, :jamB, :estimasi, :pos, 1,
                           :noRujukan, 3, 1, :jamPraktek)";

            $stmtIns = $pdo->prepare($sqlInsert);
            $stmtIns->execute([
                ':id'             => $kodeBooking,
                ':tglKunjungan'   => $tglKunjungan,
                ':norm'           => $norm,
                ':nik'            => $nikVal,
                ':nama'           => $pasien['NAMA'] ?: $auth['nama'],
                ':tmpLahir'       => $pasien['TEMPAT_LAHIR'] ?: '',
                ':tglLahir'       => $pasien['TANGGAL_LAHIR'] ?: '1990-01-01',
                ':alamat'         => $pasien['ALAMAT'] ?: '',
                ':poli'           => $poliId,
                ':poliBpjs'       => !empty($poliBpjs) ? $poliBpjs : null,
                ':dokter'         => $dokterBpjs ?: ($dokterId ?: '0'),
                ':carabayar'      => $caraBayar,
                ':jenisKunjungan' => $jenisKunjungan,
                ':noKartu'        => $noKartuBpjs,
                ':contact'        => $contactVal,
                ':noLoket'        => $noAntrianLoket,
                ':noPoli'         => $noAntrianPoli,
                ':noPoliDua'      => $noAntrianPoli,
                ':jamA'           => $jamStr,
                ':jamB'           => $jamStr,
                ':estimasi'       => $estimasiJam,
                ':pos'            => $posAntrian,
                ':noRujukan'      => $noRujukan,
                ':jamPraktek'     => $jamPraktek
            ]);

            // Sync nomor booking ke jadwal_kontrol jika ada
            if (!empty($noRujukan)) {
                $posLoketStr = $posAntrian . ($caraBayar == 2 ? '2' : '1') . '-' . str_pad($noAntrianLoket, 3, '0', STR_PAD_LEFT);
                $stUpdJk = $pdo->prepare("UPDATE medicalrecord.jadwal_kontrol 
                                          SET NOMOR_BOOKING = :booking, NOMOR_ANTRIAN = :noAntri 
                                          WHERE NOMOR_REFERENSI = :ref AND STATUS = 1");
                $stUpdJk->execute([':booking' => $kodeBooking, ':noAntri' => $posLoketStr, ':ref' => $noRujukan]);
            }

            $pdo->commit();

            echo json_encode([
                'success'      => true,
                'message'      => 'Pendaftaran antrean online berhasil!',
                'kode_booking' => $kodeBooking,
                'no_antrian'   => $noAntrianPoli,
                'pos_loket'    => $posAntrian . ($caraBayar == 2 ? '2' : '1') . '-' . str_pad($noAntrianLoket, 3, '0', STR_PAD_LEFT),
                'tanggal'      => date('d M Y', $tglTime),
                'poli'         => $poliInfo['DESKRIPSI']
            ]);
            break;

        /**
         * 6. Batalkan booking antrean online
         */
        case 'cancel_antrean':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                exit;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $bookingId = trim($input['kode_booking'] ?? '');

            if (empty($bookingId)) {
                echo json_encode(['success' => false, 'message' => 'Kode booking tidak valid']);
                exit;
            }

            $stmtCancel = $pdo->prepare("UPDATE regonline.reservasi SET STATUS = 0 WHERE ID = :id AND NORM = :norm");
            $stmtCancel->execute([':id' => $bookingId, ':norm' => $norm]);

            if ($stmtCancel->rowCount() > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Antrean online berhasil dibatalkan.'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Gagal membatalkan atau tiket tidak ditemukan.'
                ]);
            }
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali']);
            break;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
