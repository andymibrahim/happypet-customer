@echo off
title HappyPet - Public Online Server Launcher
echo ======================================================================
echo   HappyPet - Peluncur Server Online Publik (Bisa Diakses Siapa Saja)
echo ======================================================================
echo.

set PHP_EXE=php
if exist "C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe" (
    set PHP_EXE="C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe"
) else if exist "C:\xampp\php\php.exe" (
    set PHP_EXE="C:\xampp\php\php.exe"
)

:: Cek apakah PHP server lokal sudah berjalan di port 8080
netstat -ano | findstr ":8080" >nul
if %errorlevel% neq 0 (
    echo [1/2] Menyalakan Web Server PHP HappyPet di port 8080...
    start /b "" %PHP_EXE% -S 0.0.0.0:8080
    timeout /t 2 >nul
) else (
    echo [1/2] Web Server PHP HappyPet sudah aktif di port 8080.
)

echo [2/2] Menyiapkan Jalur Publik (HTTPS Tunnel)...
echo.
echo ======================================================================
echo   PILIH METODE ONLINE PUBLIK YANG INGIN DIGUNAKAN:
echo ======================================================================
echo   1. Pinggy HTTPS Tunnel (Tanpa instalasi, otomatis via Windows SSH) [Direkomendasikan]
echo   2. LocalTunnel via NPX (Jika Node.js / NPX tersedia)
echo   3. Buka Server Lokal Saja (http://localhost:8080)
echo ======================================================================
set /p OPSI="Pilihan Anda (1/2/3) [Default: 1]: "

if "%OPSI%"=="" set OPSI=1

if "%OPSI%"=="1" (
    echo.
    echo Menghubungkan ke Pinggy Public Tunnel...
    echo URL publik HTTPS akan muncul di bawah ini dalam beberapa detik:
    echo (Bagikan URL yang berakhiran .pinggy.link ke siapapun di internet)
    echo.
    ssh -p 443 -o StrictHostKeyChecking=no -R0:localhost:8080 a.pinggy.io
    pause
    exit /b
)

if "%OPSI%"=="2" (
    echo.
    echo Menjalankan LocalTunnel...
    set PATH=%PATH%;C:\laragon\bin\nodejs\node-v18
    npx -y localtunnel --port 8080
    pause
    exit /b
)

if "%OPSI%"=="3" (
    start http://localhost:8080
    echo Server aktif di http://localhost:8080
    pause
    exit /b
)
