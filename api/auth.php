<?php
/**
 * Portal Pasien - RSUP dr. Ben Mboi Kupang
 * API Autentikasi Pasien (Solusi 1: Verifikasi 3 Data Identitas Rahasia)
 * No. RM / NIK + Tanggal Lahir + 4 Digit Terakhir No. HP
 */

require_once __DIR__ . '/../config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'check';

if ($action === 'login') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $identifier = trim((string)($input['identifier'] ?? ''));
    $tglLahirInput = trim((string)($input['tgl_lahir'] ?? ''));
    $lastPhoneInput = trim((string)($input['last_phone'] ?? ''));

    if (empty($identifier)) {
        PasienConfig::jsonResponse([
            'success' => false,
            'message' => 'Silakan masukkan No. Rekam Medis (No. RM) atau 16 Digit NIK Anda.'
        ], 400);
    }

    if (empty($tglLahirInput)) {
        PasienConfig::jsonResponse([
            'success' => false,
            'message' => 'Silakan pilih Tanggal Lahir sesuai KTP / Kartu Berobat.'
        ], 400);
    }

    // Normalisasi Tanggal Lahir ke YYYY-MM-DD (Mendukung format ISO YYYY-MM-DD maupun DD-MM-YYYY / MM-DD-YYYY)
    $dobParsed = null;
    $dobAltParsed = null;
    $cleanDob = trim($tglLahirInput);
    if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $cleanDob, $m)) {
        // Format ISO standard HTML5 datepicker: YYYY-MM-DD
        $dobParsed = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    } elseif (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $cleanDob, $m)) {
        // Jika format 3 blok angka dengan tahun di belakang
        $part1 = (int)$m[1];
        $part2 = (int)$m[2];
        $year  = (int)$m[3];

        if ($part1 > 12) {
            // Pasti DD-MM-YYYY
            $dobParsed = sprintf('%04d-%02d-%02d', $year, $part2, $part1);
        } elseif ($part2 > 12) {
            // Pasti MM-DD-YYYY
            $dobParsed = sprintf('%04d-%02d-%02d', $year, $part1, $part2);
        } else {
            // Kedua angka <= 12 (ambigu): siapkan opsi utama DD-MM-YYYY dan opsi alternatif MM-DD-YYYY
            $dobParsed    = sprintf('%04d-%02d-%02d', $year, $part2, $part1); // Standar Indonesia: DD-MM-YYYY
            $dobAltParsed = sprintf('%04d-%02d-%02d', $year, $part1, $part2); // Standar US: MM-DD-YYYY
        }
    } else {
        $t = strtotime($cleanDob);
        if ($t && $t > 0) {
            $dobParsed = date('Y-m-d', $t);
        }
    }

    if (!$dobParsed || $dobParsed === '1970-01-01') {
        PasienConfig::jsonResponse([
            'success' => false,
            'message' => 'Format Tanggal Lahir tidak valid.'
        ], 400);
    }

    $pdoMaster = PasienConfig::getConnection('master');
    if ($pdoMaster === null) {
        PasienConfig::jsonResponse([
            'success' => false,
            'message' => 'Gagal terhubung ke database server rumah sakit. Coba beberapa saat lagi.'
        ], 500);
    }

    try {
        $norm = null;
        $cleanId = preg_replace('/[^0-9]/', '', $identifier);

        // 1. Cek apakah identifier adalah 16 Digit NIK
        if (strlen($cleanId) === 16) {
            $stmtNik = $pdoMaster->prepare("
                SELECT NORM 
                FROM master.kartu_identitas_pasien 
                WHERE JENIS = 1 AND NOMOR = :nik 
                ORDER BY NORM DESC 
                LIMIT 1
            ");
            $stmtNik->execute([':nik' => $cleanId]);
            $rowNik = $stmtNik->fetch(PDO::FETCH_ASSOC);
            if ($rowNik && !empty($rowNik['NORM'])) {
                $norm = (int)$rowNik['NORM'];
            }
        }

        // 2. Jika bukan NIK atau NIK belum terdaftar, coba sebagai No. RM (NORM)
        if (!$norm) {
            $norm = (int)ltrim($cleanId, '0');
        }

        if (!$norm) {
            PasienConfig::jsonResponse([
                'success' => false,
                'message' => 'Format No. RM atau NIK tidak valid.'
            ], 400);
        }

        // 3. Ambil data pasien dari master.pasien
        $stmtPasien = $pdoMaster->prepare("
            SELECT 
                p.NORM,
                p.NAMA,
                DATE(p.TANGGAL_LAHIR) AS TGL_LAHIR,
                p.TEMPAT_LAHIR,
                p.JENIS_KELAMIN,
                COALESCE(p.ALAMAT, '-') AS ALAMAT,
                p.GOLONGAN_DARAH
            FROM master.pasien p
            WHERE p.NORM = :norm AND p.STATUS = 1
            LIMIT 1
        ");
        $stmtPasien->execute([':norm' => $norm]);
        $pasien = $stmtPasien->fetch(PDO::FETCH_ASSOC);

        if (!$pasien) {
            PasienConfig::jsonResponse([
                'success' => false,
                'message' => 'Data pasien dengan No. RM / NIK tersebut tidak ditemukan dalam sistem RS.'
            ], 404);
        }

        // 4. Verifikasi Tanggal Lahir (Kerahasiaan Tingkat 2 - Mendukung format DD-MM maupun MM-DD)
        $dobMatched = ($pasien['TGL_LAHIR'] === $dobParsed) || (!empty($dobAltParsed) && $pasien['TGL_LAHIR'] === $dobAltParsed);
        if (!$dobMatched) {
            PasienConfig::jsonResponse([
                'success' => false,
                'message' => 'Tanggal Lahir tidak cocok dengan data rekam medis terdaftar.'
            ], 401);
        }

        // 5. Verifikasi 4 Digit Terakhir No. HP (Kerahasiaan Tingkat 3)
        $stmtHp = $pdoMaster->prepare("
            SELECT NOMOR 
            FROM master.kontak_pasien 
            WHERE NORM = :norm 
              AND JENIS IN (1, 2, 3) 
              AND NOMOR IS NOT NULL 
              AND TRIM(NOMOR) != ''
        ");
        $stmtHp->execute([':norm' => $norm]);
        $phoneRows = $stmtHp->fetchAll(PDO::FETCH_ASSOC);

        $hasRegisteredPhone = !empty($phoneRows);
        $cleanLast4 = preg_replace('/[^0-9]/', '', $lastPhoneInput);

        if ($hasRegisteredPhone) {
            if (strlen($cleanLast4) < 4) {
                PasienConfig::jsonResponse([
                    'success' => false,
                    'message' => 'Mohon masukkan 4 digit terakhir nomor HP yang didaftarkan di RS.'
                ], 400);
            }

            $matchedPhone = false;
            foreach ($phoneRows as $pRow) {
                $cleanP = preg_replace('/[^0-9]/', '', $pRow['NOMOR']);
                if (substr($cleanP, -4) === $cleanLast4) {
                    $matchedPhone = true;
                    break;
                }
            }

            if (!$matchedPhone) {
                PasienConfig::jsonResponse([
                    'success' => false,
                    'message' => '4 digit terakhir nomor HP tidak sesuai dengan data pendaftaran rumah sakit.'
                ], 401);
            }
        }

        // Ambil NIK resmi jika ada
        $stmtNikReal = $pdoMaster->prepare("SELECT NOMOR FROM master.kartu_identitas_pasien WHERE NORM = :norm AND JENIS = 1 LIMIT 1");
        $stmtNikReal->execute([':norm' => $norm]);
        $nikVal = $stmtNikReal->fetchColumn() ?: '-';

        // Ambil Golongan Darah jika ada
        $golDarahMap = [1 => 'A', 2 => 'B', 3 => 'AB', 4 => 'O'];
        $golDarahTeks = $golDarahMap[$pasien['GOLONGAN_DARAH'] ?? 0] ?? '-';

        // Cek Status Rawat Inap Aktif saat ini
        $pdoDaftar = PasienConfig::getConnection('pendaftaran');
        $rawatInapAktif = null;
        if ($pdoDaftar !== null) {
            $stmtRanap = $pdoDaftar->prepare("
                SELECT 
                    k.NOMOR AS KUNJUNGAN_ID,
                    k.NOPEN,
                    r.DESKRIPSI AS RUANGAN,
                    COALESCE(rk.KAMAR, '-') AS KAMAR,
                    COALESCE(rkt.TEMPAT_TIDUR, '-') AS NO_BED,
                    DATE_FORMAT(k.MASUK, '%d-%m-%Y %H:%i') AS TGL_MASUK,
                    GREATEST(1, DATEDIFF(CURDATE(), DATE(k.MASUK)) + 1) AS LAMA_RAWAT,
                    COALESCE(
                        TRIM(CONCAT(IF(COALESCE(peg_dok.GELAR_DEPAN,'') != '', CONCAT(peg_dok.GELAR_DEPAN, '. '), ''), peg_dok.NAMA, IF(COALESCE(peg_dok.GELAR_BELAKANG,'') != '', CONCAT(', ', peg_dok.GELAR_BELAKANG), ''))),
                        TRIM(CONCAT(IF(COALESCE(peg_dir.GELAR_DEPAN,'') != '', CONCAT(peg_dir.GELAR_DEPAN, '. '), ''), peg_dir.NAMA, IF(COALESCE(peg_dir.GELAR_BELAKANG,'') != '', CONCAT(', ', peg_dir.GELAR_BELAKANG), ''))),
                        'Dokter DPJP'
                    ) AS NAMA_DPJP
                FROM pendaftaran.kunjungan k
                JOIN pendaftaran.pendaftaran p ON p.NOMOR = k.NOPEN
                JOIN master.ruangan r ON r.ID = k.RUANGAN
                LEFT JOIN master.ruang_kamar_tidur rkt ON rkt.ID = k.RUANG_KAMAR_TIDUR
                LEFT JOIN master.ruang_kamar rk ON rk.ID = rkt.RUANG_KAMAR
                LEFT JOIN master.dokter dok ON dok.ID = k.DPJP
                LEFT JOIN master.pegawai peg_dok ON peg_dok.NIP = dok.NIP
                LEFT JOIN master.pegawai peg_dir ON peg_dir.ID = k.DPJP
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
            $rawatInapAktif = $stmtRanap->fetch(PDO::FETCH_ASSOC);
        }

        // Format Session Data Pasien
        $userData = [
            'norm' => (int)$pasien['NORM'],
            'norm_formatted' => str_pad($pasien['NORM'], 8, '0', STR_PAD_LEFT),
            'nama' => $pasien['NAMA'],
            'tgl_lahir' => $pasien['TGL_LAHIR'],
            'tgl_lahir_indo' => PasienConfig::formatTglIndo($pasien['TGL_LAHIR'], false),
            'jenis_kelamin' => (int)$pasien['JENIS_KELAMIN'] === 1 ? 'Laki-laki' : 'Perempuan',
            'tempat_lahir' => $pasien['TEMPAT_LAHIR'] ?: 'Kupang',
            'alamat' => $pasien['ALAMAT'],
            'nik' => $nikVal,
            'golongan_darah' => $golDarahTeks,
            'has_registered_phone' => $hasRegisteredPhone,
            'is_ranap' => !empty($rawatInapAktif),
            'ranap_aktif' => $rawatInapAktif ?: null,
            'login_time' => date('Y-m-d H:i:s')
        ];

        $_SESSION['pasien_user'] = $userData;

        PasienConfig::jsonResponse([
            'success' => true,
            'message' => 'Verifikasi identitas berhasil. Selamat datang, ' . $pasien['NAMA'] . '!',
            'data' => $userData
        ]);

    } catch (Exception $e) {
        PasienConfig::jsonResponse([
            'success' => false,
            'message' => 'Terjadi kesalahan sistem saat verifikasi: ' . $e->getMessage()
        ], 500);
    }
}

if ($action === 'check') {
    $active = PasienConfig::getActivePatient();
    if ($active) {
        PasienConfig::jsonResponse([
            'success' => true,
            'authenticated' => true,
            'data' => $active
        ]);
    } else {
        PasienConfig::jsonResponse([
            'success' => false,
            'authenticated' => false
        ]);
    }
}

if ($action === 'logout') {
    unset($_SESSION['pasien_user']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    PasienConfig::jsonResponse([
        'success' => true,
        'message' => 'Anda telah keluar dari Portal Pasien.'
    ]);
}
