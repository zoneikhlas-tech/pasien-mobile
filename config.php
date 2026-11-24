<?php
/**
 * ==============================================================================
 * Portal Pasien (PasienKu) - Aplikasi Mobile SIMGOS 2
 * Konfigurasi & Helper Universal Standar SIMGOS 2
 * 
 * Pengembang: Suriyandi (SIRS RSUP dr. Ben Mboi Kupang)
 * ==============================================================================
 */

// Mencegah error / warning PHP merusak payload JSON ke client
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
ini_set('display_errors', '0');

// Mulai Sesi Mandiri Portal Pasien dengan Cookie Flags Aman (HttpOnly, SameSite=Lax, Secure jika HTTPS)
if (session_status() === PHP_SESSION_NONE) {
    session_name('SIMGOS_PASIEN_SESS');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
               || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// HTTP Security Headers (Anti-Clickjacking, MIME Sniffing, Referrer Policy)
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

// CORS Protection (Hanya izinkan Origin yang sama atau domain resmi RS)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($origin)) {
    $parsedHost = parse_url($origin, PHP_URL_HOST);
    $serverHost = $_SERVER['HTTP_HOST'] ?? '';
    if ($parsedHost === $serverHost || preg_match('/(^|\.)(psyco\.fun|localhost|127\.0\.0\.1|10\.10\.)/i', (string)$parsedHost)) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
    }
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

class PasienConfig {
    private static $dbConfig = null;
    private static $hospitalInfo = null;
    public static $isDemoMode = false;

    /**
     * Membaca konfigurasi koneksi database & webservice
     * Prioritas:
     * 1. File lokal `db.local.php` di folder ini
     * 2. File autoload webservice SIMGOS `local.php`
     * 3. Fallback default localhost
     */
    public static function getDbConfig() {
        if (self::$dbConfig !== null) {
            return self::$dbConfig;
        }

        // 1. Cek db.local.php di folder pasien-mobile
        $customConfigFile = __DIR__ . '/db.local.php';
        if (file_exists($customConfigFile)) {
            $cfg = include $customConfigFile;
            if (is_array($cfg)) {
                self::$dbConfig = $cfg;
                return self::$dbConfig;
            }
        }

        // 2. Baca dari webservice local.php jika ada
        $localConfigFile = __DIR__ . '/../../webservice/config/autoload/local.php';
        if (file_exists($localConfigFile)) {
            $wsConfig = include $localConfigFile;
            if (isset($wsConfig['db']['adapters']['SIMpelAdapter'])) {
                $adapter = $wsConfig['db']['adapters']['SIMpelAdapter'];
                self::$dbConfig = [
                    'host'           => $adapter['hostname'] ?? 'localhost',
                    'port'           => $adapter['port'] ?? 3306,
                    'user'           => $adapter['username'] ?? 'root',
                    'pass'           => $adapter['password'] ?? '',
                    'database'       => $adapter['database'] ?? 'aplikasi',
                    'webservice_url' => 'http://' . ($adapter['hostname'] ?? 'localhost') . '/webservice'
                ];
                return self::$dbConfig;
            }
        }

        // 3. Fallback Aman (Tanpa kredensial hardcoded)
        self::$dbConfig = [
            'host'           => 'localhost',
            'port'           => 3306,
            'user'           => 'root',
            'pass'           => '',
            'database'       => 'aplikasi',
            'webservice_url' => 'http://localhost/webservice',
            'timezone'       => 'Asia/Makassar',
            'zona_waktu_label' => 'WITA'
        ];
        return self::$dbConfig;
    }

    /**
     * Mendapatkan koneksi PDO ke database tertentu di server SIMGOS
     */
    public static function getConnection($dbName = 'aplikasi') {
        static $connections = [];

        if (isset($connections[$dbName])) {
            return $connections[$dbName];
        }

        $cfg = self::getDbConfig();
        $host = $cfg['host'] ?? 'localhost';
        $port = $cfg['port'] ?? 3306;
        $user = $cfg['user'] ?? 'root';
        $pass = $cfg['pass'] ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 4,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);
            $connections[$dbName] = $pdo;
            self::$isDemoMode = false;
            return $pdo;
        } catch (PDOException $e) {
            self::$isDemoMode = true;
            return null;
        }
    }

    /**
     * Mengambil informasi identitas rumah sakit secara dinamis
     * dari tabel `aplikasi.instansi` & `master.ppk` SIMGOS 2
     */
    public static function getHospitalInfo() {
        if (self::$hospitalInfo !== null) {
            return self::$hospitalInfo;
        }

        $cfg = self::getDbConfig();

        // Nilai bawaan jika database belum siap
        $info = [
            'kode_ppk'         => $cfg['kode_ppk'] ?? '5303056',
            'nama_rs'          => $cfg['nama_rs'] ?? 'Rumah Sakit',
            'kota'             => $cfg['kota'] ?? '',
            'alamat'           => '',
            'telepon'          => '',
            'email'            => '',
            'website'          => '',
            'bpjs_ppk'         => '',
            'timezone'         => $cfg['timezone'] ?? 'Asia/Makassar',
            'zona_waktu_label' => $cfg['zona_waktu_label'] ?? 'WITA',
        ];

        // Coba deteksi otomatis dari database SIMGOS
        try {
            $pdoMaster = self::getConnection('master');
            if ($pdoMaster) {
                $stmt = $pdoMaster->query("
                    SELECT 
                        p.KODE,
                        p.BPJS,
                        p.NAMA,
                        p.ALAMAT,
                        p.DESWILAYAH,
                        p.TELEPON,
                        i.EMAIL,
                        i.WEBSITE
                    FROM aplikasi.instansi i
                    JOIN master.ppk p ON p.ID = i.PPK
                    LIMIT 1
                ");
                $dbPpk = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($dbPpk) {
                    if (!empty($dbPpk['KODE']) && empty($cfg['kode_ppk'])) {
                        $info['kode_ppk'] = trim((string)$dbPpk['KODE']);
                    }
                    if (!empty($dbPpk['NAMA']) && empty($cfg['nama_rs'])) {
                        $info['nama_rs'] = trim($dbPpk['NAMA']);
                    }
                    if (!empty($dbPpk['DESWILAYAH']) && empty($cfg['kota'])) {
                        $info['kota'] = trim($dbPpk['DESWILAYAH']);
                    }
                    $info['alamat']   = trim($dbPpk['ALAMAT'] ?? '');
                    $info['telepon']  = trim($dbPpk['TELEPON'] ?? '');
                    $info['email']    = trim($dbPpk['EMAIL'] ?? '');
                    $info['website']  = trim($dbPpk['WEBSITE'] ?? '');
                    $info['bpjs_ppk'] = trim($dbPpk['BPJS'] ?? '');
                }
            }
        } catch (Exception $e) {
            // Tetap gunakan default jika query gagal
        }

        self::$hospitalInfo = $info;
        return self::$hospitalInfo;
    }

    /**
     * Mendapatkan Kode PPK Faskes Kemenkes (digunakan untuk prefix Jasper Report & tabel BPJS)
     */
    public static function getKodePpk() {
        $info = self::getHospitalInfo();
        return $info['kode_ppk'] ?? '5303056';
    }

    /**
     * Mendapatkan Nama Resmi Rumah Sakit
     */
    public static function getNamaRs() {
        $info = self::getHospitalInfo();
        return $info['nama_rs'] ?? 'Rumah Sakit';
    }

    /**
     * Mendapatkan Timezone PHP aktif
     */
    public static function getTimezone() {
        $cfg = self::getDbConfig();
        return $cfg['timezone'] ?? 'Asia/Makassar';
    }

    /**
     * Mendapatkan Label Zona Waktu (WIB / WITA / WIT)
     */
    public static function getTimezoneLabel() {
        $cfg = self::getDbConfig();
        return $cfg['zona_waktu_label'] ?? 'WITA';
    }

    /**
     * Mendapatkan Webservice Base URL
     */
    public static function getWebserviceUrl() {
        $cfg = self::getDbConfig();
        if (!empty($cfg['webservice_url'])) {
            return rtrim($cfg['webservice_url'], '/');
        }
        $host = $cfg['host'] ?? 'localhost';
        return "http://{$host}/webservice";
    }

    public static function jsonResponse($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function requireAuth() {
        if (!isset($_SESSION['pasien_user']) || empty($_SESSION['pasien_user']['norm'])) {
            self::jsonResponse([
                'success' => false,
                'authenticated' => false,
                'message' => 'Sesi login telah berakhir. Silakan masukkan PIN kembali.'
            ], 401);
        }

        // Auto-Logout jika tidak aktif selama 30 menit (Inactivity Timeout)
        $now = time();
        $lastActive = $_SESSION['pasien_last_active'] ?? $now;
        if (($now - $lastActive) > 1800) {
            unset($_SESSION['pasien_user'], $_SESSION['pasien_last_active']);
            self::jsonResponse([
                'success' => false,
                'authenticated' => false,
                'message' => 'Sesi Anda telah berakhir otomatis karena tidak ada aktivitas selama 30 menit demi keamanan data medis Anda.'
            ], 401);
        }
        $_SESSION['pasien_last_active'] = $now;

        return $_SESSION['pasien_user'];
    }

    public static function getActivePatient() {
        if (!isset($_SESSION['pasien_user']) || empty($_SESSION['pasien_user']['norm'])) {
            return null;
        }

        // Auto-Logout jika tidak aktif selama 30 menit
        $now = time();
        $lastActive = $_SESSION['pasien_last_active'] ?? $now;
        if (($now - $lastActive) > 1800) {
            unset($_SESSION['pasien_user'], $_SESSION['pasien_last_active']);
            return null;
        }
        $_SESSION['pasien_last_active'] = $now;

        return $_SESSION['pasien_user'];
    }

    public static function formatTglIndo($dateStr, $includeDay = true) {
        if (empty($dateStr)) return '-';
        $timestamp = strtotime($dateStr);
        if (!$timestamp) return $dateStr;

        $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $bulan = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
        ];

        $d = date('j', $timestamp);
        $m = (int)date('n', $timestamp);
        $y = date('Y', $timestamp);
        $h = $hari[(int)date('w', $timestamp)];

        $bName = $bulan[$m] ?? '';
        return $includeDay ? "{$h}, {$d} {$bName} {$y}" : "{$d} {$bName} {$y}";
    }

    public static function formatIndonesianDay($dateStr) {
        $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $ts = strtotime($dateStr);
        return $hari[(int)date('w', $ts)] ?? 'Senin';
    }

    public static function maskPhone($phone) {
        $p = preg_replace('/[^0-9]/', '', (string)$phone);
        $len = strlen($p);
        if ($len < 8) return $p;
        $start = substr($p, 0, 4);
        $end = substr($p, -4);
        return $start . '••••' . $end;
    }
}

// Inisialisasi Timezone Dinamis
date_default_timezone_set(PasienConfig::getTimezone());

// Alias Config ke PasienConfig agar fungsi yang memanggil Config:: tetap kompatibel
if (!class_exists('Config')) {
    class Config extends PasienConfig {}
}
