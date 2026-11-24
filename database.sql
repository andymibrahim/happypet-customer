-- ============================================================================
-- HappyPet - Luxury Pet Hotel & Care Database Schema (MySQL / MariaDB)
-- Kompatibel dengan InfinityFree phpMyAdmin, cPanel, Laragon, dan XAMPP
-- ============================================================================
-- PETUNJUK IMPORT:
-- * Di InfinityFree: Pilih dulu database Anda di phpMyAdmin (mis. if0_43472037_happypet),
--   lalu klik tab "Import" dan pilih file database.sql ini.
-- * Di Laragon / XAMPP: Buat database bernama `happypet_db` (atau jalankan 2 baris komentar di bawah),
--   lalu import file ini.
--
-- CREATE DATABASE IF NOT EXISTS `happypet_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE `happypet_db`;
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- 1. Tabel Users (Pelanggan & Profil)
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(120) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(30) DEFAULT '',
  `address` TEXT DEFAULT NULL,
  `emergency_contact` VARCHAR(150) DEFAULT '',
  `vet_notes` TEXT DEFAULT NULL,
  `avatar` VARCHAR(50) DEFAULT 'cat-sage',
  `member_tier` VARCHAR(40) DEFAULT 'HappyPaw Member',
  `role` VARCHAR(20) NOT NULL DEFAULT 'customer',
  `joined_at` DATE DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabel Pets (Data Hewan Peliharaan / Anabul Milik User)
CREATE TABLE IF NOT EXISTS `pets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `species` VARCHAR(50) NOT NULL COMMENT 'Kucing, Anjing Kecil, Anjing Besar, Kelinci',
  `breed` VARCHAR(80) DEFAULT '-',
  `age` VARCHAR(40) DEFAULT '1 Tahun',
  `weight` DECIMAL(5,2) DEFAULT 3.50,
  `gender` VARCHAR(20) DEFAULT 'Jantan',
  `vaccinated` TINYINT(1) DEFAULT 1,
  `special_notes` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_pets_user_id` (`user_id`),
  CONSTRAINT `fk_pets_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabel Packages (Paket Layanan Penitipan Hewan)
CREATE TABLE IF NOT EXISTS `packages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(30) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `category` VARCHAR(60) NOT NULL,
  `price_per_day` INT NOT NULL,
  `badge` VARCHAR(50) DEFAULT NULL,
  `room_size` VARCHAR(80) DEFAULT NULL,
  `suitable_for` VARCHAR(120) DEFAULT NULL,
  `description` TEXT NOT NULL,
  `facilities` TEXT NOT NULL COMMENT 'JSON array of facilities',
  `icon` VARCHAR(40) DEFAULT 'fa-paw',
  `accent` VARCHAR(20) DEFAULT 'sage'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabel Addons (Layanan Tambahan / Spa / Pet Taxi / CCTV)
CREATE TABLE IF NOT EXISTS `addons` (
  `id` VARCHAR(50) PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `price` INT NOT NULL,
  `price_type` VARCHAR(20) NOT NULL DEFAULT 'flat' COMMENT 'flat atau per_day',
  `icon` VARCHAR(40) DEFAULT 'fa-sparkles',
  `description` TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabel Bookings (Transaksi Pemesanan Penitipan & Pembayaran Midtrans)
CREATE TABLE IF NOT EXISTS `bookings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_number` VARCHAR(40) NOT NULL UNIQUE,
  `user_id` INT NOT NULL,
  `package_id` INT NOT NULL,
  `package_name` VARCHAR(100) NOT NULL,
  `package_category` VARCHAR(60) DEFAULT '',
  `price_per_day` INT NOT NULL,
  `pet_name` VARCHAR(80) NOT NULL,
  `pet_species` VARCHAR(50) NOT NULL,
  `pet_breed` VARCHAR(80) DEFAULT '-',
  `pet_weight` DECIMAL(5,2) DEFAULT 0.00,
  `pet_age` VARCHAR(40) DEFAULT '-',
  `check_in` DATE NOT NULL,
  `check_out` DATE NOT NULL,
  `duration_days` INT NOT NULL DEFAULT 1,
  `addons_json` TEXT DEFAULT NULL,
  `addons_total` INT NOT NULL DEFAULT 0,
  `room_subtotal` INT NOT NULL DEFAULT 0,
  `admin_fee` INT NOT NULL DEFAULT 5000,
  `total_amount` INT NOT NULL,
  `special_notes` TEXT DEFAULT NULL,
  `pickup_option` VARCHAR(80) DEFAULT 'Diantar Sendiri ke Lobby HappyPet',
  `payment_status` ENUM('pending', 'settlement', 'expire', 'cancel') DEFAULT 'pending',
  `payment_method` VARCHAR(60) DEFAULT 'Midtrans Snap',
  `payment_channel` VARCHAR(60) DEFAULT 'Menunggu Pembayaran',
  `va_number` VARCHAR(60) DEFAULT '-',
  `snap_token` VARCHAR(120) DEFAULT NULL,
  `midtrans_transaction_id` VARCHAR(80) DEFAULT NULL,
  `paid_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_bookings_user_id` (`user_id`),
  INDEX `idx_bookings_status` (`payment_status`),
  INDEX `idx_bookings_created_at` (`created_at`),
  CONSTRAINT `fk_bookings_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabel Midtrans Config (Pengaturan Payment Gateway)
CREATE TABLE IF NOT EXISTS `midtrans_config` (
  `id` TINYINT PRIMARY KEY DEFAULT 1,
  `environment` VARCHAR(20) NOT NULL DEFAULT 'sandbox',
  `mode` VARCHAR(20) NOT NULL DEFAULT 'simulator',
  `merchant_id` VARCHAR(60) DEFAULT 'G812945001',
  `client_key` VARCHAR(120) DEFAULT 'SB-Mid-client-HappyPetDemo2026',
  `server_key` VARCHAR(120) DEFAULT 'SB-Mid-server-HappyPetDemo2026',
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Tabel User Activity Logs (Pencatatan Real-Time Seluruh Interaksi User)
CREATE TABLE IF NOT EXISTS `user_activity_logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `user_name` VARCHAR(100) DEFAULT 'Guest',
  `user_email` VARCHAR(120) DEFAULT NULL,
  `action_type` VARCHAR(60) NOT NULL COMMENT 'login, register, logout, view_tab, open_booking_modal, create_booking, payment_success, view_receipt, print_receipt, delete_booking, update_profile, save_pet, delete_pet, filter_history, dll',
  `target_entity` VARCHAR(60) DEFAULT NULL COMMENT 'auth, tab, booking, payment, receipt, profile, pet, package',
  `target_id` VARCHAR(60) DEFAULT NULL,
  `description` TEXT NOT NULL,
  `metadata_json` TEXT DEFAULT NULL COMMENT 'Detail tambahan interaksi dalam format JSON',
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_logs_user_id` (`user_id`),
  INDEX `idx_logs_action_type` (`action_type`),
  INDEX `idx_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DATA AWAL (SEED DATA) - Aman dijalankan berulang (INSERT IGNORE)
-- ============================================================================

INSERT IGNORE INTO `midtrans_config` (`id`, `environment`, `mode`, `merchant_id`, `client_key`, `server_key`) VALUES
(1, 'sandbox', 'simulator', 'G812945001', 'SB-Mid-client-HappyPetDemo2026', 'SB-Mid-server-HappyPetDemo2026');

INSERT IGNORE INTO `packages` (`id`, `code`, `name`, `category`, `price_per_day`, `badge`, `room_size`, `suitable_for`, `description`, `facilities`, `icon`, `accent`) VALUES
(1, 'PKG-COZY', 'Cozy Alabaster Room', 'Standard Pet Hotel', 125000, 'Hemat & Steril', '1.5m × 1.5m Private Cabin', 'Kucing & Anjing Kecil (< 8 kg)', 'Kabin privat ber-AC dengan sirkulasi udara HEPA filter, alas tidur ortopedi lembut, dan pembersihan UV steril 2x sehari.', '["Kabin Privat AC & Air Purifier HEPA","Makan 2x Sehari & Air Mineral RO","Sesi Bermain Indoor 2x (30 Menit)","Update Foto & Laporan Harian via WhatsApp","Pembersihan Litter Box / Toilet Berkala"]', 'fa-house-chimney-window', 'sage'),
(2, 'PKG-GARDEN', 'Sage Garden Suite', 'Deluxe Pet Suite', 215000, 'Paling Populer', '2.4m × 2.0m Glass Suite', 'Kucing, Anjing Kecil & Sedang', 'Suite kaca kedap suara bernuansa alam dengan akses taman rumput sintetis steril, aromaterapi lavender ramah hewan, dan CCTV 24 jam.', '["Suite Kaca Tempered + Balkon Taman Privat","Akses Live CCTV 24 Jam dari Smartphone","Aromaterapi Relaksasi & Musik Klasik Hewan","Sesi Playtime & Agility Bersama Pet Sitter","Free Brushing & Cologne Harian"]', 'fa-leaf', 'forest'),
(3, 'PKG-ROYAL', 'Royal Forest VIP Villa', 'Luxury Private Villa', 350000, 'Eksklusif VIP', '3.5m × 3.0m Luxury Villa', 'Semua Jenis Hewan (Termasuk Ras Besar)', 'Villa mewah eksklusif dengan kasur hypoallergenic premium, dedicated butler, pemeriksaan dokter hewan harian, dan sesi organic spa gratis.', '["Private Luxury Villa + Kasur Orthopedic Memory Foam","1-on-1 Dedicated Pet Butler & Pemeriksaan drh. Harian","Akses Private Live Stream CCTV Full HD 24/7","Gratis 1x Organic Oatmeal Spa & Blow Dry","Menu Premium Wet Food + Vitamin Imun Harian"]', 'fa-crown', 'amber'),
(4, 'PKG-DAYCARE', 'Zen Daycare & Social Club', 'Daycare & Socialization', 95000, 'Penitipan Harian', 'Indoor Playground Area', 'Kucing & Anjing Aktif', 'Titip harian dari pagi hingga malam dengan aktivitas sosialisasi terawasi, permainan ketangkasan, dan tidur siang di ruang relaksasi.', '["Full Day Care (07.30 - 20.00 WIB)","Social Playgroup Sesuai Ukuran & Karakter","Snack Sehat & Hidrasi Terkontrol","Nap Time di Ruangan Ber-AC Tenang","Laporan Aktivitas Sore Hari"]', 'fa-sun', 'sand'),
(5, 'PKG-MEDICAL', 'Gentle Vet & Senior Care', 'Special Needs & Senior', 275000, 'Pendampingan Medis', '2.2m × 2.0m Care Suite', 'Hewan Senior / Pemulihan / Butuh Obat Rutin', 'Ruang penitipan tenang dengan pengawasan tim paramedis veteriner, pemberian obat/suplemen terjadwal, serta lantai anti-slip hangat.', '["Observasi Paramedis & Dokter Hewan 2x Sehari","Pemberian Obat / Vitamin Tepat Jadwal","Lantai Anti-Slip & Pemanas Ruangan Terukur","Video Call Konsultasi Singkat dengan Dokter Hewan","Kabin Isolasi Steril & Tenang"]', 'fa-heart-pulse', 'sage');

INSERT IGNORE INTO `addons` (`id`, `name`, `price`, `price_type`, `icon`, `description`) VALUES
('addon_spa', 'Organic Spa & Full Grooming', 85000, 'flat', 'fa-shower', 'Mandi organik hypoallergenic, potong kuku, pembersihan telinga & blow styling sebelum pulang.'),
('addon_shuttle', 'Pet Taxi Antar-Jemput ber-AC', 60000, 'flat', 'fa-van-shuttle', 'Jemput dari rumah & antar kembali dengan armada HappyPet steril (area dalam kota).'),
('addon_cctv', 'Akses Private CCTV 24 Jam & Video Reel', 35000, 'flat', 'fa-video', 'Link streaming privat 24/7 & kompilasi video HD kegiatan anabul.'),
('addon_meal', 'Upgrade Premium Grain-Free Meal & Salmon Oil', 30000, 'per_day', 'fa-bowl-food', 'Pakan super premium + suplemen minyak salmon & probiotik (+Rp 30.000/hari).'),
('addon_vet', 'Medical Check-Up & Dental Cleanse', 75000, 'flat', 'fa-stethoscope', 'Pemeriksaan fisik menyeluruh oleh dokter hewan + pembersihan plak gigi ringan.');

-- Seed User Demo (Password untuk keduanya: password123)
INSERT IGNORE INTO `users` (`id`, `name`, `email`, `password`, `phone`, `address`, `emergency_contact`, `vet_notes`, `avatar`, `member_tier`, `joined_at`) VALUES
(1, 'Nadya Prameswari', 'nadya@happypet.id', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0812-8899-7766', 'Jl. Cempaka Putih Tengah No. 24, Jakarta Pusat', 'Raka Pratama (Suami) - 0811-2233-4455', 'Klinik Hewan Sehat Sentosa (drh. Clara) - Alergi pakan ayam olahan tertentu.', 'cat-sage', 'Platinum Paw Member', '2025-02-14'),
(2, 'Budi Santoso', 'budi@happypet.id', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0813-4567-8901', 'Jl. Kemang Raya No. 18B, Jakarta Selatan', 'Sinta (Istri) - 0812-9988-1122', 'Rutin vitamin bulu setiap pagi.', 'dog-amber', 'Priority Member', '2025-06-10');
