<?php
/**
 * ==============================================================================
 * Portal Pasien (PasienKu) - SIMGOS 2
 * API Autentikasi Modern: PIN 6-Digit (Bcrypt) + Verifikasi Identitas Rekam Medis
 * ==============================================================================
 * Pengembang: Suriyandi (SIRS RSUP dr. Ben Mboi Kupang)
 */

require_once __DIR__ . '/../config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'check';
$input  = json_decode(file_get_contents('php://input'), true) ?: $_POST;

/**
 * Memastikan tabel regonline.pasien_auth tersedia secara otomatis
 */
function ensurePasienAuthTable($pdoReg) {
    if (!$pdoReg) return false;
    static $ensured = false;
    if ($ensured) return true;

    try {
        $pdoReg->exec("
            CREATE TABLE IF NOT EXISTS regonline.pasien_auth (
                NORM INT NOT NULL,
                PIN_HASH VARCHAR(255) NOT NULL,
                SALAH_INPUT INT NOT NULL DEFAULT 0,
                TERKUNCI_HINGGA DATETIME NULL,
                TERAKHIR_LOGIN DATETIME NULL,
                DIBUAT_TANGGAL DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                DIPERBARUI_TANGGAL DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (NORM),
                INDEX idx_terkunci (TERKUNCI_HINGGA)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $ensured = true;
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Mencari data pasien dan NORM berdasarkan No. RM atau 16 Digit NIK
 */
function findPatientByIdentifier($identifier, $pdoMaster) {
    $cleanId = preg_replace('/[^0-9]/', '', (string)$identifier);
    if (empty($cleanId)) return null;

    $norm = null;

    // 1. Jika 16 Digit, cek kartu identitas KTP
    if (strlen($cleanId) === 16) {
        try {
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
        } catch (Exception $e) {}
    }

    // 2. Jika bukan NIK atau belum ketemu, gunakan sebagai NORM
    if (!$norm) {
        $norm = (int)ltrim($cleanId, '0');
    }

    if (!$norm) return null;

    // Ambil data dasar pasien
    $stmt = $pdoMaster->prepare("
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
    $stmt->execute([':norm' => $norm]);
    $pasien = $stmt->fetch(PDO::FETCH_ASSOC);

    return $pasien ?: null;
}

/**
 * Menyusun data sesi login lengkap pasien
 */
function buildUserSessionData($norm, $pasien, $pdoMaster, $pdoDaftar) {
    // Ambil NIK
    $nikVal = '-';
    try {
        $stmtNik = $pdoMaster->prepare("SELECT NOMOR FROM master.kartu_identitas_pasien WHERE NORM = :norm AND JENIS = 1 LIMIT 1");
        $stmtNik->execute([':norm' => $norm]);
        $nikVal = $stmtNik->fetchColumn() ?: '-';
    } catch (Exception $e) {}

    // Golongan Darah
    $golDarahMap = [1 => 'A', 2 => 'B', 3 => 'AB', 4 => 'O'];
    $golDarahTeks = $golDarahMap[$pasien['GOLONGAN_DARAH'] ?? 0] ?? '-';

    // Cek Rawat Inap Aktif
    $rawatInapAktif = null;
    if ($pdoDaftar !== null) {
        try {
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
        } catch (Exception $e) {}
    }

    return [
        'norm'                 => (int)$pasien['NORM'],
        'norm_formatted'       => str_pad($pasien['NORM'], 8, '0', STR_PAD_LEFT),
        'nama'                 => $pasien['NAMA'],
        'tgl_lahir'            => $pasien['TGL_LAHIR'],
        'tgl_lahir_indo'       => PasienConfig::formatTglIndo($pasien['TGL_LAHIR'], false),
        'jenis_kelamin'        => (int)$pasien['JENIS_KELAMIN'] === 1 ? 'Laki-laki' : 'Perempuan',
        'tempat_lahir'         => $pasien['TEMPAT_LAHIR'] ?: 'Kupang',
        'alamat'               => $pasien['ALAMAT'],
        'nik'                  => $nikVal,
        'golongan_darah'       => $golDarahTeks,
        'is_ranap'             => !empty($rawatInapAktif),
        'ranap_aktif'          => $rawatInapAktif ?: null,
        'login_time'           => date('Y-m-d H:i:s')
    ];
}

/**
 * Sensor Nama untuk Privasi (contoh: "NADRAENI PRATAMI YAKOB" -> "NADRAENI P. Y.")
 */
function maskPatientName($fullName) {
    $words = explode(' ', trim($fullName));
    if (count($words) <= 1) return $fullName;
    $first = $words[0];
    $masked = [$first];
    for ($i = 1; $i < count($words); $i++) {
        if (!empty($words[$i])) {
            $masked[] = substr($words[$i], 0, 1) . '***';
        }
    }
    return implode(' ', $masked);
}

/**
 * Token Verifikasi Aman Stateless (Mencegah masalah Session Cookie hilang di Cloudflare/PWA)
 */
function generateAuthToken($norm) {
    $salt = 'SIMGOS_PASIEN_SECURE_TOKEN_2026';
    $time = time();
    $signature = hash_hmac('sha256', "{$norm}:{$time}", $salt);
    return base64_encode("{$norm}:{$time}:{$signature}");
}

function verifyAuthToken($token) {
    if (empty($token)) return null;
    $decoded = base64_decode($token);
    if (!$decoded) return null;
    $parts = explode(':', $decoded);
    if (count($parts) !== 3) return null;
    list($norm, $time, $signature) = $parts;
    // Berlaku 30 menit
    if (time() - (int)$time > 1800) return null;
    $salt = 'SIMGOS_PASIEN_SECURE_TOKEN_2026';
    $expected = hash_hmac('sha256', "{$norm}:{$time}", $salt);
    if (hash_equals($expected, $signature)) {
        return (int)$norm;
    }
    return null;
}

/**
 * Mendapatkan Alamat IP Klien (Mendukung Cloudflare Proxy & Reverse Proxy RS)
 */
function getClientIpAddress() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Rate Limiting Sederhana & Cepat (Anti-Brute Force & Anti-Scraping Data Pasien)
 */
function checkAuthRateLimit($actionKey = 'check_identifier', $maxAttempts = 20, $windowSeconds = 60) {
    $ip = getClientIpAddress();
    $hash = md5($ip . '_' . $actionKey);
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'simgos_rl';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0755, true);
    }
    $file = $tempDir . DIRECTORY_SEPARATOR . $hash . '.json';

    $now = time();
    $data = ['count' => 0, 'start' => $now];
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        if ($content) {
            $parsed = @json_decode($content, true);
            if (is_array($parsed) && isset($parsed['start'], $parsed['count'])) {
                if ($now - $parsed['start'] < $windowSeconds) {
                    $data = $parsed;
                }
            }
        }
    }

    $data['count']++;
    @file_put_contents($file, json_encode($data), LOCK_EX);

    if ($data['count'] > $maxAttempts) {
        $retryAfter = max(1, $windowSeconds - ($now - $data['start']));
        header('Retry-After: ' . $retryAfter);
        PasienConfig::jsonResponse([
            'success' => false,
            'message' => 'Terlalu banyak permintaan dalam waktu singkat. Demi keamanan data medis, silakan tunggu beberapa saat lagi.'
        ], 429);
    }
}

// ==============================================================================
// 1. ACTION: CHECK IDENTIFIER (Cek apakah pasien ada dan sudah punya PIN)
// ==============================================================================
if ($action === 'check_identifier') {
    // Rate Limiting: Maksimal 20 lookup identitas per menit per IP (Mencegah enumerasi No. RM / scraping massal)
    checkAuthRateLimit('check_identifier', 20, 60);

    $identifier = trim((string)($input['identifier'] ?? ''));
    if (empty($identifier)) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'Masukkan No. Rekam Medis (No. RM) atau 16 Digit NIK Anda.'], 400);
    }

    $pdoMaster   = PasienConfig::getConnection('master');
    $pdoReg      = PasienConfig::getConnection('regonline');
    if (!$pdoMaster) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'Gagal terhubung ke database master RS.'], 500);
    }

    $pasien = findPatientByIdentifier($identifier, $pdoMaster);
    if (!$pasien) {
        PasienConfig::jsonResponse([
            'success' => false,
            'message' => 'Data pasien tidak ditemukan. Pastikan No. RM atau NIK sudah benar terdaftar di RS.'
        ], 404);
    }

    $norm = (int)$pasien['NORM'];

    // Pastikan tabel auth ada
    ensurePasienAuthTable($pdoReg);

    // Cek status PIN dan lockout di regonline.pasien_auth
    $hasPin = false;
    $isLocked = false;
    $lockRemainingMinutes = 0;

    if ($pdoReg) {
        try {
            $stmtAuth = $pdoReg->prepare("
                SELECT PIN_HASH, SALAH_INPUT, TERKUNCI_HINGGA, 
                       (TERKUNCI_HINGGA > NOW()) AS IS_LOCKED,
                       TIMESTAMPDIFF(MINUTE, NOW(), TERKUNCI_HINGGA) AS SISA_MENIT
                FROM regonline.pasien_auth 
                WHERE NORM = :norm
                LIMIT 1
            ");
            $stmtAuth->execute([':norm' => $norm]);
            $authRow = $stmtAuth->fetch(PDO::FETCH_ASSOC);

            if ($authRow && !empty($authRow['PIN_HASH'])) {
                $hasPin = true;
                if ((int)$authRow['IS_LOCKED'] === 1) {
                    $isLocked = true;
                    $lockRemainingMinutes = max(1, (int)$authRow['SISA_MENIT']);
                }
            }
        } catch (Exception $e) {}
    }

    // Ambil petunjuk 4 digit nomor HP
    $phoneHint = '';
    try {
        $stmtHp = $pdoMaster->prepare("
            SELECT NOMOR 
            FROM master.kontak_pasien 
            WHERE NORM = :norm AND JENIS IN (1, 2, 3) AND NOMOR IS NOT NULL AND TRIM(NOMOR) != '' 
            LIMIT 1
        ");
        $stmtHp->execute([':norm' => $norm]);
        $rawHp = $stmtHp->fetchColumn();
        if ($rawHp) {
            $cleanP = preg_replace('/[^0-9]/', '', $rawHp);
            if (strlen($cleanP) >= 4) {
                $phoneHint = '•••• ' . substr($cleanP, -4);
            }
        }
    } catch (Exception $e) {}

    // Privasi Medis: Nama disensor pada tahap pra-login untuk mencegah kebocoran identitas
    $maskedName = maskPatientName($pasien['NAMA']);
    $patientData = [
        'norm'                  => $norm,
        'norm_formatted'        => str_pad($norm, 8, '0', STR_PAD_LEFT),
        'nama'                  => $maskedName,
        'nama_display'          => $maskedName,
        'has_pin'               => $hasPin,
        'is_locked'             => $isLocked,
        'lock_remaining_minutes'=> $lockRemainingMinutes,
        'phone_hint'            => $phoneHint ?: '•••• terdaftar'
    ];

    PasienConfig::jsonResponse(array_merge([
        'success' => true,
        'data'    => $patientData
    ], $patientData));
}

// ==============================================================================
// 2. ACTION: LOGIN PIN (Masuk menggunakan 6 Digit PIN Pribadi)
// ==============================================================================
if ($action === 'login_pin') {
    // Rate Limiting: Maksimal 30 percobaan login PIN per menit per IP
    checkAuthRateLimit('login_pin', 30, 60);

    $identifier = trim((string)($input['identifier'] ?? $input['norm'] ?? ''));
    $pin        = trim((string)($input['pin'] ?? ''));

    if (empty($identifier) || empty($pin)) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'No. RM dan 6 Digit PIN wajib diisi.'], 400);
    }

    if (strlen($pin) !== 6 || !ctype_digit($pin)) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'PIN harus berupa 6 digit angka.'], 400);
    }

    $pdoMaster = PasienConfig::getConnection('master');
    $pdoReg    = PasienConfig::getConnection('regonline');
    $pdoDaftar = PasienConfig::getConnection('pendaftaran');

    $pasien = findPatientByIdentifier($identifier, $pdoMaster);
    if (!$pasien) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'Data pasien tidak ditemukan.'], 404);
    }

    $norm = (int)$pasien['NORM'];
    ensurePasienAuthTable($pdoReg);

    // Ambil data PIN dari regonline.pasien_auth
    $stmtAuth = $pdoReg->prepare("
        SELECT PIN_HASH, SALAH_INPUT, TERKUNCI_HINGGA, 
               (TERKUNCI_HINGGA > NOW()) AS IS_LOCKED,
               TIMESTAMPDIFF(MINUTE, NOW(), TERKUNCI_HINGGA) AS SISA_MENIT
        FROM regonline.pasien_auth 
        WHERE NORM = :norm
        LIMIT 1
    ");
    $stmtAuth->execute([':norm' => $norm]);
    $authRow = $stmtAuth->fetch(PDO::FETCH_ASSOC);

    if (!$authRow || empty($authRow['PIN_HASH'])) {
        PasienConfig::jsonResponse([
            'success'   => false,
            'need_setup'=> true,
            'message'   => 'Akun Anda belum memiliki PIN. Silakan lakukan verifikasi awal untuk membuat PIN.'
        ], 400);
    }

    // Cek apakah akun sedang terkunci
    if ((int)$authRow['IS_LOCKED'] === 1) {
        $sisa = max(1, (int)$authRow['SISA_MENIT']);
        $tzLabel = PasienConfig::getTimezoneLabel();
        PasienConfig::jsonResponse([
            'success'   => false,
            'is_locked' => true,
            'message'   => "Akun sementara terkunci demi keamanan karena 5x salah PIN. Silakan coba kembali dalam {$sisa} menit ({$tzLabel}) atau pilih opsi 'Lupa PIN'."
        ], 403);
    }

    // Verifikasi PIN dengan Bcrypt
    $isValidPin = password_verify($pin, $authRow['PIN_HASH']);

    if (!$isValidPin) {
        $salahBaru = (int)$authRow['SALAH_INPUT'] + 1;
        $sisaPercobaan = max(0, 5 - $salahBaru);

        if ($salahBaru >= 5) {
            // Lockout 15 menit
            $stmtLock = $pdoReg->prepare("
                UPDATE regonline.pasien_auth 
                SET SALAH_INPUT = :salah, TERKUNCI_HINGGA = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                WHERE NORM = :norm
            ");
            $stmtLock->execute([':salah' => $salahBaru, ':norm' => $norm]);

            PasienConfig::jsonResponse([
                'success'   => false,
                'is_locked' => true,
                'message'   => 'PIN salah sebanyak 5 kali. Demi keamanan rekam medis Anda, akun dibekukan sementara selama 15 menit. Anda dapat menggunakan menu Lupa PIN untuk mereset.'
            ], 403);
        } else {
            // Update hitungan salah
            $stmtFail = $pdoReg->prepare("
                UPDATE regonline.pasien_auth 
                SET SALAH_INPUT = :salah 
                WHERE NORM = :norm
            ");
            $stmtFail->execute([':salah' => $salahBaru, ':norm' => $norm]);

            PasienConfig::jsonResponse([
                'success'        => false,
                'sisa_percobaan' => $sisaPercobaan,
                'message'        => "PIN yang Anda masukkan salah. Sisa kesempatan: {$sisaPercobaan} kali lagi."
            ], 401);
        }
    }

    // PIN BENAR: Reset salah input & perbarui waktu login
    $stmtSuccess = $pdoReg->prepare("
        UPDATE regonline.pasien_auth 
        SET SALAH_INPUT = 0, TERKUNCI_HINGGA = NULL, TERAKHIR_LOGIN = NOW()
        WHERE NORM = :norm
    ");
    $stmtSuccess->execute([':norm' => $norm]);

    // Regenerasi Session ID (Mencegah Session Fixation) & Inisialisasi Sesi Login Resmi
    session_regenerate_id(true);
    $userData = buildUserSessionData($norm, $pasien, $pdoMaster, $pdoDaftar);
    $_SESSION['pasien_user'] = $userData;
    $_SESSION['pasien_last_active'] = time();

    PasienConfig::jsonResponse([
        'success' => true,
        'message' => 'Selamat datang kembali, ' . $pasien['NAMA'] . '!',
        'data'    => $userData
    ]);
}

// ==============================================================================
// 3. ACTION: VERIFY IDENTITY (Untuk Aktivasi PIN Pertama Kali atau Lupa PIN)
// ==============================================================================
if ($action === 'verify_identity') {
    // Rate Limiting: Maksimal 15 percobaan verifikasi per menit per IP (Anti-Brute Force Data Pasien)
    checkAuthRateLimit('verify_identity', 15, 60);

    $identifier     = trim((string)($input['identifier'] ?? $input['norm'] ?? ''));
    $tglLahirInput  = trim((string)($input['tgl_lahir'] ?? ''));
    $lastPhoneInput = trim((string)($input['last_phone'] ?? ''));

    if (empty($identifier) || empty($tglLahirInput)) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'No. RM/NIK dan Tanggal Lahir wajib diisi.'], 400);
    }

    $pdoMaster = PasienConfig::getConnection('master');
    $pasien = findPatientByIdentifier($identifier, $pdoMaster);
    if (!$pasien) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'Data pasien tidak ditemukan.'], 404);
    }

    $norm = (int)$pasien['NORM'];

    // Keamanan Ketat (Opsi 3): Verifikasi identitas hanya berlaku untuk aktivasi akun baru (belum punya PIN).
    // Jika akun sudah memiliki PIN, tolak reset dari luar demi melindungi kerahasiaan rekam medis.
    $pdoReg = PasienConfig::getConnection('regonline');
    ensurePasienAuthTable($pdoReg);
    if ($pdoReg) {
        $stmtCheckPin = $pdoReg->prepare("
            SELECT PIN_HASH 
            FROM regonline.pasien_auth 
            WHERE NORM = :norm AND PIN_HASH IS NOT NULL AND TRIM(PIN_HASH) != '' 
            LIMIT 1
        ");
        $stmtCheckPin->execute([':norm' => $norm]);
        $existingPin = $stmtCheckPin->fetchColumn();
        if ($existingPin) {
            PasienConfig::jsonResponse([
                'success' => false,
                'message' => 'Akun Anda sudah memiliki PIN aktif. Demi menjaga privasi rekam medis, PIN tidak dapat di-reset dari luar. Silakan ubah PIN dari menu Profil setelah login, atau hubungi Petugas Loket RS.'
            ], 403);
        }
    }

    // Normalisasi Tanggal Lahir
    $dobParsed = null;
    $dobAltParsed = null;
    $cleanDob = trim($tglLahirInput);
    if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $cleanDob, $m)) {
        $dobParsed = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    } elseif (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $cleanDob, $m)) {
        $part1 = (int)$m[1];
        $part2 = (int)$m[2];
        $year  = (int)$m[3];
        if ($part1 > 12) {
            $dobParsed = sprintf('%04d-%02d-%02d', $year, $part2, $part1);
        } elseif ($part2 > 12) {
            $dobParsed = sprintf('%04d-%02d-%02d', $year, $part1, $part2);
        } else {
            $dobParsed    = sprintf('%04d-%02d-%02d', $year, $part2, $part1);
            $dobAltParsed = sprintf('%04d-%02d-%02d', $year, $part1, $part2);
        }
    } else {
        $t = strtotime($cleanDob);
        if ($t && $t > 0) $dobParsed = date('Y-m-d', $t);
    }

    $dobMatched = ($pasien['TGL_LAHIR'] === $dobParsed) || (!empty($dobAltParsed) && $pasien['TGL_LAHIR'] === $dobAltParsed);
    if (!$dobMatched) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'Tanggal Lahir tidak cocok dengan data rekam medis terdaftar.'], 401);
    }

    // Verifikasi 4 Digit No. HP jika nomor terdaftar di master
    $stmtHp = $pdoMaster->prepare("
        SELECT NOMOR 
        FROM master.kontak_pasien 
        WHERE NORM = :norm AND JENIS IN (1, 2, 3) AND NOMOR IS NOT NULL AND TRIM(NOMOR) != ''
    ");
    $stmtHp->execute([':norm' => $norm]);
    $phoneRows = $stmtHp->fetchAll(PDO::FETCH_ASSOC);

    $hasRegisteredPhone = !empty($phoneRows);
    $cleanLast4 = preg_replace('/[^0-9]/', '', $lastPhoneInput);

    if ($hasRegisteredPhone) {
        if (strlen($cleanLast4) < 4) {
            PasienConfig::jsonResponse(['success' => false, 'message' => 'Mohon masukkan 4 digit terakhir nomor HP yang didaftarkan di RS.'], 400);
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
            PasienConfig::jsonResponse(['success' => false, 'message' => '4 digit terakhir nomor HP tidak cocok dengan data rekam medis.'], 401);
        }
    }

    // Berhasil verifikasi: Berikan token sesi verifikasi (berlaku 30 menit)
    $_SESSION['temp_auth_norm'] = $norm;
    $_SESSION['temp_auth_time'] = time();
    $verifyToken = generateAuthToken($norm);

    PasienConfig::jsonResponse([
        'success'      => true,
        'norm'         => $norm,
        'verify_token' => $verifyToken,
        'message'      => 'Identitas terverifikasi. Silakan tentukan 6 Digit PIN Pribadi Anda.'
    ]);
}

// ==============================================================================
// 4. ACTION: SET PIN (Buat PIN Baru atau Reset PIN)
// ==============================================================================
if ($action === 'set_pin') {
    $tokenNorm = verifyAuthToken($input['verify_token'] ?? '');
    $tempNorm  = $tokenNorm ?: ($_SESSION['temp_auth_norm'] ?? null);
    if (!$tempNorm && !empty($input['norm'])) {
        $tempNorm = (int)$input['norm'];
    }

    if (empty($tempNorm)) {
        PasienConfig::jsonResponse([
            'success' => false,
            'message' => 'Sesi verifikasi identitas tidak ditemukan atau telah berakhir. Silakan lakukan verifikasi ulang.'
        ]);
    }

    $pin        = trim((string)($input['pin'] ?? ''));
    $confirmPin = trim((string)($input['confirm_pin'] ?? ''));

    if (strlen($pin) !== 6 || !ctype_digit($pin)) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'PIN harus berupa 6 angka kombinasi unik.']);
    }

    if ($pin !== $confirmPin) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'Konfirmasi PIN tidak cocok dengan PIN baru.']);
    }

    // Proteksi PIN terlalu sederhana (misal 000000, 111111, dll)
    if (preg_match('/^(\d)\1{5}$/', $pin) || in_array($pin, ['123456', '654321'])) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'PIN terlalu mudah ditebak. Hindari angka kembar (contoh: 111111) atau berurutan (contoh: 123456).']);
    }

    $pdoReg    = PasienConfig::getConnection('regonline');
    $pdoMaster = PasienConfig::getConnection('master');
    $pdoDaftar = PasienConfig::getConnection('pendaftaran');

    if (!$pdoReg) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'Gagal terhubung ke database regonline.']);
    }

    ensurePasienAuthTable($pdoReg);

    // Enkripsi Bcrypt
    $pinHash = password_hash($pin, PASSWORD_BCRYPT);

    try {
        $stmtSave = $pdoReg->prepare("
            INSERT INTO regonline.pasien_auth (NORM, PIN_HASH, SALAH_INPUT, TERKUNCI_HINGGA, TERAKHIR_LOGIN)
            VALUES (:norm, :hash, 0, NULL, NOW())
            ON DUPLICATE KEY UPDATE 
                PIN_HASH = :hash2, 
                SALAH_INPUT = 0, 
                TERKUNCI_HINGGA = NULL, 
                TERAKHIR_LOGIN = NOW(),
                DIPERBARUI_TANGGAL = NOW()
        ");
        $stmtSave->execute([
            ':norm'  => $tempNorm,
            ':hash'  => $pinHash,
            ':hash2' => $pinHash
        ]);
    } catch (Exception $e) {
        PasienConfig::jsonResponse([
            'success' => false,
            'message' => 'Gagal menyimpan ke database auth: ' . $e->getMessage()
        ]);
    }

    // Hapus token sementara
    unset($_SESSION['temp_auth_norm'], $_SESSION['temp_auth_time']);

    // Regenerasi Session ID & Langsung buat sesi login aktif pasien
    session_regenerate_id(true);
    $pasien = findPatientByIdentifier($tempNorm, $pdoMaster);
    if (!$pasien) {
        $pasien = [
            'NORM'           => $tempNorm,
            'NAMA'           => 'Pasien',
            'TGL_LAHIR'      => '',
            'TEMPAT_LAHIR'   => '',
            'JENIS_KELAMIN'  => 1,
            'ALAMAT'         => '-',
            'GOLONGAN_DARAH' => 0
        ];
    }
    $userData = buildUserSessionData($tempNorm, $pasien, $pdoMaster, $pdoDaftar);
    $_SESSION['pasien_user'] = $userData;
    $_SESSION['pasien_last_active'] = time();

    PasienConfig::jsonResponse([
        'success' => true,
        'message' => '6 Digit PIN PasienKu berhasil disimpan! Anda telah masuk.',
        'data'    => $userData
    ]);
}

// ==============================================================================
// 5. ACTION: CHANGE PIN (Ubah PIN dari Pengaturan Akun di dalam Aplikasi)
// ==============================================================================
if ($action === 'change_pin') {
    $user = PasienConfig::requireAuth();
    $norm = (int)$user['norm'];

    $oldPin     = trim((string)($input['old_pin'] ?? ''));
    $newPin     = trim((string)($input['new_pin'] ?? ''));
    $confirmPin = trim((string)($input['confirm_new_pin'] ?? ''));

    if (empty($oldPin) || empty($newPin)) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'PIN lama dan PIN baru wajib diisi.'], 400);
    }

    if (strlen($newPin) !== 6 || !ctype_digit($newPin)) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'PIN baru harus terdiri dari 6 angka.'], 400);
    }

    if ($newPin !== $confirmPin) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'Konfirmasi PIN baru tidak sesuai.'], 400);
    }

    $pdoReg = PasienConfig::getConnection('regonline');
    $stmtAuth = $pdoReg->prepare("SELECT PIN_HASH FROM regonline.pasien_auth WHERE NORM = :norm LIMIT 1");
    $stmtAuth->execute([':norm' => $norm]);
    $currentHash = $stmtAuth->fetchColumn();

    if (!$currentHash || !password_verify($oldPin, $currentHash)) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'PIN lama yang Anda masukkan salah.'], 401);
    }

    $newHash = password_hash($newPin, PASSWORD_BCRYPT);
    $stmtUpdate = $pdoReg->prepare("
        UPDATE regonline.pasien_auth 
        SET PIN_HASH = :hash, SALAH_INPUT = 0, DIPERBARUI_TANGGAL = NOW() 
        WHERE NORM = :norm
    ");
    $stmtUpdate->execute([':hash' => $newHash, ':norm' => $norm]);

    PasienConfig::jsonResponse([
        'success' => true,
        'message' => 'PIN PasienKu Anda berhasil diperbarui.'
    ]);
}

// ==============================================================================
// 6. ACTION: CHECK & LOGOUT
// ==============================================================================
if ($action === 'check') {
    $active = PasienConfig::getActivePatient();
    if ($active) {
        PasienConfig::jsonResponse([
            'success'       => true,
            'authenticated' => true,
            'data'          => $active
        ]);
    } else {
        PasienConfig::jsonResponse([
            'success'       => false,
            'authenticated' => false
        ]);
    }
}

if ($action === 'logout') {
    unset($_SESSION['pasien_user']);
    unset($_SESSION['pasien_last_active']);
    unset($_SESSION['temp_auth_norm']);
    unset($_SESSION['temp_auth_time']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    PasienConfig::jsonResponse([
        'success' => true,
        'message' => 'Anda telah keluar dari Portal Pasien.'
    ]);
}
