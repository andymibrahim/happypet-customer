<?php
/**
 * HappyPet - Backend API Endpoint (JSON)
 * Handles Popup Booking Creation, Midtrans Snap Payment Callbacks, Profile Updates & Pet Management
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/midtrans.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['happypet_user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Sesi Anda telah berakhir. Silakan login kembali.']);
    exit;
}

$db = HappyPetDB::getInstance();
$userId = (int)$_SESSION['happypet_user_id'];
$currentUser = $db->findUserById($userId);

if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Pengguna tidak ditemukan.']);
    exit;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$rawBody = file_get_contents('php://input');
$jsonBody = json_decode($rawBody, true) ?? [];
$input = array_merge($_POST, $jsonBody);

switch ($action) {
    case 'create_booking':
        $packageId = (int)($input['package_id'] ?? 0);
        $pkg = $db->getPackageById($packageId);
        if (!$pkg) {
            echo json_encode(['status' => 'error', 'message' => 'Paket layanan tidak valid.']);
            exit;
        }

        $petName = trim($input['pet_name'] ?? '');
        $petSpecies = trim($input['pet_species'] ?? 'Kucing');
        $petBreed = trim($input['pet_breed'] ?? '-');
        $petWeight = (float)($input['pet_weight'] ?? 3.5);
        $petAge = trim($input['pet_age'] ?? '1 Tahun');
        $checkIn = trim($input['check_in'] ?? date('Y-m-d'));
        $checkOut = trim($input['check_out'] ?? date('Y-m-d', strtotime('+1 day')));
        $pickupOption = trim($input['pickup_option'] ?? 'Diantar Sendiri ke Lobby HappyPet');
        $specialNotes = trim($input['special_notes'] ?? '');
        $selectedAddonIds = $input['addons'] ?? [];

        if ($petName === '') {
            echo json_encode(['status' => 'error', 'message' => 'Nama hewan peliharaan wajib diisi.']);
            exit;
        }

        // Calculate duration days
        $tsIn = strtotime($checkIn);
        $tsOut = strtotime($checkOut);
        if (!$tsIn || !$tsOut || $tsOut <= $tsIn) {
            $durationDays = 1;
            $checkOut = date('Y-m-d', strtotime($checkIn . ' +1 day'));
        } else {
            $durationDays = max(1, (int)round(($tsOut - $tsIn) / 86400));
        }

        // Calculate room & addons
        $pricePerDay = (int)$pkg['price_per_day'];
        $roomSubtotal = $pricePerDay * $durationDays;

        $allAddons = $db->getAddons();
        $chosenAddons = [];
        $addonsTotal = 0;

        if (is_array($selectedAddonIds)) {
            foreach ($allAddons as $addon) {
                if (in_array($addon['id'], $selectedAddonIds, true)) {
                    $computedPrice = ($addon['price_type'] === 'per_day')
                        ? ((int)$addon['price'] * $durationDays)
                        : (int)$addon['price'];
                    $chosenAddons[] = [
                        'id' => $addon['id'],
                        'name' => $addon['name'] . ($addon['price_type'] === 'per_day' ? " ({$durationDays} hari)" : ''),
                        'price' => $computedPrice
                    ];
                    $addonsTotal += $computedPrice;
                }
            }
        }

        $adminFee = 5000;
        $totalAmount = $roomSubtotal + $addonsTotal + $adminFee;
        $invoiceNumber = 'INV-HP-' . date('Ymd') . '-' . rand(1000, 9999);

        // Optionally save new pet to user's pet list if requested
        if (!empty($input['save_new_pet'])) {
            $db->savePet($userId, [
                'name' => $petName,
                'species' => $petSpecies,
                'breed' => $petBreed,
                'age' => $petAge,
                'weight' => $petWeight,
                'vaccinated' => true,
                'special_notes' => $specialNotes
            ]);
        }

        $draftBooking = [
            'invoice_number' => $invoiceNumber,
            'user_id' => $userId,
            'package_id' => $pkg['id'],
            'package_name' => $pkg['name'],
            'package_category' => $pkg['category'],
            'price_per_day' => $pricePerDay,
            'pet_name' => $petName,
            'pet_species' => $petSpecies,
            'pet_breed' => $petBreed ?: '-',
            'pet_weight' => $petWeight,
            'pet_age' => $petAge ?: '-',
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'duration_days' => $durationDays,
            'addons' => $chosenAddons,
            'addons_total' => $addonsTotal,
            'room_subtotal' => $roomSubtotal,
            'admin_fee' => $adminFee,
            'total_amount' => $totalAmount,
            'special_notes' => $specialNotes ?: 'Tidak ada catatan khusus.',
            'pickup_option' => $pickupOption,
            'payment_status' => 'pending',
            'payment_method' => 'Midtrans Snap',
            'payment_channel' => 'Menunggu Pembayaran',
            'va_number' => '-',
            'midtrans_transaction_id' => 'MID-TRX-' . date('Ymd') . '-' . rand(100000, 999999),
            'paid_at' => null,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $midtrans = new MidtransService();
        $snapResult = $midtrans->createSnapTransaction($draftBooking, $currentUser);
        $draftBooking['snap_token'] = $snapResult['snap_token'];

        $savedBooking = $db->createBooking($draftBooking);

        $db->logActivity(
            $userId,
            'create_booking',
            'Membuat pesanan penitipan baru ' . $savedBooking['invoice_number'] . ' (' . $savedBooking['package_name'] . ' untuk ' . $savedBooking['pet_name'] . ').',
            'booking',
            (string)$savedBooking['id'],
            [
                'invoice_number' => $savedBooking['invoice_number'],
                'package_name' => $savedBooking['package_name'],
                'pet_name' => $savedBooking['pet_name'],
                'duration_days' => $savedBooking['duration_days'],
                'total_amount' => $savedBooking['total_amount'],
                'addons_count' => count($chosenAddons)
            ],
            $currentUser
        );

        echo json_encode([
            'status' => 'success',
            'message' => 'Pesanan penitipan berhasil dibuat! Silakan selesaikan pembayaran melalui Midtrans.',
            'booking' => $savedBooking,
            'snap' => $snapResult
        ]);
        break;

    case 'midtrans_callback':
        $bookingId = (int)($input['booking_id'] ?? 0);
        $paymentStatus = $input['payment_status'] ?? 'settlement';
        $paymentChannel = $input['payment_channel'] ?? 'QRIS (GoPay / M-Banking)';
        $vaNumber = $input['va_number'] ?? ('8277' . rand(1000000000, 9999999999));

        $updated = $db->updateBookingPayment($bookingId, $userId, [
            'payment_status' => $paymentStatus,
            'payment_channel' => $paymentChannel,
            'va_number' => $vaNumber,
            'midtrans_transaction_id' => $input['midtrans_transaction_id'] ?? ('MID-TRX-' . date('Ymd') . '-' . rand(100000, 999999))
        ]);

        if (!$updated) {
            echo json_encode(['status' => 'error', 'message' => 'Data transaksi tidak ditemukan.']);
            exit;
        }

        $db->logActivity(
            $userId,
            $paymentStatus === 'settlement' ? 'payment_success' : 'payment_update',
            'Pembayaran ' . $updated['invoice_number'] . ' status: ' . strtoupper($paymentStatus) . ' via ' . $paymentChannel . '.',
            'payment',
            (string)$updated['id'],
            [
                'invoice_number' => $updated['invoice_number'],
                'payment_status' => $paymentStatus,
                'payment_channel' => $paymentChannel,
                'total_amount' => $updated['total_amount']
            ],
            $currentUser
        );

        echo json_encode([
            'status' => 'success',
            'message' => $paymentStatus === 'settlement'
                ? 'Pembayaran Midtrans Berhasil! Pesanan penitipan telah terkonfirmasi LUNAS.'
                : 'Status pembayaran berhasil diperbarui.',
            'booking' => $updated
        ]);
        break;

    case 'get_booking':
        $bookingId = (int)($_GET['id'] ?? ($input['id'] ?? 0));
        $booking = $db->getBookingById($bookingId, $userId);
        if (!$booking) {
            echo json_encode(['status' => 'error', 'message' => 'Data pesanan tidak ditemukan.']);
            exit;
        }
        $db->logActivity(
            $userId,
            'view_booking_detail',
            'Melihat detail & preview struk pesanan ' . $booking['invoice_number'] . '.',
            'booking',
            (string)$booking['id'],
            ['invoice_number' => $booking['invoice_number']],
            $currentUser
        );
        echo json_encode([
            'status' => 'success',
            'booking' => $booking,
            'user' => [
                'name' => $currentUser['name'],
                'email' => $currentUser['email'],
                'phone' => $currentUser['phone'],
                'address' => $currentUser['address']
            ]
        ]);
        break;

    case 'update_profile':
        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        if ($name === '' || $email === '') {
            echo json_encode(['status' => 'error', 'message' => 'Nama lengkap dan email tidak boleh kosong.']);
            exit;
        }

        $avatarIcons = [
            'cat-sage' => ['icon' => 'fa-cat', 'bg' => '#84A98C', 'label' => 'Sage Cat'],
            'dog-amber' => ['icon' => 'fa-dog', 'bg' => '#D4A373', 'label' => 'Amber Dog'],
            'paw-forest' => ['icon' => 'fa-paw', 'bg' => '#354F52', 'label' => 'Forest Paw'],
            'dove-sand' => ['icon' => 'fa-feather-pointed', 'bg' => '#C59B76', 'label' => 'Sand Feather']
        ];
        $chosenAvatar = $input['avatar'] ?? ($currentUser['avatar'] ?? 'cat-sage');
        if (!isset($avatarIcons[$chosenAvatar])) {
            $chosenAvatar = 'cat-sage';
        }

        $updatedUser = $db->updateUser($userId, [
            'name' => $name,
            'email' => $email,
            'phone' => $input['phone'] ?? '',
            'address' => $input['address'] ?? '',
            'emergency_contact' => $input['emergency_contact'] ?? '',
            'vet_notes' => $input['vet_notes'] ?? '',
            'avatar' => $chosenAvatar,
            'new_password' => $input['new_password'] ?? ''
        ]);

        $avDetails = $avatarIcons[$updatedUser['avatar']] ?? $avatarIcons['cat-sage'];

        $db->logActivity(
            $userId,
            'update_profile',
            'Memperbarui profil akun (' . $updatedUser['name'] . ', avatar: ' . $avDetails['label'] . ').',
            'profile',
            (string)$userId,
            [
                'name' => $updatedUser['name'],
                'email' => $updatedUser['email'],
                'avatar' => $updatedUser['avatar'],
                'password_changed' => !empty($input['new_password'])
            ],
            $updatedUser
        );

        echo json_encode([
            'status' => 'success',
            'message' => 'Profil Anda berhasil diperbarui!',
            'user' => [
                'name' => $updatedUser['name'],
                'email' => $updatedUser['email'],
                'phone' => $updatedUser['phone'],
                'address' => $updatedUser['address'],
                'emergency_contact' => $updatedUser['emergency_contact'],
                'vet_notes' => $updatedUser['vet_notes'],
                'avatar' => $updatedUser['avatar'],
                'avatar_icon' => $avDetails['icon'],
                'avatar_bg' => $avDetails['bg'],
                'avatar_label' => $avDetails['label']
            ]
        ]);
        break;

    case 'save_pet':
        $petName = trim($input['name'] ?? '');
        if ($petName === '') {
            echo json_encode(['status' => 'error', 'message' => 'Nama hewan wajib diisi.']);
            exit;
        }
        $pet = $db->savePet($userId, $input);
        $db->logActivity(
            $userId,
            'save_pet',
            'Menyimpan data hewan peliharaan: ' . $pet['name'] . ' (' . $pet['species'] . ').',
            'pet',
            (string)$pet['id'],
            ['pet_name' => $pet['name'], 'species' => $pet['species'], 'breed' => $pet['breed']],
            $currentUser
        );
        echo json_encode([
            'status' => 'success',
            'message' => 'Data hewan peliharaan (' . $pet['name'] . ') berhasil disimpan!',
            'pet' => $pet,
            'pets' => $db->getPetsByUser($userId)
        ]);
        break;

    case 'delete_pet':
        $petId = (int)($input['pet_id'] ?? 0);
        $db->deletePet($userId, $petId);
        $db->logActivity(
            $userId,
            'delete_pet',
            'Menghapus data hewan peliharaan ID #' . $petId . '.',
            'pet',
            (string)$petId,
            ['pet_id' => $petId],
            $currentUser
        );
        echo json_encode([
            'status' => 'success',
            'message' => 'Data hewan berhasil dihapus.',
            'pets' => $db->getPetsByUser($userId)
        ]);
        break;

    case 'delete_booking':
        $bookingId = (int)($input['booking_id'] ?? ($_POST['booking_id'] ?? ($_GET['booking_id'] ?? ($_GET['id'] ?? 0))));
        if ($bookingId <= 0) {
            echo json_encode([
                'status' => 'error',
                'message' => 'ID transaksi tidak valid.'
            ]);
            exit;
        }
        $existingBooking = $db->getBookingById($bookingId, $userId);
        $deleted = $db->deleteBooking($userId, $bookingId);
        if (!$deleted) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Riwayat transaksi tidak ditemukan atau sudah dihapus.'
            ]);
            exit;
        }
        $invLabel = $existingBooking ? $existingBooking['invoice_number'] : ('#' . $bookingId);
        $db->logActivity(
            $userId,
            'delete_booking',
            'Menghapus riwayat transaksi ' . $invLabel . '.',
            'booking',
            (string)$bookingId,
            ['booking_id' => $bookingId, 'invoice_number' => $invLabel],
            $currentUser
        );
        echo json_encode([
            'status' => 'success',
            'message' => 'Riwayat pembelian berhasil dihapus.'
        ]);
        break;

    case 'save_midtrans_config':
        $cfg = $db->updateMidtransConfig([
            'mode' => $input['mode'] ?? 'simulator',
            'environment' => $input['environment'] ?? 'sandbox',
            'client_key' => trim($input['client_key'] ?? ''),
            'server_key' => trim($input['server_key'] ?? '')
        ]);
        $db->logActivity(
            $userId,
            'save_midtrans_config',
            'Memperbarui pengaturan Midtrans (' . $cfg['mode'] . ' / ' . $cfg['environment'] . ').',
            'config',
            'midtrans',
            ['mode' => $cfg['mode'], 'environment' => $cfg['environment']],
            $currentUser
        );
        echo json_encode([
            'status' => 'success',
            'message' => 'Konfigurasi Midtrans Payment Gateway berhasil disimpan!',
            'config' => $cfg
        ]);
        break;

    case 'log_interaction':
        $actionType = trim($input['action_type'] ?? 'ui_interaction');
        $description = trim($input['description'] ?? 'Interaksi pengguna di dashboard.');
        $targetEntity = isset($input['target_entity']) ? trim((string)$input['target_entity']) : 'ui';
        $targetId = isset($input['target_id']) ? (string)$input['target_id'] : null;
        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];

        $entry = $db->logActivity(
            $userId,
            $actionType,
            $description,
            $targetEntity,
            $targetId,
            $metadata,
            $currentUser
        );
        echo json_encode([
            'status' => 'success',
            'driver' => $db->getDriver(),
            'log' => $entry
        ]);
        break;

    case 'get_activity_logs':
        $limit = (int)($_GET['limit'] ?? ($input['limit'] ?? 50));
        echo json_encode([
            'status' => 'success',
            'db_info' => $db->getDbInfo(),
            'logs' => $db->getUserActivities($userId, $limit)
        ]);
        break;

    case 'db_status':
        echo json_encode([
            'status' => 'success',
            'db_info' => $db->getDbInfo()
        ]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Aksi API tidak dikenali.']);
        break;
}
