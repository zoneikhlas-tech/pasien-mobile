<?php
/**
 * Portal Pasien - RSUP dr. Ben Mboi Kupang
 * API Dashboard Ringkasan Rawat Inap, Rawat Jalan, Diagnosa, Jadwal Kontrol & Antrian
 */

require_once __DIR__ . '/../config.php';

$user = PasienConfig::requireAuth();
$norm = (int)$user['norm'];
$action = $_GET['action'] ?? 'summary';

$pdoDaftar = PasienConfig::getConnection('pendaftaran');
$pdoMedrec = PasienConfig::getConnection('medicalrecord');
$pdoMaster = PasienConfig::getConnection('master');
$pdoLaporan = PasienConfig::getConnection('laporan');
$pdoRegonline = PasienConfig::getConnection('regonline');

if ($action === 'summary') {
    $hInfo = PasienConfig::getHospitalInfo();
    $tzLabel = PasienConfig::getTimezoneLabel();

    $result = [
        'pasien' => $user,
        'hospital' => $hInfo,
        'status_rawat' => 'RAWAT_JALAN', // RAWAT_INAP | RAWAT_JALAN
        'ranap_aktif' => null,
        'ttv_terakhir' => null,
        'kunjungan_terakhir' => null,
        'riwayat_kunjungan' => [],
        'jadwal_kontrol_aktif' => null,
        'riwayat_kontrol' => [],
        'antrian_hari_ini' => null,
        'info_layanan' => [
            'rs_nama' => $hInfo['nama_rs'],
            'call_center' => !empty($hInfo['telepon']) ? $hInfo['telepon'] : '(0380) 8553900',
            'nurse_station' => 'Tekan bel perawat di samping tempat tidur untuk bantuan segera',
            'jam_visite' => "08:30 - 13:00 {$tzLabel}",
            'jam_kunjung' => "Siang: 11:00 - 13:00 | Sore: 17:00 - 19:00 {$tzLabel}"
        ]
    ];

    if ($pdoDaftar !== null) {
        try {
            // 1. Cek Kunjungan Rawat Inap Aktif
            $stmtRanap = $pdoDaftar->prepare("
                SELECT 
                    k.NOMOR AS KUNJUNGAN_ID,
                    k.NOPEN,
                    k.RUANGAN AS RUANGAN_ID,
                    r.DESKRIPSI AS RUANGAN,
                    COALESCE(rk.KAMAR, '-') AS KAMAR,
                    COALESCE(rkt.TEMPAT_TIDUR, '-') AS NO_BED,
                    DATE_FORMAT(k.MASUK, '%d-%m-%Y %H:%i') AS TGL_MASUK,
                    k.MASUK AS RAW_MASUK,
                    GREATEST(1, DATEDIFF(CURDATE(), DATE(k.MASUK)) + 1) AS LAMA_RAWAT,
                    COALESCE(ref_pj.DESKRIPSI, 'BPJS Kesehatan') AS PENJAMIN,
                    COALESCE(
                        TRIM(CONCAT(IF(COALESCE(peg_dok.GELAR_DEPAN,'') != '', CONCAT(peg_dok.GELAR_DEPAN, '. '), ''), peg_dok.NAMA, IF(COALESCE(peg_dok.GELAR_BELAKANG,'') != '', CONCAT(', ', peg_dok.GELAR_BELAKANG), ''))),
                        TRIM(CONCAT(IF(COALESCE(peg_dir.GELAR_DEPAN,'') != '', CONCAT(peg_dir.GELAR_DEPAN, '. '), ''), peg_dir.NAMA, IF(COALESCE(peg_dir.GELAR_BELAKANG,'') != '', CONCAT(', ', peg_dir.GELAR_BELAKANG), ''))),
                        'Dokter DPJP'
                    ) AS NAMA_DPJP,
                    COALESCE(dm.DIAGNOSA, '-') AS DIAGNOSA_MASUK
                FROM pendaftaran.kunjungan k
                JOIN pendaftaran.pendaftaran p ON p.NOMOR = k.NOPEN
                JOIN master.ruangan r ON r.ID = k.RUANGAN
                LEFT JOIN master.ruang_kamar_tidur rkt ON rkt.ID = k.RUANG_KAMAR_TIDUR
                LEFT JOIN master.ruang_kamar rk ON rk.ID = rkt.RUANG_KAMAR
                LEFT JOIN master.dokter dok ON dok.ID = k.DPJP
                LEFT JOIN master.pegawai peg_dok ON peg_dok.NIP = dok.NIP
                LEFT JOIN master.pegawai peg_dir ON peg_dir.ID = k.DPJP
                LEFT JOIN master.diagnosa_masuk dm ON dm.ID = p.DIAGNOSA_MASUK
                LEFT JOIN pendaftaran.penjamin pj ON pj.NOPEN = p.NOMOR
                LEFT JOIN master.referensi ref_pj ON ref_pj.JENIS = 10 AND ref_pj.ID = pj.JENIS
                WHERE p.NORM = :norm 
                  AND k.STATUS = 1 
                  AND r.JENIS_KUNJUNGAN IN (2, 3)
                  AND NOT EXISTS (
                      SELECT 1 FROM layanan.pasien_pulang pp 
                      WHERE pp.KUNJUNGAN = k.NOMOR AND pp.STATUS = 1
                  )
                ORDER BY k.MASUK DESC 
                LIMIT 1
            ");
            $stmtRanap->execute([':norm' => $norm]);
            $ranap = $stmtRanap->fetch(PDO::FETCH_ASSOC);

            if ($ranap) {
                $result['status_rawat'] = 'RAWAT_INAP';
                $ranap['tgl_masuk_indo'] = PasienConfig::formatTglIndo($ranap['RAW_MASUK'], true);
                $result['ranap_aktif'] = $ranap;

                // Ambil Tanda Vital Terakhir di Rawat Inap
                if ($pdoMedrec !== null) {
                    $stmtTv = $pdoMedrec->prepare("
                        SELECT 
                            DATE_FORMAT(WAKTU_PEMERIKSAAN, '%d-%m-%Y %H:%i') AS WAKTU,
                            SISTOLIK, DISTOLIK, SUHU, FREKUENSI_NADI, FREKUENSI_NAFAS, SATURASI_O2,
                            EWSS, PEWSS
                        FROM medicalrecord.tanda_vital
                        WHERE KUNJUNGAN = :kunjungan AND STATUS = 1
                        ORDER BY WAKTU_PEMERIKSAAN DESC, ID DESC
                        LIMIT 1
                    ");
                    $stmtTv->execute([':kunjungan' => $ranap['KUNJUNGAN_ID']]);
                    $tv = $stmtTv->fetch(PDO::FETCH_ASSOC);
                    if ($tv) {
                        $result['ttv_terakhir'] = [
                            'waktu' => $tv['WAKTU'],
                            'tensi' => (!empty($tv['SISTOLIK']) && !empty($tv['DISTOLIK'])) ? ((int)$tv['SISTOLIK'] . '/' . (int)$tv['DISTOLIK'] . ' mmHg') : '-',
                            'suhu' => !empty($tv['SUHU']) ? ((float)$tv['SUHU'] . ' °C') : '-',
                            'nadi' => !empty($tv['FREKUENSI_NADI']) ? ((int)$tv['FREKUENSI_NADI'] . ' x/mnt') : '-',
                            'spo2' => !empty($tv['SATURASI_O2']) ? ((int)$tv['SATURASI_O2'] . ' %') : '-',
                            'status_kondisi' => ((int)($tv['EWSS'] ?? 0) <= 2) ? 'Stabil & Terpantau Baik' : 'Dalam Pengawasan Khusus Tim Medis'
                        ];
                    }
                }
            }

            // 2. Ambil Riwayat Kunjungan per Episode Pelayanan Rumah Sakit
            // Menyaring hanya kunjungan pelayanan utama (Rawat Inap, Rawat Jalan, Gawat Darurat, VK, HD, Kemo)
            // Mengabaikan layanan penunjang internal (Laboratorium, Radiologi, Farmasi) agar tidak menduplikasi riwayat
            // Menggabungkan episode yang telah dilebur ke rawat inap melalui pembayaran.gabung_tagihan
            $stmtHist = $pdoDaftar->prepare("
                SELECT 
                    p.NOMOR AS NOPEN,
                    MIN(k.MASUK) AS RAW_MASUK,
                    DATE_FORMAT(MIN(k.MASUK), '%d-%m-%Y') AS TGL_KUNJUNGAN,
                    MAX(k.KELUAR) AS RAW_KELUAR,
                    DATE_FORMAT(MAX(k.KELUAR), '%d-%m-%Y') AS TGL_KELUAR,
                    
                    -- Tipe Layanan Episode (Prioritaskan Rawat Inap > Kamar Bersalin > Gawat Darurat > HD > Kemo > Rawat Jalan)
                    CASE 
                        WHEN MAX(CASE WHEN r.JENIS_KUNJUNGAN = 3 THEN 1 ELSE 0 END) = 1 THEN 'Rawat Inap'
                        WHEN MAX(CASE WHEN r.JENIS_KUNJUNGAN = 12 THEN 1 ELSE 0 END) = 1 THEN 'Kamar Bersalin'
                        WHEN MAX(CASE WHEN r.JENIS_KUNJUNGAN = 2 THEN 1 ELSE 0 END) = 1 THEN 'Gawat Darurat'
                        WHEN MAX(CASE WHEN r.JENIS_KUNJUNGAN = 7 THEN 1 ELSE 0 END) = 1 THEN 'Hemodialisa'
                        WHEN MAX(CASE WHEN r.JENIS_KUNJUNGAN = 20 THEN 1 ELSE 0 END) = 1 THEN 'Kemoterapi'
                        ELSE 'Rawat Jalan'
                    END AS TIPE_LAYANAN,

                    -- Unit Layanan Utama (Prioritaskan Kamar Rawat Inap jika ada, lalu Tujuan Pasien, lalu kunjungan pertama)
                    COALESCE(
                        MAX(CASE WHEN r.JENIS_KUNJUNGAN = 3 THEN r.DESKRIPSI END),
                        r_tjp.DESKRIPSI,
                        SUBSTRING_INDEX(GROUP_CONCAT(r.DESKRIPSI ORDER BY k.MASUK ASC SEPARATOR '|||'), '|||', 1),
                        'Poliklinik / Ruangan Rawat'
                    ) AS UNIT_LAYANAN,

                    -- DPJP / Dokter Pemeriksa Utama
                    COALESCE(
                        MAX(CASE WHEN r.JENIS_KUNJUNGAN = 3 THEN 
                            TRIM(CONCAT(IF(COALESCE(peg_dok.GELAR_DEPAN,'') != '', CONCAT(peg_dok.GELAR_DEPAN, '. '), ''), peg_dok.NAMA, IF(COALESCE(peg_dok.GELAR_BELAKANG,'') != '', CONCAT(', ', peg_dok.GELAR_BELAKANG), '')))
                        END),
                        TRIM(CONCAT(IF(COALESCE(peg_tjp.GELAR_DEPAN,'') != '', CONCAT(peg_tjp.GELAR_DEPAN, '. '), ''), peg_tjp.NAMA, IF(COALESCE(peg_tjp.GELAR_BELAKANG,'') != '', CONCAT(', ', peg_tjp.GELAR_BELAKANG), ''))),
                        SUBSTRING_INDEX(
                            GROUP_CONCAT(
                                TRIM(CONCAT(IF(COALESCE(peg_dok.GELAR_DEPAN,'') != '', CONCAT(peg_dok.GELAR_DEPAN, '. '), ''), peg_dok.NAMA, IF(COALESCE(peg_dok.GELAR_BELAKANG,'') != '', CONCAT(', ', peg_dok.GELAR_BELAKANG), '')))
                                ORDER BY k.MASUK ASC SEPARATOR '|||'
                            ),
                            '|||', 1
                        ),
                        'Dokter Pemeriksa'
                    ) AS DOKTER,

                    -- Status Kunjungan Episode
                    CASE 
                        WHEN MAX(CASE WHEN r.JENIS_KUNJUNGAN = 3 AND k.STATUS = 1 AND (k.KELUAR IS NULL OR k.KELUAR = '0000-00-00 00:00:00') THEN 1 ELSE 0 END) = 1 THEN 'Sedang Dirawat'
                        WHEN MAX(CASE WHEN EXISTS (SELECT 1 FROM layanan.pasien_pulang pp WHERE pp.KUNJUNGAN = k.NOMOR AND pp.STATUS = 1) THEN 1 ELSE 0 END) = 1 THEN 'Selesai / Pulang'
                        ELSE 'Selesai Pelayanan'
                    END AS STATUS_KUNJUNGAN,

                    -- Kunjungan ID referensi
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            k.NOMOR 
                            ORDER BY 
                                CASE WHEN r.JENIS_KUNJUNGAN = 3 THEN 10 ELSE 1 END DESC,
                                k.MASUK DESC 
                            SEPARATOR '|||'
                        ),
                        '|||', 1
                    ) AS KUNJUNGAN_ID,

                    -- Deteksi apakah pendaftaran ini menerima leburan pendaftaran lain (seperti IGD -> Rawat Inap)
                    (
                        SELECT GROUP_CONCAT(DISTINCT gt.DARI SEPARATOR ',')
                        FROM pembayaran.gabung_tagihan gt
                        WHERE gt.KE = p.NOMOR AND gt.STATUS = 1
                    ) AS MERGED_FROM_NOPENS

                FROM pendaftaran.pendaftaran p
                JOIN pendaftaran.kunjungan k ON k.NOPEN = p.NOMOR AND k.STATUS != 0
                JOIN master.ruangan r ON r.ID = k.RUANGAN AND r.JENIS_KUNJUNGAN IN (1, 2, 3, 6, 7, 12, 20)
                LEFT JOIN pendaftaran.tujuan_pasien tjp ON tjp.NOPEN = p.NOMOR
                LEFT JOIN master.ruangan r_tjp ON r_tjp.ID = tjp.RUANGAN
                LEFT JOIN master.dokter dok_tjp ON dok_tjp.ID = tjp.DOKTER
                LEFT JOIN master.pegawai peg_tjp ON peg_tjp.NIP = dok_tjp.NIP
                LEFT JOIN master.dokter dok ON dok.ID = k.DPJP
                LEFT JOIN master.pegawai peg_dok ON peg_dok.NIP = dok.NIP
                WHERE p.NORM = :norm
                GROUP BY p.NOMOR
                HAVING NOT EXISTS (
                    SELECT 1 FROM pembayaran.gabung_tagihan gt 
                    WHERE gt.DARI = p.NOMOR AND gt.STATUS = 1
                )
                ORDER BY RAW_MASUK DESC
                LIMIT 10
            ");
            $stmtHist->execute([':norm' => $norm]);
            $rawHist = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

            // Kumpulkan semua NOPEN (termasuk yang digabung) untuk query Diagnosa & Resume Medis secara batch
            $allQueryNopens = [];
            $episodeNopenMapping = []; // map sub-nopen -> episode nopen
            foreach ($rawHist as $item) {
                $epNopen = $item['NOPEN'];
                $allQueryNopens[] = $epNopen;
                $episodeNopenMapping[$epNopen] = $epNopen;

                if (!empty($item['MERGED_FROM_NOPENS'])) {
                    $subList = explode(',', $item['MERGED_FROM_NOPENS']);
                    foreach ($subList as $sn) {
                        $sn = trim($sn);
                        if ($sn !== '') {
                            $allQueryNopens[] = $sn;
                            $episodeNopenMapping[$sn] = $epNopen;
                        }
                    }
                }
            }
            $allQueryNopens = array_unique($allQueryNopens);

            // Query Diagnosa ICD-10
            $diagnosaMap = [];
            if (!empty($allQueryNopens) && $pdoMedrec !== null) {
                try {
                    $placeholders = implode(',', array_fill(0, count($allQueryNopens), '?'));
                    $stmtDiag = $pdoMedrec->prepare("
                        SELECT 
                            d.NOPEN,
                            d.KODE,
                            COALESCE(NULLIF(d.DIAGNOSA, ''), mc.STR, d.KODE, '-') AS DIAGNOSA,
                            d.UTAMA
                        FROM medicalrecord.diagnosa d
                        LEFT JOIN (
                            SELECT CODE, STR FROM master.mrconso WHERE SAB LIKE 'ICD10%' GROUP BY CODE
                        ) mc ON mc.CODE = d.KODE
                        WHERE d.NOPEN IN ($placeholders) AND d.STATUS = 1
                        ORDER BY d.NOPEN DESC, d.UTAMA ASC, d.ID ASC
                    ");
                    $stmtDiag->execute(array_values($allQueryNopens));
                    $diagRows = $stmtDiag->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($diagRows as $dr) {
                        $targetEpNopen = $episodeNopenMapping[$dr['NOPEN']] ?? $dr['NOPEN'];
                        if (!isset($diagnosaMap[$targetEpNopen])) {
                            $diagnosaMap[$targetEpNopen] = [
                                'utama' => null,
                                'list' => []
                            ];
                        }
                        $diagText = (!empty($dr['KODE']) ? ($dr['KODE'] . ' - ') : '') . $dr['DIAGNOSA'];
                        $isUtama = ((int)$dr['UTAMA'] === 1);
                        if ($isUtama && $diagnosaMap[$targetEpNopen]['utama'] === null) {
                            $diagnosaMap[$targetEpNopen]['utama'] = $diagText;
                        }
                        $diagnosaMap[$targetEpNopen]['list'][] = [
                            'kode' => $dr['KODE'],
                            'nama' => $dr['DIAGNOSA'],
                            'label' => $diagText,
                            'is_utama' => $isUtama
                        ];
                    }
                } catch (Exception $e) {}
            }

            // Query Resume Medis PDF
            $resumeMap = [];
            if (!empty($allQueryNopens) && $pdoLaporan !== null) {
                try {
                    $placeholders = implode(',', array_fill(0, count($allQueryNopens), '?'));
                    $stmtRes = $pdoLaporan->prepare("
                        SELECT ID, REF_ID AS NOPEN, DIBUAT_TANGGAL, TTD_TANGGAL
                        FROM laporan.request_report
                        WHERE DOCUMENT_DIRECTORY_ID = 2 AND REF_ID IN ($placeholders) AND STATUS = 2
                    ");
                    $stmtRes->execute(array_values($allQueryNopens));
                    $resRows = $stmtRes->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($resRows as $rr) {
                        $targetEpNopen = $episodeNopenMapping[$rr['NOPEN']] ?? $rr['NOPEN'];
                        $resumeMap[$targetEpNopen] = [
                            'report_id' => $rr['ID'],
                            'tgl_tte' => $rr['TTD_TANGGAL'] ?: $rr['DIBUAT_TANGGAL']
                        ];
                    }
                } catch (Exception $e) {}
            }

            foreach ($rawHist as &$hItem) {
                $hItem['tgl_indo'] = PasienConfig::formatTglIndo($hItem['RAW_MASUK'], false);
                $np = $hItem['NOPEN'];
                $hItem['diagnosa_utama'] = $diagnosaMap[$np]['utama'] ?? ($diagnosaMap[$np]['list'][0]['label'] ?? '-');
                $hItem['diagnosa_list'] = $diagnosaMap[$np]['list'] ?? [];
                $hItem['has_resume'] = isset($resumeMap[$np]);
                $hItem['resume_report_id'] = $resumeMap[$np]['report_id'] ?? null;
                $hItem['is_gabungan'] = !empty($hItem['MERGED_FROM_NOPENS']);
            }
            $result['riwayat_kunjungan'] = $rawHist;

            if (!empty($rawHist)) {
                $result['kunjungan_terakhir'] = $rawHist[0];
            }

            // 3. Ambil Jadwal Kontrol Pasien (Surat Rencana Kontrol / SKDP)
            if ($pdoMedrec !== null) {
                try {
                    $stmtCtrl = $pdoMedrec->prepare("
                        SELECT 
                            jk.ID,
                            jk.NOMOR_REFERENSI AS NO_SURAT_KONTROL,
                            jk.TANGGAL AS RAW_TANGGAL,
                            DATE_FORMAT(jk.TANGGAL, '%d-%m-%Y') AS TGL_KONTROL,
                            TIME_FORMAT(jk.JAM, '%H:%i') AS JAM_KONTROL,
                            COALESCE(r.ID, r_asal.ID, r_kunj.ID) AS ID_POLI,
                            COALESCE(pr.RUANGAN_PENJAMIN, pb.KDPOLI, '') AS KODE_POLI_BPJS,
                            CASE 
                                WHEN jk.TUJUAN NOT IN (1, '1') AND r.ID IS NOT NULL AND r.JENIS_KUNJUNGAN != 0 THEN r.DESKRIPSI
                                WHEN r_asal.ID IS NOT NULL AND r_asal.ID NOT IN (1, '1') THEN r_asal.DESKRIPSI
                                WHEN r_kunj.ID IS NOT NULL AND r_kunj.ID NOT IN (1, '1') THEN r_kunj.DESKRIPSI
                                ELSE COALESCE(r_asal.DESKRIPSI, r_kunj.DESKRIPSI, 'Poliklinik Rawat Jalan')
                            END AS POLI_TUJUAN,
                            jk.DOKTER AS ID_DOKTER,
                            h.KD_DOKTER AS KODE_DOKTER_BPJS,
                            COALESCE(
                                TRIM(CONCAT(IF(COALESCE(peg.GELAR_DEPAN,'') != '', CONCAT(peg.GELAR_DEPAN, '. '), ''), peg.NAMA, IF(COALESCE(peg.GELAR_BELAKANG,'') != '', CONCAT(', ', peg.GELAR_BELAKANG), ''))),
                                'Dokter Spesialis'
                            ) AS DOKTER,
                            COALESCE(jk.DESKRIPSI, '-') AS CATATAN_DOKTER,
                            jk.SUDAH_DIGUNAKAN,
                            jk.NOMOR_BOOKING,
                            jk.NOMOR_ANTRIAN,
                            DATEDIFF(jk.TANGGAL, CURDATE()) AS SISA_HARI
                        FROM medicalrecord.jadwal_kontrol jk
                        JOIN pendaftaran.kunjungan k ON k.NOMOR = jk.KUNJUNGAN
                        JOIN pendaftaran.pendaftaran p ON p.NOMOR = k.NOPEN
                        LEFT JOIN master.ruangan r ON r.ID = jk.TUJUAN
                        LEFT JOIN master.ruangan r_asal ON r_asal.ID = jk.RUANGAN
                        LEFT JOIN master.ruangan r_kunj ON r_kunj.ID = k.RUANGAN
                        LEFT JOIN master.penjamin_ruangan pr ON pr.RUANGAN_RS = COALESCE(r.ID, r_asal.ID, r_kunj.ID) AND pr.PENJAMIN = 2 AND pr.STATUS = 1
                        LEFT JOIN regonline.poli_bpjs pb ON pb.ID = COALESCE(r.ID, r_asal.ID, r_kunj.ID) OR pb.NMPOLI = r.DESKRIPSI
                        LEFT JOIN master.dokter dok ON dok.ID = jk.DOKTER
                        LEFT JOIN master.pegawai peg ON peg.NIP = dok.NIP
                        LEFT JOIN regonline.jadwal_dokter_hfis h ON (h.NM_DOKTER LIKE CONCAT('%', peg.NAMA, '%') OR peg.NAMA LIKE CONCAT('%', h.NM_DOKTER, '%'))
                        WHERE p.NORM = :norm AND jk.STATUS = 1
                        GROUP BY jk.ID
                        ORDER BY jk.TANGGAL DESC
                        LIMIT 15
                    ");
                    $stmtCtrl->execute([':norm' => $norm]);
                    $ctrlList = $stmtCtrl->fetchAll(PDO::FETCH_ASSOC);

                    $activeUpcoming = null;
                    $upcomingCandidates = [];
                    foreach ($ctrlList as &$c) {
                        $c['tgl_kontrol_indo'] = PasienConfig::formatTglIndo($c['RAW_TANGGAL'], true);
                        $sisa = (int)$c['SISA_HARI'];
                        if ($sisa === 0) {
                            $c['sisa_hari_label'] = 'Hari Ini';
                            $c['sisa_badge'] = 'bg-rose-500 text-white';
                        } elseif ($sisa === 1) {
                            $c['sisa_hari_label'] = 'Besok';
                            $c['sisa_badge'] = 'bg-amber-500 text-white';
                        } elseif ($sisa > 1) {
                            $c['sisa_hari_label'] = "{$sisa} Hari Lagi";
                            $c['sisa_badge'] = 'bg-teal-600 text-white';
                        } else {
                            $c['sisa_hari_label'] = 'Telah Lewat';
                            $c['sisa_badge'] = 'bg-slate-200 text-slate-700';
                        }

                        $isUsed = ((int)$c['SUDAH_DIGUNAKAN'] === 1);
                        $c['status_label'] = $isUsed ? 'Sudah Digunakan' : ($sisa >= 0 ? 'Aktif Menunggu Kontrol' : 'Kadaluarsa');

                        // Kumpulkan kandidat kontrol mendatang yang belum digunakan
                        if (!$isUsed && $sisa >= 0) {
                            $upcomingCandidates[] = $c;
                        }
                    }

                    // Pilih jadwal kontrol mendatang yang paling dekat (sisa hari terkecil)
                    if (!empty($upcomingCandidates)) {
                        usort($upcomingCandidates, function($a, $b) {
                            return (int)$a['SISA_HARI'] - (int)$b['SISA_HARI'];
                        });
                        $activeUpcoming = $upcomingCandidates[0];

                        // Cek apakah antrean untuk jadwal kontrol ini sudah didaftarkan / aktif di regonline.reservasi
                        if ($pdoRegonline !== null) {
                            try {
                                $stmtCekAntri = $pdoRegonline->prepare("SELECT ID, ANTRIAN_POLI, POS_ANTRIAN, NO as NO_LOKET, CARABAYAR, STATUS 
                                                                       FROM regonline.reservasi 
                                                                       WHERE NORM = :norm AND TANGGALKUNJUNGAN = :tgl AND (POLI = :poli OR NO_REF_BPJS = :skdp) AND STATUS = 1 LIMIT 1");
                                $stmtCekAntri->execute([
                                    ':norm' => $norm,
                                    ':tgl'  => $activeUpcoming['RAW_TANGGAL'],
                                    ':poli' => $activeUpcoming['ID_POLI'],
                                    ':skdp' => $activeUpcoming['NO_SURAT_KONTROL']
                                ]);
                                $resRow = $stmtCekAntri->fetch(PDO::FETCH_ASSOC);
                                if ($resRow) {
                                    $activeUpcoming['sudah_daftar_antrean'] = true;
                                    $activeUpcoming['booking_id'] = $resRow['ID'];
                                    $activeUpcoming['antrean_poli'] = $resRow['ANTRIAN_POLI'];
                                    $activeUpcoming['no_loket'] = ($resRow['POS_ANTRIAN'] ? $resRow['POS_ANTRIAN'] . ($resRow['CARABAYAR'] == 2 ? '2' : '1') . '-' : '') . str_pad($resRow['NO_LOKET'], 3, '0', STR_PAD_LEFT);
                                } else {
                                    $activeUpcoming['sudah_daftar_antrean'] = false;
                                }
                            } catch (Exception $eCek) {
                                $activeUpcoming['sudah_daftar_antrean'] = false;
                            }
                        }
                    }

                    $result['jadwal_kontrol_aktif'] = $activeUpcoming;
                    $result['riwayat_kontrol'] = $ctrlList;

                } catch (Exception $e) {
                    $result['error_kontrol'] = $e->getMessage();
                }
            }

            // 4. Cek Antrian Live Hari Ini (Live Queue Tracker)
            if ($pdoRegonline !== null) {
                try {
                    $stmtAntri = $pdoRegonline->prepare("
                        SELECT 
                            r.ID AS KODE_BOOKING,
                            r.ANTRIAN_POLI AS NOMOR_ANTRIAN,
                            r.POLI AS POLI_ID,
                            r.TANGGALKUNJUNGAN,
                            (r.TANGGALKUNJUNGAN = CURDATE()) AS IS_TODAY,
                            COALESCE(r_poli.DESKRIPSI, 'Poliklinik') AS POLI_NAMA,
                            COALESCE(r.JAM_PELAYANAN, '08:00 - Selesai') AS ESTIMASI_JAM,
                            COALESCE(pap.NOMOR, '-') AS SEDANG_DILAYANI,
                            GREATEST(0, (r.ANTRIAN_POLI - COALESCE(pap.NOMOR, 0))) AS SISA_ANTRIAN
                        FROM regonline.reservasi r
                        LEFT JOIN master.ruangan r_poli ON r_poli.ID = r.POLI
                        LEFT JOIN (
                            SELECT POLI, MAX(NOMOR) AS NOMOR 
                            FROM regonline.panggil_antrian_poli 
                            WHERE TANGGAL = CURDATE() 
                            GROUP BY POLI
                        ) pap ON pap.POLI = r.POLI
                        WHERE r.NORM = :norm 
                          AND r.TANGGALKUNJUNGAN >= CURDATE()
                          AND r.STATUS = 1
                        ORDER BY (r.TANGGALKUNJUNGAN = CURDATE()) DESC, r.TANGGALKUNJUNGAN ASC
                        LIMIT 1
                    ");
                    $stmtAntri->execute([':norm' => $norm]);
                    $antri = $stmtAntri->fetch(PDO::FETCH_ASSOC);

                    if ($antri && !empty($antri['NOMOR_ANTRIAN'])) {
                        $antri['tgl_indo'] = PasienConfig::formatIndonesianDay($antri['TANGGALKUNJUNGAN']) . ', ' . date('d M Y', strtotime($antri['TANGGALKUNJUNGAN']));
                        $result['antrian_hari_ini'] = $antri;
                    }
                } catch (Exception $e) {}
            }

        } catch (Exception $e) {
            $result['error_db'] = $e->getMessage();
        }
    }

    PasienConfig::jsonResponse([
        'success' => true,
        'data' => $result
    ]);
}
