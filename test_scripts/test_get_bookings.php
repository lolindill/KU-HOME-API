<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Test GET /bookings ===\n\n";

// Step 1: Login
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'http://hotel.test/api/v1/login',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['email' => 'admin@kuhome.com', 'password' => 'password123']),
]);
$resp = curl_exec($ch);
$loginData = json_decode($resp, true);
$token = $loginData['access_token'] ?? null;

if (!$token) {
    echo "❌ Login failed!\n";
    echo "Response: $resp\n";
    exit(1);
}
echo "✅ Login OK — Token: " . substr($token, 0, 20) . "...\n\n";

// Step 2: GET /bookings
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'http://hotel.test/api/v1/bookings',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'GET',
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ],
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";

$data = json_decode($resp, true);
echo "Status: " . ($data['status'] ?? 'N/A') . "\n";
echo "Message: " . ($data['message'] ?? 'N/A') . "\n";

$bookings = $data['bookings'] ?? $data['data'] ?? [];
echo "Total bookings: " . count($bookings) . "\n\n";

if (empty($bookings)) {
    echo "Raw response (first 2000 chars):\n";
    echo substr($resp, 0, 2000) . "\n";
} else {
    foreach ($bookings as $i => $b) {
        $num = $i + 1;
        echo "━━━ Booking #{$num} ━━━\n";
        echo "  ID:           " . ($b['id'] ?? 'N/A') . "\n";
        echo "  Confirmation: " . ($b['confirmation'] ?? 'N/A') . "\n";
        echo "  Guest:        " . ($b['guest_name'] ?? 'N/A') . "\n";
        echo "  Email:        " . ($b['guest_email'] ?? 'N/A') . "\n";
        echo "  Status:       " . ($b['status'] ?? 'N/A') . "\n";
        echo "  Source:       " . ($b['source'] ?? 'N/A') . "\n";
        echo "  Check-in:     " . ($b['check_in'] ?? 'N/A') . "\n";
        echo "  Check-out:    " . ($b['check_out'] ?? 'N/A') . "\n";
        echo "  Total Amount: " . ($b['total_amount'] ?? 'N/A') . "\n";
        echo "  Is Paid:      " . (($b['is_paid'] ?? false) ? '✅ Yes' : '❌ No') . "\n";
        echo "  Payment Deadline: " . ($b['payment_deadline'] ?? 'N/A') . "\n";

        // Booking rooms
        $rooms = $b['booking_rooms'] ?? [];
        if (!empty($rooms)) {
            foreach ($rooms as $j => $br) {
                $roomNum = $br['room']['room_number'] ?? $br['room_id'] ?? 'Not assigned';
                $typeName = $br['room_type']['name_en'] ?? $br['room_type_id'] ?? 'N/A';
                echo "  Room " . ($j+1) . ":       #{$roomNum} (Type: {$typeName})\n";
            }
        }
        echo "\n";
    }
}

echo "=== DONE ===\n";