# PasienKu - Portal Mandiri & Mobile Pasien SIMGOS 2

<p align="center">
  <img src="assets/screenshots/hero-mockup.png" alt="PasienKu Mobile SIMGOS 2 Showcase Banner" width="100%" style="border-radius: 16px;"/>
</p>

> **Dikembangkan oleh**: **Suriyandi** (SIRS RSUP dr. Ben Mboi Kupang)  
> Didedikasikan untuk mendukung transformasi digital rekam medis dan kemudahan layanan pasien pada seluruh rumah sakit pengguna **SIMGOS 2** Kementerian Kesehatan RI.

Aplikasi Portal Pasien berbasis web modern responsif & **Progressive Web App (PWA)** kelas rumah sakit modern yang terhubung langsung dengan sistem rekam medis elektronik (RME) dan billing **SIMGOS 2** (Kementerian Kesehatan RI).

Didesain dengan antarmuka berkelas internasional (ala *Apple Health* dan standar aplikasi kesehatan modern) untuk memberikan transparansi informasi perawatan, rincian biaya, jadwal kontrol, antrean poliklinik, serta akses dokumen medis resmi bertandatangan elektronik (TTE).

---

## 🌟 Fitur Utama

1. **Dashboard Kunjungan & Riwayat Rekam Medis (Per Episode)**:
   - Pengelompokan episode rawat jalan / rawat inap terstruktur tanpa kerumitan rujukan internal lab/rad/farmasi.
   - Resume medis DPJP & kode diagnosa ICD-10 terverifikasi.
   - Akses dokumen resume medis PDF resmi dengan QR Code verifikasi.
2. **Dokumen & Surat Medis Resmi (TTE)**:
   - Surat Rencana Kontrol (SKDP BPJS) terintegrasi dengan kode booking antrean.
   - Surat Keterangan Sakit, Surat Sehat, Surat Keterangan Rawat Inap (Opname), Surat Kenal Lahir, dan Surat Traveling Hemodialisa (HD).
   - Penampil PDF terintegrasi (*Built-in PDF Viewer*) dengan fitur zoom, cetak, dan pembesar QR Code APM (*Barcode Scanner Friendly*).
3. **Hasil Pemeriksaan Penunjang (Lab & Radiologi)**:
   - Hasil laboratorium lengkap dengan nilai rujukan, satuan, dan tanda status kritis/normal.
   - Hasil expertise dokter spesialis radiologi (Rontgen, USG, CT-Scan, MRI) beserta kesimpulan klinis.
4. **Transparansi Biaya & Billing Real-time**:
   - Rincian biaya transparan (akomodasi kamar, visite dokter, tindakan medis, lab, radiologi, dan farmasi).
   - Mendukung perhitungan gabung tagihan (*merge billing*) dan kalkulasi akumulasi manfaat klaim penjamin BPJS / Asuransi.
5. **Jadwal Dokter & Ketersediaan Tempat Tidur**:
   - Jadwal praktek dokter spesialis terintegrasi dengan sistem HFIS BPJS & SIMGOS.
   - Ketersediaan tempat tidur rawat inap real-time per kelas dan per ruangan.
6. **Riwayat Terapi Obat & Resep**:
   - Daftar obat pulang/rawat jalan, aturan konsumsi, kuantitas, dan petunjuk pemakaian.
7. **Kartu Pasien Digital (Executive Health Pass)**:
   - Kartu identitas pasien digital resmi rumah sakit dengan Barcode / QR Code No. RM siap scan di mesin APM rumah sakit.

<p align="center">
  <img src="assets/screenshots/features-mockup.png" alt="PasienKu Multi-Screen Features Showcase" width="100%" style="border-radius: 16px;"/>
</p>

---

## 🛠️ Arsitektur & Teknologi

- **Frontend**: Single Page Application (SPA) ringan, Vanilla JS modern, Tailwind CSS (CDN), Lucide Icons, dan PWA Service Worker. Sangat cepat, hemat kuota internet pasien, dan tidak memerlukan *build tools* rumit (Node.js/Webpack tidak wajib di server).
- **Backend**: Native PHP RESTful API terstruktur dan aman, PDO Database Abstraction, terintegrasi dengan arsitektur micro-database SIMGOS 2 (`master`, `pendaftaran`, `layanan`, `medicalrecord`, `pembayaran`, `farmasi`, `laporan`, dan `regonline`).
- **Laporan & Dokumen**: Terhubung langsung dengan Document Storage SIMGOS dan JasperReports Web Service engine.

---

## 🚀 Panduan Instalasi (3 Langkah Cepat)

### Langkah 1: Unduh / Clone Repositori
Tempatkan folder `pasien-mobile` ini di dalam struktur web server SIMGOS Anda:
```bash
# Contoh direktori pada instalasi standar SIMGOS 2:
cd /var/www/html/production/webapps/application/
git clone https://github.com/<username>/simgos-pasien-mobile.git pasien-mobile
```
*(Atau pada Windows: `D:\SIMGOS\production\webapps\application\pasien-mobile`)*

### Langkah 2: Salin File Konfigurasi
Masuk ke folder `pasien-mobile`, lalu salin template `db.local.php.example` menjadi `db.local.php`:
```bash
cd pasien-mobile
cp db.local.php.example db.local.php
```

### Langkah 3: Sesuaikan Konfigurasi Database & RS
Buka file `db.local.php` dengan editor teks, lalu sesuaikan:
```php
return [
    // 1. KONEKSI DATABASE MYSQL SIMGOS 2
    'host'           => '10.10.x.x',     // IP Database Server SIMGOS RS Anda
    'port'           => 3306,
    'user'           => 'user_simgos',   // User MySQL
    'pass'           => 'password_anda', // Password MySQL
    'database'       => 'aplikasi',

    // 2. SIMGOS WEBSERVICE URL
    'webservice_url' => 'http://10.10.x.x/webservice',

    // 3. IDENTITAS RS (Opsional - Otomatis terdeteksi dari tabel master.ppk)
    // 'kode_ppk'    => '3372015',       // Kode Faskes / PPK Kemenkes 7 Digit
    // 'nama_rs'     => 'RSUD Dr. Sehat',

    // 4. ZONA WAKTU
    'timezone'         => 'Asia/Jakarta', // 'Asia/Jakarta' (WIB), 'Asia/Makassar' (WITA), 'Asia/Jayapura' (WIT)
    'zona_waktu_label' => 'WIB',
];
```

> [!NOTE]
> File `db.local.php` sudah otomatis terdaftar dalam `.gitignore` sehingga data kredensial database rumah sakit Anda tidak akan pernah bocor ke publik saat melakukan push ke Git repository.

## 🌐 Cara Mengakses & Menjalankan

### A. Melalui Web Server Rumah Sakit (Apache / Nginx / IIS)
Aplikasi dapat langsung diakses oleh pasien melalui URL alias:
```
http://<IP_ATAU_DOMAIN_WEBAPPS_RS>/application/pasien-mobile/
```

### B. Uji Coba Cepat (Local Development)
Jalankan file `start-server.bat` (Windows) atau jalankan PHP built-in server:
```bash
php -S 0.0.0.0:8085
```
Buka browser di `http://localhost:8085`.

---

## 📱 PWA (Install ke Smartphone Pasien)
Pasien dapat mengunduh dan memasang aplikasi ini langsung di layar utama smartphone tanpa melalui Google Play Store atau Apple App Store:
- **Android (Chrome)**: Buka tautan portal -> Ketuk tombol menu (titik tiga) -> Pilih **"Tambahkan ke Layar Utama" / "Install Aplikasi"**.
- **iPhone / iOS (Safari)**: Buka tautan portal -> Ketuk tombol Bagikan (*Share icon*) -> Pilih **"Add to Home Screen"**.

---

## 🔐 Keamanan & Akses Pasien
Aplikasi mengusung metode autentikasi **Zero-Friction & High Security** yang ramah pasien (terutama pasien lansia yang sering lupa kata sandi):
1. **No. Rekam Medis (No. RM)** atau **16 Digit NIK KTP**.
2. **Tanggal Lahir Pasien** (Verifikasi ganda kecocokan data rekam medis).
3. **4 Digit Terakhir No. Handphone** (Verifikasi kepemilikan nomor telepon terdaftar).

---

## 👨‍💻 Pengembang (Developer)

* **Pengembang**: **Suriyandi**
* **Unit Kerja**: Sistem Informasi Rumah Sakit (SIRS)
* **Instansi**: RSUP dr. Ben Mboi Kupang, Nusa Tenggara Timur
* **Deskripsi**: Inisiator & arsitek pengembang modul portal mobile mandiri pasien (PasienKu) berbasis web responsif & PWA yang terintegrasi penuh dengan arsitektur micro-database SIMGOS 2.

---

## 📄 Lisensi & Kontribusi
Aplikasi ini dikembangkan untuk mendukung ekosistem digitalisasi rumah sakit pengguna **SIMGOS 2** Kementerian Kesehatan RI. Terbuka untuk kontribusi fitur, pelaporan kendala (*issues*), dan penyesuaian kebutuhan rumah sakit di seluruh Indonesia.
