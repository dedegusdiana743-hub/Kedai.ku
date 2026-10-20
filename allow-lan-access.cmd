@echo off
REM Membuka port 8080 untuk akses dari HP pada Wi-Fi lokal.
REM Klik kanan file ini lalu pilih "Run as administrator".
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0allow-lan-access.ps1" -Port 8080
echo.
echo Selesai. Tekan tombol apa saja untuk menutup jendela ini.
pause > nul
