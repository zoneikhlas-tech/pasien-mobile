<?php
/**
 * Entry Point PasienKu - Portal Pasien Mobile SIMGOS 2
 * Dikembangkan oleh: Suriyandi (SIRS RSUP dr. Ben Mboi Kupang)
 * Mengirimkan No-Cache Headers agar mobile browser selalu memuat versi UI terkini
 */
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

require __DIR__ . '/index.html';
