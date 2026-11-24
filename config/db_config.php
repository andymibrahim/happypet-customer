<?php
/**
 * HappyPet - Konfigurasi Koneksi Database MySQL (Laragon Localhost)
 *
 * Pengaturan ini dikonfigurasi untuk menggunakan MySQL secara penuh (real-time)
 * melalui Laragon di komputer lokal Anda (http://localhost/happypet atau http://happypet.test)
 * sehingga seluruh data & interaksi user langsung tersimpan di MySQL dan terlihat di phpMyAdmin
 * (http://localhost/phpmyadmin).
 */

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

    // Strict MySQL mode: false agar sistem 100% menggunakan MySQL (tidak diam-diam fallback ke JSON)
    'fallback_json' => false,
];
