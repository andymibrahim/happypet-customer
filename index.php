<?php
/**
 * HappyPet — Main Application Dashboard
 * Layout: Left Sidebar Navigation + Right Dashboard Content Area
 * Features: Auth + Edit Profile, Dedicated Layanan & Paket Section, Popup Booking,
 *           Purchase History + Delete History + Receipt Printing, Midtrans Snap Gateway
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/midtrans.php';

if (empty($_SESSION['happypet_user_id'])) {
    header('Location: login.php');
    exit;
}

$db = HappyPetDB::getInstance();
$userId = (int)$_SESSION['happypet_user_id'];
$currentUser = $db->findUserById($userId);

if (!$currentUser) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$packages = $db->getPackages();
$addons = $db->getAddons();
$userPets = $db->getPetsByUser($userId);
$userBookings = $db->getBookingsByUser($userId);
$midtransService = new MidtransService();
$midtransConfig = $midtransService->getConfig();

// Compute Dashboard Statistics
$totalBookingsCount = count($userBookings);
$paidBookingsCount = 0;
$pendingBookingsCount = 0;
$totalSpentAmount = 0;

foreach ($userBookings as $b) {
    if ($b['payment_status'] === 'settlement') {
        $paidBookingsCount++;
        $totalSpentAmount += (int)$b['total_amount'];
    } elseif ($b['payment_status'] === 'pending') {
        $pendingBookingsCount++;
    }
}

$activeTab = $_GET['tab'] ?? 'dashboard';
$allowedTabs = ['dashboard', 'packages', 'history', 'pets', 'profile'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'dashboard';
}

// Catat kunjungan halaman ke SQL secara real-time
$db->logActivity(
    $userId,
    'view_page',
    'Membuka halaman HappyPet (tab: ' . $activeTab . ').',
    'page',
    $activeTab,
    ['tab' => $activeTab, 'driver' => $db->getDriver()],
    $currentUser
);

$flashMessage = $_SESSION['happypet_flash'] ?? '';
unset($_SESSION['happypet_flash']);

$avatarIcons = [
    'cat-sage' => ['icon' => 'fa-cat', 'bg' => '#84A98C', 'label' => 'Sage Cat'],
    'dog-amber' => ['icon' => 'fa-dog', 'bg' => '#D4A373', 'label' => 'Amber Dog'],
    'paw-forest' => ['icon' => 'fa-paw', 'bg' => '#354F52', 'label' => 'Forest Paw'],
    'dove-sand' => ['icon' => 'fa-feather-pointed', 'bg' => '#C59B76', 'label' => 'Sand Feather']
];
$currentAvatarKey = $currentUser['avatar'] ?? 'cat-sage';
$currentAvatar = $avatarIcons[$currentAvatarKey] ?? $avatarIcons['cat-sage'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HappyPet</title>
    <link rel="icon" type="image/png" href="assets/img/happypet-paw-badge-transparent.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=JetBrains+Mono:wght@500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <?php if (($midtransConfig['mode'] ?? 'simulator') === 'live_api' && !empty($midtransConfig['client_key'])): ?>
        <script src="<?= htmlspecialchars($midtransService->getSnapJsUrl()) ?>" data-client-key="<?= htmlspecialchars($midtransConfig['client_key']) ?>"></script>
    <?php endif; ?>
</head>
<body>

<div class="app-layout">
    <!-- ==================================================================
         LEFT SIDEBAR NAVIGATION (Semua Navigasi di Sebelah Kiri)
         ================================================================== -->
    <aside class="left-sidebar" id="leftSidebar">
        <!-- Brand Header -->
        <div class="sidebar-brand">
            <div class="brand-logo-box">
                <i class="fa-solid fa-paw"></i>
            </div>
            <div>
                <div class="brand-title">HappyPet</div>
                <div class="brand-subtitle">Pet Hotel & Organic Spa</div>
            </div>
        </div>

        <!-- Primary Action Button: Pesan Penitipan via Popup -->
        <button type="button" class="nav-item nav-item-cta" onclick="openBookingModal()">
            <i class="fa-solid fa-calendar-plus"></i>
            <span>Pesan Penitipan (Popup)</span>
        </button>

        <div class="sidebar-nav-label" style="margin-top:8px;">Menu Navigasi Utama</div>

        <nav class="sidebar-nav">
            <button type="button" class="nav-item <?= $activeTab === 'dashboard' ? 'active' : '' ?>" data-section="dashboard" onclick="switchSection('dashboard')">
                <i class="fa-solid fa-table-columns"></i>
                <span>Dashboard</span>
            </button>

            <button type="button" class="nav-item <?= $activeTab === 'packages' ? 'active' : '' ?>" data-section="packages" onclick="switchSection('packages')">
                <i class="fa-solid fa-box-open"></i>
                <span>Layanan & Paket</span>
            </button>

            <button type="button" class="nav-item <?= $activeTab === 'history' ? 'active' : '' ?>" data-section="history" onclick="switchSection('history')">
                <i class="fa-solid fa-receipt"></i>
                <span>Riwayat & Struk</span>
            </button>

            <button type="button" class="nav-item <?= $activeTab === 'pets' ? 'active' : '' ?>" data-section="pets" onclick="switchSection('pets')">
                <i class="fa-solid fa-shield-cat"></i>
                <span>Data Hewan Saya</span>
            </button>

            <button type="button" class="nav-item <?= $activeTab === 'profile' ? 'active' : '' ?>" data-section="profile" onclick="switchSection('profile')">
                <i class="fa-solid fa-user-gear"></i>
                <span>Edit Profil Saya</span>
            </button>
        </nav>

        <!-- Sidebar Footer: User Profile at the Bottom + Logout -->
        <div class="sidebar-footer">
            <div class="sidebar-user-card" onclick="switchSection('profile')" title="Klik untuk Edit Profil">
                <div class="user-avatar-badge live-user-avatar" id="sidebarUserAvatarBadge" style="background: <?= htmlspecialchars($currentAvatar['bg']) ?>; color: #FFFFFF;">
                    <i class="fa-solid <?= htmlspecialchars($currentAvatar['icon']) ?>"></i>
                </div>
                <div class="user-card-info">
                    <div class="user-card-name live-user-name"><?= htmlspecialchars($currentUser['name']) ?></div>
                    <div class="user-card-tier">
                        <i class="fa-solid fa-medal"></i> <?= htmlspecialchars($currentUser['member_tier'] ?? 'Priority Member') ?>
                    </div>
                </div>
                <i class="fa-solid fa-pen-to-square" style="color:var(--sand-secondary); font-size:13px;"></i>
            </div>

            <a href="logout.php" class="nav-item nav-item-logout">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- ==================================================================
         RIGHT DASHBOARD CONTENT AREA (Dashboard di Sebelah Kanan)
         ================================================================== -->
    <main class="right-dashboard">
        <!-- Top Header Bar -->
        <header class="dashboard-topbar">
            <div style="display:flex; align-items:center; gap:14px;">
                <button type="button" class="mobile-menu-btn" onclick="toggleMobileSidebar()">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-greeting">
                    <h1>Halo, <span class="live-user-name"><?= htmlspecialchars($currentUser['name']) ?></span>! 🐾</h1>
                    <p>Selamat datang di pusat layanan penitipan & spa hewan <strong>HappyPet</strong> &bull; <?= formatDateIndo(date('Y-m-d')) ?></p>
                </div>
            </div>
        </header>

        <!-- ==============================================================
             SECTION 1: DASHBOARD UTAMA (Ringkasan & Transaksi Terbaru)
             ============================================================== -->
        <section id="section-dashboard" class="dashboard-section <?= $activeTab === 'dashboard' ? 'active' : '' ?>">
            <!-- Hero Banner -->
            <div class="hero-banner">
                <div class="hero-content">
                    <span class="hero-pill">
                        <i class="fa-solid fa-sparkles"></i> Steril, Tenang & Diawasi Dokter Hewan 24 Jam
                    </span>
                    <h2>Ruang Penitipan Bernuansa Alam untuk Ketenangan Anabul Anda</h2>
                    <p>
                        Setiap kabin dan villa di HappyPet dirancang untuk meredakan stres hewan, dilengkapi filter udara HEPA, pakan terukur, dan akses Live CCTV 24/7.
                    </p>
                    <div style="display:flex; gap:12px; flex-wrap:wrap;">
                        <button type="button" class="btn btn-sand" onclick="switchSection('packages')">
                            <i class="fa-solid fa-box-open"></i> Jelajahi Layanan & Paket
                        </button>
                    </div>
                    <div class="hero-highlights">
                        <div class="hero-highlight-item"><i class="fa-solid fa-circle-check"></i> Pembayaran Instan Midtrans</div>
                        <div class="hero-highlight-item"><i class="fa-solid fa-circle-check"></i> Garansi Sanitasi UV Steril</div>
                        <div class="hero-highlight-item"><i class="fa-solid fa-circle-check"></i> Cetak Struk Resmi Kapan Saja</div>
                    </div>
                </div>

                <div class="hero-visual-card">
                    <div style="font-size:11.5px; text-transform:uppercase; letter-spacing:0.06em; color:var(--sand-secondary); font-weight:700; margin-bottom:8px;">
                        <i class="fa-solid fa-bolt"></i> Ringkasan Akun Anda
                    </div>
                    <div style="font-size:26px; font-weight:800; color:#FFFFFF; margin-bottom:4px;">
                        <?= formatRupiah($totalSpentAmount) ?>
                    </div>
                    <div style="font-size:12.5px; color:rgba(255,255,255,0.8); margin-bottom:14px;">
                        Total transaksi lunas (<?= $paidBookingsCount ?> pesanan selesai)
                    </div>
                    <div style="border-top:1px solid rgba(230,204,178,0.25); padding-top:12px; display:flex; align-items:center; justify-content:space-between; font-size:12.5px;">
                        <span>Anabul Terdaftar:</span>
                        <span style="background:rgba(255,255,255,0.2); padding:3px 10px; border-radius:99px; font-weight:700;"><?= count($userPets) ?> Ekor</span>
                    </div>
                </div>
            </div>

            <!-- 4 KPI Stat Cards -->
            <div class="stats-grid">
                <div class="stat-card" onclick="switchSection('history')" style="cursor:pointer;">
                    <div class="stat-icon sage">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Total Pemesanan</div>
                        <div class="stat-value"><?= $totalBookingsCount ?> Transaksi</div>
                        <div class="stat-sub">Riwayat penitipan tercatat</div>
                    </div>
                </div>

                <div class="stat-card" onclick="switchSection('history')" style="cursor:pointer;">
                    <div class="stat-icon forest">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Pembayaran Lunas</div>
                        <div class="stat-value"><?= $paidBookingsCount ?> Lunas</div>
                        <div class="stat-sub">Terverifikasi Midtrans</div>
                    </div>
                </div>

                <div class="stat-card" onclick="switchSection('history')" style="cursor:pointer;">
                    <div class="stat-icon amber">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Menunggu Bayar</div>
                        <div class="stat-value"><?= $pendingBookingsCount ?> Pending</div>
                        <div class="stat-sub">Siap dibayar via Snap</div>
                    </div>
                </div>

                <div class="stat-card" onclick="switchSection('pets')" style="cursor:pointer;">
                    <div class="stat-icon sand">
                        <i class="fa-solid fa-cat"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Anabul Terdaftar</div>
                        <div class="stat-value"><?= count($userPets) ?> Ekor</div>
                        <div class="stat-sub">Siap pilih saat booking</div>
                    </div>
                </div>
            </div>

            <!-- Recent Bookings Preview inside Dashboard -->
            <div class="card-panel">
                <div class="section-header" style="margin-bottom:16px; flex-wrap:wrap;">
                    <div>
                        <h2 style="font-size:19px;">Transaksi Penitipan Terbaru Anda</h2>
                        <p>Pantau status pembayaran, cetak struk bukti penitipan, atau kelola riwayat pesanan Anda.</p>
                    </div>
                    <div>
                        <button type="button" class="btn btn-outline btn-sm" onclick="switchSection('history')">
                            Semua Riwayat (<?= $totalBookingsCount ?>) <i class="fa-solid fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="hp-table">
                        <thead>
                            <tr>
                                <th style="width:200px;">No. Invoice</th>
                                <th>Anabul & Paket</th>
                                <th style="width:210px;">Jadwal Menginap</th>
                                <th style="width:160px;">Total Biaya</th>
                                <th style="width:110px; text-align:center;">Status</th>
                                <th style="width:230px; text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($userBookings)): ?>
                                <tr>
                                    <td colspan="6" style="text-align:center; padding:32px; color:var(--text-muted);">
                                        Belum ada riwayat transaksi. Silakan klik tombol <strong>Pesan Penitipan</strong> untuk membuat pesanan baru.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach (array_slice($userBookings, 0, 4) as $b): ?>
                                    <tr data-booking-id="<?= (int)$b['id'] ?>">
                                        <td>
                                            <strong style="font-family:'JetBrains Mono',monospace; font-size:13px; color:var(--forest-cta); white-space:nowrap; display:block;">
                                                <?= htmlspecialchars($b['invoice_number']) ?>
                                            </strong>
                                            <div style="font-size:11.5px; color:var(--text-muted); white-space:nowrap;"><?= formatDateIndo($b['created_at'], true) ?></div>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($b['pet_name']) ?></strong> <span style="font-size:12.5px; color:var(--text-muted);">(<?= htmlspecialchars($b['pet_species']) ?>)</span>
                                            <div style="font-size:12px; color:var(--sage-dark); font-weight:600;"><?= htmlspecialchars($b['package_name']) ?></div>
                                        </td>
                                        <td>
                                            <div style="white-space:nowrap; font-weight:600;"><?= formatDateIndo($b['check_in']) ?> &rarr; <?= formatDateIndo($b['check_out']) ?></div>
                                            <div style="font-size:11.5px; color:var(--text-muted);"><?= (int)$b['duration_days'] ?> Hari / Malam</div>
                                        </td>
                                        <td>
                                            <strong style="color:var(--forest-cta); font-size:14px; white-space:nowrap; display:block;"><?= formatRupiah($b['total_amount']) ?></strong>
                                            <div style="font-size:11.5px; color:var(--text-muted); white-space:nowrap;"><?= htmlspecialchars($b['payment_channel']) ?></div>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($b['payment_status'] === 'settlement'): ?>
                                                <span class="status-badge status-settlement">
                                                    <i class="fa-solid fa-circle-check"></i> Lunas
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge status-pending">
                                                    <i class="fa-solid fa-clock"></i> Pending
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <div class="action-btn-group" style="justify-content:center;">
                                                <?php if ($b['payment_status'] === 'pending'): ?>
                                                    <button type="button" class="btn btn-amber btn-sm btn-action" onclick="payPendingBooking(<?= (int)$b['id'] ?>)">
                                                        <i class="fa-solid fa-credit-card"></i> Bayar
                                                    </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-outline btn-sm btn-action" onclick="openReceiptPreviewModal(<?= (int)$b['id'] ?>)" title="Preview Struk">
                                                    <i class="fa-solid fa-eye"></i> Detail
                                                </button>
                                                <a href="struk.php?id=<?= (int)$b['id'] ?>&autoprint=1" class="btn btn-forest btn-sm btn-action" title="Cetak Struk">
                                                    <i class="fa-solid fa-print"></i> Struk
                                                </a>
                                                <button type="button" class="btn btn-danger-soft btn-sm btn-icon-del" onclick="deleteBookingItem(<?= (int)$b['id'] ?>, '<?= htmlspecialchars($b['invoice_number']) ?>')" title="Hapus Riwayat">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ==============================================================
             SECTION 2: LAYANAN & PAKET PENITIPAN HEWAN
             (Semua Layanan dan Paket Dipindahkan ke Navigasi Ini)
             ============================================================== -->
        <section id="section-packages" class="dashboard-section <?= $activeTab === 'packages' ? 'active' : '' ?>">
            <div class="section-header" style="flex-wrap:wrap;">
                <div>
                    <h2><i class="fa-solid fa-box-open" style="color:var(--sage-primary);"></i> Pilih Paket Kamar & Layanan Penitipan HappyPet</h2>
                    <p>Klik tombol <strong>"Pesan Paket Ini"</strong> pada paket pilihan Anda untuk membuka formulir pemesanan popup.</p>
                </div>
            </div>

            <div class="packages-grid">
                <?php foreach ($packages as $pkg): ?>
                    <?php $isFeatured = ($pkg['id'] === 2 || $pkg['id'] === 3); ?>
                    <div class="package-card <?= $isFeatured ? 'featured' : '' ?>">
                        <div>
                            <div class="package-top-bar">
                                <div class="package-icon-wrap">
                                    <i class="fa-solid <?= htmlspecialchars($pkg['icon']) ?>"></i>
                                </div>
                                <span class="package-badge"><?= htmlspecialchars($pkg['badge']) ?></span>
                            </div>

                            <div class="package-category"><?= htmlspecialchars($pkg['category']) ?></div>
                            <h3 class="package-title"><?= htmlspecialchars($pkg['name']) ?></h3>

                            <div class="package-meta-pills">
                                <span class="meta-pill"><i class="fa-solid fa-ruler-combined"></i> <?= htmlspecialchars($pkg['room_size']) ?></span>
                                <span class="meta-pill"><i class="fa-solid fa-paw"></i> <?= htmlspecialchars($pkg['suitable_for']) ?></span>
                            </div>

                            <p class="package-desc"><?= htmlspecialchars($pkg['description']) ?></p>

                            <ul class="package-facilities">
                                <?php foreach ($pkg['facilities'] as $fac): ?>
                                    <li>
                                        <i class="fa-solid fa-circle-check"></i>
                                        <span><?= htmlspecialchars($fac) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <div class="package-footer">
                            <div class="package-price">
                                <span class="price-amount"><?= formatRupiah($pkg['price_per_day']) ?></span>
                                <span class="price-unit">per malam / hari</span>
                            </div>
                            <button type="button" class="btn btn-forest btn-book-package" onclick="openBookingModal(<?= (int)$pkg['id'] ?>)">
                                <i class="fa-solid fa-calendar-plus"></i> Pesan Paket Ini (Popup)
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Daftar Layanan Tambahan (Add-ons Care) -->
            <div class="card-panel">
                <div class="section-header" style="margin-bottom:16px; flex-wrap:wrap;">
                    <div>
                        <h2 style="font-size:19px;"><i class="fa-solid fa-spa" style="color:var(--sage-primary);"></i> Layanan Tambahan (Add-on Spa, Grooming & Antar-Jemput)</h2>
                        <p>Layanan ekstra ini dapat Anda centang langsung di dalam popup saat melakukan pemesanan kamar.</p>
                    </div>
                </div>

                <div class="addons-list">
                    <?php foreach ($addons as $addon): ?>
                        <div class="addon-item" style="cursor:default; justify-content:space-between; align-items:center;">
                            <div style="display:flex; align-items:flex-start; gap:12px;">
                                <div style="width:42px; height:42px; border-radius:11px; background:var(--sage-light); color:var(--forest-cta); display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0;">
                                    <i class="fa-solid <?= htmlspecialchars($addon['icon']) ?>"></i>
                                </div>
                                <div>
                                    <div style="font-weight:700; font-size:14px; color:var(--text-slate);">
                                        <?= htmlspecialchars($addon['name']) ?>
                                    </div>
                                    <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">
                                        <?= htmlspecialchars($addon['desc']) ?>
                                    </div>
                                </div>
                            </div>
                            <div style="text-align:right; flex-shrink:0; margin-left:10px;">
                                <div style="font-weight:800; color:var(--forest-cta); font-size:14px; white-space:nowrap;">
                                    <?= formatRupiah($addon['price']) ?>
                                </div>
                                <div style="font-size:11px; color:var(--amber-hover); font-weight:700;">
                                    <?= $addon['price_type'] === 'per_day' ? 'per hari' : 'per sesi' ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- ==============================================================
             SECTION 3: RIWAYAT PEMBELIAN, CETAK STRUK & HAPUS RIWAYAT
             ============================================================== -->
        <section id="section-history" class="dashboard-section <?= $activeTab === 'history' ? 'active' : '' ?>">
            <div class="card-panel">
                <div class="section-header" style="margin-bottom:20px; flex-wrap:wrap;">
                    <div>
                        <h2><i class="fa-solid fa-receipt" style="color:var(--sage-primary);"></i> Riwayat Pembelian & Cetak Struk Resmi</h2>
                        <p>Semua riwayat pemesanan jasa penitipan hewan Anda beserta fitur cetak struk dan hapus riwayat.</p>
                    </div>
                    <div class="filter-pills">
                        <button type="button" class="filter-pill active" onclick="filterHistoryRows('all', this)">
                            Semua (<?= $totalBookingsCount ?>)
                        </button>
                        <button type="button" class="filter-pill" onclick="filterHistoryRows('settlement', this)">
                            Lunas / Settlement (<?= $paidBookingsCount ?>)
                        </button>
                        <button type="button" class="filter-pill" onclick="filterHistoryRows('pending', this)">
                            Menunggu Pembayaran (<?= $pendingBookingsCount ?>)
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="hp-table">
                        <thead>
                            <tr>
                                <th style="width:190px;">Invoice & Waktu</th>
                                <th>Data Hewan (Anabul)</th>
                                <th>Paket & Add-ons</th>
                                <th style="width:190px;">Jadwal Check-In/Out</th>
                                <th style="width:150px;">Metode Bayar</th>
                                <th style="width:140px;">Total Biaya</th>
                                <th style="width:110px; text-align:center;">Status</th>
                                <th style="width:230px; text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($userBookings)): ?>
                                <tr>
                                    <td colspan="8" style="text-align:center; padding:36px;">
                                        Belum ada riwayat pemesanan. Klik tombol <strong>Pesan Penitipan</strong> untuk mulai memesan.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($userBookings as $b): ?>
                                    <tr class="history-row-item" data-status="<?= htmlspecialchars($b['payment_status']) ?>" data-booking-id="<?= (int)$b['id'] ?>">
                                        <td>
                                            <div style="font-family:'JetBrains Mono',monospace; font-weight:700; color:var(--forest-cta); white-space:nowrap;">
                                                <?= htmlspecialchars($b['invoice_number']) ?>
                                            </div>
                                            <div style="font-size:11.5px; color:var(--text-muted); white-space:nowrap;">
                                                <?= formatDateIndo($b['created_at'], true) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($b['pet_name']) ?></strong>
                                            <div style="font-size:12px; color:var(--text-muted);">
                                                <?= htmlspecialchars($b['pet_species']) ?> &bull; <?= htmlspecialchars($b['pet_breed']) ?> (<?= htmlspecialchars((string)$b['pet_weight']) ?> kg)
                                            </div>
                                        </td>
                                        <td>
                                            <strong style="color:var(--forest-cta);"><?= htmlspecialchars($b['package_name']) ?></strong>
                                            <?php if (!empty($b['addons']) && is_array($b['addons'])): ?>
                                                <div style="font-size:11.5px; color:var(--sage-dark); margin-top:2px;">
                                                    + <?= count($b['addons']) ?> Layanan Tambahan
                                                </div>
                                            <?php else: ?>
                                                <div style="font-size:11.5px; color:var(--text-muted);">Tanpa Add-on</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="white-space:nowrap; font-weight:600;"><?= formatDateIndo($b['check_in']) ?> s/d <?= formatDateIndo($b['check_out']) ?></div>
                                            <span style="font-size:11.5px; background:var(--sage-light); color:var(--forest-cta); padding:2px 8px; border-radius:6px; font-weight:700;">
                                                <?= (int)$b['duration_days'] ?> Hari
                                            </span>
                                        </td>
                                        <td>
                                            <div style="font-weight:600;"><?= htmlspecialchars($b['payment_channel']) ?></div>
                                            <div style="font-size:11px; font-family:monospace; color:var(--text-muted); white-space:nowrap;">
                                                Ref: <?= htmlspecialchars($b['va_number'] ?: '-') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong style="font-size:15px; color:var(--forest-cta); white-space:nowrap; display:block;"><?= formatRupiah($b['total_amount']) ?></strong>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($b['payment_status'] === 'settlement'): ?>
                                                <span class="status-badge status-settlement">
                                                    <i class="fa-solid fa-circle-check"></i> Lunas
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge status-pending">
                                                    <i class="fa-solid fa-clock"></i> Pending
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <div class="action-btn-group" style="justify-content:center;">
                                                <?php if ($b['payment_status'] === 'pending'): ?>
                                                    <button type="button" class="btn btn-amber btn-sm btn-action" onclick="payPendingBooking(<?= (int)$b['id'] ?>)">
                                                        <i class="fa-solid fa-bolt"></i> Bayar
                                                    </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-outline btn-sm btn-action" onclick="openReceiptPreviewModal(<?= (int)$b['id'] ?>)" title="Preview Struk di Popup">
                                                    <i class="fa-solid fa-eye"></i> Detail
                                                </button>
                                                <a href="struk.php?id=<?= (int)$b['id'] ?>&autoprint=1" class="btn btn-forest btn-sm btn-action" title="Buka & Cetak Struk">
                                                    <i class="fa-solid fa-print"></i> Struk
                                                </a>
                                                <button type="button" class="btn btn-danger-soft btn-sm btn-icon-del" onclick="deleteBookingItem(<?= (int)$b['id'] ?>, '<?= htmlspecialchars($b['invoice_number']) ?>')" title="Hapus Riwayat Ini">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ==============================================================
             SECTION 4: DATA HEWAN PELIHARAAN SAYA (ANABUL)
             ============================================================== -->
        <section id="section-pets" class="dashboard-section <?= $activeTab === 'pets' ? 'active' : '' ?>">
            <div class="form-grid-2" style="align-items:start;">
                <!-- Saved Pets List -->
                <div class="card-panel">
                    <div class="section-header" style="margin-bottom:16px;">
                        <div>
                            <h2><i class="fa-solid fa-shield-cat" style="color:var(--sage-primary);"></i> Daftar Anabul Kesayangan</h2>
                            <p>Pilih anabul di bawah untuk langsung memesan kamar penitipan dengan data otomatis.</p>
                        </div>
                    </div>

                    <div style="display:flex; flex-direction:column; gap:14px;">
                        <?php if (empty($userPets)): ?>
                            <div style="padding:28px; text-align:center; color:var(--text-muted); background:var(--bg-alabaster); border-radius:14px; border:1px dashed var(--border-subtle);">
                                Belum ada data hewan tersimpan. Tambahkan data anabul Anda melalui formulir di samping.
                            </div>
                        <?php else: ?>
                            <?php foreach ($userPets as $pet): ?>
                                <div style="border:1.5px solid var(--border-subtle); border-radius:16px; padding:18px; background:var(--bg-alabaster); display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap;">
                                    <div style="display:flex; align-items:center; gap:14px;">
                                        <div style="width:50px; height:50px; border-radius:14px; background:var(--sage-light); color:var(--forest-cta); display:flex; align-items:center; justify-content:center; font-size:22px;">
                                            <i class="fa-solid <?= stripos($pet['species'], 'Anjing') !== false ? 'fa-dog' : 'fa-cat' ?>"></i>
                                        </div>
                                        <div>
                                            <div style="display:flex; align-items:center; gap:8px;">
                                                <strong style="font-size:16.5px; color:var(--text-slate);"><?= htmlspecialchars($pet['name']) ?></strong>
                                                <span style="font-size:11px; background:var(--sand-light); color:var(--forest-cta); padding:2px 9px; border-radius:99px; font-weight:700; border:1px solid var(--sand-secondary);">
                                                    <?= htmlspecialchars($pet['species']) ?>
                                                </span>
                                            </div>
                                            <div style="font-size:12.5px; color:var(--text-muted); margin-top:2px;">
                                                Ras: <strong><?= htmlspecialchars($pet['breed']) ?></strong> &bull;
                                                Usia: <?= htmlspecialchars($pet['age']) ?> &bull;
                                                Berat: <?= htmlspecialchars((string)$pet['weight']) ?> kg
                                            </div>
                                            <?php if (!empty($pet['special_notes'])): ?>
                                                <div style="font-size:12px; color:var(--sage-dark); margin-top:4px;">
                                                    <i class="fa-solid fa-notes-medical"></i> <?= htmlspecialchars($pet['special_notes']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="action-btn-group">
                                        <button type="button" class="btn btn-forest btn-sm" onclick="openBookingModal(); fillSavedPetInBooking(<?= (int)$pet['id'] ?>);">
                                            <i class="fa-solid fa-calendar-plus"></i> Titipkan <?= htmlspecialchars($pet['name']) ?>
                                        </button>
                                        <button type="button" class="btn btn-danger-soft btn-sm" onclick="deletePetItem(<?= (int)$pet['id'] ?>)" title="Hapus Data Hewan">
                                            <i class="fa-solid fa-trash-can"></i> Hapus
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Add New Pet Form -->
                <div class="card-panel">
                    <h3 style="font-size:19px; margin-bottom:6px;"><i class="fa-solid fa-plus-circle" style="color:var(--amber-cta);"></i> Tambah Data Hewan Baru</h3>
                    <p style="font-size:13px; color:var(--text-muted); margin-bottom:18px;">
                        Simpan profil anabul Anda agar saat melakukan pemesanan via popup tidak perlu mengetik ulang.
                    </p>

                    <form onsubmit="submitAddPetForm(event)">
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label class="form-label">Nama Panggilan Hewan *</label>
                                <input type="text" id="newPetName" class="form-control" required placeholder="Contoh: Coco / Leo">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Jenis Hewan *</label>
                                <select id="newPetSpecies" class="form-control">
                                    <option value="Kucing">Kucing</option>
                                    <option value="Anjing Kecil">Anjing Kecil (&lt; 10 kg)</option>
                                    <option value="Anjing Besar">Anjing Besar (&gt; 10 kg)</option>
                                    <option value="Kelinci / Exotic">Kelinci / Exotic Pet</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-grid-3">
                            <div class="form-group">
                                <label class="form-label">Ras / Breed</label>
                                <input type="text" id="newPetBreed" class="form-control" placeholder="Persia / Corgi">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Usia</label>
                                <input type="text" id="newPetAge" class="form-control" placeholder="1.5 Tahun">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Berat (kg)</label>
                                <input type="number" step="0.1" id="newPetWeight" class="form-control" value="4.0">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Jenis Kelamin</label>
                            <select id="newPetGender" class="form-control">
                                <option value="Jantan">Jantan</option>
                                <option value="Betina">Betina</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Catatan Kebiasaan / Alergi Makanan</label>
                            <textarea id="newPetNotes" rows="3" class="form-control" placeholder="Contoh: Makan 2x sehari pagi & sore, alergi ikan tuna..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-amber" style="width:100%;">
                            <i class="fa-solid fa-floppy-disk"></i> Simpan Data Anabul
                        </button>
                    </form>
                </div>
            </div>
        </section>

        <!-- ==============================================================
             SECTION 5: EDIT PROFIL & PENGATURAN AKUN
             ============================================================== -->
        <section id="section-profile" class="dashboard-section <?= $activeTab === 'profile' ? 'active' : '' ?>">
            <div class="card-panel" style="max-width:860px;">
                <div class="section-header" style="margin-bottom:20px;">
                    <div>
                        <h2><i class="fa-solid fa-user-pen" style="color:var(--sage-primary);"></i> Edit Profil Pemilik Hewan</h2>
                        <p>Perbarui informasi pribadi, alamat penjemputan Pet Taxi, kontak darurat, hingga kata sandi akun Anda.</p>
                    </div>
                </div>

                <form onsubmit="submitProfileUpdate(event)">
                    <!-- Pilih Avatar -->
                    <div class="form-group">
                        <label class="form-label">Pilih Ikon Avatar Profil</label>
                        <div class="avatar-radio-group" style="display:flex; gap:14px; flex-wrap:wrap;">
                            <?php foreach ($avatarIcons as $key => $av): ?>
                                <label class="avatar-picker-item <?= $currentAvatarKey === $key ? 'active' : '' ?>" onclick="handleAvatarSelect(this)">
                                    <input type="radio" name="profile_avatar" value="<?= htmlspecialchars($key) ?>" <?= $currentAvatarKey === $key ? 'checked' : '' ?> onchange="handleAvatarRadioChange(this.value)">
                                    <span style="width:32px; height:32px; border-radius:9px; background:<?= htmlspecialchars($av['bg']) ?>; color:#FFF; display:inline-flex; align-items:center; justify-content:center;">
                                        <i class="fa-solid <?= htmlspecialchars($av['icon']) ?>"></i>
                                    </span>
                                    <span style="font-size:13px; font-weight:600;"><?= htmlspecialchars($av['label']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Nama Lengkap Pemilik *</label>
                            <input type="text" id="profileName" class="form-control" value="<?= htmlspecialchars($currentUser['name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Alamat Email (Untuk Bukti Pembayaran Midtrans) *</label>
                            <input type="email" id="profileEmail" class="form-control" value="<?= htmlspecialchars($currentUser['email']) ?>" required>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Nomor WhatsApp / Telepon Aktif *</label>
                            <input type="text" id="profilePhone" class="form-control" value="<?= htmlspecialchars($currentUser['phone'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Kontak Darurat (Keluarga / Kerabat)</label>
                            <input type="text" id="profileEmergency" class="form-control" value="<?= htmlspecialchars($currentUser['emergency_contact'] ?? '') ?>" placeholder="Nama & Nomor Telepon">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Alamat Lengkap Rumah (Untuk Layanan Jemput Pet Taxi & Invoice)</label>
                        <textarea id="profileAddress" rows="2" class="form-control"><?= htmlspecialchars($currentUser['address'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Catatan Dokter Hewan Langganan / Riwayat Medis Umum</label>
                        <textarea id="profileVetNotes" rows="2" class="form-control" placeholder="Nama klinik hewan langganan atau catatan alergi..."><?= htmlspecialchars($currentUser['vet_notes'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group" style="background:var(--sand-light); padding:16px; border-radius:14px; border:1px solid var(--sand-secondary);">
                        <label class="form-label" style="margin-bottom:4px;"><i class="fa-solid fa-lock"></i> Ubah Kata Sandi (Opsional)</label>
                        <p style="font-size:12px; color:var(--text-muted); margin-bottom:10px;">Kosongkan kolom ini jika Anda tidak ingin mengubah kata sandi saat ini.</p>
                        <input type="password" id="profileNewPassword" class="form-control" placeholder="Masukkan kata sandi baru (minimal 6 karakter)">
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:18px;">
                        <button type="submit" id="btnSaveProfile" class="btn btn-forest">
                            <i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan Profil
                        </button>
                    </div>
                </form>
            </div>
        </section>
    </main>
</div>

<!-- ======================================================================
     MODAL 1: POPUP PEMESANAN JASA PENITIPAN HEWAN (#bookingModal)
     (Spesifikasi #4: Saat melakukan pemesanan gunakan popup)
     ====================================================================== -->
<div class="modal-overlay" id="bookingModal">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h3><i class="fa-solid fa-paw" style="color:var(--sand-secondary);"></i> Form Pemesanan Penitipan Hewan (HappyPet)</h3>
                <p>Lengkapi detail anabul & jadwal menginap, lalu lanjutkan ke pembayaran instan via Midtrans Snap.</p>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeBookingModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form onsubmit="submitBookingForm(event)">
            <div class="modal-body">
                <!-- Quick Select Saved Pet -->
                <?php if (!empty($userPets)): ?>
                    <div style="margin-bottom:18px; background:var(--sage-light); padding:12px 16px; border-radius:13px; border:1px solid var(--sage-Mid);">
                        <div style="font-size:12px; font-weight:700; color:var(--forest-cta); margin-bottom:8px;">
                            <i class="fa-solid fa-wand-magic-sparkles"></i> Pilih Cepat dari Data Hewan Anda:
                        </div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <?php foreach ($userPets as $pet): ?>
                                <button type="button" class="btn btn-outline btn-sm saved-pet-chip" data-pet-id="<?= (int)$pet['id'] ?>" onclick="fillSavedPetInBooking(<?= (int)$pet['id'] ?>)">
                                    <i class="fa-solid <?= stripos($pet['species'], 'Anjing') !== false ? 'fa-dog' : 'fa-cat' ?>"></i>
                                    <?= htmlspecialchars($pet['name']) ?> (<?= htmlspecialchars($pet['breed']) ?>)
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Step 1: Pilih Paket Kamar -->
                <div class="form-group">
                    <label class="form-label">1. Pilih Paket Kamar & Layanan Penitipan *</label>
                    <select id="bookingPackageSelect" class="form-control" onchange="recalculateBookingPopup()" required>
                        <?php foreach ($packages as $pkg): ?>
                            <option value="<?= (int)$pkg['id'] ?>" data-price="<?= (int)$pkg['price_per_day'] ?>" data-name="<?= htmlspecialchars($pkg['name']) ?>">
                                <?= htmlspecialchars($pkg['name']) ?> (<?= htmlspecialchars($pkg['category']) ?>) — <?= formatRupiah($pkg['price_per_day']) ?> / hari
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Step 2: Jadwal Check-In & Check-Out -->
                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Tanggal Check-In *</label>
                        <input type="date" id="bookingCheckIn" class="form-control" value="<?= date('Y-m-d') ?>" onchange="recalculateBookingPopup()" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="display:flex; justify-content:space-between;">
                            <span>Tanggal Check-Out *</span>
                            <span id="bookingDurationBadge" style="background:var(--amber-cta); color:#FFF; padding:1px 9px; border-radius:99px; font-size:11px;">2 Hari / Malam</span>
                        </label>
                        <input type="date" id="bookingCheckOut" class="form-control" value="<?= date('Y-m-d', strtotime('+2 days')) ?>" onchange="recalculateBookingPopup()" required>
                    </div>
                </div>

                <!-- Step 3: Informasi Hewan -->
                <div class="form-grid-3">
                    <div class="form-group">
                        <label class="form-label">Nama Hewan (Anabul) *</label>
                        <input type="text" id="bookingPetName" class="form-control" value="<?= htmlspecialchars($userPets[0]['name'] ?? 'Mochi') ?>" required placeholder="Contoh: Mochi">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Jenis Hewan *</label>
                        <select id="bookingPetSpecies" class="form-control">
                            <option value="Kucing">Kucing</option>
                            <option value="Anjing Kecil">Anjing Kecil (&lt; 10 kg)</option>
                            <option value="Anjing Besar">Anjing Besar (&gt; 10 kg)</option>
                            <option value="Kelinci / Exotic">Kelinci / Exotic Pet</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ras / Breed</label>
                        <input type="text" id="bookingPetBreed" class="form-control" value="<?= htmlspecialchars($userPets[0]['breed'] ?? 'Scottish Fold') ?>" placeholder="Contoh: Persia / Poodle">
                    </div>
                </div>

                <div class="form-grid-3">
                    <div class="form-group">
                        <label class="form-label">Berat Badan (kg)</label>
                        <input type="number" step="0.1" id="bookingPetWeight" class="form-control" value="<?= htmlspecialchars((string)($userPets[0]['weight'] ?? 4.2)) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Usia Hewan</label>
                        <input type="text" id="bookingPetAge" class="form-control" value="<?= htmlspecialchars($userPets[0]['age'] ?? '2 Tahun') ?>" placeholder="Contoh: 2 Tahun">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Metode Kedatangan</label>
                        <select id="bookingPickupOption" class="form-control">
                            <option value="Diantar Sendiri ke Lobby HappyPet">Diantar Sendiri ke Lobby HappyPet</option>
                            <option value="Jemput dengan Pet Taxi HappyPet">Jemput dengan Pet Taxi HappyPet</option>
                        </select>
                    </div>
                </div>

                <!-- Step 4: Layanan Tambahan (Add-ons) -->
                <div class="form-group">
                    <label class="form-label">2. Pilih Layanan Tambahan / Add-ons Care (Opsional)</label>
                    <div class="addons-list">
                        <?php foreach ($addons as $addon): ?>
                            <label class="addon-item">
                                <input type="checkbox" class="booking-addon-checkbox" value="<?= htmlspecialchars($addon['id']) ?>" data-price="<?= (int)$addon['price'] ?>" data-pricetype="<?= htmlspecialchars($addon['price_type']) ?>" onchange="recalculateBookingPopup()">
                                <div>
                                    <div style="font-weight:700; font-size:13px; color:var(--text-slate);">
                                        <i class="fa-solid <?= htmlspecialchars($addon['icon']) ?>" style="color:var(--sage-primary);"></i>
                                        <?= htmlspecialchars($addon['name']) ?>
                                    </div>
                                    <div style="font-size:12px; font-weight:700; color:var(--amber-hover);">
                                        + <?= formatRupiah($addon['price']) ?><?= $addon['price_type'] === 'per_day' ? ' / hari' : '' ?>
                                    </div>
                                    <div style="font-size:11.5px; color:var(--text-muted); line-height:1.35; margin-top:2px;">
                                        <?= htmlspecialchars($addon['desc']) ?>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:8px;">
                    <label class="form-label">Catatan Pakan / Jadwal Obat / Kebiasaan Khusus</label>
                    <textarea id="bookingSpecialNotes" rows="2" class="form-control" placeholder="Tuliskan instruksi khusus untuk Pet Sitter & Dokter Hewan kami..."><?= htmlspecialchars($userPets[0]['special_notes'] ?? '') ?></textarea>
                </div>

                <!-- Real-time Summary Calculation -->
                <div class="booking-summary-box">
                    <div class="summary-row">
                        <span id="summaryPkgLabel">Paket Penitipan</span>
                        <strong id="summaryRoomSubtotal">Rp 0</strong>
                    </div>
                    <div class="summary-row">
                        <span>Total Layanan Tambahan (Add-ons)</span>
                        <strong id="summaryAddonsTotal">Rp 0</strong>
                    </div>
                    <div class="summary-row">
                        <span>Biaya Layanan & Asuransi Kesehatan Anabul</span>
                        <strong>Rp 5.000</strong>
                    </div>
                    <div class="summary-row total">
                        <span>TOTAL PEMBAYARAN (MIDTRANS)</span>
                        <span id="summaryGrandTotal">Rp 0</span>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeBookingModal()">
                    Batal
                </button>
                <button type="submit" id="btnSubmitBooking" class="btn btn-forest" style="padding:12px 24px; font-size:15px;">
                    <i class="fa-solid fa-shield-halved"></i> Lanjut ke Pembayaran Midtrans
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ======================================================================
     MODAL 2: MIDTRANS SNAP PAYMENT GATEWAY POPUP (#midtransModal)
     (Spesifikasi #6: Gunakan payment gateway seperti Midtrans)
     ====================================================================== -->
<div class="modal-overlay" id="midtransModal">
    <div class="modal-box midtrans-box">
        <div class="midtrans-header">
            <div class="midtrans-brand-row">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:34px; height:34px; border-radius:9px; background:#00AAE7; display:flex; align-items:center; justify-content:center; font-weight:900; color:#FFF;">
                        M
                    </div>
                    <div>
                        <div style="font-weight:800; font-size:15px; letter-spacing:0.02em;">midtrans <span style="font-weight:400; opacity:0.8;">SNAP</span></div>
                        <div style="font-size:11px; color:#93C5FD;">HappyPet Luxury Pet Care &bull; Merchant G812945001</div>
                    </div>
                </div>
                <span class="midtrans-badge">SANDBOX</span>
            </div>

            <div class="midtrans-amount-bar">
                <div>
                    <div style="font-size:11px; color:#CBD5E1;">Total Tagihan</div>
                    <div id="midtransOrderTotal" style="font-size:21px; font-weight:800; color:#FFFFFF;">Rp 0</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:11px; color:#CBD5E1;">Order ID</div>
                    <div id="midtransOrderInvoice" style="font-family:monospace; font-size:12.5px; font-weight:700; color:#FDE68A;">INV-HP-XXXX</div>
                </div>
            </div>
            <div id="midtransItemSummary" style="font-size:12px; color:#E2E8F0; margin-top:8px; opacity:0.9;"></div>
        </div>

        <div class="modal-body" style="padding:20px 24px;">
            <div style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:10px;">
                Pilih Metode Pembayaran Midtrans:
            </div>

            <div class="payment-channel-card active" data-channel="QRIS (GoPay / M-Banking)" onclick="selectMidtransChannel('QRIS (GoPay / M-Banking)', 'QRIS-HP-998120', 'qris')">
                <div style="display:flex; align-items:center; gap:12px;">
                    <i class="fa-solid fa-qrcode" style="font-size:20px; color:var(--forest-cta); width:24px; text-align:center;"></i>
                    <div>
                        <div style="font-weight:700; font-size:13.5px;">QRIS Instant (GoPay, OVO, Dana, ShopeePay)</div>
                        <div style="font-size:11.5px; color:var(--text-muted);">Verifikasi otomatis real-time tanpa biaya tambahan</div>
                    </div>
                </div>
                <i class="fa-solid fa-chevron-right" style="color:var(--sage-primary);"></i>
            </div>

            <div class="payment-channel-card" data-channel="BCA Virtual Account" onclick="selectMidtransChannel('BCA Virtual Account', '8277081288997766', 'va')">
                <div style="display:flex; align-items:center; gap:12px;">
                    <i class="fa-solid fa-building-columns" style="font-size:19px; color:#005BAA; width:24px; text-align:center;"></i>
                    <div>
                        <div style="font-weight:700; font-size:13.5px;">BCA Virtual Account</div>
                        <div style="font-size:11.5px; color:var(--text-muted);">Bayar dari m-BCA, KlikBCA, atau ATM BCA</div>
                    </div>
                </div>
                <i class="fa-solid fa-chevron-right" style="color:var(--sage-primary);"></i>
            </div>

            <div class="payment-channel-card" data-channel="Mandiri Bill Payment" onclick="selectMidtransChannel('Mandiri Bill Payment', '700128899001122', 'va')">
                <div style="display:flex; align-items:center; gap:12px;">
                    <i class="fa-solid fa-landmark" style="font-size:19px; color:#D4A373; width:24px; text-align:center;"></i>
                    <div>
                        <div style="font-weight:700; font-size:13.5px;">Mandiri Virtual Account (Livin')</div>
                        <div style="font-size:11.5px; color:var(--text-muted);">Kode Perusahaan 70012 (Midtrans)</div>
                    </div>
                </div>
                <i class="fa-solid fa-chevron-right" style="color:var(--sage-primary);"></i>
            </div>

            <div class="payment-channel-card" data-channel="Kartu Kredit / Debit Visa-Mastercard" onclick="selectMidtransChannel('Kartu Kredit / Debit Visa-Mastercard', 'CC-4811-1114', 'card')">
                <div style="display:flex; align-items:center; gap:12px;">
                    <i class="fa-regular fa-credit-card" style="font-size:19px; color:var(--forest-cta); width:24px; text-align:center;"></i>
                    <div>
                        <div style="font-weight:700; font-size:13.5px;">Kartu Kredit / Debit (Visa, Mastercard, JCB)</div>
                        <div style="font-size:11.5px; color:var(--text-muted);">3D Secure 2.0 Instant Settlement</div>
                    </div>
                </div>
                <i class="fa-solid fa-chevron-right" style="color:var(--sage-primary);"></i>
            </div>

            <!-- Dynamic Payment Instruction / QR / VA Display -->
            <div id="midtransInstructionBox" style="margin-top:14px;"></div>

            <div style="margin-top:12px; font-size:11px; color:var(--text-muted); display:flex; justify-content:space-between;">
                <span>Snap Token: <code id="midtransSnapTokenText">SNAP-TOKEN</code></span>
                <span><i class="fa-solid fa-lock"></i> 256-bit SSL Encrypted</span>
            </div>
        </div>

        <div class="modal-footer" style="padding:16px 24px; flex-direction:column; gap:8px;">
            <button type="button" id="btnMidtransConfirmPay" class="btn btn-forest" style="width:100%; padding:13px;" onclick="processMidtransSimulation('settlement')">
                <i class="fa-solid fa-circle-check"></i> Konfirmasi & Bayar Sekarang (Lunas)
            </button>
            <div style="display:flex; width:100%; gap:8px;">
                <button type="button" class="btn btn-outline btn-sm" style="flex:1;" onclick="processMidtransSimulation('pending')">
                    <i class="fa-regular fa-clock"></i> Bayar Nanti (Pending)
                </button>
                <button type="button" class="btn btn-outline btn-sm" onclick="closeMidtransModal(true)">
                    Tutup
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ======================================================================
     MODAL 3: PREVIEW & CETAK STRUK POPUP (#receiptModal)
     ====================================================================== -->
<div class="modal-overlay" id="receiptModal">
    <div class="modal-box" style="max-width:660px;">
        <div class="modal-header">
            <div>
                <h3><i class="fa-solid fa-receipt" style="color:var(--sand-secondary);"></i> Bukti Pembelian & Struk Penitipan</h3>
                <p>Struk resmi HappyPet terverifikasi oleh Midtrans Payment Gateway.</p>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeReceiptModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="modal-body" id="receiptModalContent">
            <!-- Populated dynamically via JS -->
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeReceiptModal()">
                Tutup
            </button>
            <div style="display:flex; gap:10px;">
                <a href="struk.php" id="btnViewFullReceipt" class="btn btn-sand">
                    <i class="fa-solid fa-up-right-from-square"></i> Mode Struk 80mm / A4
                </a>
                <a href="struk.php" id="btnPrintFullReceipt" class="btn btn-forest">
                    <i class="fa-solid fa-print"></i> Cetak Struk Sekarang
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<script>
    window.HAPPYPET_PETS = <?= json_encode($userPets, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
<?php if ($flashMessage): ?>
<script>
    window.addEventListener('DOMContentLoaded', function() {
        showToast(<?= json_encode($flashMessage) ?>);
    });
</script>
<?php endif; ?>
</body>
</html>
