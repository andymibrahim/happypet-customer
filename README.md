# HappyPet — Website Pembelian Jasa Penitipan Hewan (PHP + Midtrans Snap)

Website pemesanan jasa penitipan hewan (**Pet Hotel, Daycare, & Organic Spa**) bernama **HappyPet** yang dibangun menggunakan **PHP 8+**.

## Fitur Utama Sesuai Spesifikasi
1. **Bahasa Pemrograman PHP**: Arsitektur modular PHP (`index.php`, `login.php`, `logout.php`, `api.php`, `struk.php`, `config/database.php`, `config/midtrans.php`).
2. **Fitur Login, Register, Logout & Edit Profil**:
   - Halaman autentikasi di `login.php` lengkap dengan tombol **Isi & Masuk Akun Demo** (`nadya@happypet.id` / `password123`).
   - Fitur **Edit Profil** untuk memperbarui nama, email, nomor WhatsApp, alamat penjemputan, kontak darurat, catatan dokter hewan langganan, ikon avatar, dan kata sandi.
3. **Navigasi di Sebelah Kiri & Dashboard di Sebelah Kanan**:
   - Seluruh menu navigasi (`Dashboard & Layanan`, `Pesan Penitipan (Popup)`, `Riwayat & Cetak Struk`, `Data Hewan Saya`, `Edit Profil`, `Payment Midtrans`, dan `Logout`) berada di **Sidebar Kiri**.
   - Seluruh konten utama, statistik, dan katalog layanan berada di **Dashboard Sebelah Kanan**.
4. **Pemesanan Menggunakan Popup (`#bookingModal`)**:
   - Klik tombol **Pesan (Popup)** pada kartu paket layanan atau di sidebar kiri untuk membuka modal popup pemesanan.
   - Mendukung pilih cepat data anabul tersimpan, kalkulasi durasi hari otomatis dari tanggal Check-In & Check-Out, layanan tambahan (*Add-ons*), serta ringkasan harga real-time.
5. **Riwayat Pembelian & Cetak Struk (`struk.php` & `#receiptModal`)**:
   - Menampilkan riwayat transaksi lengkap dengan filter status (*Semua*, *Lunas/Settlement*, *Pending*).
   - Tersedia fitur **Preview Struk (Popup)** dan halaman khusus **Cetak Struk Resmi** (`struk.php`) yang mendukung mode **Invoice A4** maupun **Struk Kasir Thermal 80mm**.
6. **Payment Gateway Midtrans Snap (`config/midtrans.php`)**:
   - Mendukung integrasi REST API Midtrans Snap (`https://app.sandbox.midtrans.com/snap/v1/transactions`) maupun **Interactive Midtrans Snap Simulator Popup** (QRIS, BCA Virtual Account, Mandiri Bill Payment, Kartu Kredit/Debit).

## Tema Warna yang Digunakan
- **Background Utama**: Off-White / Alabaster (`#F8FAFC`)
- **Warna Primer**: Sage Green (`#84A98C`)
- **Warna Sekunder**: Soft Sand / Beige (`#E6CCB2`)
- **Aksen / Tombol (CTA)**: Deep Forest Green (`#354F52`) & Warm Amber (`#D4A373`)
- **Teks Utama**: Dark Slate (`#1E293B`)

## Cara Menjalankan
1. **Cara Tercepat (1-Klik di Windows)**:
   Klik 2x file `jalankan-happypet.bat` atau jalankan perintah berikut di terminal:
   ```powershell
   & "C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe" -S localhost:8080 -t "C:\Users\andym\.gemini\antigravity\scratch\happypet"
   ```
   Lalu buka `http://localhost:8080` di browser.
2. **Menggunakan Laragon / XAMPP**:
   Salin folder `happypet` ke `C:\laragon\www\happypet` (atau `C:\xampp\htdocs\happypet`), lalu akses `http://localhost/happypet`. Skema SQL MySQL juga tersedia di `database.sql`.
