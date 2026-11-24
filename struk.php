<?php
ob_start();
/**
 * HappyPet - Halaman Cetak Struk Resmi & Invoice Penitipan Hewan
 */

require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['happypet_user_id'])) {
    header('Location: login.php');
    exit;
}

$db = HappyPetDB::getInstance();
$userId = (int)$_SESSION['happypet_user_id'];
$actorId = $userId;
$currentUser = $db->findUserById($userId);
$actorUser = $currentUser;

$bookingId = (int)($_GET['id'] ?? 0);
$booking = $db->getBookingById($bookingId, $userId);

if (!$booking) {
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Struk Tidak Ditemukan</title></head><body style="font-family:sans-serif;padding:40px;text-align:center;"><h2>Data struk tidak ditemukan</h2><p><a href="index.php">Kembali ke Dashboard HappyPet</a></p></body></html>';
    exit;
}

$isPaid = ($booking['payment_status'] === 'settlement');
$autoPrint = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';

$db->logActivity(
    $actorId,
    $autoPrint ? 'print_receipt' : 'view_receipt',
    ($autoPrint ? 'Mencetak struk resmi ' : 'Membuka halaman struk ') . $booking['invoice_number'] . ' (' . $booking['pet_name'] . ').',
    'receipt',
    (string)$booking['id'],
    ['invoice_number' => $booking['invoice_number'], 'total_amount' => $booking['total_amount'], 'autoprint' => $autoPrint],
    $actorUser
);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Struk #<?= htmlspecialchars($booking['invoice_number']) ?> — HappyPet</title>
    <link rel="icon" type="image/png" href="assets/img/happypet-paw-badge-transparent.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=JetBrains+Mono:wght@500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --bg-alabaster: #F8FAFC;
            --sage-primary: #84A98C;
            --sand-secondary: #E6CCB2;
            --forest-cta: #354F52;
            --amber-cta: #D4A373;
            --text-slate: #1E293B;
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            background-color: var(--bg-alabaster);
            color: var(--text-slate);
            font-family: 'Plus Jakarta Sans', sans-serif;
            padding: 32px 18px 60px;
        }
        .print-toolbar {
            max-width: 720px;
            margin: 0 auto 22px;
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 16px;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            box-shadow: 0 6px 20px rgba(53, 79, 82, 0.08);
        }
        .toolbar-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13.5px;
            cursor: pointer;
            text-decoration: none;
            border: 1px solid transparent;
            transition: all 0.2s ease;
        }
        .btn-back {
            background: #F1F5F9;
            color: var(--text-slate);
        }
        .btn-mode {
            background: #FFFFFF;
            border-color: #CBD5E1;
            color: var(--forest-cta);
        }
        .btn-mode.active {
            background: var(--sage-primary);
            color: #FFFFFF;
            border-color: var(--sage-primary);
        }
        .btn-print {
            background: var(--forest-cta);
            color: #FFFFFF;
        }
        .btn-print:hover {
            background: #24383A;
        }

        /* RECEIPT CONTAINER */
        .receipt-card {
            max-width: 720px;
            margin: 0 auto;
            background: #FFFFFF;
            border-radius: 22px;
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(30, 41, 59, 0.12);
            border: 1.5px solid var(--sand-secondary);
            position: relative;
            transition: max-width 0.25s ease;
        }
        .receipt-card.thermal-mode {
            max-width: 410px;
            font-size: 13px;
        }

        .receipt-header {
            background: linear-gradient(135deg, var(--forest-cta) 0%, #446568 100%);
            color: #FFFFFF;
            padding: 28px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            border-bottom: 4px solid var(--amber-cta);
        }
        .receipt-card.thermal-mode .receipt-header {
            flex-direction: column;
            text-align: center;
            padding: 22px 20px;
        }

        .brand-row {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .receipt-card.thermal-mode .brand-row {
            flex-direction: column;
            gap: 8px;
        }
        .logo-badge {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--sage-primary), var(--amber-cta));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #FFFFFF;
        }

        .receipt-body {
            padding: 30px 32px;
            position: relative;
        }
        .receipt-card.thermal-mode .receipt-body {
            padding: 22px 20px;
        }

        /* Watermark Stamp */
        .paid-stamp {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 14px;
            border-radius: 99px;
            font-weight: 800;
            font-size: 12px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .stamp-lunas {
            background: #E6F4EA;
            color: #1E6F43;
            border: 1.5px solid #84A98C;
        }
        .stamp-pending {
            background: #FEF5E7;
            color: #9C6515;
            border: 1.5px solid #D4A373;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            padding: 18px 20px;
            margin: 18px 0 22px;
        }
        .receipt-card.thermal-mode .meta-grid {
            grid-template-columns: 1fr;
            gap: 12px;
            padding: 14px;
        }
        .meta-block h4 {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748B;
            margin-bottom: 6px;
        }
        .meta-block p {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-slate);
            line-height: 1.45;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .items-table th {
            background: #EBF2ED;
            color: var(--forest-cta);
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 10px 14px;
            text-align: left;
            border-bottom: 2px solid var(--sage-primary);
        }
        .items-table td {
            padding: 12px 14px;
            border-bottom: 1px dashed #CBD5E1;
            font-size: 13.5px;
        }
        .items-table td.right, .items-table th.right {
            text-align: right;
        }

        .total-box {
            background: linear-gradient(135deg, #F7EFE6 0%, #EFE2D3 100%);
            border: 1.5px solid var(--sand-secondary);
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 22px;
        }
        .total-line {
            display: flex;
            justify-content: space-between;
            font-size: 13.5px;
            margin-bottom: 6px;
            color: #475569;
        }
        .total-line.grand {
            border-top: 1.5px dashed #C8AD90;
            padding-top: 10px;
            margin-top: 8px;
            font-size: 18px;
            font-weight: 800;
            color: var(--forest-cta);
        }

        .receipt-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-top: 18px;
            border-top: 1px solid #E2E8F0;
            font-size: 12px;
            color: #64748B;
        }
        .receipt-card.thermal-mode .receipt-footer {
            flex-direction: column;
            text-align: center;
        }

        .qr-placeholder {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #F8FAFC;
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid #E2E8F0;
        }

        @media print {
            body {
                background: #FFFFFF;
                padding: 0;
            }
            .print-toolbar {
                display: none !important;
            }
            .receipt-card {
                box-shadow: none;
                border: 1px solid #CBD5E1;
                max-width: 100%;
                border-radius: 0;
            }
        }
    </style>
</head>
<body>

    <div class="print-toolbar">
        <a href="index.php?tab=history" class="toolbar-btn btn-back">
            <i class="fa-solid fa-arrow-left"></i> Kembali ke Dashboard
        </a>

        <div style="display:flex; gap:8px; align-items:center;">
            <button type="button" class="toolbar-btn btn-mode active" id="btnModeA4" onclick="setReceiptMode('a4')">
                <i class="fa-solid fa-file-invoice"></i> Ukuran Invoice A4
            </button>
            <button type="button" class="toolbar-btn btn-mode" id="btnModeThermal" onclick="setReceiptMode('thermal')">
                <i class="fa-solid fa-receipt"></i> Struk Kasir 80mm
            </button>
            <button type="button" class="toolbar-btn btn-print" onclick="triggerPrintReceipt()">
                <i class="fa-solid fa-print"></i> Cetak Struk Sekarang
            </button>
        </div>
    </div>

    <div class="receipt-card" id="receiptContainer">
        <!-- Header -->
        <div class="receipt-header">
            <div class="brand-row">
                <img src="assets/img/happypet-paw-badge-transparent.png" alt="HappyPet Paw Logo" style="width:50px; height:50px; object-fit:contain; flex-shrink:0;">
                <div>
                    <h1 style="font-family:'Fraunces',serif; font-size:24px; color:#FFFFFF;">HappyPet</h1>
                    <p style="font-size:12px; color:var(--sand-secondary);">Luxury Pet Hotel, Daycare & Organic Spa</p>
                    <p style="font-size:11.5px; color:rgba(255,255,255,0.8); margin-top:2px;">
                        Jl. Taman Kemang Utama No. 88, Jakarta &bull; Telp: (021) 5098-7788
                    </p>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:11px; text-transform:uppercase; color:var(--sand-secondary); letter-spacing:0.06em; font-weight:700;">
                    Bukti Pembelian & Penitipan
                </div>
                <div style="font-family:'JetBrains Mono',monospace; font-size:16px; font-weight:700; color:#FFFFFF; margin-top:3px;">
                    <?= htmlspecialchars($booking['invoice_number']) ?>
                </div>
                <div style="font-size:11.5px; color:rgba(255,255,255,0.82); margin-top:2px;">
                    Diterbitkan: <?= formatDateIndo($booking['created_at'], true) ?>
                    <input type=hidden value>
                </div>
            </div>
        </div>

        <!-- Body -->
        <div class="receipt-body">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                <div>
                    <?php if ($isPaid): ?>
                        <span class="paid-stamp stamp-lunas">
                            <i class="fa-solid fa-circle-check"></i> LUNAS &bull; TERVERIFIKASI MIDTRANS
                        </span>
                    <?php else: ?>
                        <span class="paid-stamp stamp-pending">
                            <i class="fa-solid fa-clock"></i> MENUNGGU PEMBAYARAN MIDTRANS
                        </span>
                    <?php endif; ?>
                </div>
                <div style="font-size:12.5px; color:#475569;">
                    <strong>Midtrans ID:</strong> <code><?= htmlspecialchars($booking['midtrans_transaction_id'] ?? '-') ?></code>
                </div>
            </div>

            <div class="meta-grid">
                <div class="meta-block">
                    <h4><i class="fa-solid fa-user"></i> Data Pemilik Hewan</h4>
                    <p><?= htmlspecialchars($currentUser['name']) ?></p>
                    <div style="font-size:12.5px; color:#64748B; margin-top:3px;">
                        <?= htmlspecialchars($currentUser['phone'] ?: '-') ?> &bull; <?= htmlspecialchars($currentUser['email']) ?><br>
                        <?= htmlspecialchars($currentUser['address'] ?: '-') ?>
                    </div>
                </div>

                <div class="meta-block">
                    <h4><i class="fa-solid fa-shield-dog"></i> Data Hewan Peliharaan (Anabul)</h4>
                    <p><?= htmlspecialchars($booking['pet_name']) ?> (<?= htmlspecialchars($booking['pet_species']) ?>)</p>
                    <div style="font-size:12.5px; color:#64748B; margin-top:3px;">
                        Ras: <?= htmlspecialchars($booking['pet_breed'] ?: '-') ?> &bull;
                        Berat: <?= htmlspecialchars((string)$booking['pet_weight']) ?> kg &bull;
                        Usia: <?= htmlspecialchars($booking['pet_age'] ?: '-') ?>
                    </div>
                </div>

                <div class="meta-block">
                    <h4><i class="fa-solid fa-calendar-check"></i> Jadwal Check-In & Check-Out</h4>
                    <p><?= formatDateIndo($booking['check_in']) ?> s/d <?= formatDateIndo($booking['check_out']) ?></p>
                    <div style="font-size:12.5px; color:#64748B; margin-top:3px;">
                        Durasi Menginap: <strong><?= (int)$booking['duration_days'] ?> Hari / Malam</strong><br>
                        Opsi Kedatangan: <?= htmlspecialchars($booking['pickup_option'] ?? 'Diantar Sendiri') ?>
                    </div>
                </div>

                <div class="meta-block">
                    <h4><i class="fa-solid fa-credit-card"></i> Metode Pembayaran Midtrans</h4>
                    <p><?= htmlspecialchars($booking['payment_channel'] ?: 'Midtrans Snap') ?></p>
                    <div style="font-size:12.5px; color:#64748B; margin-top:3px;">
                        No. VA / Ref: <code><?= htmlspecialchars($booking['va_number'] ?: '-') ?></code><br>
                        Waktu Bayar: <?= $booking['paid_at'] ? formatDateIndo($booking['paid_at'], true) : 'Belum Dibayar' ?>
                    </div>
                </div>
            </div>

            <!-- Itemized Breakdown Table -->
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Deskripsi Layanan Penitipan</th>
                        <th class="right">Harga Satuan</th>
                        <th class="right">Durasi / Qty</th>
                        <th class="right">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($booking['package_name']) ?></strong>
                            <div style="font-size:12px; color:#64748B;">
                                Paket Kamar Penitipan &bull; Anabul: <?= htmlspecialchars($booking['pet_name']) ?>
                            </div>
                        </td>
                        <td class="right"><?= formatRupiah($booking['price_per_day']) ?>/hari</td>
                        <td class="right"><?= (int)$booking['duration_days'] ?> Hari</td>
                        <td class="right"><strong><?= formatRupiah($booking['room_subtotal']) ?></strong></td>
                    </tr>

                    <?php if (!empty($booking['addons']) && is_array($booking['addons'])): ?>
                        <?php foreach ($booking['addons'] as $addon): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($addon['name']) ?>
                                    <div style="font-size:11.5px; color:#64748B;">Layanan Tambahan (Add-on Care)</div>
                                </td>
                                <td class="right"><?= formatRupiah($addon['price']) ?></td>
                                <td class="right">1 Paket</td>
                                <td class="right"><strong><?= formatRupiah($addon['price']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <tr>
                        <td>
                            Biaya Layanan & Proteksi Asuransi Kesehatan Hewan
                        </td>
                        <td class="right"><?= formatRupiah($booking['admin_fee']) ?></td>
                        <td class="right">1x</td>
                        <td class="right"><strong><?= formatRupiah($booking['admin_fee']) ?></strong></td>
                    </tr>
                </tbody>
            </table>

            <!-- Notes -->
            <div style="background:#F8FAFC; border-left:4px solid var(--sage-primary); padding:12px 16px; border-radius:8px; margin-bottom:18px; font-size:13px;">
                <strong><i class="fa-solid fa-clipboard-list"></i> Catatan Perawatan Khusus:</strong><br>
                <span style="color:#475569;"><?= htmlspecialchars($booking['special_notes'] ?: 'Tidak ada catatan khusus.') ?></span>
            </div>

            <!-- Totals -->
            <div class="total-box">
                <div class="total-line">
                    <span>Subtotal Kamar (<?= (int)$booking['duration_days'] ?> Hari)</span>
                    <span><?= formatRupiah($booking['room_subtotal']) ?></span>
                </div>
                <div class="total-line">
                    <span>Subtotal Layanan Tambahan (Add-ons)</span>
                    <span><?= formatRupiah($booking['addons_total']) ?></span>
                </div>
                <div class="total-line">
                    <span>Biaya Layanan & Asuransi</span>
                    <span><?= formatRupiah($booking['admin_fee']) ?></span>
                </div>
                <div class="total-line grand">
                    <span>TOTAL PEMBAYARAN</span>
                    <span><?= formatRupiah($booking['total_amount']) ?></span>
                </div>
            </div>

            <!-- Footer -->
            <div class="receipt-footer">
                <div>
                    <strong style="color:var(--forest-cta);">Terima kasih telah mempercayakan anabul Anda di HappyPet!</strong>
                    <p style="margin-top:4px;">
                        Harap tunjukkan struk ini (cetak atau digital) kepada petugas resepsionis saat Check-In maupun penjemputan hewan.
                    </p>
                </div>
                <div class="qr-placeholder">
                    <i class="fa-solid fa-qrcode" style="font-size:34px; color:var(--forest-cta);"></i>
                    <div style="text-align:left;">
                        <div style="font-weight:800; color:var(--forest-cta); font-size:11px;">VERIFIED BY MIDTRANS</div>
                        <div style="font-family:'JetBrains Mono',monospace; font-size:10.5px; color:#475569;">
                            <?= htmlspecialchars($booking['snap_token'] ?? 'SNAP-VERIFIED') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function setReceiptMode(mode) {
            const container = document.getElementById('receiptContainer');
            const btnA4 = document.getElementById('btnModeA4');
            const btnThermal = document.getElementById('btnModeThermal');
            if (mode === 'thermal') {
                container.classList.add('thermal-mode');
                btnThermal.classList.add('active');
                btnA4.classList.remove('active');
            } else {
                container.classList.remove('thermal-mode');
                btnA4.classList.add('active');
                btnThermal.classList.remove('active');
            }
        }
        var hasAutoPrintedReceipt = false;
        function triggerPrintReceipt() {
            try {
                window.focus();
                window.print();
            } catch (e) {
                alert('Silakan tekan Ctrl + P pada keyboard Anda untuk mencetak struk.');
            }
        }
        <?php if ($autoPrint): ?>
        function runReceiptAutoPrint() {
            if (hasAutoPrintedReceipt) return;
            hasAutoPrintedReceipt = true;
            setTimeout(triggerPrintReceipt, 500);
        }
        if (document.readyState === 'interactive' || document.readyState === 'complete') {
            runReceiptAutoPrint();
        } else {
            document.addEventListener('DOMContentLoaded', runReceiptAutoPrint);
        }
        <?php endif; ?>
    </script>
</body>
</html>
