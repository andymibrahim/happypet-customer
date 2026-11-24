# 🗄️ Panduan Koneksi Database SQL Real-Time (MySQL / MariaDB) — HappyPet

Website **HappyPet** kini mendukung penyimpanan **SQL (MySQL / MariaDB via PDO)** secara **real-time**.  
Seluruh data transaksi, akun pengguna, data hewan, dan **seluruh log interaksi user** (login, registrasi, pindah menu, membuka popup booking, memilih metode bayar, mencetak struk, mengedit profil/avatar, menghapus riwayat, dll.) otomatis tersimpan langsung ke tabel SQL.

---

## 📊 Daftar Tabel SQL yang Disimpan Real-Time
1. **`users`** — Data akun pelanggan, profil lengkap, avatar, dan password terenkripsi.
2. **`pets`** — Data hewan peliharaan (anabul) milik tiap user.
3. **`packages`** — Katalog paket kamar & layanan penitipan.
4. **`addons`** — Layanan tambahan (Spa, Pet Taxi, CCTV, Premium Meal, Medical Check-up).
5. **`bookings`** — Seluruh pesanan penitipan, status pembayaran Midtrans, dan struk.
6. **`midtrans_config`** — Pengaturan Payment Gateway Midtrans.
7. **`user_activity_logs`** — **Log interaksi real-time** mencatat setiap aktivitas user lengkap dengan `action_type`, `description`, `metadata_json`, `ip_address`, `user_agent`, dan waktu (`created_at`).

---

## 🌐 Cara Menghubungkan ke SQL di InfinityFree (`happy-pet.freedev.app`)

1. Buka **Dashboard InfinityFree** akun Anda (`if0_43472037`) → klik menu **MySQL Databases** (atau di VistaPanel).
2. Buat database baru (misalnya: `happypet`, sehingga nama lengkapnya menjadi `if0_43472037_happypet`).
3. Catat 4 informasi dari halaman **MySQL Databases** tersebut:
   - **MySQL Hostname** (contoh: `sql305.infinityfree.com`)
   - **MySQL Database Name** (contoh: `if0_43472037_happypet`)
   - **MySQL Username** (contoh: `if0_43472037`)
   - **MySQL Password** (sama dengan *vPanel / Account Password* Anda)
4. Edit file **`config/db_config.php`** dan isi 4 kredensial tersebut:
   ```php
   return [
       'enabled'       => true,
       'host'          => 'sql305.infinityfree.com', // Ganti sesuai MySQL Hostname Anda
       'port'          => '3306',
       'database'      => 'if0_43472037_happypet',   // Ganti sesuai nama DB Anda
       'username'      => 'if0_43472037',            // Ganti sesuai username Anda
       'password'      => 'PASSWORD_ANDA',           // Ganti sesuai password Anda
       'charset'       => 'utf8mb4',
       'auto_migrate'  => true,
       'fallback_json' => true,
   ];
   ```
5. Upload ulang folder proyek (atau file yang diperbarui: `config/db_config.php`, `config/database.php`, `api.php`, `index.php`, `login.php`, `logout.php`, `struk.php`, `assets/js/app.js`, `.htaccess`) ke folder **`htdocs/`** di InfinityFree.
6. **(Opsional)** Karena `auto_migrate => true` aktif, saat website dibuka pertama kali, seluruh 7 tabel SQL akan **dibuat dan diisi data awal secara otomatis**!  
   Atau Anda juga bisa mengimpor file **`database.sql`** secara manual melalui tombol **phpMyAdmin** di InfinityFree.

---

## 💻 Cara Menggunakan SQL di Localhost (Laragon / XAMPP)

1. Nyalakan service **MySQL** di Laragon (klik **Start All**) atau XAMPP Control Panel.
2. Tidak perlu mengubah `config/db_config.php` karena pengaturan default sudah mengarah ke:
   - Host: `127.0.0.1` | Port: `3306` | Database: `happypet_db` | User: `root` | Password: *(kosong)*
3. Saat website dibuka di `http://localhost:8080`, sistem otomatis membuat database `happypet_db`, membuat 7 tabel, dan memindahkan data yang sudah ada di `storage/db.json` ke MySQL secara otomatis!

---

## ✅ Cara Mengecek Koneksi SQL & Melihat Data Interaksi Real-Time

1. Setelah login ke website HappyPet, buka alamat berikut di tab baru browser:
   - Cek status SQL aktif: `api.php?action=db_status`  
     *(Jika berhasil terhubung ke MySQL, akan muncul `"driver": "mysql"` dan `"connected_sql": true` beserta jumlah baris tiap tabel).*
   - Lihat log interaksi real-time dalam JSON: `api.php?action=get_activity_logs`
2. Untuk melihat langsung di database SQL, buka **phpMyAdmin** (di InfinityFree atau Laragon), pilih database Anda, lalu klik tabel **`user_activity_logs`**, **`bookings`**, **`users`**, atau **`pets`**. Setiap klik dan aksi user langsung masuk secara real-time!
