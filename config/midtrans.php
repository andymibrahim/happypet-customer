<?php
/**
 * HappyPet - Midtrans Snap Payment Gateway Integration
 * Supports Official Midtrans Snap REST API (cURL) + Built-in Interactive Snap Sandbox Simulator
 */

require_once __DIR__ . '/database.php';

class MidtransService {
    private array $config;

    public function __construct() {
        $db = HappyPetDB::getInstance();
        $this->config = $db->getMidtransConfig();
    }

    public function getConfig(): array {
        return $this->config;
    }

    public function getSnapJsUrl(): string {
        return ($this->config['environment'] ?? 'sandbox') === 'production'
            ? 'https://app.midtrans.com/snap/snap.js'
            : 'https://app.sandbox.midtrans.com/snap/snap.js';
    }

    public function getApiEndpoint(): string {
        return ($this->config['environment'] ?? 'sandbox') === 'production'
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
    }

    /**
     * Create Midtrans Snap Token for a booking transaction
     */
    public function createSnapTransaction(array $booking, array $user): array {
        $itemDetails = [
            [
                'id' => 'PKG-' . $booking['package_id'],
                'price' => (int)$booking['price_per_day'],
                'quantity' => (int)$booking['duration_days'],
                'name' => substr($booking['package_name'] . ' (' . $booking['pet_name'] . ')', 0, 50)
            ]
        ];

        if (!empty($booking['addons']) && is_array($booking['addons'])) {
            foreach ($booking['addons'] as $addon) {
                $itemDetails[] = [
                    'id' => $addon['id'] ?? 'ADDON',
                    'price' => (int)$addon['price'],
                    'quantity' => 1,
                    'name' => substr($addon['name'], 0, 50)
                ];
            }
        }

        if (!empty($booking['admin_fee'])) {
            $itemDetails[] = [
                'id' => 'ADMIN-FEE',
                'price' => (int)$booking['admin_fee'],
                'quantity' => 1,
                'name' => 'Biaya Layanan & Asuransi Penitipan'
            ];
        }

        $payload = [
            'transaction_details' => [
                'order_id' => $booking['invoice_number'],
                'gross_amount' => (int)$booking['total_amount']
            ],
            'item_details' => $itemDetails,
            'customer_details' => [
                'first_name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'] ?? '081200000000',
                'billing_address' => [
                    'address' => $user['address'] ?? 'Jakarta, Indonesia'
                ]
            ]
        ];

        // If user enabled live_api mode with their own Midtrans Server Key, call Midtrans Snap API
        $serverKey = trim($this->config['server_key'] ?? '');
        $isCustomKey = $serverKey !== '' && $serverKey !== 'SB-Mid-server-HappyPetDemo2026';

        if (($this->config['mode'] ?? 'simulator') === 'live_api' && $isCustomKey && function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->getApiEndpoint(),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Basic ' . base64_encode($serverKey . ':')
                ],
                CURLOPT_TIMEOUT => 10
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300 && $response) {
                $decoded = json_decode($response, true);
                if (!empty($decoded['token'])) {
                    return [
                        'snap_token' => $decoded['token'],
                        'redirect_url' => $decoded['redirect_url'] ?? '',
                        'mode' => 'live_api',
                        'payload' => $payload
                    ];
                }
            }
        }

        // Default / Fallback: Generate deterministic Sandbox Snap Token for Interactive Midtrans Snap Popup
        $snapToken = 'SNAP-HP-' . date('His') . '-' . strtoupper(substr(md5($booking['invoice_number'] . microtime()), 0, 8));
        return [
            'snap_token' => $snapToken,
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/' . $snapToken,
            'mode' => 'simulator',
            'payload' => $payload
        ];
    }
}
