@echo off
title PasienKu - SIMGOS Pasien Mobile Server
echo ========================================================
echo   Menjalankan Server Mandiri PasienKu (pasien-mobile)
echo ========================================================
echo.
echo * Buka di Laptop/PC : http://localhost:8085
echo * Buka di HP (WiFi) : http://<IP_KOMPUTER_ANDA>:8085
echo.
echo Tekan Ctrl+C untuk menghentikan server.
echo ========================================================
echo.
cd /d "%~dp0"
php -S 0.0.0.0:8085
pause
