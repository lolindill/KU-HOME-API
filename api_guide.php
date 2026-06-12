<?php
/**
 * 🔗 KU HOME API — Full Chain Integration Test (Seed → Check-Out)
 * 
 * สคริปต์ทดสอบ API แบบ Chain Flow ครบวงจร
 * ดึง ID จาก response แล้วส่งต่อไป request ถัดไปอัตโนมัติ
 * 
 * Chain: GET room-types → GET rooms → POST login → POST booking → POST payment
 *        → POST webhook → PUT confirm → PUT assign-rooms → POST check-in → POST check-out
 * 
 * วิธีใช้: php api_guide.php
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// ════════════════════════════════════════════════════════════════
// ✏️ FILLABLE CONFIG — แก้ค่าตรงนี้ได้เลยค่ะนายท่าน!
// ════════════════════════════════════════════════════════════════
$BASE_URL     = 'http://hotel.test/api/v1';
$ROOM_TYPE_ID = '';                    // ว่าง = auto-detect จาก GET /room-types
$ROOM_ID      = '';                    // ว่าง = auto-detect จาก GET /rooms (available)
$EMAIL        = 'admin@kuhome.com';    // Admin login
$PASSWORD     = 'password123';

// ════════════════════════════════════════════════════════════════
// 🎨 Color Helpers
// ════════════════════════════════════════════════════════════════
function green($t) { return "\033[32m{$t}\033[0m"; }
function red($t)   { return "\033[31m{$t}\033[0m"; }
function yellow($t){ return "\033[33m{$t}\033[0m"; }
function cyan($t)  { return "\033[36m{$t}\033[0m"; }
function bold($t)  { return "\033[1m{$t}\033[0m"; }
function magenta($t){ return "\033[35m{$t}\033[0m"; }

// ════════════════════════════════════════════════════════════════
// 🔧 HTTP Helpers
// ════════════════════════════════════════════════════════════════
$stats = ['pass' => 0, 'fail' => 0, 'skip' => 0];
$chain = []; // Store chained IDs

function apiCall($method, $url, $data = null, $token = null, $headers = []) {
    $ch = curl_init();
    
    $curlHeaders = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $curlHeaders[] = "Authorization: Bearer {$token}";
    }
    foreach ($headers as $k => $v) {
        $curlHeaders[] = "{$k}: {$v}";
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $curlHeaders,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['http_code' => 0, 'body' => null, 'error' => $error];
    }

    return [
        'http_code' => $httpCode,
        'body'      => json_decode($response, true),
        'error'     => null,
    ];
}

function step($stepNum, $label, $method, $url, $data = null, $token = null, $expectedCode = null) {
    global $BASE_URL, $stats;
    
    $fullUrl = strpos($url, 'http') === 0 ? $url : $BASE_URL . $url;
    $methodDisplay = strtoupper($method);
    
    echo bold("  Step {$stepNum}: ") . magenta("{$methodDisplay} {$url}") . "\n";
    echo cyan("    → {$label}") . "\n";
    
    // Show request body (compact)
    if ($data !== null) {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 200) {
            $json = substr($json, 0, 200) . '...';
        }
        echo yellow("    → Body: {$json}") . "\n";
    }
    
    $result = apiCall($method, $fullUrl, $data, $token);
    
    if ($result['error']) {
        $stats['fail']++;
        echo red("    ❌ FAIL: cURL error — {$result['error']}") . "\n\n";
        return $result;
    }
    
    $code = $result['http_code'];
    $body = $result['body'];
    
    $passed = true;
    if ($expectedCode !== null && $code !== $expectedCode) {
        $passed = false;
    }
    
    if ($passed) {
        $stats['pass']++;
        echo green("    ✅ {$code}");
    } else {
        $stats['fail']++;
        echo red("    ❌ {$code} (expected {$expectedCode})");
    }
    
    // Show brief response info
    if ($body) {
        $status = $body['status'] ?? '???';
        $msg = $body['message'] ?? '';
        echo " | status={$status}";
        if ($msg && mb_strlen($msg) < 80) {
            echo " | {$msg}";
        }
    }
    echo "\n";
    
    return $result;
}

function chainSave($key, $value) {
    global $chain;
    if ($value !== null) {
        $chain[$key] = $value;
        echo yellow("    💾 Chain: {$key} = {$value}") . "\n";
    }
}

function chainGet($key) {
    global $chain;
    return $chain[$key] ?? null;
}

function printSeparator() {
    echo "\n" . bold(str_repeat('─', 60)) . "\n";
}

// ════════════════════════════════════════════════════════════════
// 🧹 Pre-flight: Clean up ALL old test data (drafts + stuck bookings)
// ════════════════════════════════════════════════════════════════
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Payment as TestPayment;
use App\Models\Receipt as TestReceipt;
use App\Models\HousekeepingTask;

// Delete all test bookings (any status) + related data
$testBookingIds = Booking::where('guest_email', 'like', '%@kuhome.test')->pluck('id')->toArray();
if (!empty($testBookingIds)) {
    // Delete related records first (FK constraints)
    TestPayment::whereIn('booking_id', $testBookingIds)->forceDelete();
    TestReceipt::whereIn('booking_id', $testBookingIds)->forceDelete();
    $taskRoomIds = BookingRoom::whereIn('booking_id', $testBookingIds)->whereNotNull('room_id')->pluck('room_id')->toArray();
    HousekeepingTask::whereIn('room_id', $taskRoomIds)->forceDelete();
    BookingRoom::whereIn('booking_id', $testBookingIds)->forceDelete();
    Booking::whereIn('id', $testBookingIds)->forceDelete();
    echo yellow("🧹 Cleaned up " . count($testBookingIds) . " old test booking(s) + related data") . "\n";

    // Reset rooms that were stuck in occupied/checkout_makeup back to available
    $resetRooms = \App\Models\Room::whereIn('status', ['occupied', 'checkout_makeup', 'prep_checkin'])->get();
    foreach ($resetRooms as $r) {
        $r->update(['status' => 'available']);
    }
    if ($resetRooms->count() > 0) {
        echo yellow("🧹 Reset {$resetRooms->count()} room(s) back to available") . "\n";
    }
}
echo "\n";

// ════════════════════════════════════════════════════════════════
// 🚀 Start Testing
// ════════════════════════════════════════════════════════════════
echo bold("\n" . str_repeat('=', 60)) . "\n";
echo bold("🏨 KU HOME API — Full Chain Guide (Seed → Check-Out)") . "\n";
echo bold(str_repeat('=', 60)) . "\n";
echo "Base URL: {$BASE_URL}\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// ════════════════════════════════════════════════════════════════
// Step 1: GET /room-types — ดึง Room Type ID
// ════════════════════════════════════════════════════════════════
printSeparator();
echo bold("📋 PHASE 1: Read Seed Data (Room Types & Rooms)") . "\n";
printSeparator();

if (!empty($ROOM_TYPE_ID)) {
    echo yellow("  ⚡ ROOM_TYPE_ID pre-filled: {$ROOM_TYPE_ID}") . "\n";
    chainSave('ROOM_TYPE_ID', $ROOM_TYPE_ID);
} else {
    $r = step(1, 'ดึงประเภทห้องทั้งหมด', 'GET', '/room-types', null, null, 200);
    $types = $r['body']['room_types'] ?? $r['body']['data'] ?? [];
    if (!empty($types)) {
        // ใช้ type แรกที่เจอ
        $firstType = is_array($types) ? ($types[0] ?? null) : null;
        // Try nested array from response
        if (!$firstType && isset($r['body']['room_types'])) {
            $firstType = $r['body']['room_types'][0] ?? null;
        }
        if ($firstType && isset($firstType['id'])) {
            chainSave('ROOM_TYPE_ID', $firstType['id']);
            echo "    📊 Room Type: {$firstType['name_en']} — rate: {$firstType['rate_daily_general']}/night\n";
        }
    }
}

// ════════════════════════════════════════════════════════════════
// Step 2: GET /rooms — ดึง Room ID ที่ available
// ════════════════════════════════════════════════════════════════
if (!empty($ROOM_ID)) {
    echo yellow("  ⚡ ROOM_ID pre-filled: {$ROOM_ID}") . "\n";
    chainSave('ROOM_ID', $ROOM_ID);
} else {
    $r = step(2, 'ดึงห้องพักทั้งหมด (หาห้อง available)', 'GET', '/rooms', null, null, 200);
    $rooms = $r['body']['rooms'] ?? $r['body']['data'] ?? [];
    $ROOM_TYPE_ID = chainGet('ROOM_TYPE_ID');
    if (!empty($rooms)) {
        // หาห้อง available ที่ตรงกับ room type ที่เลือก
        foreach ($rooms as $room) {
            $isAvailable = ($room['status'] ?? '') === 'available';
            $typeMatch = !$ROOM_TYPE_ID || ($room['room_type_id'] ?? '') === $ROOM_TYPE_ID;
            if ($isAvailable && $typeMatch) {
                chainSave('ROOM_ID', $room['id']);
                echo "    🛏️ Selected Room: #{$room['room_number']} (status: {$room['status']})\n";
                break;
            }
        }
    }
}

echo "\n";
echo "  📊 Chain State so far:\n";
foreach ($chain as $k => $v) {
    echo yellow("    💾 {$k} = {$v}") . "\n";
}

// ════════════════════════════════════════════════════════════════
// Step 3: POST /login — เข้าสู่ระบบ (Admin)
// ════════════════════════════════════════════════════════════════
printSeparator();
echo bold("🔑 PHASE 2: Login (Admin)") . "\n";
printSeparator();

$r = step(3, 'Login as admin', 'POST', '/login', [
    'email'    => $EMAIL,
    'password' => $PASSWORD,
], null, 200);

$TOKEN = $r['body']['access_token'] ?? null;
if ($TOKEN) {
    chainSave('TOKEN', $TOKEN);
} else {
    echo red("  ⛔ No token! Cannot continue. Check your credentials.") . "\n";
    exit(1);
}

// Get user profile to grab user_id
$r = step('3b', 'Get profile (/me)', 'GET', '/me', null, $TOKEN, 200);
$USER_ID = $r['body']['user']['id'] ?? $r['body']['id'] ?? null;
if ($USER_ID) {
    chainSave('USER_ID', $USER_ID);
    $userRole = $r['body']['user']['role'] ?? $r['body']['role'] ?? '???';
    echo "    👤 Role: {$userRole}\n";
}

// ════════════════════════════════════════════════════════════════
// Step 4: POST /bookings — สร้างการจอง (draft)
// ════════════════════════════════════════════════════════════════
printSeparator();
echo bold("📅 PHASE 3: Create Booking (→ draft)") . "\n";
printSeparator();

$TIMESTAMP = time();
$TEST_EMAIL = "guide_test_{$TIMESTAMP}@kuhome.test";
$ROOM_TYPE_ID = chainGet('ROOM_TYPE_ID');
$TOKEN = chainGet('TOKEN');

$tomorrow = date('Y-m-d', strtotime('+1 day'));
$dayAfter = date('Y-m-d', strtotime('+3 days'));

$bookingData = [
    'source'            => 'online',
    'check_in'          => $tomorrow,
    'check_out'         => $dayAfter,
    'guest_title'       => 'Mr.',
    'guest_name'        => "Guide Test Guest {$TIMESTAMP}",
    'guest_email'       => $TEST_EMAIL,
    'guest_phone'       => '081-234-5678',
    'guest_nationality' => 'Thai',
    'children'          => 0,
    'booking_rooms'     => [
        [
            'room_type_id' => $ROOM_TYPE_ID,
            'quantity'     => 1,
            'extra_beds'   => 0,
            'addons'       => [
                'breakfast'           => 1,
                'breakfast_price'     => 200,
                'early_checkIn_price' => 0,
                'late_checkOut_price' => 0,
            ],
        ],
    ],
];

$r = step(4, 'สร้างการจองใหม่ (จะได้สถานะ draft)', 'POST', '/bookings', $bookingData, $TOKEN, 201);

$BOOKING_ID = $r['body']['booking_id'] ?? null;
$TOTAL_AMOUNT = $r['body']['total_amount'] ?? null;
chainSave('BOOKING_ID', $BOOKING_ID);
chainSave('TOTAL_AMOUNT', $TOTAL_AMOUNT);
echo "    💰 Total Amount: " . ($TOTAL_AMOUNT ? number_format($TOTAL_AMOUNT / 100, 2) . ' THB (satang)' : 'N/A') . "\n";
echo "    ⏰ Payment Deadline: " . ($r['body']['payment_deadline'] ?? 'N/A') . "\n";

if (!$BOOKING_ID) {
    echo red("\n  ⛔ BOOKING_ID is null! Cannot continue chain. Fix the error above first.\n") ;
    exit(1);
}

// ════════════════════════════════════════════════════════════════
// Step 5: POST /front-desk/{id}/payment — บันทึกการชำระเงิน
//         (Front Desk recordPayment — status=completed, auto draft→paid)
// ════════════════════════════════════════════════════════════════
printSeparator();
echo bold("💳 PHASE 4: Record Payment (draft → paid)") . "\n";
printSeparator();

$BOOKING_ID = chainGet('BOOKING_ID');
$TOTAL_AMOUNT = chainGet('TOTAL_AMOUNT');
$TOKEN = chainGet('TOKEN');
$USER_ID = chainGet('USER_ID');

$r = step(5, 'บันทึกการชำระเงิน (completed → auto draft→paid)', 'POST', "/front-desk/{$BOOKING_ID}/payment", [
    'booking_id'      => $BOOKING_ID,
    'amount'          => $TOTAL_AMOUNT ?? 100000,
    'payment_method'  => 'cash',
    'reference_number' => 'CASH-GUIDE-' . $TIMESTAMP,
    'received_by'     => $USER_ID,
], $TOKEN, 201);

$PAYMENT_ID = $r['body']['payment']['id'] ?? null;
chainSave('PAYMENT_ID', $PAYMENT_ID);
echo "    📊 Booking is_paid: " . (($r['body']['booking_is_paid'] ?? false) ? '✅ Yes' : '❌ No') . "\n";
echo "    📊 Booking status: " . ($r['body']['booking_status'] ?? '???') . "\n";
// Show full error details if failed
if ($r['http_code'] >= 400 && $r['body']) {
    echo red("    🔍 Error details: " . json_encode($r['body'], JSON_UNESCAPED_UNICODE)) . "\n";
}

// ════════════════════════════════════════════════════════════════
// Step 7: PUT /bookings/update/{id} — Admin confirm (paid → confirmed)
// ════════════════════════════════════════════════════════════════
printSeparator();
echo bold("🔄 PHASE 5: Admin Confirm (paid → confirmed)") . "\n";
printSeparator();

$BOOKING_ID = chainGet('BOOKING_ID');
$TOKEN = chainGet('TOKEN');

$r = step(7, 'Admin confirm booking (paid → confirmed)', 'PUT', "/bookings/update/{$BOOKING_ID}", [
    'status' => 'confirmed',
], $TOKEN, 200);

$newStatus = $r['body']['booking_status'] ?? '???';
echo "    📊 Booking status now: {$newStatus}\n";

// ════════════════════════════════════════════════════════════════
// Step 8: PUT /bookings/{id}/assign-rooms — Auto-assign ห้อง
// ════════════════════════════════════════════════════════════════
printSeparator();
echo bold("🛏️ PHASE 6: Assign Rooms (auto)") . "\n";
printSeparator();

$r = step(8, 'Auto-assign rooms to booking', 'PUT', "/bookings/{$BOOKING_ID}/assign-rooms", null, $TOKEN, 200);

// Also try to get the assigned room_id from the booking details
$r2 = step('8b', 'ดู booking detail (เก็บ assigned room_id)', 'GET', "/bookings/{$BOOKING_ID}", null, $TOKEN, 200);
$bookingDetail = $r2['body']['booking'] ?? null;
if ($bookingDetail) {
    $bookingRooms = $bookingDetail['booking_rooms'] ?? [];
    if (!empty($bookingRooms)) {
        $assignedRoomId = $bookingRooms[0]['room']['id'] ?? $bookingRooms[0]['room_id'] ?? null;
        if ($assignedRoomId) {
            chainSave('ASSIGNED_ROOM_ID', $assignedRoomId);
            $roomNumber = $bookingRooms[0]['room']['room_number'] ?? '???';
            echo "    🛏️ Assigned Room: #{$roomNumber}\n";
        }
    }
}

// ════════════════════════════════════════════════════════════════
// Step 9: POST /front-desk/{id}/check-in — Check-in
// ════════════════════════════════════════════════════════════════
printSeparator();
echo bold("🛎️ PHASE 7: Check-In (confirmed → checked_in)") . "\n";
printSeparator();

$BOOKING_ID = chainGet('BOOKING_ID');
$ASSIGNED_ROOM_ID = chainGet('ASSIGNED_ROOM_ID');
$ROOM_ID = chainGet('ROOM_ID');
$TOKEN = chainGet('TOKEN');

// Use assigned room if available, fallback to ROOM_ID from seed
$checkInRoomId = $ASSIGNED_ROOM_ID ?? $ROOM_ID;

$r = step(9, 'Check-in guest (confirmed → checked_in)', 'POST', "/front-desk/{$BOOKING_ID}/check-in", [
    'assigned_rooms' => [$checkInRoomId],
], $TOKEN, 200);

echo "    📊 Booking status: " . ($r['body']['booking_status'] ?? '???') . "\n";
$roomUpdates = $r['body']['room_updates'] ?? [];
foreach ($roomUpdates as $ru) {
    echo "    🛏️ Room #{$ru['room_number']} → {$ru['new_status']}\n";
}

// ════════════════════════════════════════════════════════════════
// Step 10: POST /front-desk/{id}/check-out — Check-out
// ════════════════════════════════════════════════════════════════
printSeparator();
echo bold("🧹 PHASE 8: Check-Out (checked_in → checked_out)") . "\n";
printSeparator();

$USER_ID = chainGet('USER_ID');

$r = step(10, 'Check-out guest (checked_in → checked_out)', 'POST', "/front-desk/{$BOOKING_ID}/check-out", [
    'verified_by' => $USER_ID,
    'notes'       => 'Guide test check-out',
], $TOKEN, 200);

echo "    📊 Booking status: " . ($r['body']['booking_status'] ?? '???') . "\n";
$roomUpdates = $r['body']['room_updates'] ?? [];
foreach ($roomUpdates as $ru) {
    echo "    🛏️ Room #{$ru['room_number']} → {$ru['room_status']}\n";
    echo "    🧹 Housekeeping task: " . ($ru['housekeeping_task_id'] ?? 'N/A') . "\n";
}

// ════════════════════════════════════════════════════════════════
// 🏁 Done!
// ════════════════════════════════════════════════════════════════
printSeparator();
echo bold("🏁 COMPLETE — Full Booking Lifecycle Finished!") . "\n";
printSeparator();

echo "\n  📊 Final Chain State:\n";
echo "  ┌──────────────────────┬──────────────────────────────────────┐\n";
echo "  │ Key                  │ Value                                │\n";
echo "  ├──────────────────────┼──────────────────────────────────────┤\n";
foreach ($chain as $k => $v) {
    $display = strlen($v) > 36 ? substr($v, 0, 33) . '...' : $v;
    printf("  │ %-20s │ %-36s │\n", $k, $display);
}
echo "  └──────────────────────┴──────────────────────────────────────┘\n";

// ════════════════════════════════════════════════════════════════
// 📊 Summary
// ════════════════════════════════════════════════════════════════
$total = $stats['pass'] + $stats['fail'] + $stats['skip'];

echo "\n" . bold(str_repeat('═', 60)) . "\n";
echo bold("📊 TEST SUMMARY") . "\n";
echo bold(str_repeat('═', 60)) . "\n";
echo green("  ✅ Passed:  {$stats['pass']}") . "\n";
echo red("    ❌ Failed:  {$stats['fail']}") . "\n";
echo yellow("  ⏭️ Skipped:  {$stats['skip']}") . "\n";
echo "  📋 Total:   {$total}\n";

if ($stats['fail'] === 0) {
    echo green("\n🎉 ALL TESTS PASSED! Full chain completed — สบายมากค่ะนายท่าน! ✨💖\n");
} else {
    echo yellow("\n⚠️ มีบางเทสตกอยู่นะคะ ตรวจสอบด่วนเลย! 💅\n");
}

echo bold(str_repeat('═', 60)) . "\n";