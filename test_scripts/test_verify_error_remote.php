<?php

$BASE_URL = getenv('KUHOME_BASE_URL') ?: 'https://ku-home.ku.ac.th/backend/api/v1';
$ADMIN_EMAIL = 'admin@kuhome.com';
$ADMIN_PASS = 'password123';

function green($t) { return "\033[32m{$t}\033[0m"; }
function red($t) { return "\033[31m{$t}\033[0m"; }
function cyan($t) { return "\033[36m{$t}\033[0m"; }
function bold($t) { return "\033[1m{$t}\033[0m"; }

function apiJson($method, $url, $data = null, $token = null) {
    $ch = curl_init();
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) $headers[] = "Authorization: Bearer {$token}";

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($res, true), 'raw' => $res];
}

function apiMultipart($url, $fields, $token = null) {
    $ch = curl_init();
    $headers = ['Accept: application/json'];
    if ($token) $headers[] = "Authorization: Bearer {$token}";

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($res, true), 'raw' => $res];
}

echo bold("\n🧪 Testing verify_error Lifecycle on Real Domain: {$BASE_URL}\n");

// 1. Get room type
$r = apiJson('GET', "{$BASE_URL}/room-types");
$roomTypeId = $r['body']['room_types'][0]['id'] ?? null;
if (!$roomTypeId) { echo red("❌ Could not get room_type_id\n"); exit(1); }
echo cyan("1. Harvested room_type_id: {$roomTypeId}\n");

// 2. Register user
$ts = time();
$userEmail = "remote_verify_{$ts}@kuhome.test";
$r = apiJson('POST', "{$BASE_URL}/register", [
    'name' => "Remote Test User {$ts}",
    'email' => $userEmail,
    'password' => 'password123',
    'password_confirmation' => 'password123',
    'phone' => '0812345678',
]);
$userToken = $r['body']['access_token'] ?? null;
if (!$userToken) { echo red("❌ User registration failed: " . json_encode($r['body']) . "\n"); exit(1); }
echo green("2. Registered user token obtained: {$userEmail}\n");

// 3. Admin login
$r = apiJson('POST', "{$BASE_URL}/login", ['email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASS]);
$adminToken = $r['body']['access_token'] ?? null;
if (!$adminToken) { echo red("❌ Admin login failed\n"); exit(1); }
echo green("3. Admin token obtained\n");

// 4. Create draft booking
$ci = date('Y-m-d', strtotime('+7 days'));
$co = date('Y-m-d', strtotime('+9 days'));
$r = apiJson('POST', "{$BASE_URL}/bookings", [
    'source' => 'online',
    'booking_rooms' => [[
        'room_type_id' => $roomTypeId,
        'check_in' => $ci,
        'check_out' => $co,
        'guests' => [['title' => 'Mr', 'name' => 'Verify Test', 'nationality' => 'Thai']],
    ]],
], $userToken);
$bookingId = $r['body']['booking_id'] ?? null;
if (!$bookingId) { echo red("❌ Create booking failed: " . json_encode($r['body']) . "\n"); exit(1); }
echo green("4. Booking created (draft): {$bookingId}\n");

// Create temporary slip image
$tmpSlip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "test_slip_{$ts}.png";
$img = imagecreatetruecolor(200, 200);
$bg = imagecolorallocate($img, 240, 240, 240);
imagefill($img, 0, 0, $bg);
imagepng($img, $tmpSlip);
imagedestroy($img);

// 5. Submit slip #1 (POST confirm -> pending)
$cfile = new CURLFile($tmpSlip, 'image/png', 'test_slip.png');
$r = apiMultipart("{$BASE_URL}/bookings/{$bookingId}/confirm", [
    'slip_image' => $cfile,
    'transfer_time' => date('Y-m-d H:i:s'),
], $userToken);
$confirmationId1 = $r['body']['confirmation_id'] ?? null;
$bStatus1 = $r['body']['booking_status'] ?? null;
echo cyan("5. Confirm #1 response (HTTP {$r['code']}): booking_status = {$bStatus1}\n");
if ($r['code'] === 201 && $bStatus1 === 'pending') {
    echo green("   ✅ PASS: Booking is 'pending'\n");
} else {
    echo red("   ❌ FAIL: Expected 201 and status 'pending', got HTTP {$r['code']} - " . json_encode($r['body']) . "\n");
}

// 6. Admin reject slip (PUT reject -> verify_error)
$r = apiJson('PUT', "{$BASE_URL}/booking-confirmations/{$confirmationId1}/reject", [
    'review_note' => 'สลิปไม่ชัดเจนค่ะ ทดสอบระบบ',
], $adminToken);
$bStatus2 = $r['body']['booking_status'] ?? null;
echo cyan("6. Admin Reject response (HTTP {$r['code']}): booking_status = {$bStatus2}\n");
if ($r['code'] === 200 && $bStatus2 === 'verify_error') {
    echo green("   ✅ PASS: Booking transitioned to 'verify_error' on reject!\n");
} else {
    echo red("   ❌ FAIL: Expected booking_status 'verify_error', got '{$bStatus2}' (HTTP {$r['code']}) - " . json_encode($r['body']) . "\n");
}

// 7. User resubmit slip #2 (POST confirm -> pending)
$cfile2 = new CURLFile($tmpSlip, 'image/png', 'test_slip_2.png');
$r = apiMultipart("{$BASE_URL}/bookings/{$bookingId}/confirm", [
    'slip_image' => $cfile2,
    'transfer_time' => date('Y-m-d H:i:s'),
], $userToken);
$confirmationId2 = $r['body']['confirmation_id'] ?? null;
$bStatus3 = $r['body']['booking_status'] ?? null;
echo cyan("7. Confirm #2 (Resubmit from verify_error) response (HTTP {$r['code']}): booking_status = {$bStatus3}\n");
if ($r['code'] === 201 && $bStatus3 === 'pending') {
    echo green("   ✅ PASS: Booking transitioned back to 'pending' from 'verify_error'!\n");
} else {
    echo red("   ❌ FAIL: Expected 201 and status 'pending', got HTTP {$r['code']} - " . json_encode($r['body']) . "\n");
}

// 8. Admin verify slip #2 (PUT verify -> confirmed)
$r = apiJson('PUT', "{$BASE_URL}/booking-confirmations/{$confirmationId2}/verify", [
    'review_note' => 'สลิปใหม่ถูกต้องค่ะ',
], $adminToken);
$bStatus4 = $r['body']['booking_status'] ?? null;
echo cyan("8. Admin Verify response (HTTP {$r['code']}): booking_status = {$bStatus4}\n");
if ($r['code'] === 200 && $bStatus4 === 'confirmed') {
    echo green("   ✅ PASS: Booking confirmed successfully!\n");
} else {
    echo red("   ❌ FAIL: Expected booking_status 'confirmed', got '{$bStatus4}' (HTTP {$r['code']}) - " . json_encode($r['body']) . "\n");
}

@unlink($tmpSlip);
echo bold("\n🎉 Real Domain verify_error Lifecycle Test Complete!\n\n");