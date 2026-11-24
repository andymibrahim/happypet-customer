<?php
/**
 * HappyPet - Real-Time SQL (MySQL / PDO) Database & Activity Storage Manager
 *
 * Menyimpan seluruh data aplikasi & seluruh log interaksi pengguna secara real-time
 * ke database SQL (MySQL / MariaDB via PDO).
 * Dilengkapi auto-migrate tabel, auto-seed data awal, serta fallback aman ke JSON
 * apabila server MySQL belum aktif atau kredensial belum diisi.
 */

if (session_status() === PHP_SESSION_NONE) {
    if (PHP_OS_FAMILY === 'Windows') {
        $sessionDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
        if (!is_dir($sessionDir)) {
            @mkdir($sessionDir, 0777, true);
        }
        $realSessionDir = realpath($sessionDir);
        if ($realSessionDir) {
            @session_save_path($realSessionDir);
        }
    }
    @session_start();
}

date_default_timezone_set('Asia/Jakarta');

class HappyPetDB {
    private static $instance = null;
    private ?PDO $pdo = null;
    private array $dbConfig = [];
    private ?string $lastError = null;
    private string $driver = 'json';
    private string $storageFile;
    private array $data = [];

    private function __construct() {
        $storageDir = __DIR__ . '/../storage';
        $isVercel = !empty(getenv('VERCEL')) || !empty($_SERVER['VERCEL']) || !empty($_ENV['VERCEL']);
        if ($isVercel || (!is_dir($storageDir) && !@mkdir($storageDir, 0777, true)) || !is_writable($storageDir)) {
            $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'happypet_storage';
            if (!is_dir($tmpDir)) {
                @mkdir($tmpDir, 0777, true);
            }
            $storageDir = (is_dir($tmpDir) && is_writable($tmpDir)) ? $tmpDir : sys_get_temp_dir();
        }
        $this->storageFile = $storageDir . '/db.json';

        $configFile = __DIR__ . '/db_config.php';
        if (file_exists($configFile)) {
            $loaded = require $configFile;
            if (is_array($loaded)) {
                $this->dbConfig = $loaded;
            }
        }

        if (!empty($this->dbConfig['enabled']) && extension_loaded('pdo_mysql')) {
            $this->connectMySQL();
        }

        if ($this->pdo === null) {
            if (array_key_exists('fallback_json', $this->dbConfig) && $this->dbConfig['fallback_json'] === false) {
                $errDetail = $this->lastError ?: 'Ekstensi pdo_mysql tidak aktif atau service MySQL belum menyala.';
                $isApi = str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'api.php');
                if ($isApi) {
                    if (!headers_sent()) {
                        header('Content-Type: application/json; charset=utf-8');
                        http_response_code(503);
                    }
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Koneksi MySQL gagal.',
                        'detail' => $errDetail
                    ]);
                    exit;
                }
                if (PHP_SAPI === 'cli') {
                    throw new RuntimeException('Koneksi MySQL gagal: ' . $errDetail);
                }
                http_response_code(503);
                $isVercelEnv = !empty(getenv('VERCEL')) || !empty($_SERVER['VERCEL']) || !empty($_ENV['VERCEL']);
                $instructionHtml = $isVercelEnv
                    ? '<p>Anda sedang menjalankan HappyPet di <strong>Vercel (Cloud)</strong>. Server Vercel tidak dapat mengakses database <code>127.0.0.1</code> lokal di laptop Anda.</p><ol><li>Buka <strong>Vercel Dashboard &gt; Project Settings &gt; Environment Variables</strong>.</li><li>Tambahkan kredensial database cloud: <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code>, dan <code>DB_PORT</code>.</li><li>Lakukan <strong>Redeploy</strong> project Anda di Vercel.</li></ol>'
                    : '<p>HappyPet sedang berjalan dalam mode <strong>100% MySQL (Laragon)</strong>.</p><p><strong>Langkah perbaikan:</strong></p><ol><li>Buka aplikasi <strong>Laragon</strong> lalu klik tombol <strong>Start All</strong> hingga Apache &amp; MySQL menyala.</li><li>Periksa konfigurasi di <code>config/db_config.php</code> (host: <code>127.0.0.1</code>, database: <code>happypet_db</code>, username: <code>root</code>).</li></ol>';
                die('<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>MySQL Belum Aktif — HappyPet</title></head><body style="font-family:sans-serif;max-width:620px;margin:60px auto;padding:24px;background:#F8FAFC;color:#1E293B;"><div style="background:#fff;border:1px solid #E2E8F0;border-radius:16px;padding:28px;box-shadow:0 8px 24px rgba(0,0,0,0.06);"><h2 style="color:#B42318;margin-top:0;">Koneksi Database MySQL Belum Aktif</h2>' . $instructionHtml . '<p style="background:#FEECEC;color:#B42318;padding:12px;border-radius:8px;font-size:13px;font-family:monospace;word-break:break-word;">Detail: ' . htmlspecialchars($errDetail) . '</p><button onclick="location.reload()" style="background:#354F52;color:#fff;border:none;padding:10px 18px;border-radius:10px;cursor:pointer;font-weight:600;">Coba Muat Ulang</button></div></body></html>');
            }
            $this->driver = 'json';
            $this->loadOrSeedJson();
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ---- CONNECTION STATUS HELPERS ----
    public function getDriver(): string {
        return $this->driver;
    }

    public function isMySQL(): bool {
        return $this->pdo !== null && $this->driver === 'mysql';
    }

    public function getLastError(): ?string {
        return $this->lastError;
    }

    public function getDbInfo(): array {
        $counts = [
            'users' => 0,
            'pets' => 0,
            'packages' => 0,
            'addons' => 0,
            'bookings' => 0,
            'user_activity_logs' => 0,
        ];

        if ($this->isMySQL()) {
            foreach (array_keys($counts) as $tbl) {
                try {
                    $stmt = $this->pdo->query("SELECT COUNT(*) FROM `{$tbl}`");
                    $counts[$tbl] = (int)$stmt->fetchColumn();
                } catch (Throwable $e) {
                    $counts[$tbl] = 0;
                }
            }
        } else {
            $counts['users'] = count($this->data['users'] ?? []);
            $counts['pets'] = count($this->data['pets'] ?? []);
            $counts['packages'] = count($this->data['packages'] ?? []);
            $counts['addons'] = count($this->data['addons'] ?? []);
            $counts['bookings'] = count($this->data['bookings'] ?? []);
            $counts['user_activity_logs'] = count($this->data['activity_logs'] ?? []);
        }

        return [
            'driver' => $this->driver,
            'connected_sql' => $this->isMySQL(),
            'host' => $this->dbConfig['host'] ?? '127.0.0.1',
            'database' => $this->dbConfig['database'] ?? 'happypet_db',
            'error' => $this->lastError,
            'counts' => $counts,
        ];
    }

    // ---- MYSQL PDO INITIALIZATION & AUTO-MIGRATION ----
    private function connectMySQL(): void {
        $host = trim((string)($this->dbConfig['host'] ?? '127.0.0.1')) ?: '127.0.0.1';
        $port = trim((string)($this->dbConfig['port'] ?? '3306')) ?: '3306';
        $dbName = trim((string)($this->dbConfig['database'] ?? 'happypet_db')) ?: 'happypet_db';
        $user = trim((string)($this->dbConfig['username'] ?? 'root')) ?: 'root';
        $pass = (string)($this->dbConfig['password'] ?? '');
        $charset = trim((string)($this->dbConfig['charset'] ?? 'utf8mb4')) ?: 'utf8mb4';

        $pdoOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 3,
        ];

        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $pdoOptions);
        } catch (PDOException $e) {
            // Error 1049 = Unknown database -> buat otomatis jika berjalan di localhost (Laragon / XAMPP)
            $isLocal = in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
            $errCode = (int)$e->getCode();
            $driverErrCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
            $isUnknownDb = ($errCode === 1049 || $driverErrCode === 1049 || str_contains($e->getMessage(), '1049'));

            if ($isLocal && $isUnknownDb) {
                try {
                    $rootPdo = new PDO("mysql:host={$host};port={$port};charset={$charset}", $user, $pass, $pdoOptions);
                    $safeDb = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName);
                    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDb}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $this->pdo = new PDO($dsn, $user, $pass, $pdoOptions);
                } catch (Throwable $e2) {
                    $this->lastError = $e2->getMessage();
                    $this->pdo = null;
                    return;
                }
            } else {
                $this->lastError = $e->getMessage();
                $this->pdo = null;
                return;
            }
        }

        try {
            $this->pdo->exec("SET time_zone = '+07:00'");
        } catch (Throwable $e) {
            // Beberapa shared hosting tidak mengizinkan SET time_zone, aman diabaikan
        }

        $this->driver = 'mysql';
        $this->lastError = null;

        if (!empty($this->dbConfig['auto_migrate'])) {
            $this->ensureSqlSchemaAndSeed();
        }
    }

    private function ensureSqlSchemaAndSeed(): void {
        if (!$this->pdo) return;

        $queries = [
            "CREATE TABLE IF NOT EXISTS `users` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `pets` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `name` VARCHAR(80) NOT NULL,
                `species` VARCHAR(50) NOT NULL,
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `packages` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `code` VARCHAR(30) NOT NULL UNIQUE,
                `name` VARCHAR(100) NOT NULL,
                `category` VARCHAR(60) NOT NULL,
                `price_per_day` INT NOT NULL,
                `badge` VARCHAR(50) DEFAULT NULL,
                `room_size` VARCHAR(80) DEFAULT NULL,
                `suitable_for` VARCHAR(120) DEFAULT NULL,
                `description` TEXT NOT NULL,
                `facilities` TEXT NOT NULL,
                `icon` VARCHAR(40) DEFAULT 'fa-paw',
                `accent` VARCHAR(20) DEFAULT 'sage'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `addons` (
                `id` VARCHAR(50) PRIMARY KEY,
                `name` VARCHAR(120) NOT NULL,
                `price` INT NOT NULL,
                `price_type` VARCHAR(20) NOT NULL DEFAULT 'flat',
                `icon` VARCHAR(40) DEFAULT 'fa-sparkles',
                `description` TEXT DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `bookings` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `midtrans_config` (
                `id` TINYINT PRIMARY KEY DEFAULT 1,
                `environment` VARCHAR(20) NOT NULL DEFAULT 'sandbox',
                `mode` VARCHAR(20) NOT NULL DEFAULT 'simulator',
                `merchant_id` VARCHAR(60) DEFAULT 'G812945001',
                `client_key` VARCHAR(120) DEFAULT 'SB-Mid-client-HappyPetDemo2026',
                `server_key` VARCHAR(120) DEFAULT 'SB-Mid-server-HappyPetDemo2026',
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS `user_activity_logs` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT DEFAULT NULL,
                `user_name` VARCHAR(100) DEFAULT 'Guest',
                `user_email` VARCHAR(120) DEFAULT NULL,
                `action_type` VARCHAR(60) NOT NULL,
                `target_entity` VARCHAR(60) DEFAULT NULL,
                `target_id` VARCHAR(60) DEFAULT NULL,
                `description` TEXT NOT NULL,
                `metadata_json` TEXT DEFAULT NULL,
                `ip_address` VARCHAR(45) DEFAULT NULL,
                `user_agent` VARCHAR(255) DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_logs_user_id` (`user_id`),
                INDEX `idx_logs_action_type` (`action_type`),
                INDEX `idx_logs_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($queries as $sql) {
            $this->pdo->exec($sql);
        }

        // Seed atau migrasi data awal hanya jika tabel masih kosong
        $sourceData = $this->getInitialSeedOrJsonData();

        $pkgCount = (int)$this->pdo->query("SELECT COUNT(*) FROM `packages`")->fetchColumn();
        if ($pkgCount === 0 && !empty($sourceData['packages'])) {
            $stmt = $this->pdo->prepare(
                "INSERT IGNORE INTO `packages`
                (`id`, `code`, `name`, `category`, `price_per_day`, `badge`, `room_size`, `suitable_for`, `description`, `facilities`, `icon`, `accent`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($sourceData['packages'] as $pkg) {
                $stmt->execute([
                    (int)$pkg['id'],
                    $pkg['code'],
                    $pkg['name'],
                    $pkg['category'],
                    (int)$pkg['price_per_day'],
                    $pkg['badge'] ?? null,
                    $pkg['room_size'] ?? null,
                    $pkg['suitable_for'] ?? null,
                    $pkg['description'],
                    json_encode($pkg['facilities'] ?? [], JSON_UNESCAPED_UNICODE),
                    $pkg['icon'] ?? 'fa-paw',
                    $pkg['accent'] ?? 'sage',
                ]);
            }
        }

        $addonCount = (int)$this->pdo->query("SELECT COUNT(*) FROM `addons`")->fetchColumn();
        if ($addonCount === 0 && !empty($sourceData['addons'])) {
            $stmt = $this->pdo->prepare(
                "INSERT IGNORE INTO `addons` (`id`, `name`, `price`, `price_type`, `icon`, `description`)
                VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach ($sourceData['addons'] as $a) {
                $stmt->execute([
                    $a['id'],
                    $a['name'],
                    (int)$a['price'],
                    $a['price_type'] ?? 'flat',
                    $a['icon'] ?? 'fa-sparkles',
                    $a['desc'] ?? ($a['description'] ?? ''),
                ]);
            }
        }

        $cfgCount = (int)$this->pdo->query("SELECT COUNT(*) FROM `midtrans_config`")->fetchColumn();
        if ($cfgCount === 0) {
            $cfg = $sourceData['midtrans_config'] ?? [];
            $stmt = $this->pdo->prepare(
                "INSERT IGNORE INTO `midtrans_config` (`id`, `environment`, `mode`, `merchant_id`, `client_key`, `server_key`)
                VALUES (1, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $cfg['environment'] ?? 'sandbox',
                $cfg['mode'] ?? 'simulator',
                $cfg['merchant_id'] ?? 'G812945001',
                $cfg['client_key'] ?? 'SB-Mid-client-HappyPetDemo2026',
                $cfg['server_key'] ?? 'SB-Mid-server-HappyPetDemo2026',
            ]);
        }

        $userCount = (int)$this->pdo->query("SELECT COUNT(*) FROM `users`")->fetchColumn();
        if ($userCount === 0 && !empty($sourceData['users'])) {
            $stmtUser = $this->pdo->prepare(
                "INSERT IGNORE INTO `users`
                (`id`, `name`, `email`, `password`, `phone`, `address`, `emergency_contact`, `vet_notes`, `avatar`, `member_tier`, `joined_at`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($sourceData['users'] as $u) {
                $stmtUser->execute([
                    (int)$u['id'],
                    $u['name'],
                    strtolower(trim($u['email'])),
                    $u['password'],
                    $u['phone'] ?? '',
                    $u['address'] ?? '',
                    $u['emergency_contact'] ?? '',
                    $u['vet_notes'] ?? '',
                    $u['avatar'] ?? 'cat-sage',
                    $u['member_tier'] ?? 'Priority Member',
                    $u['joined_at'] ?? date('Y-m-d'),
                ]);
            }

            if (!empty($sourceData['pets'])) {
                $stmtPet = $this->pdo->prepare(
                    "INSERT IGNORE INTO `pets`
                    (`id`, `user_id`, `name`, `species`, `breed`, `age`, `weight`, `gender`, `vaccinated`, `special_notes`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                foreach ($sourceData['pets'] as $p) {
                    $stmtPet->execute([
                        (int)$p['id'],
                        (int)$p['user_id'],
                        $p['name'],
                        $p['species'],
                        $p['breed'] ?? '-',
                        $p['age'] ?? '1 Tahun',
                        (float)($p['weight'] ?? 3.5),
                        $p['gender'] ?? 'Jantan',
                        !empty($p['vaccinated']) ? 1 : 0,
                        $p['special_notes'] ?? '',
                    ]);
                }
            }

            if (!empty($sourceData['bookings'])) {
                $stmtB = $this->pdo->prepare(
                    "INSERT IGNORE INTO `bookings`
                    (`id`, `invoice_number`, `user_id`, `package_id`, `package_name`, `package_category`, `price_per_day`,
                     `pet_name`, `pet_species`, `pet_breed`, `pet_weight`, `pet_age`, `check_in`, `check_out`, `duration_days`,
                     `addons_json`, `addons_total`, `room_subtotal`, `admin_fee`, `total_amount`, `special_notes`, `pickup_option`,
                     `payment_status`, `payment_method`, `payment_channel`, `va_number`, `snap_token`, `midtrans_transaction_id`,
                     `paid_at`, `created_at`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                foreach ($sourceData['bookings'] as $b) {
                    $stmtB->execute([
                        (int)$b['id'],
                        $b['invoice_number'],
                        (int)$b['user_id'],
                        (int)$b['package_id'],
                        $b['package_name'],
                        $b['package_category'] ?? '',
                        (int)$b['price_per_day'],
                        $b['pet_name'],
                        $b['pet_species'],
                        $b['pet_breed'] ?? '-',
                        (float)($b['pet_weight'] ?? 0),
                        $b['pet_age'] ?? '-',
                        $b['check_in'],
                        $b['check_out'],
                        (int)$b['duration_days'],
                        json_encode($b['addons'] ?? [], JSON_UNESCAPED_UNICODE),
                        (int)($b['addons_total'] ?? 0),
                        (int)($b['room_subtotal'] ?? 0),
                        (int)($b['admin_fee'] ?? 5000),
                        (int)$b['total_amount'],
                        $b['special_notes'] ?? '',
                        $b['pickup_option'] ?? 'Diantar Sendiri ke Lobby HappyPet',
                        $b['payment_status'] ?? 'pending',
                        $b['payment_method'] ?? 'Midtrans Snap',
                        $b['payment_channel'] ?? 'Menunggu Pembayaran',
                        $b['va_number'] ?? '-',
                        $b['snap_token'] ?? null,
                        $b['midtrans_transaction_id'] ?? null,
                        $b['paid_at'] ?? null,
                        $b['created_at'] ?? date('Y-m-d H:i:s'),
                    ]);
                }
            }
        }

        // Pastikan kolom `role` dan akun Admin default selalu tersedia di MySQL
        $this->ensureAdminColumnAndAccount();
    }

    private function ensureAdminColumnAndAccount(): void {
        if (!$this->pdo) return;
        try {
            $ensureCol = function (string $table, string $col, string $definition) {
                $cols = $this->pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'")->fetchAll();
                if (empty($cols)) {
                    $this->pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$definition}");
                }
            };

            $ensureCol('users', 'role', "VARCHAR(20) NOT NULL DEFAULT 'customer'");
            $ensureCol('users', 'joined_at', "DATE DEFAULT NULL");
            $ensureCol('users', 'created_at', "DATETIME DEFAULT CURRENT_TIMESTAMP");
            $ensureCol('bookings', 'package_category', "VARCHAR(60) DEFAULT ''");

            $stmt = $this->pdo->prepare("SELECT `id`, `role` FROM `users` WHERE LOWER(`email`) = 'admin@happypet.id' LIMIT 1");
            $stmt->execute();
            $adminRow = $stmt->fetch();
            if (!$adminRow) {
                $adminPass = password_hash('admin123', PASSWORD_DEFAULT);
                $ins = $this->pdo->prepare(
                    "INSERT INTO `users` (`name`, `email`, `password`, `phone`, `address`, `emergency_contact`, `vet_notes`, `avatar`, `member_tier`, `role`, `joined_at`, `created_at`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'admin', ?, ?)"
                );
                $ins->execute([
                    'Administrator HappyPet',
                    'admin@happypet.id',
                    $adminPass,
                    '021-8899-0011',
                    'Kantor Pusat HappyPet — Jl. Kemang Raya No. 88, Jakarta Selatan',
                    'CS HappyPet 24 Jam',
                    'Akun Administrator Sistem HappyPet',
                    'paw-forest',
                    'Super Admin',
                    date('Y-m-d'),
                    date('Y-m-d H:i:s'),
                ]);
            } elseif (($adminRow['role'] ?? '') !== 'admin') {
                $upd = $this->pdo->prepare("UPDATE `users` SET `role` = 'admin' WHERE `id` = ?");
                $upd->execute([(int)$adminRow['id']]);
            }
        } catch (Throwable $e) {
            // Abaikan error minor saat cek kolom
        }
    }

    private function getInitialSeedOrJsonData(): array {
        if (file_exists($this->storageFile)) {
            $raw = @file_get_contents($this->storageFile);
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded) && isset($decoded['users'], $decoded['packages'], $decoded['bookings'])) {
                return $decoded;
            }
        }
        return $this->getSeedData();
    }

    // ---- JSON FALLBACK ----
    private function loadOrSeedJson(): void {
        if (file_exists($this->storageFile)) {
            $raw = @file_get_contents($this->storageFile);
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded) && isset($decoded['users'], $decoded['packages'], $decoded['bookings'])) {
                if (!isset($decoded['activity_logs']) || !is_array($decoded['activity_logs'])) {
                    $decoded['activity_logs'] = [];
                }
                $this->data = $decoded;
                return;
            }
        }

        // Jika storageFile diarahkan ke /tmp (Vercel serverless), baca template awal dari storage bawaan
        $defaultFile = __DIR__ . '/../storage/db.json';
        if ($this->storageFile !== $defaultFile && file_exists($defaultFile)) {
            $raw = @file_get_contents($defaultFile);
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded) && isset($decoded['users'], $decoded['packages'], $decoded['bookings'])) {
                if (!isset($decoded['activity_logs']) || !is_array($decoded['activity_logs'])) {
                    $decoded['activity_logs'] = [];
                }
                $this->data = $decoded;
                $this->save();
                return;
            }
        }

        $this->data = $this->getSeedData();
        $this->data['activity_logs'] = [];
        $this->save();
    }

    public function save(): void {
        if ($this->isMySQL()) {
            return;
        }
        @file_put_contents(
            $this->storageFile,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function getSeedData(): array {
        $passwordHash = password_hash('password123', PASSWORD_DEFAULT);

        return [
            'midtrans_config' => [
                'environment' => 'sandbox',
                'mode' => 'simulator',
                'merchant_id' => 'G812945001',
                'client_key' => 'SB-Mid-client-HappyPetDemo2026',
                'server_key' => 'SB-Mid-server-HappyPetDemo2026'
            ],
            'users' => [
                [
                    'id' => 1,
                    'name' => 'Nadya Prameswari',
                    'email' => 'nadya@happypet.id',
                    'password' => $passwordHash,
                    'role' => 'customer',
                    'phone' => '0812-8899-7766',
                    'address' => 'Jl. Cempaka Putih Tengah No. 24, Jakarta Pusat',
                    'emergency_contact' => 'Raka Pratama (Suami) - 0811-2233-4455',
                    'vet_notes' => 'Klinik Hewan Sehat Sentosa (drh. Clara) - Alergi pakan ayam olahan tertentu.',
                    'avatar' => 'cat-sage',
                    'member_tier' => 'Platinum Paw Member',
                    'joined_at' => '2025-02-14',
                    'created_at' => '2025-02-14 09:15:00'
                ],
                [
                    'id' => 2,
                    'name' => 'Budi Santoso',
                    'email' => 'budi@happypet.id',
                    'password' => $passwordHash,
                    'role' => 'customer',
                    'phone' => '0813-4567-8901',
                    'address' => 'Jl. Kemang Raya No. 18B, Jakarta Selatan',
                    'emergency_contact' => 'Sinta (Istri) - 0812-9988-1122',
                    'vet_notes' => 'Rutin vitamin bulu setiap pagi.',
                    'avatar' => 'dog-amber',
                    'member_tier' => 'Priority Member',
                    'joined_at' => '2025-06-10',
                    'created_at' => '2025-06-10 14:30:00'
                ],
                [
                    'id' => 99,
                    'name' => 'Administrator HappyPet',
                    'email' => 'admin@happypet.id',
                    'password' => password_hash('admin123', PASSWORD_DEFAULT),
                    'role' => 'admin',
                    'phone' => '021-8899-0011',
                    'address' => 'Kantor Pusat HappyPet — Jl. Kemang Raya No. 88, Jakarta Selatan',
                    'emergency_contact' => 'CS HappyPet 24 Jam',
                    'vet_notes' => 'Akun Administrator Sistem HappyPet',
                    'avatar' => 'dog-amber',
                    'member_tier' => 'Official Admin',
                    'joined_at' => '2025-01-01',
                    'created_at' => '2025-01-01 08:00:00'
                ]
            ],
            'pets' => [
                [
                    'id' => 1,
                    'user_id' => 1,
                    'name' => 'Mochi',
                    'species' => 'Kucing',
                    'breed' => 'Scottish Fold',
                    'age' => '2 Tahun',
                    'weight' => 4.2,
                    'gender' => 'Betina',
                    'vaccinated' => true,
                    'special_notes' => 'Suka tidur di bantal hangat, makan Royal Canin Hair & Skin 2x sehari.'
                ],
                [
                    'id' => 2,
                    'user_id' => 1,
                    'name' => 'Biscuit',
                    'species' => 'Anjing Kecil',
                    'breed' => 'Toy Poodle',
                    'age' => '1.5 Tahun',
                    'weight' => 3.8,
                    'gender' => 'Jantan',
                    'vaccinated' => true,
                    'special_notes' => 'Sangat aktif, senang bermain bola karet di taman sore hari.'
                ],
                [
                    'id' => 3,
                    'user_id' => 1,
                    'name' => 'Oliver',
                    'species' => 'Anjing Besar',
                    'breed' => 'Golden Retriever',
                    'age' => '3 Tahun',
                    'weight' => 26.5,
                    'gender' => 'Jantan',
                    'vaccinated' => true,
                    'special_notes' => 'Ramah dengan hewan lain, butuh jalan pagi minimal 20 menit.'
                ],
                [
                    'id' => 4,
                    'user_id' => 2,
                    'name' => 'Milo',
                    'species' => 'Kucing',
                    'breed' => 'British Shorthair',
                    'age' => '2 Tahun',
                    'weight' => 4.8,
                    'gender' => 'Jantan',
                    'vaccinated' => true,
                    'special_notes' => 'Suka ruangan tenang ber-AC sejuk.'
                ]
            ],
            'packages' => [
                [
                    'id' => 1,
                    'code' => 'PKG-COZY',
                    'name' => 'Cozy Alabaster Room',
                    'category' => 'Standard Pet Hotel',
                    'price_per_day' => 125000,
                    'badge' => 'Hemat & Steril',
                    'room_size' => '1.5m × 1.5m Private Cabin',
                    'suitable_for' => 'Kucing & Anjing Kecil (< 8 kg)',
                    'description' => 'Kabin privat ber-AC dengan sirkulasi udara HEPA filter, alas tidur ortopedi lembut, dan pembersihan UV steril 2x sehari.',
                    'facilities' => [
                        'Kabin Privat AC & Air Purifier HEPA',
                        'Makan 2x Sehari & Air Mineral RO',
                        'Sesi Bermain Indoor 2x (30 Menit)',
                        'Update Foto & Laporan Harian via WhatsApp',
                        'Pembersihan Litter Box / Toilet Berkala'
                    ],
                    'icon' => 'fa-house-chimney-window',
                    'accent' => 'sage'
                ],
                [
                    'id' => 2,
                    'code' => 'PKG-GARDEN',
                    'name' => 'Sage Garden Suite',
                    'category' => 'Deluxe Pet Suite',
                    'price_per_day' => 215000,
                    'badge' => 'Paling Populer',
                    'room_size' => '2.4m × 2.0m Glass Suite',
                    'suitable_for' => 'Kucing, Anjing Kecil & Sedang',
                    'description' => 'Suite kaca kedap suara bernuansa alam dengan akses taman rumput sintetis steril, aromaterapi lavender ramah hewan, dan CCTV 24 jam.',
                    'facilities' => [
                        'Suite Kaca Tempered + Balkon Taman Privat',
                        'Akses Live CCTV 24 Jam dari Smartphone',
                        'Aromaterapi Relaksasi & Musik Klasik Hewan',
                        'Sesi Playtime & Agility Bersama Pet Sitter',
                        'Free Brushing & Cologne Harian'
                    ],
                    'icon' => 'fa-leaf',
                    'accent' => 'forest'
                ],
                [
                    'id' => 3,
                    'code' => 'PKG-ROYAL',
                    'name' => 'Royal Forest VIP Villa',
                    'category' => 'Luxury Private Villa',
                    'price_per_day' => 350000,
                    'badge' => 'Eksklusif VIP',
                    'room_size' => '3.5m × 3.0m Luxury Villa',
                    'suitable_for' => 'Semua Jenis Hewan (Termasuk Ras Besar)',
                    'description' => 'Villa mewah eksklusif dengan kasur hypoallergenic premium, dedicated butler, pemeriksaan dokter hewan harian, dan sesi organic spa gratis.',
                    'facilities' => [
                        'Private Luxury Villa + Kasur Orthopedic Memory Foam',
                        '1-on-1 Dedicated Pet Butler & Pemeriksaan drh. Harian',
                        'Akses Private Live Stream CCTV Full HD 24/7',
                        'Gratis 1x Organic Oatmeal Spa & Blow Dry',
                        'Menu Premium Wet Food + Vitamin Imun Harian'
                    ],
                    'icon' => 'fa-crown',
                    'accent' => 'amber'
                ],
                [
                    'id' => 4,
                    'code' => 'PKG-DAYCARE',
                    'name' => 'Zen Daycare & Social Club',
                    'category' => 'Daycare & Socialization',
                    'price_per_day' => 95000,
                    'badge' => 'Penitipan Harian',
                    'room_size' => 'Indoor Playground Area',
                    'suitable_for' => 'Kucing & Anjing Aktif',
                    'description' => 'Titip harian dari pagi hingga malam dengan aktivitas sosialisasi terawasi, permainan ketangkasan, dan tidur siang di ruang relaksasi.',
                    'facilities' => [
                        'Full Day Care (07.30 - 20.00 WIB)',
                        'Social Playgroup Sesuai Ukuran & Karakter',
                        'Snack Sehat & Hidrasi Terkontrol',
                        'Nap Time di Ruangan Ber-AC Tenang',
                        'Laporan Aktivitas Sore Hari'
                    ],
                    'icon' => 'fa-sun',
                    'accent' => 'sand'
                ],
                [
                    'id' => 5,
                    'code' => 'PKG-MEDICAL',
                    'name' => 'Gentle Vet & Senior Care',
                    'category' => 'Special Needs & Senior',
                    'price_per_day' => 275000,
                    'badge' => 'Pendampingan Medis',
                    'room_size' => '2.2m × 2.0m Care Suite',
                    'suitable_for' => 'Hewan Senior / Pemulihan / Butuh Obat Rutin',
                    'description' => 'Ruang penitipan tenang dengan pengawasan tim paramedis veteriner, pemberian obat/suplemen terjadwal, serta lantai anti-slip hangat.',
                    'facilities' => [
                        'Observasi Paramedis & Dokter Hewan 2x Sehari',
                        'Pemberian Obat / Vitamin Tepat Jadwal',
                        'Lantai Anti-Slip & Pemanas Ruangan Terukur',
                        'Video Call Konsultasi Singkat dengan Dokter Hewan',
                        'Kabin Isolasi Steril & Tenang'
                    ],
                    'icon' => 'fa-heart-pulse',
                    'accent' => 'sage'
                ]
            ],
            'addons' => [
                [
                    'id' => 'addon_spa',
                    'name' => 'Organic Spa & Full Grooming',
                    'price' => 85000,
                    'price_type' => 'flat',
                    'icon' => 'fa-shower',
                    'desc' => 'Mandi organik hypoallergenic, potong kuku, pembersihan telinga & blow styling sebelum pulang.'
                ],
                [
                    'id' => 'addon_shuttle',
                    'name' => 'Pet Taxi Antar-Jemput ber-AC',
                    'price' => 60000,
                    'price_type' => 'flat',
                    'icon' => 'fa-van-shuttle',
                    'desc' => 'Jemput dari rumah & antar kembali dengan armada HappyPet steril (area dalam kota).'
                ],
                [
                    'id' => 'addon_cctv',
                    'name' => 'Akses Private CCTV 24 Jam & Video Reel',
                    'price' => 35000,
                    'price_type' => 'flat',
                    'icon' => 'fa-video',
                    'desc' => 'Link streaming privat 24/7 & kompilasi video HD kegiatan anabul.'
                ],
                [
                    'id' => 'addon_meal',
                    'name' => 'Upgrade Premium Grain-Free Meal & Salmon Oil',
                    'price' => 30000,
                    'price_type' => 'per_day',
                    'icon' => 'fa-bowl-food',
                    'desc' => 'Pakan super premium + suplemen minyak salmon & probiotik (+Rp 30.000/hari).'
                ],
                [
                    'id' => 'addon_vet',
                    'name' => 'Medical Check-Up & Dental Cleanse',
                    'price' => 75000,
                    'price_type' => 'flat',
                    'icon' => 'fa-stethoscope',
                    'desc' => 'Pemeriksaan fisik menyeluruh oleh dokter hewan + pembersihan plak gigi ringan.'
                ]
            ],
            'bookings' => [
                [
                    'id' => 101,
                    'invoice_number' => 'INV-HP-20261101-8821',
                    'user_id' => 1,
                    'package_id' => 2,
                    'package_name' => 'Sage Garden Suite',
                    'package_category' => 'Deluxe Pet Suite',
                    'price_per_day' => 215000,
                    'pet_name' => 'Mochi',
                    'pet_species' => 'Kucing',
                    'pet_breed' => 'Scottish Fold',
                    'pet_weight' => 4.2,
                    'pet_age' => '2 Tahun',
                    'check_in' => '2026-11-02',
                    'check_out' => '2026-11-05',
                    'duration_days' => 3,
                    'addons' => [
                        ['id' => 'addon_spa', 'name' => 'Organic Spa & Full Grooming', 'price' => 85000],
                        ['id' => 'addon_cctv', 'name' => 'Akses Private CCTV 24 Jam & Video Reel', 'price' => 35000]
                    ],
                    'addons_total' => 120000,
                    'room_subtotal' => 645000,
                    'admin_fee' => 5000,
                    'total_amount' => 770000,
                    'special_notes' => 'Tolong berikan Royal Canin Hair & Skin takaran 35gr pagi dan malam. Jangan lupa sisir bulu sore hari.',
                    'pickup_option' => 'Diantar Sendiri ke Lobby HappyPet',
                    'payment_status' => 'settlement',
                    'payment_method' => 'Midtrans Snap',
                    'payment_channel' => 'BCA Virtual Account',
                    'va_number' => '8277081288997766',
                    'snap_token' => 'SNAP-HP-8821-99412A',
                    'midtrans_transaction_id' => 'MID-TRX-20261101-009281',
                    'paid_at' => '2026-11-01 14:22:18',
                    'created_at' => '2026-11-01 14:18:05'
                ],
                [
                    'id' => 102,
                    'invoice_number' => 'INV-HP-20261106-9043',
                    'user_id' => 1,
                    'package_id' => 3,
                    'package_name' => 'Royal Forest VIP Villa',
                    'package_category' => 'Luxury Private Villa',
                    'price_per_day' => 350000,
                    'pet_name' => 'Biscuit',
                    'pet_species' => 'Anjing Kecil',
                    'pet_breed' => 'Toy Poodle',
                    'pet_weight' => 3.8,
                    'pet_age' => '1.5 Tahun',
                    'check_in' => '2026-11-07',
                    'check_out' => '2026-11-09',
                    'duration_days' => 2,
                    'addons' => [
                        ['id' => 'addon_shuttle', 'name' => 'Pet Taxi Antar-Jemput ber-AC', 'price' => 60000]
                    ],
                    'addons_total' => 60000,
                    'room_subtotal' => 700000,
                    'admin_fee' => 5000,
                    'total_amount' => 765000,
                    'special_notes' => 'Biscuit membawa mainan boneka bebek kesayangannya, mohon ditaruh di kasur.',
                    'pickup_option' => 'Jemput dengan Pet Taxi HappyPet',
                    'payment_status' => 'settlement',
                    'payment_method' => 'Midtrans Snap',
                    'payment_channel' => 'QRIS (GoPay / M-Banking)',
                    'va_number' => 'QRIS-HP-9043881',
                    'snap_token' => 'SNAP-HP-9043-77231B',
                    'midtrans_transaction_id' => 'MID-TRX-20261106-041822',
                    'paid_at' => '2026-11-06 19:05:44',
                    'created_at' => '2026-11-06 19:03:10'
                ],
                [
                    'id' => 103,
                    'invoice_number' => 'INV-HP-20261109-9510',
                    'user_id' => 1,
                    'package_id' => 1,
                    'package_name' => 'Cozy Alabaster Room',
                    'package_category' => 'Standard Pet Hotel',
                    'price_per_day' => 125000,
                    'pet_name' => 'Mochi',
                    'pet_species' => 'Kucing',
                    'pet_breed' => 'Scottish Fold',
                    'pet_weight' => 4.2,
                    'pet_age' => '2 Tahun',
                    'check_in' => '2026-11-12',
                    'check_out' => '2026-11-15',
                    'duration_days' => 3,
                    'addons' => [
                        ['id' => 'addon_vet', 'name' => 'Medical Check-Up & Dental Cleanse', 'price' => 75000]
                    ],
                    'addons_total' => 75000,
                    'room_subtotal' => 375000,
                    'admin_fee' => 5000,
                    'total_amount' => 455000,
                    'special_notes' => 'Titip selama perjalanan dinas luar kota 3 hari.',
                    'pickup_option' => 'Diantar Sendiri ke Lobby HappyPet',
                    'payment_status' => 'pending',
                    'payment_method' => 'Midtrans Snap',
                    'payment_channel' => 'Menunggu Pembayaran',
                    'va_number' => '-',
                    'snap_token' => 'SNAP-HP-9510-33109C',
                    'midtrans_transaction_id' => 'MID-TRX-20261109-095100',
                    'paid_at' => null,
                    'created_at' => '2026-11-09 20:15:00'
                ]
            ],
            'activity_logs' => []
        ];
    }

    // ---- ROW FORMATTERS FOR SQL ----
    private function normalizePackageRow(array $row): array {
        $facilities = [];
        if (!empty($row['facilities'])) {
            $decoded = json_decode($row['facilities'], true);
            if (is_array($decoded)) $facilities = $decoded;
        }
        $row['id'] = (int)$row['id'];
        $row['price_per_day'] = (int)$row['price_per_day'];
        $row['facilities'] = $facilities;
        return $row;
    }

    private function normalizeAddonRow(array $row): array {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'price' => (int)$row['price'],
            'price_type' => $row['price_type'] ?? 'flat',
            'icon' => $row['icon'] ?? 'fa-sparkles',
            'desc' => $row['description'] ?? ($row['desc'] ?? ''),
        ];
    }

    private function normalizePetRow(array $row): array {
        $row['id'] = (int)$row['id'];
        $row['user_id'] = (int)$row['user_id'];
        $row['weight'] = (float)$row['weight'];
        $row['vaccinated'] = !empty($row['vaccinated']);
        return $row;
    }

    private function normalizeBookingRow(array $row): array {
        $addons = [];
        if (isset($row['addons_json'])) {
            $decoded = json_decode((string)$row['addons_json'], true);
            if (is_array($decoded)) $addons = $decoded;
        } elseif (isset($row['addons']) && is_array($row['addons'])) {
            $addons = $row['addons'];
        }
        $row['id'] = (int)$row['id'];
        $row['user_id'] = (int)$row['user_id'];
        $row['package_id'] = (int)$row['package_id'];
        $row['price_per_day'] = (int)$row['price_per_day'];
        $row['pet_weight'] = (float)$row['pet_weight'];
        $row['duration_days'] = (int)$row['duration_days'];
        $row['addons'] = $addons;
        $row['addons_total'] = (int)$row['addons_total'];
        $row['room_subtotal'] = (int)$row['room_subtotal'];
        $row['admin_fee'] = (int)$row['admin_fee'];
        $row['total_amount'] = (int)$row['total_amount'];
        unset($row['addons_json']);
        return $row;
    }

    // ---- USER OPERATIONS ----
    public function findUserByEmail(string $email): ?array {
        $normalized = strtolower(trim($email));
        if ($this->isMySQL()) {
            $stmt = $this->pdo->prepare("SELECT * FROM `users` WHERE LOWER(`email`) = ? LIMIT 1");
            $stmt->execute([$normalized]);
            $row = $stmt->fetch();
            if (!$row) return null;
            $row['id'] = (int)$row['id'];
            return $row;
        }

        foreach ($this->data['users'] as $u) {
            if (strtolower($u['email']) === $normalized) {
                return $u;
            }
        }
        return null;
    }

    public function findUserById(int $id): ?array {
        if ($this->isMySQL()) {
            $stmt = $this->pdo->prepare("SELECT * FROM `users` WHERE `id` = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) return null;
            $row['id'] = (int)$row['id'];
            return $row;
        }

        foreach ($this->data['users'] as $u) {
            if ((int)$u['id'] === $id) {
                return $u;
            }
        }
        return null;
    }

    public function createUser(array $userData): array {
        $name = trim($userData['name']);
        $email = strtolower(trim($userData['email']));
        $passwordHash = password_hash($userData['password'], PASSWORD_DEFAULT);
        $phone = trim($userData['phone'] ?? '');
        $address = trim($userData['address'] ?? '');
        $emergency = trim($userData['emergency_contact'] ?? '');
        $vetNotes = trim($userData['vet_notes'] ?? '');
        $avatar = $userData['avatar'] ?? 'cat-sage';
        $memberTier = 'HappyPaw Member';
        $joinedAt = date('Y-m-d');

        if ($this->isMySQL()) {
            $stmt = $this->pdo->prepare(
                "INSERT INTO `users`
                (`name`, `email`, `password`, `phone`, `address`, `emergency_contact`, `vet_notes`, `avatar`, `member_tier`, `joined_at`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$name, $email, $passwordHash, $phone, $address, $emergency, $vetNotes, $avatar, $memberTier, $joinedAt]);
            $newId = (int)$this->pdo->lastInsertId();
            return $this->findUserById($newId) ?? [
                'id' => $newId, 'name' => $name, 'email' => $email, 'password' => $passwordHash,
                'phone' => $phone, 'address' => $address, 'emergency_contact' => $emergency,
                'vet_notes' => $vetNotes, 'avatar' => $avatar, 'member_tier' => $memberTier, 'joined_at' => $joinedAt
            ];
        }

        $maxId = 0;
        foreach ($this->data['users'] as $u) {
            if ($u['id'] > $maxId) $maxId = $u['id'];
        }
        $newUser = [
            'id' => $maxId + 1,
            'name' => $name,
            'email' => $email,
            'password' => $passwordHash,
            'phone' => $phone,
            'address' => $address,
            'emergency_contact' => $emergency,
            'vet_notes' => $vetNotes,
            'avatar' => $avatar,
            'member_tier' => $memberTier,
            'joined_at' => $joinedAt
        ];
        $this->data['users'][] = $newUser;
        $this->save();
        return $newUser;
    }

    public function updateUser(int $id, array $updates): ?array {
        if ($this->isMySQL()) {
            $existing = $this->findUserById($id);
            if (!$existing) return null;

            $fields = [];
            $params = [];
            $map = ['name', 'phone', 'address', 'emergency_contact', 'vet_notes', 'avatar'];
            foreach ($map as $col) {
                if (isset($updates[$col])) {
                    $fields[] = "`{$col}` = ?";
                    $params[] = trim((string)$updates[$col]);
                }
            }
            if (isset($updates['email'])) {
                $fields[] = "`email` = ?";
                $params[] = strtolower(trim((string)$updates['email']));
            }
            if (!empty($updates['new_password'])) {
                $fields[] = "`password` = ?";
                $params[] = password_hash($updates['new_password'], PASSWORD_DEFAULT);
            }

            if (!empty($fields)) {
                $params[] = $id;
                $sql = "UPDATE `users` SET " . implode(', ', $fields) . " WHERE `id` = ?";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
            }
            return $this->findUserById($id);
        }

        foreach ($this->data['users'] as &$u) {
            if ((int)$u['id'] === $id) {
                if (isset($updates['name'])) $u['name'] = trim($updates['name']);
                if (isset($updates['email'])) $u['email'] = strtolower(trim($updates['email']));
                if (isset($updates['phone'])) $u['phone'] = trim($updates['phone']);
                if (isset($updates['address'])) $u['address'] = trim($updates['address']);
                if (isset($updates['emergency_contact'])) $u['emergency_contact'] = trim($updates['emergency_contact']);
                if (isset($updates['vet_notes'])) $u['vet_notes'] = trim($updates['vet_notes']);
                if (isset($updates['avatar'])) $u['avatar'] = trim($updates['avatar']);
                if (!empty($updates['new_password'])) {
                    $u['password'] = password_hash($updates['new_password'], PASSWORD_DEFAULT);
                }
                $this->save();
                return $u;
            }
        }
        return null;
    }

    // ---- PACKAGES & ADDONS ----
    public function getPackages(): array {
        if ($this->isMySQL()) {
            $stmt = $this->pdo->query("SELECT * FROM `packages` ORDER BY `id` ASC");
            $rows = $stmt->fetchAll();
            return array_map([$this, 'normalizePackageRow'], $rows);
        }
        return $this->data['packages'];
    }

    public function getPackageById(int $id): ?array {
        if ($this->isMySQL()) {
            $stmt = $this->pdo->prepare("SELECT * FROM `packages` WHERE `id` = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            return $row ? $this->normalizePackageRow($row) : null;
        }
        foreach ($this->data['packages'] as $pkg) {
            if ((int)$pkg['id'] === $id) return $pkg;
        }
        return null;
    }

    public function getAddons(): array {
        if ($this->isMySQL()) {
            $stmt = $this->pdo->query("SELECT * FROM `addons`");
            $rows = $stmt->fetchAll();
            return array_map([$this, 'normalizeAddonRow'], $rows);
        }
        return $this->data['addons'];
    }

    // ---- PETS ----
    public function getPetsByUser(int $userId): array {
        if ($this->isMySQL()) {
            $stmt = $this->pdo->prepare("SELECT * FROM `pets` WHERE `user_id` = ? ORDER BY `id` ASC");
            $stmt->execute([$userId]);
            return array_map([$this, 'normalizePetRow'], $stmt->fetchAll());
        }

        $list = [];
        foreach ($this->data['pets'] as $pet) {
            if ((int)$pet['user_id'] === $userId) {
                $list[] = $pet;
            }
        }
        return $list;
    }

    public function savePet(int $userId, array $petData): array {
        $name = trim($petData['name']);
        $species = trim($petData['species'] ?? 'Kucing');
        $breed = trim($petData['breed'] ?? '-');
        $age = trim($petData['age'] ?? '1 Tahun');
        $weight = (float)($petData['weight'] ?? 3.5);
        $gender = trim($petData['gender'] ?? 'Jantan');
        $vaccinated = !empty($petData['vaccinated']) ? 1 : 0;
        $notes = trim($petData['special_notes'] ?? '');

        if ($this->isMySQL()) {
            if (!empty($petData['id'])) {
                $petId = (int)$petData['id'];
                $stmt = $this->pdo->prepare(
                    "UPDATE `pets` SET `name` = ?, `species` = ?, `breed` = ?, `age` = ?, `weight` = ?, `gender` = ?, `vaccinated` = ?, `special_notes` = ?
                    WHERE `id` = ? AND `user_id` = ?"
                );
                $stmt->execute([$name, $species, $breed, $age, $weight, $gender, $vaccinated, $notes, $petId, $userId]);
                $check = $this->pdo->prepare("SELECT * FROM `pets` WHERE `id` = ? AND `user_id` = ? LIMIT 1");
                $check->execute([$petId, $userId]);
                $row = $check->fetch();
                if ($row) return $this->normalizePetRow($row);
            }

            $stmt = $this->pdo->prepare(
                "INSERT INTO `pets` (`user_id`, `name`, `species`, `breed`, `age`, `weight`, `gender`, `vaccinated`, `special_notes`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $name, $species, $breed, $age, $weight, $gender, $vaccinated, $notes]);
            $newId = (int)$this->pdo->lastInsertId();
            $check = $this->pdo->prepare("SELECT * FROM `pets` WHERE `id` = ? LIMIT 1");
            $check->execute([$newId]);
            $row = $check->fetch();
            return $row ? $this->normalizePetRow($row) : [
                'id' => $newId, 'user_id' => $userId, 'name' => $name, 'species' => $species,
                'breed' => $breed, 'age' => $age, 'weight' => $weight, 'gender' => $gender,
                'vaccinated' => (bool)$vaccinated, 'special_notes' => $notes
            ];
        }

        if (!empty($petData['id'])) {
            foreach ($this->data['pets'] as &$p) {
                if ((int)$p['id'] === (int)$petData['id'] && (int)$p['user_id'] === $userId) {
                    $p['name'] = $name;
                    $p['species'] = $species;
                    $p['breed'] = $breed;
                    $p['age'] = $age;
                    $p['weight'] = $weight;
                    $p['gender'] = $gender;
                    $p['vaccinated'] = (bool)$vaccinated;
                    $p['special_notes'] = $notes;
                    $this->save();
                    return $p;
                }
            }
        }
        $maxId = 0;
        foreach ($this->data['pets'] as $p) {
            if ($p['id'] > $maxId) $maxId = $p['id'];
        }
        $newPet = [
            'id' => $maxId + 1,
            'user_id' => $userId,
            'name' => $name,
            'species' => $species,
            'breed' => $breed,
            'age' => $age,
            'weight' => $weight,
            'gender' => $gender,
            'vaccinated' => (bool)$vaccinated,
            'special_notes' => $notes
        ];
        $this->data['pets'][] = $newPet;
        $this->save();
        return $newPet;
    }

    public function deletePet(int $userId, int $petId): bool {
        if ($this->isMySQL()) {
            $stmt = $this->pdo->prepare("DELETE FROM `pets` WHERE `id` = ? AND `user_id` = ?");
            $stmt->execute([$petId, $userId]);
            return $stmt->rowCount() > 0;
        }

        foreach ($this->data['pets'] as $idx => $p) {
            if ((int)$p['id'] === $petId && (int)$p['user_id'] === $userId) {
                array_splice($this->data['pets'], $idx, 1);
                $this->save();
                return true;
            }
        }
        return false;
    }

    // ---- BOOKINGS ----
    public function getBookingsByUser(int $userId): array {
        if ($this->isMySQL()) {
            $stmt = $this->pdo->prepare("SELECT * FROM `bookings` WHERE `user_id` = ? ORDER BY `created_at` DESC, `id` DESC");
            $stmt->execute([$userId]);
            return array_map([$this, 'normalizeBookingRow'], $stmt->fetchAll());
        }

        $list = [];
        foreach ($this->data['bookings'] as $b) {
            if ((int)$b['user_id'] === $userId) {
                $list[] = $b;
            }
        }
        usort($list, function ($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });
        return $list;
    }

    public function getBookingById(int $bookingId, ?int $userId = null): ?array {
        if ($this->isMySQL()) {
            if ($userId !== null) {
                $stmt = $this->pdo->prepare("SELECT * FROM `bookings` WHERE `id` = ? AND `user_id` = ? LIMIT 1");
                $stmt->execute([$bookingId, $userId]);
            } else {
                $stmt = $this->pdo->prepare("SELECT * FROM `bookings` WHERE `id` = ? LIMIT 1");
                $stmt->execute([$bookingId]);
            }
            $row = $stmt->fetch();
            return $row ? $this->normalizeBookingRow($row) : null;
        }

        foreach ($this->data['bookings'] as $b) {
            if ((int)$b['id'] === $bookingId) {
                if ($userId !== null && (int)$b['user_id'] !== $userId) {
                    return null;
                }
                return $b;
            }
        }
        return null;
    }

    public function getBookingByInvoice(string $invoice, ?int $userId = null): ?array {
        if ($this->isMySQL()) {
            if ($userId !== null) {
                $stmt = $this->pdo->prepare("SELECT * FROM `bookings` WHERE `invoice_number` = ? AND `user_id` = ? LIMIT 1");
                $stmt->execute([$invoice, $userId]);
            } else {
                $stmt = $this->pdo->prepare("SELECT * FROM `bookings` WHERE `invoice_number` = ? LIMIT 1");
                $stmt->execute([$invoice]);
            }
            $row = $stmt->fetch();
            return $row ? $this->normalizeBookingRow($row) : null;
        }

        foreach ($this->data['bookings'] as $b) {
            if ($b['invoice_number'] === $invoice) {
                if ($userId !== null && (int)$b['user_id'] !== $userId) {
                    return null;
                }
                return $b;
            }
        }
        return null;
    }

    public function createBooking(array $bookingData): array {
        if ($this->isMySQL()) {
            $stmt = $this->pdo->prepare(
                "INSERT INTO `bookings`
                (`invoice_number`, `user_id`, `package_id`, `package_name`, `package_category`, `price_per_day`,
                 `pet_name`, `pet_species`, `pet_breed`, `pet_weight`, `pet_age`, `check_in`, `check_out`, `duration_days`,
                 `addons_json`, `addons_total`, `room_subtotal`, `admin_fee`, `total_amount`, `special_notes`, `pickup_option`,
                 `payment_status`, `payment_method`, `payment_channel`, `va_number`, `snap_token`, `midtrans_transaction_id`,
                 `paid_at`, `created_at`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $bookingData['invoice_number'],
                (int)$bookingData['user_id'],
                (int)$bookingData['package_id'],
                $bookingData['package_name'],
                $bookingData['package_category'] ?? '',
                (int)$bookingData['price_per_day'],
                $bookingData['pet_name'],
                $bookingData['pet_species'],
                $bookingData['pet_breed'] ?? '-',
                (float)($bookingData['pet_weight'] ?? 0),
                $bookingData['pet_age'] ?? '-',
                $bookingData['check_in'],
                $bookingData['check_out'],
                (int)$bookingData['duration_days'],
                json_encode($bookingData['addons'] ?? [], JSON_UNESCAPED_UNICODE),
                (int)($bookingData['addons_total'] ?? 0),
                (int)($bookingData['room_subtotal'] ?? 0),
                (int)($bookingData['admin_fee'] ?? 5000),
                (int)$bookingData['total_amount'],
                $bookingData['special_notes'] ?? '',
                $bookingData['pickup_option'] ?? 'Diantar Sendiri ke Lobby HappyPet',
                $bookingData['payment_status'] ?? 'pending',
                $bookingData['payment_method'] ?? 'Midtrans Snap',
                $bookingData['payment_channel'] ?? 'Menunggu Pembayaran',
                $bookingData['va_number'] ?? '-',
                $bookingData['snap_token'] ?? null,
                $bookingData['midtrans_transaction_id'] ?? null,
                $bookingData['paid_at'] ?? null,
                $bookingData['created_at'] ?? date('Y-m-d H:i:s'),
            ]);
            $newId = (int)$this->pdo->lastInsertId();
            return $this->getBookingById($newId) ?? array_merge($bookingData, ['id' => $newId]);
        }

        $maxId = 100;
        foreach ($this->data['bookings'] as $b) {
            if ($b['id'] > $maxId) $maxId = $b['id'];
        }
        $bookingData['id'] = $maxId + 1;
        $this->data['bookings'][] = $bookingData;
        $this->save();
        return $bookingData;
    }

    public function updateBookingPayment(int $bookingId, int $userId, array $paymentData): ?array {
        if ($this->isMySQL()) {
            $existing = $this->getBookingById($bookingId, $userId);
            if (!$existing) return null;

            $status = $paymentData['payment_status'] ?? $existing['payment_status'];
            $channel = $paymentData['payment_channel'] ?? $existing['payment_channel'];
            $vaNumber = $paymentData['va_number'] ?? $existing['va_number'];
            $trxId = !empty($paymentData['midtrans_transaction_id'])
                ? $paymentData['midtrans_transaction_id']
                : $existing['midtrans_transaction_id'];
            $paidAt = ($status === 'settlement' && empty($existing['paid_at']))
                ? date('Y-m-d H:i:s')
                : $existing['paid_at'];

            $stmt = $this->pdo->prepare(
                "UPDATE `bookings`
                SET `payment_status` = ?, `payment_channel` = ?, `va_number` = ?, `midtrans_transaction_id` = ?, `paid_at` = ?
                WHERE `id` = ? AND `user_id` = ?"
            );
            $stmt->execute([$status, $channel, $vaNumber, $trxId, $paidAt, $bookingId, $userId]);
            return $this->getBookingById($bookingId, $userId);
        }

        foreach ($this->data['bookings'] as &$b) {
            if ((int)$b['id'] === $bookingId && (int)$b['user_id'] === $userId) {
                $b['payment_status'] = $paymentData['payment_status'] ?? $b['payment_status'];
                $b['payment_channel'] = $paymentData['payment_channel'] ?? $b['payment_channel'];
                $b['va_number'] = $paymentData['va_number'] ?? $b['va_number'];
                if (!empty($paymentData['midtrans_transaction_id'])) {
                    $b['midtrans_transaction_id'] = $paymentData['midtrans_transaction_id'];
                }
                if ($b['payment_status'] === 'settlement' && empty($b['paid_at'])) {
                    $b['paid_at'] = date('Y-m-d H:i:s');
                }
                $this->save();
                return $b;
            }
        }
        return null;
    }

    public function deleteBooking(int $userId, int $bookingId): bool {
        if ($this->isMySQL()) {
            $stmt = $this->pdo->prepare("DELETE FROM `bookings` WHERE `id` = ? AND `user_id` = ?");
            $stmt->execute([$bookingId, $userId]);
            return $stmt->rowCount() > 0;
        }

        foreach ($this->data['bookings'] as $idx => $b) {
            if ((int)$b['id'] === $bookingId) {
                if ((int)$b['user_id'] === $userId || $userId > 0) {
                    array_splice($this->data['bookings'], $idx, 1);
                    $this->save();
                    return true;
                }
            }
        }
        return false;
    }

    // ---- MIDTRANS CONFIG ----
    public function getMidtransConfig(): array {
        $default = [
            'environment' => 'sandbox',
            'mode' => 'simulator',
            'merchant_id' => 'G812945001',
            'client_key' => 'SB-Mid-client-HappyPetDemo2026',
            'server_key' => 'SB-Mid-server-HappyPetDemo2026'
        ];

        if ($this->isMySQL()) {
            $stmt = $this->pdo->query("SELECT `environment`, `mode`, `merchant_id`, `client_key`, `server_key` FROM `midtrans_config` WHERE `id` = 1 LIMIT 1");
            $row = $stmt->fetch();
            return $row ? array_merge($default, $row) : $default;
        }

        return $this->data['midtrans_config'] ?? $default;
    }

    public function updateMidtransConfig(array $cfg): array {
        $current = $this->getMidtransConfig();
        $merged = array_merge($current, $cfg);

        if ($this->isMySQL()) {
            $stmt = $this->pdo->prepare(
                "INSERT INTO `midtrans_config` (`id`, `environment`, `mode`, `merchant_id`, `client_key`, `server_key`)
                VALUES (1, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    `environment` = VALUES(`environment`),
                    `mode` = VALUES(`mode`),
                    `merchant_id` = VALUES(`merchant_id`),
                    `client_key` = VALUES(`client_key`),
                    `server_key` = VALUES(`server_key`)"
            );
            $stmt->execute([
                $merged['environment'],
                $merged['mode'],
                $merged['merchant_id'],
                $merged['client_key'],
                $merged['server_key'],
            ]);
            return $this->getMidtransConfig();
        }

        $this->data['midtrans_config'] = $merged;
        $this->save();
        return $this->data['midtrans_config'];
    }

    // ---- REAL-TIME USER INTERACTION / ACTIVITY LOGS ----
    private function getClientIp(): string {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return substr(trim($parts[0]), 0, 45);
        }
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45);
    }

    private function getClientUserAgent(): string {
        return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Unknown'), 0, 255);
    }

    public function logActivity(
        ?int $userId,
        string $actionType,
        string $description,
        ?string $targetEntity = null,
        $targetId = null,
        array $metadata = [],
        ?array $userOverride = null
    ): array {
        $userName = 'Guest';
        $userEmail = null;

        if ($userOverride) {
            $userName = $userOverride['name'] ?? 'Guest';
            $userEmail = $userOverride['email'] ?? null;
        } elseif ($userId !== null && $userId > 0) {
            $u = $this->findUserById($userId);
            if ($u) {
                $userName = $u['name'];
                $userEmail = $u['email'];
            }
        }

        $actionType = substr(trim($actionType), 0, 60);
        $targetEntity = $targetEntity !== null ? substr(trim($targetEntity), 0, 60) : null;
        $targetIdStr = $targetId !== null ? substr((string)$targetId, 0, 60) : null;
        $metaJson = !empty($metadata) ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;
        $ip = $this->getClientIp();
        $ua = $this->getClientUserAgent();
        $createdAt = date('Y-m-d H:i:s');

        if ($this->isMySQL()) {
            try {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO `user_activity_logs`
                    (`user_id`, `user_name`, `user_email`, `action_type`, `target_entity`, `target_id`, `description`, `metadata_json`, `ip_address`, `user_agent`, `created_at`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $userId,
                    $userName,
                    $userEmail,
                    $actionType,
                    $targetEntity,
                    $targetIdStr,
                    $description,
                    $metaJson,
                    $ip,
                    $ua,
                    $createdAt,
                ]);
                $id = (int)$this->pdo->lastInsertId();
            } catch (Throwable $e) {
                $id = 0;
            }
            return [
                'id' => $id,
                'user_id' => $userId,
                'user_name' => $userName,
                'user_email' => $userEmail,
                'action_type' => $actionType,
                'target_entity' => $targetEntity,
                'target_id' => $targetIdStr,
                'description' => $description,
                'metadata' => $metadata,
                'ip_address' => $ip,
                'created_at' => $createdAt,
            ];
        }

        if (!isset($this->data['activity_logs']) || !is_array($this->data['activity_logs'])) {
            $this->data['activity_logs'] = [];
        }
        $nextId = count($this->data['activity_logs']) + 1;
        $entry = [
            'id' => $nextId,
            'user_id' => $userId,
            'user_name' => $userName,
            'user_email' => $userEmail,
            'action_type' => $actionType,
            'target_entity' => $targetEntity,
            'target_id' => $targetIdStr,
            'description' => $description,
            'metadata' => $metadata,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'created_at' => $createdAt,
        ];
        array_unshift($this->data['activity_logs'], $entry);
        // Batasi 500 log terbaru khusus untuk mode fallback JSON
        if (count($this->data['activity_logs']) > 500) {
            $this->data['activity_logs'] = array_slice($this->data['activity_logs'], 0, 500);
        }
        $this->save();
        return $entry;
    }

    public function getUserActivities(int $userId, int $limit = 50): array {
        $limit = max(1, min(200, $limit));
        if ($this->isMySQL()) {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM `user_activity_logs` WHERE `user_id` = ? ORDER BY `created_at` DESC, `id` DESC LIMIT ?"
            );
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return array_map(function ($r) {
                $r['id'] = (int)$r['id'];
                $r['user_id'] = $r['user_id'] !== null ? (int)$r['user_id'] : null;
                $r['metadata'] = !empty($r['metadata_json']) ? (json_decode($r['metadata_json'], true) ?: []) : [];
                unset($r['metadata_json']);
                return $r;
            }, $rows);
        }

        $logs = array_values(array_filter($this->data['activity_logs'] ?? [], function ($l) use ($userId) {
            return (int)($l['user_id'] ?? 0) === $userId;
        }));
        return array_slice($logs, 0, $limit);
    }

    public function getAllActivities(int $limit = 100): array {
        $limit = max(1, min(500, $limit));
        if ($this->isMySQL()) {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM `user_activity_logs` ORDER BY `created_at` DESC, `id` DESC LIMIT ?"
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return array_map(function ($r) {
                $r['id'] = (int)$r['id'];
                $r['user_id'] = $r['user_id'] !== null ? (int)$r['user_id'] : null;
                $r['metadata'] = !empty($r['metadata_json']) ? (json_decode($r['metadata_json'], true) ?: []) : [];
                unset($r['metadata_json']);
                return $r;
            }, $rows);
        }
        return array_slice($this->data['activity_logs'] ?? [], 0, $limit);
    }

    // ---- ADMIN OPERATIONS & REPORTING ----
    public function getAllCustomers(string $search = '', string $dateFrom = '', string $dateTo = ''): array {
        $search = trim($search);
        $dateFrom = trim($dateFrom);
        $dateTo = trim($dateTo);

        if ($this->isMySQL()) {
            $where = ["(u.`role` IS NULL OR u.`role` != 'admin')"];
            $params = [];

            if ($search !== '') {
                $like = '%' . $search . '%';
                $where[] = "(u.`name` LIKE ? OR u.`email` LIKE ? OR u.`phone` LIKE ?)";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
            if ($dateFrom !== '') {
                $where[] = "DATE(COALESCE(u.`created_at`, CONCAT(u.`joined_at`, ' 00:00:00'))) >= ?";
                $params[] = $dateFrom;
            }
            if ($dateTo !== '') {
                $where[] = "DATE(COALESCE(u.`created_at`, CONCAT(u.`joined_at`, ' 00:00:00'))) <= ?";
                $params[] = $dateTo;
            }

            $sql = "SELECT
                        u.`id`, u.`name`, u.`email`, u.`phone`, u.`address`, u.`avatar`,
                        u.`member_tier`, u.`role`, u.`joined_at`, u.`created_at`,
                        (SELECT COUNT(*) FROM `pets` p WHERE p.`user_id` = u.`id`) AS `total_pets`,
                        (SELECT COUNT(*) FROM `bookings` b WHERE b.`user_id` = u.`id`) AS `total_bookings`,
                        (SELECT COUNT(*) FROM `bookings` b WHERE b.`user_id` = u.`id` AND b.`payment_status` = 'settlement') AS `paid_bookings`,
                        (SELECT COALESCE(SUM(b.`total_amount`), 0) FROM `bookings` b WHERE b.`user_id` = u.`id` AND b.`payment_status` = 'settlement') AS `total_spent`
                    FROM `users` u
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY COALESCE(u.`created_at`, CONCAT(u.`joined_at`, ' 00:00:00')) DESC, u.`id` DESC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            return array_map(function ($r) {
                $r['id'] = (int)$r['id'];
                $r['total_pets'] = (int)$r['total_pets'];
                $r['total_bookings'] = (int)$r['total_bookings'];
                $r['paid_bookings'] = (int)$r['paid_bookings'];
                $r['total_spent'] = (int)$r['total_spent'];
                return $r;
            }, $rows);
        }

        $list = [];
        foreach ($this->data['users'] ?? [] as $u) {
            if (($u['role'] ?? 'customer') === 'admin') continue;
            $createdDate = substr((string)($u['created_at'] ?? ($u['joined_at'] ?? '')), 0, 10);
            if ($search !== '') {
                $hay = strtolower(($u['name'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . ($u['phone'] ?? ''));
                if (!str_contains($hay, strtolower($search))) continue;
            }
            if ($dateFrom !== '' && $createdDate < $dateFrom) continue;
            if ($dateTo !== '' && $createdDate > $dateTo) continue;

            $uid = (int)$u['id'];
            $userBookings = $this->getBookingsByUser($uid);
            $paidCount = 0;
            $spent = 0;
            foreach ($userBookings as $b) {
                if (($b['payment_status'] ?? '') === 'settlement') {
                    $paidCount++;
                    $spent += (int)$b['total_amount'];
                }
            }
            $u['total_pets'] = count($this->getPetsByUser($uid));
            $u['total_bookings'] = count($userBookings);
            $u['paid_bookings'] = $paidCount;
            $u['total_spent'] = $spent;
            $list[] = $u;
        }
        return $list;
    }

    public function getAllBookingsReport(array $filters = []): array {
        $status = trim((string)($filters['status'] ?? 'all'));
        $dateFrom = trim((string)($filters['date_from'] ?? ''));
        $dateTo = trim((string)($filters['date_to'] ?? ''));
        $packageId = (int)($filters['package_id'] ?? 0);
        $search = trim((string)($filters['search'] ?? ''));

        if ($this->isMySQL()) {
            $where = ['1=1'];
            $params = [];

            if ($status !== '' && $status !== 'all') {
                $where[] = "b.`payment_status` = ?";
                $params[] = $status;
            }
            if ($packageId > 0) {
                $where[] = "b.`package_id` = ?";
                $params[] = $packageId;
            }
            if ($dateFrom !== '') {
                $where[] = "DATE(b.`created_at`) >= ?";
                $params[] = $dateFrom;
            }
            if ($dateTo !== '') {
                $where[] = "DATE(b.`created_at`) <= ?";
                $params[] = $dateTo;
            }
            if ($search !== '') {
                $like = '%' . $search . '%';
                $where[] = "(b.`invoice_number` LIKE ? OR b.`pet_name` LIKE ? OR b.`package_name` LIKE ? OR u.`name` LIKE ? OR u.`email` LIKE ?)";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            $sql = "SELECT
                        b.*,
                        COALESCE(u.`name`, 'Customer Terhapus') AS `customer_name`,
                        COALESCE(u.`email`, '-') AS `customer_email`,
                        COALESCE(u.`phone`, '-') AS `customer_phone`,
                        u.`created_at` AS `customer_created_at`
                    FROM `bookings` b
                    LEFT JOIN `users` u ON u.`id` = b.`user_id`
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY b.`created_at` DESC, b.`id` DESC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return array_map([$this, 'normalizeBookingRow'], $stmt->fetchAll());
        }

        $list = [];
        foreach ($this->data['bookings'] ?? [] as $b) {
            if ($status !== '' && $status !== 'all' && ($b['payment_status'] ?? '') !== $status) continue;
            if ($packageId > 0 && (int)$b['package_id'] !== $packageId) continue;
            $cDate = substr((string)($b['created_at'] ?? ''), 0, 10);
            if ($dateFrom !== '' && $cDate < $dateFrom) continue;
            if ($dateTo !== '' && $cDate > $dateTo) continue;

            $u = $this->findUserById((int)$b['user_id']);
            $b['customer_name'] = $u['name'] ?? 'Customer';
            $b['customer_email'] = $u['email'] ?? '-';
            $b['customer_phone'] = $u['phone'] ?? '-';
            $b['customer_created_at'] = $u['created_at'] ?? ($u['joined_at'] ?? null);

            if ($search !== '') {
                $hay = strtolower(($b['invoice_number'] ?? '') . ' ' . ($b['pet_name'] ?? '') . ' ' . ($b['package_name'] ?? '') . ' ' . $b['customer_name'] . ' ' . $b['customer_email']);
                if (!str_contains($hay, strtolower($search))) continue;
            }
            $list[] = $b;
        }
        usort($list, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return $list;
    }

    public function getAdminDashboardStats(): array {
        $customers = $this->getAllCustomers();
        $bookings = $this->getAllBookingsReport();
        $today = date('Y-m-d');
        $thisMonth = date('Y-m');

        $newCustomersMonth = 0;
        $totalPets = 0;
        foreach ($customers as $c) {
            $cDate = substr((string)($c['created_at'] ?? ($c['joined_at'] ?? '')), 0, 7);
            if ($cDate === $thisMonth) $newCustomersMonth++;
            $totalPets += (int)($c['total_pets'] ?? 0);
        }

        $paidCount = 0;
        $pendingCount = 0;
        $totalRevenue = 0;
        $pendingRevenue = 0;
        $todayBookings = 0;
        $packageStats = [];

        foreach ($bookings as $b) {
            $amt = (int)($b['total_amount'] ?? 0);
            $st = $b['payment_status'] ?? 'pending';
            if ($st === 'settlement') {
                $paidCount++;
                $totalRevenue += $amt;
            } elseif ($st === 'pending') {
                $pendingCount++;
                $pendingRevenue += $amt;
            }
            if (substr((string)($b['created_at'] ?? ''), 0, 10) === $today) {
                $todayBookings++;
            }

            $pkgName = $b['package_name'] ?? 'Lainnya';
            if (!isset($packageStats[$pkgName])) {
                $packageStats[$pkgName] = ['name' => $pkgName, 'count' => 0, 'paid_count' => 0, 'revenue' => 0];
            }
            $packageStats[$pkgName]['count']++;
            if ($st === 'settlement') {
                $packageStats[$pkgName]['paid_count']++;
                $packageStats[$pkgName]['revenue'] += $amt;
            }
        }

        usort($packageStats, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        return [
            'total_customers' => count($customers),
            'new_customers_month' => $newCustomersMonth,
            'total_pets' => $totalPets,
            'total_bookings' => count($bookings),
            'today_bookings' => $todayBookings,
            'paid_bookings' => $paidCount,
            'pending_bookings' => $pendingCount,
            'total_revenue' => $totalRevenue,
            'pending_revenue' => $pendingRevenue,
            'package_breakdown' => array_values($packageStats),
        ];
    }

    public function updateBookingStatusAdmin(int $bookingId, string $status): ?array {
        $allowed = ['pending', 'settlement', 'expire', 'cancel'];
        if (!in_array($status, $allowed, true)) return null;

        if ($this->isMySQL()) {
            $existing = $this->getBookingById($bookingId);
            if (!$existing) return null;
            $paidAt = ($status === 'settlement' && empty($existing['paid_at']))
                ? date('Y-m-d H:i:s')
                : $existing['paid_at'];
            $channel = ($status === 'settlement' && ($existing['payment_channel'] === 'Menunggu Pembayaran' || $existing['payment_channel'] === '-'))
                ? 'Konfirmasi Admin (Manual)'
                : $existing['payment_channel'];
            $stmt = $this->pdo->prepare(
                "UPDATE `bookings` SET `payment_status` = ?, `payment_channel` = ?, `paid_at` = ? WHERE `id` = ?"
            );
            $stmt->execute([$status, $channel, $paidAt, $bookingId]);
            return $this->getBookingById($bookingId);
        }

        foreach ($this->data['bookings'] as &$b) {
            if ((int)$b['id'] === $bookingId) {
                $b['payment_status'] = $status;
                if ($status === 'settlement' && empty($b['paid_at'])) {
                    $b['paid_at'] = date('Y-m-d H:i:s');
                }
                $this->save();
                return $b;
            }
        }
        return null;
    }
}

function formatRupiah(int|float $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function formatDateIndo(?string $dateStr, bool $withTime = false): string {
    if (empty($dateStr)) return '-';
    $months = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
    ];
    $ts = strtotime($dateStr);
    if (!$ts) return $dateStr;
    $d = date('d', $ts);
    $m = $months[(int)date('n', $ts)] ?? date('M', $ts);
    $y = date('Y', $ts);
    $formatted = "$d $m $y";
    if ($withTime) {
        $formatted .= ', ' . date('H:i', $ts) . ' WIB';
    }
    return $formatted;
}
