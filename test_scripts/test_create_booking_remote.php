<?php

/**
 * 🌐 KU HOME API — Remote Test: Create Booking with booking_rooms return & firstName/lastName
 *
 * Test against live domain: https://ku-home.ku.ac.th/backend/api/v1
 */
$BASE_URL = getenv('KUHOME_BASE_URL') ?: 'https://ku-home.ku.ac.th/backend/api/v1';
$ADMIN_EMAIL = getenv('KUHOME_ADMIN_EMAIL') ?: 'admin@kuhome.com';
$ADMIN_PASS = getenv('KUHOME_ADMIN_PASS') ?: 'password123';

$TS = time();
$TEST_EMAIL = "test_create_{$TS}@kuhome.test";
$TEST_PASSWORD = 'password123';
$TEST_NAME = "Test User {$TS}";

function out($t)
{
    echo $t."\n";
}
function ok($t)
{
    echo "\033[32m[PASS] {$t}\033[0m\n";
}
function bad($t)
{
    echo "\033[31m[FAIL] {$t}\033[0m\n";
}
function info($t)
{
    echo "\033[36m[INFO] {$t}\033[0m\n";
}
function warn($t)
{
    echo "\033[33m[WARN] {$t}\033[0m\n";
}

$PASS = 0;
$FAIL = 0;

function check($label, $cond, $detail = '')
{
    global $PASS, $FAIL;
    if ($cond) {
        $PASS++;
        ok($label.($detail ? " — {$detail}" : ''));
    } else {
        $FAIL++;
        bad($label.($detail ? " — {$detail}" : ''));
    }
}

function request($method, $path, $data = null, $token = null)
{
    global $BASE_URL;
    $url = $BASE_URL.$path;
    $ch = curl_init($url);
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    if ($token) {
        $headers[] = "Authorization: Bearer {$token}";
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $json = $raw ? json_decode($raw, true) : null;

    return ['status' => $status, 'json' => $json, 'raw' => $raw, 'error' => $err];
}

out("\n=======================================================");
out('🌐 KU HOME API — Remote Domain Test');
out("Target: {$BASE_URL}");
out("=======================================================\n");

// 1. Get Room Types
info('Step 1: Fetching available room types...');
$res = request('GET', '/room-types');
check('GET /room-types returns 200', $res['status'] === 200);

$roomTypes = $res['json']['room_types'] ?? $res['json']['data'] ?? $res['json'] ?? [];
if (! is_array($roomTypes) || empty($roomTypes)) {
    bad('No room types found. Raw response: '.$res['raw']);
    exit(1);
}

$roomType = $roomTypes[0];
$roomTypeId = $roomType['id'];
info("Selected room type: {$roomType['name_en']} ({$roomTypeId})");

// 2. Register/Login test user
info("Step 2: Registering test user: {$TEST_EMAIL}...");
$regRes = request('POST', '/register', [
    'name' => $TEST_NAME,
    'email' => $TEST_EMAIL,
    'password' => $TEST_PASSWORD,
    'password_confirmation' => $TEST_PASSWORD,
]);

$token = null;
if ($regRes['status'] === 201 || $regRes['status'] === 200) {
    $token = $regRes['json']['token'] ?? $regRes['json']['access_token'] ?? null;
    ok('Registered successfully, got token');
} else {
    warn("Register status {$regRes['status']}, attempting login as admin instead...");
    $loginRes = request('POST', '/login', [
        'email' => $ADMIN_EMAIL,
        'password' => $ADMIN_PASS,
    ]);
    check('Admin login returns 200', $loginRes['status'] === 200);
    $token = $loginRes['json']['token'] ?? $loginRes['json']['access_token'] ?? null;
}

if (! $token) {
    bad('Failed to get auth token');
    exit(1);
}

// 3. Create Booking with firstName, lastName, email, phone
info('Step 3: Creating booking with booking_rooms payload (firstName, lastName, email, phone)...');
$checkIn = date('Y-m-d', strtotime('+30 days'));
$checkOut = date('Y-m-d', strtotime('+32 days'));

$bookingPayload = [
    'source' => 'online',
    'booking_rooms' => [
        [
            'room_type_id' => $roomTypeId,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'extra_beds' => 0,
            'guests' => [
                [
                    'title' => 'Mr.',
                    'firstName' => 'Somchai',
                    'lastName' => 'RemoteTest',
                    'email' => 'somchai.remote@ku.th',
                    'phone' => '0899998888',
                    'nationality' => 'TH',
                ],
            ],
            'billing_address' => '50 Ngamwongwan Rd, Bangkok',
            'billing_comment' => 'Tax ID: 0105559999999',
            'addons' => [
                'breakfast' => 1,
                'early_checkin' => false,
                'late_checkout' => false,
            ],
        ],
    ],
];

$createRes = request('POST', '/bookings', $bookingPayload, $token);
info("Response status: {$createRes['status']}");
info('Response body: '.json_encode($createRes['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

check('POST /bookings returns 201 Created', $createRes['status'] === 201);
check('Response contains booking_id', ! empty($createRes['json']['booking_id']));
check('Response contains confirmation', ! empty($createRes['json']['confirmation']));
check('Response contains total_amount', isset($createRes['json']['total_amount']));
check('Response contains booking_rooms array', is_array($createRes['json']['booking_rooms'] ?? null));

$createdRooms = $createRes['json']['booking_rooms'] ?? [];
check('booking_rooms count is 1', count($createdRooms) === 1);

if (! empty($createdRooms)) {
    $br = $createdRooms[0];
    check('booking_room has id', ! empty($br['id']));
    check('booking_room has room_type_id', ! empty($br['room_type_id']));
    check('booking_room has early_checkin boolean', isset($br['early_checkin']) && is_bool($br['early_checkin']));
    check('booking_room has late_checkout boolean', isset($br['late_checkout']) && is_bool($br['late_checkout']));
    check('booking_room has addon relation loaded', isset($br['addon']) && is_array($br['addon']));

    $guests = $br['guests'] ?? [];
    check('booking_room has guests array', is_array($guests) && ! empty($guests));
    if (! empty($guests)) {
        $firstGuest = $guests[0];
        check('Guest firstName is Somchai', ($firstGuest['firstName'] ?? '') === 'Somchai');
        check('Guest lastName is RemoteTest', ($firstGuest['lastName'] ?? '') === 'RemoteTest');
        check('Guest email is somchai.remote@ku.th', ($firstGuest['email'] ?? '') === 'somchai.remote@ku.th');
        check('Guest phone is 0899998888', ($firstGuest['phone'] ?? '') === '0899998888');
    }
}

$bookingId = $createRes['json']['booking_id'] ?? null;

// 4. Verify with GET /bookings/{id}
if ($bookingId) {
    info("Step 4: Fetching booking via GET /bookings/{$bookingId}...");
    $getRes = request('GET', "/bookings/{$bookingId}", null, $token);
    check('GET /bookings/{id} returns 200', $getRes['status'] === 200);
    check('Fetched booking has matching id', ($getRes['json']['booking']['id'] ?? null) === $bookingId);

    // 5. Cleanup: DELETE draft booking
    info("Step 5: Cleaning up draft booking via DELETE /bookings/{$bookingId}...");
    $delRes = request('DELETE', "/bookings/{$bookingId}", null, $token);
    check('DELETE /bookings/{id} returns 200', $delRes['status'] === 200);
}

out("\n=======================================================");
out("Summary: Passed: {$PASS} | Failed: {$FAIL}");
out("=======================================================\n");

exit($FAIL > 0 ? 1 : 0);
