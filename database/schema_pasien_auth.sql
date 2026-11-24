-- ==============================================================================
-- Skema Tabel Autentikasi Mandiri Pasien (PasienKu) - SIMGOS 2
-- Database Target: regonline
-- Tabel: pasien_auth
-- Pengembang: Suriyandi (SIRS RSUP dr. Ben Mboi Kupang)
-- ==============================================================================

CREATE DATABASE IF NOT EXISTS `regonline`;
USE `regonline`;

CREATE TABLE IF NOT EXISTS `pasien_auth` (
    `NORM` INT(11) NOT NULL COMMENT 'Nomor Rekam Medis Pasien (master.pasien.NORM)',
    `PIN_HASH` VARCHAR(255) NOT NULL COMMENT 'Hash 6 Digit PIN Pribadi Pasien (Bcrypt)',
    `SALAH_INPUT` INT(11) NOT NULL DEFAULT 0 COMMENT 'Jumlah percobaan salah PIN berturut-turut',
    `TERKUNCI_HINGGA` DATETIME NULL DEFAULT NULL COMMENT 'Waktu pembekuan akun jika salah input mencapai 5 kali',
    `TERAKHIR_LOGIN` DATETIME NULL DEFAULT NULL COMMENT 'Timestamp terakhir kali pasien berhasil login',
    `DIBUAT_TANGGAL` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu aktivasi PIN pertama kali',
    `DIPERBARUI_TANGGAL` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Waktu terakhir PIN diubah atau diperbarui',
    PRIMARY KEY (`NORM`),
    KEY `idx_terkunci` (`TERKUNCI_HINGGA`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabel Kredensial & Autentikasi PIN Portal Pasien (PasienKu)';
