# 🚀 Panduan Deploy HappyPet Agar Bisa Dibuka di Publik (Internet)

Website **HappyPet** dapat dibuka di publik menggunakan 2 metode berikut:

---

## ⚡ Metode 1: Buka Publik Instan (Paling Cepat - 1 Klik dari Komputer)
Cocok untuk demo tugas, presentasi ke dosen/klien, atau pengujian langsung dari HP/luar jaringan tanpa perlu daftar akun hosting.

1. Buka folder proyek:
   `c:\Users\andym\.gemini\antigravity\scratch\happypet`
2. Klik dua kali file:
   👉 **`jalankan-public.bat`**
3. Pilih opsi **1** (Pinggy HTTPS Tunnel).
4. Terminal akan langsung menampilkan URL publik HTTPS (contoh: `https://xxxx.a.pinggy.link`).
5. **Salin URL tersebut dan bagikan ke siapa saja** — website HappyPet langsung bisa dibuka dari HP, laptop lain, atau internet publik dengan fitur booking dan transaksi lengkap!

---

## 🌐 Metode 2: Deploy Cloud Permanen (Online 24 Jam Nonstop)
Website tetap aktif meskipun laptop/komputer Anda dimatikan.

### Pilihan A: Render.com (Gratis & Mendukung Docker Otomatis)
1. Buat akun gratis di [https://render.com](https://render.com).
2. Upload folder `happypet` ke GitHub repository Anda (Public atau Private).
3. Di dashboard Render, klik **New +** -> **Web Service**.
4. Hubungkan repository GitHub Anda.
5. Render akan mendeteksi file **`Dockerfile`** dan **`render.yaml`** yang sudah kami siapkan secara otomatis.
6. Klik **Create Web Service**.
7. Tunggu build selesai (sekitar 2-3 menit). Anda akan mendapatkan link publik permanen seperti:
   `https://happypet-xxx.onrender.com`

---

### Pilihan B: InfinityFree / cPanel Hosting Tradisional (Gratis Selamanya)
1. Buat akun di [https://www.infinityfree.com](https://www.infinityfree.com) (Penyedia hosting PHP gratis dengan SSL).
2. Buat akun hosting baru dan pilih subdomain gratis (contoh: `happypet.infinityfreeapp.com`).
3. Buka **File Manager** (atau via FileZilla FTP) lalu masuk ke folder **`htdocs/`**.
4. Kompres seluruh isi folder `happypet` menjadi file **`happypet.zip`**, lalu upload dan ekstrak di dalam folder `htdocs/`.
5. File konfigurasi keamanan **`.htaccess`** yang kami buat sudah otomatis terpasang untuk melindungi data transaksi Anda.
6. Buka domain Anda, website HappyPet langsung aktif secara publik!

---

## 🔒 Catatan Keamanan & Penyimpanan
- Database bawaan menggunakan penyimpanan file JSON persisten di `storage/db.json` sehingga tidak mewajibkan setup database MySQL yang rumit.
- File `.htaccess` sudah dikonfigurasi untuk mencegah pengunduhan data `db.json` secara langsung dari browser demi keamanan data.
