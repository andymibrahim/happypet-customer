@echo off
title HappyPet - Luxury Pet Hotel & Care Server
echo ============================================================
echo   HappyPet - Website Pembelian Jasa Penitipan Hewan (PHP)
echo ============================================================
echo.

set PHP_EXE=php
if exist "C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe" (
    set PHP_EXE="C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe"
) else if exist "C:\xampp\php\php.exe" (
    set PHP_EXE="C:\xampp\php\php.exe"
)

echo Menjalankan server HappyPet di http://localhost:8080 ...
echo Silakan buka browser Anda ke: http://localhost:8080
echo.
start http://localhost:8080
%PHP_EXE% -S localhost:8080
pause
