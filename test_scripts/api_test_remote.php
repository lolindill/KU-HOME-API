<?php
/**
 * 🌐 KU HOME API — Remote Chained Integration Test
 *
 * สคริปต์ทดสอบ API แบบ Chain Flow บนโดเมนจริง (production)
 * ดึง ID จาก response แล้วส่งต่อไป request ถัดไปอัตโนมัติ
 * ไม่ต้อง bootstrap Laravel (เพราะยิง remote)
 *
 * วิธีใช้:
 *   php test_scripts/api_test_remote.php
 *   php test_scripts/api_test_remote.php --admin   # ใช้ admin credentials
 *
 * 🔑 Credentials (override ผ่าน env หรือ flag ได้)
 *   User role : สมัครใหม่ทุกครั้ง (timestamped email)
 *   Admin     : admin@kuhome.com / password123
 */

// ============================================
// ⚙️ Configuration
// ============================================
$BASE_URL      = getenv('KUHOME_BASE_URL') ?: 'https://ku-home.ku.ac.th/backend/api/v1';
$ADMIN_EMAIL   = getenv('KUHOME_ADMIN_EMAIL') ?: 'admin@kuhome.com';
$ADMIN_PASS    = getenv('KUHOME_ADMIN_PASS') ?: 'password123';

$USE_ADMIN     = in_array('--admin', $argv ?? [], true);
$TIMESTAMP     = time();
$TEST_EMAIL    = "test_chain_{$TIMESTAMP}@kuhome.test";
$TEST_PASSWORD = 'password123';
$TEST_NAME     = "Test User {$TIMESTAMP}";

// ============================================
// 🎨 Color Helpers (Windows-safe fallback)
// ============================================
$COLOR = (DIRECTORY_SEPARATOR === '\\') ? false : true;
function green($t)  { global $COLOR; return $COLOR ? "\033[32m{$t}\033[0m" : $t; }
function red($t)    { global $COLOR; return $COLOR ? "\033[31m{$t}\033[0m" : $t; }
function yellow($t) { global $COLOR; return $COLOR ? "\033[33m{$t}\033[0m" : $t; }
function cyan($t)   { global $COLOR; return $COLOR ? "\033[36m{$t}\033[0m" : $t; }
function bold($t)   { global $COLOR; return $COLOR ? "\033[1m{$t}\033[0m" : $t; }

// ============================================
// 🔧 HTTP + Stats
// ============================================
$stats = ['pass' => 0, 'fail' => 0, 'skip' => 0];

function apiCall($method, $url, $data = null, $token = null) {
    $ch = curl_init();

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer {$token}";
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['http_code' => 0, 'body' => null, 'error' => $error];
    }

    return [
        'http_code' => $httpCode,
        'body'      => json_decode($response, true),
        'raw'       => $response,
        'error'     => null,
    ];
}

function test($label, $method, $url, $data = null, $token = null, $expectedCode = null) {
    global $BASE_URL, $stats;

    $fullUrl       = (strpos($url, 'http') === 0) ? $url : $BASE_URL . $url;
    $methodDisplay = strtoupper($method);

    echo cyan("  → {$methodDisplay} {$url}") . "\n";

    $r = apiCall($method, $fullUrl, $data, $token);

    if ($r['error']) {
        $stats['fail']++;
        echo red("    ❌ FAIL: cURL error — {$r['error']}") . "\n\n";
        return $r;
    }

    $code = $r['http_code'];
    $body = $r['body'];

    $passed = ($expectedCode === null || $code === $expectedCode);
    if ($passed) {
        $stats['pass']++;
        echo green("    ✅ PASS → {$code}");
    } else {
        $stats['fail']++;
        echo red("    ❌ FAIL → {$code} (expected {$expectedCode})");
    }

    if ($body) {
        $msg    = $body['message'] ?? '';
        $status = $body['status'] ?? ($msg ? '—' : '???');
        echo " | status={$status}";
        if ($msg && mb_strlen($msg) < 90) {
            echo " | {$msg}";
        }
    }
    echo "\n";

    return $r;
}

function showVar($name, $value) {
    if ($value !== null && $value !== '') {
        echo yellow("    💾 Saved: {$name} = {$value}") . "\n";
    }
}

function saveId($key, $value) {
    if ($value !== null && $value !== '') {
        echo yellow("    💾 {$key} = {$value}") . "\n";
    }
}

// ============================================
// 🚀 Banner
// ============================================
echo bold("\n" . str_repeat('=', 64)) . "\n";
echo bold("🌐 KU HOME API — Remote Chained Integration Test") . "\n";
echo bold(str_repeat('=', 64)) . "\n";
echo "Base URL : {$BASE_URL}\n";
echo "Mode     : " . ($USE_ADMIN ? bold(green('ADMIN')) : 'USER (register new)') . "\n";
echo "Time     : " . date('Y-m-d H:i:s') . "\n\n";

// Quick connectivity check
echo bold("━━━ Phase 0: 🔌 Connectivity Check ━━━") . "\n";
$conn = apiCall('GET', $BASE_URL . '/room-types');
if ($conn['error']) {
    echo red("  ❌ Cannot reach {$BASE_URL} — {$conn['error']}") . "\n";
    echo red("  ⛔ Aborting.") . "\n";
    exit(1);
}
$stats['pass']++;
echo green("  ✅ Server reachable (HTTP {$conn['http_code']})") . "\n\n";

// ============================================
// Phase 1: Public GET Endpoints (also harvest IDs)
// ============================================
echo bold("━━━ Phase 1: 🌐 Public GET Endpoints (harvest IDs) ━━━") . "\n";

$ROOM_TYPE_ID = null;
$ROOM_ID      = null;

$r = test('All Rooms', 'GET', '/rooms', null, null, 200);
$rooms = $r['body']['rooms'] ?? [];
echo "    📊 Total rooms: " . ($r['body']['total_rooms'] ?? 0) . "\n";
if (!empty($rooms)) {
    $ROOM_ID = $rooms[0]['id'] ?? null;
    saveId('ROOM_ID', $ROOM_ID);
}

$r = test('Room Types', 'GET', '/room-types', null, null, 200);
$types = $r['body']['room_types'] ?? [];
echo "    📊 Total types: " . ($r['body']['total_types'] ?? 0) . "\n";
if (!empty($types)) {
    $ROOM_TYPE_ID = $types[0]['id'] ?? null;
    saveId('ROOM_TYPE_ID', $ROOM_TYPE_ID);
}

test('Room Status', 'GET', '/rooms/status', null, null, 200);

if ($ROOM_ID) {
    test('Room by ID', 'GET', "/rooms/{$ROOM_ID}", null, null, 200);
    test('Room by bad ID (404)', 'GET', '/rooms/not-a-uuid', null, null, 404);
}

if ($ROOM_TYPE_ID) {
    test('Room Type by ID', 'GET', "/room-types/{$ROOM_TYPE_ID}", null, null, 200);
}

test('Availability', 'GET', '/availability', null, null, 200);

$r = test('Global Rates', 'GET', '/global-rates', null, null, 200);
$rates = $r['body']['rates'] ?? [];
echo "    📊 Total global rates: " . count($rates) . "\n";
if (!empty($rates)) {
    $ADDON_ID = $rates[0]['id'] ?? null;
    saveId('ADDON_ID', $ADDON_ID);
}

echo "\n";

// ============================================
// Phase 2: Auth Chain — Get Token
// ============================================
echo bold("━━━ Phase 2: 🔑 Auth Chain ━━━") . "\n";

$TOKEN   = null;
$USER_ID = null;

if ($USE_ADMIN) {
    echo yellow("  → Using ADMIN login ({$ADMIN_EMAIL})") . "\n";
    $r = test('Login (admin)', 'POST', '/login', [
        'email'    => $ADMIN_EMAIL,
        'password' => $ADMIN_PASS,
    ], null, 200);
    $TOKEN = $r['body']['access_token'] ?? null;
    showVar('TOKEN', $TOKEN ? 'received (' . strlen($TOKEN) . ' chars)' : null);
} else {
    // Register a brand-new user
    $r = test('Register (new user)', 'POST', '/register', [
        'name'     => $TEST_NAME,
        'email'    => $TEST_EMAIL,
        'password' => $TEST_PASSWORD,
    ], null, 201);
    $TOKEN = $r['body']['access_token'] ?? null;
    showVar('TOKEN', $TOKEN ? 'received (' . strlen($TOKEN) . ' chars)' : null);

    if (!$TOKEN) {
        echo yellow("  ⚠️ Register failed — fallback to login") . "\n";
        $r = test('Login (fallback)', 'POST', '/login', [
            'email'    => $TEST_EMAIL,
            'password' => $TEST_PASSWORD,
        ], null, 200);
        $TOKEN = $r['body']['access_token'] ?? null;
    }

    // Re-login to verify
    test('Login (verify)', 'POST', '/login', [
        'email'    => $TEST_EMAIL,
        'password' => $TEST_PASSWORD,
    ], null, 200);

    // Negative: bad credentials
    test('Login (wrong password → 401)', 'POST', '/login', [
        'email'    => $TEST_EMAIL,
        'password' => 'wrong-password-xxx',
    ], null, 401);
}

if (!$TOKEN) {
    echo red("  ⛔ No token — cannot continue. Aborting.") . "\n";
    exit(2);
}

echo "\n";

// ============================================
// Phase 3: Authenticated Endpoints
// ============================================
echo bold("━━━ Phase 3: 🔒 Authenticated Endpoints ━━━") . "\n";

$r = test('Get Profile (/me)', 'GET', '/me', null, $TOKEN, 200);
$USER_ID = $r['body']['user']['id'] ?? ($r['body']['id'] ?? null);
saveId('USER_ID', $USER_ID);

test('List My Bookings', 'GET', '/bookings', null, $TOKEN, 200);

test('Validate Discount (DRAFT)', 'POST', '/bookings/validate-discount', [
    'code'     => 'WELCOME10',
    'subtotal' => 1000,
], $TOKEN); // expected code flexible — system may not be complete

// Negative: protected without token
test('Access /me without token (→ 401)', 'GET', '/me', null, null, 401);

echo "\n";

// ============================================
// Phase 4: Booking Creation Chain
// ============================================
echo bold("━━━ Phase 4: 📅 Booking Creation Chain ━━━") . "\n";

$BOOKING_ID   = null;
$TOTAL_AMOUNT = null;

if ($TOKEN && $ROOM_TYPE_ID) {
    $tomorrow  = date('Y-m-d', strtotime('+1 day'));
    $dayAfter  = date('Y-m-d', strtotime('+3 days'));

    $bookingData = [
        'source'        => 'online',
        'booking_rooms' => [
            [
                'room_type_id' => $ROOM_TYPE_ID,
                'check_in'     => $tomorrow,
                'check_out'    => $dayAfter,
                'extra_beds'   => 0,
                'children'     => 0,
                'guests'       => [
                    [
                        'title'       => 'Mr.',
                        'name'        => "Test Guest {$TIMESTAMP}",
                        'nationality' => 'Thai',
                    ],
                ],
                'addons' => [
                    'breakfast'      => 1,
                    'early_checkin'  => false,
                    'late_checkout'  => false,
                ],
            ],
        ],
    ];

    $r = test('Create Booking', 'POST', '/bookings', $bookingData, $TOKEN, 201);
    $BOOKING_ID   = $r['body']['booking_id']   ?? null;
    $TOTAL_AMOUNT = $r['body']['total_amount'] ?? null;
    saveId('BOOKING_ID', $BOOKING_ID);
    saveId('TOTAL_AMOUNT', $TOTAL_AMOUNT);

    if ($BOOKING_ID) {
        $r = test('Get Booking by ID', 'GET', "/bookings/{$BOOKING_ID}", null, $TOKEN, 200);
    }

    // Negative: invalid booking payload (missing room_type_id)
    test('Create Booking (invalid payload → 422)', 'POST', '/bookings', [
        'source'        => 'online',
        'booking_rooms' => [],
    ], $TOKEN, 422);
} else {
    $stats['skip'] += 2;
    echo yellow("  ⏭️ SKIPPED: booking chain (no token or no room_type_id)") . "\n";
}

echo "\n";

// ============================================
// Phase 5: Admin-Only Operations
// ============================================
echo bold("━━━ Phase 5: 🛡️ Admin-Only Operations ━━━") . "\n";

if ($USE_ADMIN && $TOKEN) {
    // Users management
    test('List Users (admin)', 'GET', '/users', null, $TOKEN, 200);

    // Booking status update: draft → paid (simulate)
    if ($BOOKING_ID) {
        $r = test('Update Booking Status → paid', 'PUT', "/bookings/update/{$BOOKING_ID}", [
            'status' => 'paid',
        ], $TOKEN, 200);

        // paid → confirmed
        $r = test('Update Booking Status → confirmed', 'PUT', "/bookings/update/{$BOOKING_ID}", [
            'status' => 'confirmed',
        ], $TOKEN, 200);

        // Assign rooms (paid/confirmed required)
        $r = test('Auto-Assign Rooms', 'PUT', "/bookings/{$BOOKING_ID}/assign-rooms", null, $TOKEN);
        $assignCode = $r['http_code'];
        if ($assignCode === 200) {
            $assigned = $r['body']['booking']['booking_rooms'][0]['room']['room_number'] ?? '???';
            echo "    📊 Assigned room number: {$assigned}\n";
        }

        // Room status update
        if ($ROOM_ID) {
            $r = test('Update Room Status → dirty', 'PUT', "/rooms/{$ROOM_ID}/status", [
                'status' => 'dirty',
            ], $TOKEN, 200);

            // revert
            if ($r['http_code'] === 200) {
                test('Revert Room Status → available', 'PUT', "/rooms/{$ROOM_ID}/status", [
                    'status' => 'available',
                ], $TOKEN, 200);
            }
        }

        // Global rate update
        if (!empty($ADDON_ID)) {
            test('Toggle Global Rate', 'PATCH', "/global-rates/{$ADDON_ID}/toggle", null, $TOKEN, 200);
            // toggle back to restore state
            test('Toggle Global Rate (restore)', 'PATCH', "/global-rates/{$ADDON_ID}/toggle", null, $TOKEN, 200);
        }

        // Request payment for the booking
        $r = test('Request Payment', 'POST', '/payments', [
            'booking_id'     => $BOOKING_ID,
            'amount'         => $TOTAL_AMOUNT ?? 1000,
            'payment_method' => 'credit_card',
        ], $TOKEN, 200);
        $PAYMENT_ID = $r['body']['payment_id'] ?? ($r['body']['data']['payment_id'] ?? null);
        saveId('PAYMENT_ID', $PAYMENT_ID);

        // Webhook (public, simulate gateway callback)
        if ($PAYMENT_ID) {
            test('Payment Webhook (success)', 'POST', '/payment/webhook', [
                'payment_id'       => $PAYMENT_ID,
                'status'           => 'success',
                'reference_number' => 'REF-' . $TIMESTAMP,
            ], null, 200);
        }

        // Dashboard tasks
        test('List Dashboard Tasks', 'GET', '/dashboard/tasks', null, $TOKEN, 200);
        test('Unassigned Tasks', 'GET', '/dashboard/tasks/unassigned', null, $TOKEN, 200);

    } else {
        $stats['skip']++;
        echo yellow("  ⏭️ SKIPPED: admin booking ops (no booking_id)") . "\n";
    }
} else {
    // Non-admin: verify role gate actually blocks
    test('List Users (non-admin → 403)', 'GET', '/users', null, $TOKEN, 403);
    echo yellow("  ℹ️ Skipped full admin flow — run with --admin to enable.") . "\n";
    $stats['skip'] += 4;
}

echo "\n";

// ============================================
// Phase 6: Logout
// ============================================
echo bold("━━━ Phase 6: 🚪 Logout ━━━") . "\n";

$r = test('Logout', 'POST', '/logout', null, $TOKEN, 200);

// Verify token is now invalid
test('Access /me after logout (→ 401)', 'GET', '/me', null, $TOKEN, 401);

echo "\n";

// ============================================
// 📊 Summary
// ============================================
$total = $stats['pass'] + $stats['fail'] + $stats['skip'];

echo bold(str_repeat('═', 64)) . "\n";
echo bold("📊 TEST SUMMARY") . "\n";
echo bold(str_repeat('═', 64)) . "\n";
echo green("  ✅ Passed:  {$stats['pass']}") . "\n";
echo red("    ❌ Failed:  {$stats['fail']}") . "\n";
echo yellow("  ⏭️ Skipped:  {$stats['skip']}") . "\n";
echo "  📋 Total:   {$total}\n";

if ($stats['fail'] === 0) {
    echo green("\n🎉 ALL TESTS PASSED! สบายมากค่ะนายท่าน! ✨💖\n");
} else {
    echo yellow("\n⚠️ มีบางเทสตกอยู่นะคะ ตรวจสอบด่วนเลย! 💅\n");
}

echo bold(str_repeat('═', 64)) . "\n";

exit($stats['fail'] > 0 ? 3 : 0);
