<?php
/**
 * Portal Pasien - RSUP dr. Ben Mboi Kupang
 * API Hasil Penunjang (Laboratorium & Radiologi Resmi Pasien)
 */

require_once __DIR__ . '/../config.php';

$user = PasienConfig::requireAuth();
$norm = (int)$user['norm'];
$action = $_GET['action'] ?? 'lab';

$pdoLayanan = PasienConfig::getConnection('layanan');
$pdoDaftar  = PasienConfig::getConnection('pendaftaran');
$pdoLaporan = PasienConfig::getConnection('laporan');

function getPenunjangWebserviceUrl() {
    return PasienConfig::getWebserviceUrl();
}

function downloadDocStoragePdf($wsBaseUrl, $docId) {
    if (empty($docId)) return null;
    $ch = curl_init($wsBaseUrl . '/document-storage/document/requestAccess');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['ID' => $docId]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
    $res = curl_exec($ch);
    if ($res === false) { curl_close($ch); return null; }
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($res, 0, $hs);
    $body = substr($res, $hs);
    curl_close($ch);

    $json = json_decode($body, true);
    if (empty($json['requestAccessNumber'])) return null;
    $cookie = '';
    if (preg_match('/Set-Cookie:\s*([^;]+)/i', $headers, $m)) $cookie = $m[1];

    $ch2 = curl_init($wsBaseUrl . '/document-storage/document/download/' . $json['requestAccessNumber']);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 20);
    if ($cookie !== '') curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Cookie: $cookie"]);
    $pdf = curl_exec($ch2);
    $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    return ($code === 200 && is_string($pdf) && substr($pdf, 0, 4) === '%PDF') ? $pdf : null;
}

// 1. HASIL LABORATORIUM PASIEN
if ($action === 'lab') {
    $results = [];

    if ($pdoLayanan !== null && $pdoDaftar !== null) {
        try {
            // Ambil semua nomor pendaftaran milik pasien ini
            $stmtN = $pdoDaftar->prepare("SELECT NOMOR FROM pendaftaran.pendaftaran WHERE NORM = :norm ORDER BY TANGGAL DESC LIMIT 30");
            $stmtN->execute([':norm' => $norm]);
            $nopens = $stmtN->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($nopens)) {
                $inN = implode(',', array_fill(0, count($nopens), '?'));

                // Ambil kunjungan lab yang sudah final
                $stmtKL = $pdoDaftar->prepare("
                    SELECT 
                        k.NOMOR AS LAB_KUNJUNGAN,
                        k.NOPEN,
                        DATE_FORMAT(k.MASUK, '%d-%m-%Y %H:%i') AS TGL_PEMERIKSAAN,
                        k.MASUK AS RAW_MASUK,
                        COALESCE(
                            TRIM(CONCAT(IF(COALESCE(peg_dok.GELAR_DEPAN,'') != '', CONCAT(peg_dok.GELAR_DEPAN, '. '), ''), peg_dok.NAMA, IF(COALESCE(peg_dok.GELAR_BELAKANG,'') != '', CONCAT(', ', peg_dok.GELAR_BELAKANG), ''))),
                            'Dokter Spesialis Patologi Klinik'
                        ) AS DOKTER_LAB
                    FROM pendaftaran.kunjungan k
                    JOIN master.ruangan r ON r.ID = k.RUANGAN
                    LEFT JOIN master.dokter dok ON dok.ID = k.DPJP
                    LEFT JOIN master.pegawai peg_dok ON peg_dok.NIP = dok.NIP
                    WHERE k.NOPEN IN ($inN)
                      AND r.JENIS_KUNJUNGAN = 4
                      AND k.STATUS != 0
                      AND k.FINAL_HASIL = 1
                    ORDER BY k.MASUK DESC
                    LIMIT 20
                ");
                $stmtKL->execute($nopens);
                $labKunjunganList = $stmtKL->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($labKunjunganList)) {
                    $labIds = array_column($labKunjunganList, 'LAB_KUNJUNGAN');
                    $inLab = implode(',', array_fill(0, count($labIds), '?'));

                    // Ambil detail parameter hasil lab
                    $stmtDetil = $pdoLayanan->prepare("
                        SELECT 
                            hl.ID,
                            tm.KUNJUNGAN AS LAB_KUNJUNGAN,
                            DATE_FORMAT(hl.TANGGAL, '%d-%m-%Y %H:%i') AS TANGGAL,
                            COALESCE(tm_master.NAMA, 'Pemeriksaan Lab') AS TINDAKAN_NAMA,
                            COALESCE(NULLIF(ptl.PARAMETER, ''), tm_master.NAMA, 'Pemeriksaan Lab') AS PARAMETER,
                            hl.HASIL,
                            COALESCE(NULLIF(hl.NILAI_NORMAL, ''), ptl.NILAI_RUJUKAN, '-') AS NILAI_RUJUKAN,
                            COALESCE(hl.SATUAN, '') AS SATUAN,
                            COALESCE(hl.KETERANGAN, '') AS KETERANGAN
                        FROM layanan.tindakan_medis tm
                        JOIN layanan.hasil_lab hl ON hl.TINDAKAN_MEDIS = tm.ID
                        LEFT JOIN master.tindakan tm_master ON tm_master.ID = tm.TINDAKAN
                        LEFT JOIN master.parameter_tindakan_lab ptl ON ptl.ID = hl.PARAMETER_TINDAKAN
                        WHERE tm.KUNJUNGAN IN ($inLab)
                          AND tm.STATUS = 1
                          AND hl.STATUS = 1
                        ORDER BY hl.TANGGAL DESC, tm.ID ASC, ptl.INDEKS ASC
                    ");
                    $stmtDetil->execute($labIds);
                    $detils = $stmtDetil->fetchAll(PDO::FETCH_ASSOC);

                    $detilsByLab = [];
                    foreach ($detils as $dt) {
                        $detilsByLab[$dt['LAB_KUNJUNGAN']][] = $dt;
                    }

                    foreach ($labKunjunganList as $kl) {
                        $kId = $kl['LAB_KUNJUNGAN'];
                        $items = $detilsByLab[$kId] ?? [];
                        if (!empty($items)) {
                            $results[] = [
                                'lab_kunjungan' => $kId,
                                'tgl_pemeriksaan' => $kl['TGL_PEMERIKSAAN'],
                                'tgl_indo' => PasienConfig::formatTglIndo($kl['RAW_MASUK'], true),
                                'dokter_lab' => $kl['DOKTER_LAB'],
                                'jumlah_parameter' => count($items),
                                'download_pdf_url' => 'api/penunjang.php?action=view_lab_pdf&lab_kunjungan=' . urlencode($kId),
                                'items' => $items
                            ];
                        }
                    }
                }
            }

        } catch (Exception $e) {
            PasienConfig::jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    PasienConfig::jsonResponse([
        'success' => true,
        'count' => count($results),
        'data' => $results
    ]);
}

// 2. HASIL RADIOLOGI PASIEN
if ($action === 'radiologi') {
    $results = [];

    if ($pdoLayanan !== null && $pdoDaftar !== null) {
        try {
            $stmtN = $pdoDaftar->prepare("SELECT NOMOR FROM pendaftaran.pendaftaran WHERE NORM = :norm ORDER BY TANGGAL DESC LIMIT 50");
            $stmtN->execute([':norm' => $norm]);
            $nopens = $stmtN->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($nopens)) {
                $inN = implode(',', array_fill(0, count($nopens), '?'));

                // Ambil semua hasil radiologi yang sudah diverifikasi (STATUS = 2) dari layanan.hasil_rad
                $stmtRad = $pdoLayanan->prepare("
                    SELECT 
                        hr.ID,
                        hr.TINDAKAN_MEDIS,
                        tm.KUNJUNGAN AS RAD_KUNJUNGAN,
                        k.NOPEN,
                        DATE_FORMAT(hr.TANGGAL, '%d-%m-%Y %H:%i') AS TGL_PEMERIKSAAN,
                        hr.TANGGAL AS RAW_TANGGAL,
                        COALESCE(tm_master.NAMA, 'Pemeriksaan Radiologi') AS JENIS_PEMERIKSAAN,
                        hr.HASIL AS EXPERTISE,
                        COALESCE(hr.KLINIS, '-') AS KLINIS,
                        COALESCE(NULLIF(hr.KESAN, ''), NULLIF(hr.USUL, ''), '-') AS KESIMPULAN,
                        COALESCE(hr.USUL, '-') AS USUL,
                        hr.KRITIS,
                        COALESCE(
                            TRIM(CONCAT(IF(COALESCE(peg_dok.GELAR_DEPAN,'') != '', CONCAT(peg_dok.GELAR_DEPAN, '. '), ''), peg_dok.NAMA, IF(COALESCE(peg_dok.GELAR_BELAKANG,'') != '', CONCAT(', ', peg_dok.GELAR_BELAKANG), ''))),
                            'Dokter Spesialis Radiologi'
                        ) AS DOKTER_RAD
                    FROM layanan.hasil_rad hr
                    JOIN layanan.tindakan_medis tm ON tm.ID = hr.TINDAKAN_MEDIS AND tm.STATUS = 1
                    JOIN pendaftaran.kunjungan k ON k.NOMOR = tm.KUNJUNGAN AND k.STATUS != 0
                    JOIN master.ruangan r ON r.ID = k.RUANGAN AND r.JENIS_KUNJUNGAN = 5
                    LEFT JOIN master.tindakan tm_master ON tm_master.ID = tm.TINDAKAN
                    LEFT JOIN master.dokter dok ON dok.ID = hr.DOKTER
                    LEFT JOIN master.pegawai peg_dok ON peg_dok.NIP = dok.NIP
                    WHERE k.NOPEN IN ($inN)
                      AND hr.STATUS = 2
                    ORDER BY hr.TANGGAL DESC
                    LIMIT 30
                ");
                $stmtRad->execute($nopens);
                $rows = $stmtRad->fetchAll(PDO::FETCH_ASSOC);

                // Kelompokkan per kunjungan / tanggal periksa
                $grouped = [];
                foreach ($rows as $row) {
                    $kId = $row['RAD_KUNJUNGAN'];
                    if (!isset($grouped[$kId])) {
                        $grouped[$kId] = [
                            'rad_kunjungan'    => $kId,
                            'tgl_pemeriksaan'  => $row['TGL_PEMERIKSAAN'],
                            'tgl_indo'         => PasienConfig::formatTglIndo($row['RAW_TANGGAL'], true),
                            'dokter_rad'       => $row['DOKTER_RAD'],
                            'download_pdf_url' => 'api/penunjang.php?action=view_rad_pdf&tindakan_medis=' . urlencode($row['TINDAKAN_MEDIS']),
                            'items'            => []
                        ];
                    }
                    $row['download_pdf_url'] = 'api/penunjang.php?action=view_rad_pdf&tindakan_medis=' . urlencode($row['TINDAKAN_MEDIS']);
                    $grouped[$kId]['items'][] = $row;
                }

                $results = array_values($grouped);
            }

        } catch (Exception $e) {
            PasienConfig::jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    PasienConfig::jsonResponse([
        'success' => true,
        'count'   => count($results),
        'data'    => $results
    ]);
}

// 3. STREAM PDF HASIL LAB RESMI
if ($action === 'view_lab_pdf') {
    $labKunjungan = trim($_GET['lab_kunjungan'] ?? '');
    if (empty($labKunjungan)) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'Parameter lab_kunjungan diperlukan.'], 400);
    }

    $wsBaseUrl = getPenunjangWebserviceUrl();
    $rawPdf = null;

    // 1. Ambil Dokumen Resmi dari Document Storage (Dir 4, 5, 6) via laporan.request_report
    if ($pdoLaporan !== null) {
        try {
            $stmtRR = $pdoLaporan->prepare("
                SELECT DOCUMENT_STORAGE_ID
                FROM laporan.request_report
                WHERE REF_ID = :ref
                  AND DOCUMENT_DIRECTORY_ID IN (4, 5, 6)
                  AND STATUS = 2
                  AND DOCUMENT_STORAGE_ID != ''
                ORDER BY DIBUAT_TANGGAL DESC
                LIMIT 1
            ");
            $stmtRR->execute([':ref' => $labKunjungan]);
            $docId = $stmtRR->fetchColumn();

            if (!empty($docId)) {
                $rawPdf = downloadDocStoragePdf($wsBaseUrl, $docId);
            }
        } catch (Exception $e) {}
    }

    // 2. Fallback: Live JasperReport CetakHasilLab dari SIMGOS
    if ($rawPdf === null && $pdoLayanan !== null) {
        $kodePpk = PasienConfig::getKodePpk();
        $pTindakan = '';
        try {
            $stmtTm = $pdoLayanan->prepare("
                SELECT DISTINCT tm.ID
                FROM layanan.tindakan_medis tm
                JOIN layanan.hasil_lab hl ON hl.TINDAKAN_MEDIS = tm.ID AND hl.STATUS = 1
                WHERE tm.KUNJUNGAN = :kunj AND tm.STATUS = 1
            ");
            $stmtTm->execute([':kunj' => $labKunjungan]);
            $pTindakan = implode(',', $stmtTm->fetchAll(PDO::FETCH_COLUMN));
        } catch (Exception $eTm) {}

        $payload = [
            'TITLE'             => 'HASIL LABORATORIUM - ' . $labKunjungan,
            'NAME'              => 'layanan.' . $kodePpk . '-CetakHasilLab',
            'FINAL'             => 0,
            'TYPE'              => 'Pdf',
            'EXT'               => 'pdf',
            'PARAMETER'         => [
                'PNOMOR'            => $labKunjungan,
                'PTINDAKAN'         => $pTindakan,
                'CETAK_HEADER'      => '1',
                'PFORMAT'           => '1',
                'REQUEST_REPORT_ID' => '',
                'PNIP'              => '',
                'TTE'               => ''
            ],
            'REQUEST_FOR_PRINT' => false
        ];
        $ch = curl_init($wsBaseUrl . '/plugins/request-report');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
        $res = curl_exec($ch);
        if ($res !== false) {
            $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($res, 0, $hs);
            $body = substr($res, $hs);
            $json = json_decode($body, true);
            if (!empty($json['url'])) {
                $cookie = '';
                if (preg_match('/Set-Cookie:\s*([^;]+)/i', $headers, $m)) $cookie = $m[1];
                $ch2 = curl_init($json['url']);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch2, CURLOPT_TIMEOUT, 20);
                if ($cookie !== '') curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Cookie: $cookie"]);
                $pdf = curl_exec($ch2);
                $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                curl_close($ch2);
                if ($code === 200 && is_string($pdf) && substr($pdf, 0, 4) === '%PDF') {
                    $rawPdf = $pdf;
                }
            }
        }
        curl_close($ch);
    }

    if ($rawPdf !== null) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="HasilLab-' . preg_replace('/[^A-Za-z0-9_-]/', '', $labKunjungan) . '.pdf"');
        header('Content-Length: ' . strlen($rawPdf));
        echo $rawPdf;
        exit;
    }

    PasienConfig::jsonResponse(['success' => false, 'message' => 'Dokumen PDF Hasil Lab belum tersedia untuk kunjungan ini.'], 404);
}

// 4. STREAM PDF HASIL RADIOLOGI RESMI
if ($action === 'view_rad_pdf') {
    $tindakanMedis = trim($_GET['tindakan_medis'] ?? '');
    $radKunjungan  = trim($_GET['rad_kunjungan'] ?? '');

    if (empty($tindakanMedis) && !empty($radKunjungan) && $pdoLayanan !== null) {
        try {
            $stmtTm = $pdoLayanan->prepare("SELECT ID FROM layanan.tindakan_medis WHERE KUNJUNGAN = :kunj AND STATUS = 1 ORDER BY ID DESC LIMIT 1");
            $stmtTm->execute([':kunj' => $radKunjungan]);
            $tindakanMedis = $stmtTm->fetchColumn() ?: '';
        } catch (Exception $e) {}
    }

    if (empty($tindakanMedis) && empty($radKunjungan)) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'Parameter tindakan_medis atau rad_kunjungan diperlukan.'], 400);
    }

    $wsBaseUrl = getPenunjangWebserviceUrl();
    $rawPdf = null;

    // 1. Cek di Document Storage (Directory ID 7) via laporan.request_report
    if ($pdoLaporan !== null) {
        try {
            $refCandidates = array_values(array_filter([$tindakanMedis, $radKunjungan]));
            $inRef = implode(',', array_fill(0, count($refCandidates), '?'));

            $stmtRR = $pdoLaporan->prepare("
                SELECT DOCUMENT_STORAGE_ID
                FROM laporan.request_report
                WHERE REF_ID IN ($inRef)
                  AND DOCUMENT_DIRECTORY_ID IN (7, 8, 9)
                  AND STATUS = 2
                  AND DOCUMENT_STORAGE_ID != ''
                ORDER BY DIBUAT_TANGGAL DESC
                LIMIT 1
            ");
            $stmtRR->execute($refCandidates);
            $docId = $stmtRR->fetchColumn();

            if (!empty($docId)) {
                $rawPdf = downloadDocStoragePdf($wsBaseUrl, $docId);
            }
        } catch (Exception $e) {}
    }

    // 2. Fallback: Live JasperReport CetakHasilRad
    if ($rawPdf === null) {
        $kodePpk = PasienConfig::getKodePpk();
        $paramTarget = !empty($tindakanMedis) ? (string)$tindakanMedis : (string)$radKunjungan;
        $payload = [
            'TITLE'             => 'HASIL RADIOLOGI - ' . $paramTarget,
            'NAME'              => 'layanan.' . $kodePpk . '-CetakHasilRad',
            'FINAL'             => 0,
            'TYPE'              => 'Pdf',
            'EXT'               => 'pdf',
            'PARAMETER'         => [
                'PTINDAKAN'         => $paramTarget,
                'PKUNJUNGAN'        => (string)$radKunjungan,
                'CETAK_HEADER'      => '1',
                'REQUEST_REPORT_ID' => '',
                'PNIP'              => '',
                'TTE'               => ''
            ],
            'REQUEST_FOR_PRINT' => false
        ];

        $ch = curl_init($wsBaseUrl . '/plugins/request-report');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
        $res = curl_exec($ch);
        if ($res !== false) {
            $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($res, 0, $hs);
            $body = substr($res, $hs);
            $json = json_decode($body, true);
            if (!empty($json['url'])) {
                $cookie = '';
                if (preg_match('/Set-Cookie:\s*([^;]+)/i', $headers, $m)) $cookie = $m[1];
                $ch2 = curl_init($json['url']);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch2, CURLOPT_TIMEOUT, 20);
                if ($cookie !== '') curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Cookie: $cookie"]);
                $pdf = curl_exec($ch2);
                $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                curl_close($ch2);
                if ($code === 200 && is_string($pdf) && substr($pdf, 0, 4) === '%PDF') {
                    $rawPdf = $pdf;
                }
            }
        }
        curl_close($ch);
    }

    if ($rawPdf !== null) {
        $filenameTarget = !empty($tindakanMedis) ? $tindakanMedis : $radKunjungan;
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="HasilRadiologi-' . preg_replace('/[^A-Za-z0-9_-]/', '', $filenameTarget) . '.pdf"');
        header('Content-Length: ' . strlen($rawPdf));
        echo $rawPdf;
        exit;
    }

    PasienConfig::jsonResponse(['success' => false, 'message' => 'Dokumen PDF Hasil Radiologi belum tersedia untuk pemeriksaan ini.'], 404);
}

// 5. DOWNLOAD PDF RESUME MEDIS RESMI (DISCHARGE SUMMARY)
if ($action === 'download_resume') {
    $nopen = preg_replace('/[^0-9]/', '', (string)($_GET['nopen'] ?? ''));
    if (empty($nopen)) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'Nomor Pendaftaran tidak valid.'], 400);
    }

    // Pastikan NOPEN tersebut milik pasien yang login
    $stmtCheck = $pdoDaftar->prepare("SELECT NORM FROM pendaftaran.pendaftaran WHERE NOMOR = :nopen");
    $stmtCheck->execute([':nopen' => $nopen]);
    $pNorm = (int)$stmtCheck->fetchColumn();
    if ($pNorm !== $norm) {
        PasienConfig::jsonResponse(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk dokumen ini.'], 403);
    }

    $rawPdf = null;
    $wsBaseUrl = getPenunjangWebserviceUrl();

    // Cari dari laporan.request_report (DOCUMENT_DIRECTORY_ID = 2)
    $stmtReq = $pdoLaporan->prepare("
        SELECT DOCUMENT_STORAGE_ID 
        FROM laporan.request_report 
        WHERE DOCUMENT_DIRECTORY_ID = 2 AND REF_ID = :nopen AND STATUS = 2
        ORDER BY DIBUAT_TANGGAL DESC 
        LIMIT 1
    ");
    $stmtReq->execute([':nopen' => $nopen]);
    $docStorageId = $stmtReq->fetchColumn();

    if ($docStorageId) {
        $rawPdf = downloadDocStoragePdf($wsBaseUrl, $docStorageId);
    }

    if ($rawPdf !== null) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="ResumeMedis-' . $nopen . '.pdf"');
        header('Content-Length: ' . strlen($rawPdf));
        echo $rawPdf;
        exit;
    }

    PasienConfig::jsonResponse(['success' => false, 'message' => 'Dokumen PDF Resume Medis resmi belum tersedia atau belum ditandatangani DPJP.'], 404);
}
