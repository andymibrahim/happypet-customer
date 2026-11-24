<?php
/**
 * HappyPet - Konfigurasi Koneksi Database MySQL
 *
 * Di Local (Laragon): Menggunakan MySQL 127.0.0.1:3306 (happypet_db).
 * Di Vercel / Cloud: Mengambil kredensial dari Environment Variables (DB_HOST, DB_NAME, dll).
 * Jika di Vercel belum diisi DB_HOST, otomatis fallback agar website langsung bisa dibuka & ditinjau.
 */

$isVercel = !empty(getenv('VERCEL')) || !empty($_SERVER['VERCEL']) || !empty($_ENV['VERCEL']);
$hasRemoteDb = !empty(getenv('DB_HOST'));

return [
    'enabled'       => true,
    'host'          => getenv('DB_HOST') ?: '127.0.0.1',
    'port'          => getenv('DB_PORT') ?: '3306',
    'database'      => getenv('DB_NAME') ?: 'happypet_db',
    'username'      => getenv('DB_USER') ?: 'root',
    'password'      => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
    'charset'       => 'utf8mb4',

    // Otomatis membuat database `happypet_db`, 7 tabel SQL, & data awal jika belum ada
    'auto_migrate'  => true,

    // Di Laragon (Localhost): Strict MySQL (false) agar semua interaksi 100% tersimpan di MySQL phpMyAdmin
    // Di Vercel tanpa DB_HOST: Fallback diaktifkan (true) agar website langsung bisa diakses tanpa error 503
    'fallback_json' => (getenv('DB_FALLBACK') !== false) 
        ? filter_var(getenv('DB_FALLBACK'), FILTER_VALIDATE_BOOLEAN)
        : ($isVercel && !$hasRemoteDb),
];
