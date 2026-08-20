<?php

/**
 * 🌐 KU HOME API — Remote Test: Draft Booking Ops (commit ae478a1, 17/08/26)
 *
 * เทสต์ 3 endpoint ใหม่บน domain จริง:
 *   1. PUT    /bookings/{bookingId}/rooms/{bookingRoomId}  — แก้ไข BR draft (reprice + availability re-check)
 *   2. DELETE /bookings/{bookingId}/rooms/{bookingRoomId}  — ลบ BR draft (ห้องสุดท้ายห้ามลบ)
 *   3. DELETE /bookings/{bookingId}                       — ลบ draft booking (hard delete cascade)
 *   4. PUT    /bookings/{bookingId}/rooms                 — แก้ไข BR หลายห้องพร้อมกัน (batch, 19/08/26)
 *
 * รวม error paths: 401 / 403 / 404 (ไม่พบ + BR ข้าม booking) / 422 (validation + ห้องสุดท้าย)
 * + admin override (admin ลบของคนอื่นได้)
 *
 * ⚠️ Rate limit: Laravel 13 ใช้ throttle key = user id (L11+ behavior) → ทุก route
 *    ที่มี throttle:5,1 แชร์ bucket เดียวกันต่อ user หนูเลย pace 13s ระหว่าง request
 *    ที่ต้อง auth (≤4.6 ครั้ง/นาที) เพื่อไม่ให้โดน 429 เอง
 *
 * วิธีใช้: php test_scripts/test_draft_ops_remote.php
 * ไม่ต้อง bootstrap Laravel (ยิง remote ตรงๆ เหมือน api_test_remote.php)
 */

// ============================================
// ⚙️ Configuration
// ============================================
$BASE_URL = getenv('KUHOME_BASE_URL') ?: 'https://ku-home.ku.ac.th/backend/api/v1';
$ADMIN_EMAIL = getenv('KUHOME_ADMIN_EMAIL') ?: 'admin@kuhome.com';
$ADMIN_PASS = getenv('KUHOME_ADMIN_PASS') ?: 'password123';

$TS = time();
$TEST_EMAIL_1 = "test_draftops_{$TS}@kuhome.test";
$TEST_EMAIL_2 = "test_draftops_b_{$TS}@kuhome.test";
$TEST_PASSWORD = 'password123';

// ============================================
// 🎨 Helpers
// ============================================
function out($t)
{
    echo $t."\n";
}
function ok($t)
{
    echo "\033[32m✅ {$t}\033[0m\n";
}
function bad($t)
{
    echo "\033[31m❌ {$t}\033[0m\n";
}
function info($t)
{
    echo "\033[36mℹ️  {$t}\033[0m\n";
}
function warn($t)
{
    echo "\033[33m⚠️  {$t}\033[0m\n";
}

$PASS = 0;
$FAIL = 0;
$SKIP = 0;
function check($label, $cond, $detail = '')
{
    global $PASS, $FAIL;
    if ($cond) {
        $PASS++;
        ok("{$label}".($detail ? " — {$detail}" : ''));
    } else {
        $FAIL++;
        bad("{$label}".($detail ? " — {$detail}" : ''));
    }
}
function skipCheck($label, $reason)
{
    global $SKIP;
    $SKIP++;
    warn("⏭️  {$label} — ข้าม: {$reason}");
}

// ⏳ rate-limit pacing: 13s ระหว่าง request ใน bucket เดียวกัน (user token / ip)
$lastHit = [];
function pace($bucket)
{
    global $lastHit;
    $now = microtime(true);
    if (isset($lastHit[$bucket]) && ($now - $lastHit[$bucket]) < 13) {
        $sleep = 13 - ($now - $lastHit[$bucket]);
        printf("   ⏳ pace %.1fs (bucket %s)\n", $sleep, substr($bucket, 0, 8));
        usleep((int) ($sleep * 1e6));
    }
    $lastHit[$bucket] = microtime(true);
}

function apiCall($method, $url, $data = null, $token = null)
{
    pace($token ?: 'ip'); // bucket = user token หรือ ip

    $ch = curl_init();
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer {$token}";
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
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

    return ['http_code' => $httpCode, 'body' => json_decode($response, true), 'error' => null];
}

$createdBookings = []; // สำหรับ cleanup ท้ายสุด
function trackBooking($id)
{
    global $createdBookings;
    if ($id) {
        $createdBookings[] = $id;
    }
}

$createdUsers = [];
function trackUser($id)
{
    global $createdUsers;
    if ($id) {
        $createdUsers[] = $id;
    }
}

out('══════════════════════════════════════════════════════════');
out('🌐 KU HOME API — Remote Test: Draft Booking Ops');
out("   Target: {$BASE_URL}");
out("══════════════════════════════════════════════════════════\n");

// ============================================
// 0) Register 2 test users + admin login
// ============================================
$r = apiCall('POST', $BASE_URL.'/register', [
    'name' => "Draft Ops Test A {$TS}", 'email' => $TEST_EMAIL_1, 'password' => $TEST_PASSWORD,
]);
$TOKEN1 = $r['body']['access_token'] ?? null;
if ($TOKEN1) {
    $rMe = apiCall('GET', $BASE_URL.'/me', null, $TOKEN1);
    $USER1_ID = $rMe['body']['user']['id'] ?? ($rMe['body']['id'] ?? null);
    trackUser($USER1_ID);
}
check('Register user1 → token', $r['http_code'] === 201 && $TOKEN1 !== null, "HTTP {$r['http_code']}");

$r = apiCall('POST', $BASE_URL.'/register', [
    'name' => "Draft Ops Test B {$TS}", 'email' => $TEST_EMAIL_2, 'password' => $TEST_PASSWORD,
]);
$TOKEN2 = $r['body']['access_token'] ?? null;
if ($TOKEN2) {
    $rMe = apiCall('GET', $BASE_URL.'/me', null, $TOKEN2);
    $USER2_ID = $rMe['body']['user']['id'] ?? ($rMe['body']['id'] ?? null);
    trackUser($USER2_ID);
}
check('Register user2 → token', $r['http_code'] === 201 && $TOKEN2 !== null, "HTTP {$r['http_code']}");

$r = apiCall('POST', $BASE_URL.'/login', ['email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASS]);
$ADMIN_TOKEN = $r['body']['access_token'] ?? null;
if ($ADMIN_TOKEN) {
    ok('Admin login');
} else {
    warn('Admin login ล้มเหลว (HTTP '.$r['http_code'].') — จะข้ามเทสต์ admin override');
}

// ============================================
// 1) Fixtures: room types (≥2 ห้อง) + rates
// ============================================
$r = apiCall('GET', $BASE_URL.'/room-types');
$roomTypes = $r['body']['room_types'] ?? $r['body']['data'] ?? $r['body'];
if (! is_array($roomTypes) || ! $roomTypes) {
    $roomTypes = [];
    foreach (($r['body'] ?? []) as $k => $v) {
        if (is_array($v) && isset($v[0]['id'])) {
            $roomTypes = $v;
            break;
        }
    }
}
check('GET /room-types', $r['http_code'] === 200 && count($roomTypes) > 0, 'HTTP '.$r['http_code'].', '.count($roomTypes).' types');

$r = apiCall('GET', $BASE_URL.'/rooms');
$rooms = $r['body']['rooms'] ?? $r['body']['data'] ?? $r['body'];
if (! is_array($rooms) || ! $rooms) {
    $rooms = [];
    foreach (($r['body'] ?? []) as $k => $v) {
        if (is_array($v) && isset($v[0]['id'])) {
            $rooms = $v;
            break;
        }
    }
}
check('GET /rooms', $r['http_code'] === 200 && count($rooms) > 0, 'HTTP '.$r['http_code'].', '.count($rooms).' rooms');

$countByType = [];
foreach ($rooms as $room) {
    $rt = $room['room_type_id'] ?? null;
    if ($rt) {
        $countByType[$rt] = ($countByType[$rt] ?? 0) + 1;
    }
}
$candidates = [];
foreach ($roomTypes as $rt) {
    $id = $rt['id'] ?? null;
    if ($id && ($countByType[$id] ?? 0) >= 2) {
        $candidates[$id] = $countByType[$id];
    }
}
arsort($candidates);
if (! $candidates) {
    bad('ไม่พบ room type ที่มี ≥2 ห้อง — เทสต์ต่อไม่ได้');
    exit(1);
}
$ROOM_TYPE_ID = array_key_first($candidates);
info('ใช้ room type: '.$ROOM_TYPE_ID.' ('.$candidates[$ROOM_TYPE_ID].' ห้อง)');

// rates — call เดียว แล้ว filter ในฝั่ง client (ลดจำนวน request)
$r = apiCall('GET', $BASE_URL.'/global-rates');
$rates = $r['body']['rates'] ?? [];
$roomRate = 0;
$extraBedRate = 0;
$breakfastRate = 0;
foreach ($rates as $g) {
    $rt = $g['room_type_id'] ?? null;
    $code = $g['code'] ?? '';
    $rateType = $g['rate_type'] ?? '';
    $price = (int) ($g['default_price'] ?? 0);
    if ($rateType === 'daily' && $rt === $ROOM_TYPE_ID) {
        $roomRate = $price;
    }
    if ($rateType === 'addon' && $code === 'extra_bed') {
        $extraBedRate = $price;
    }
    if ($rateType === 'addon' && $code === 'breakfast') {
        $breakfastRate = $price;
    }
}
check('มี daily rate สำหรับ room type', $roomRate > 0, $roomRate.' satang/คืน');
info("extra_bed={$extraBedRate}/คืน · breakfast={$breakfastRate}/ท่าน");

// ============================================
// 2) สร้าง booking 2 ห้อง (draft) — retry type ถ้าเต็ม
// ============================================
$D = date('Y-m-d', strtotime('+2 days'));
$D2 = date('Y-m-d', strtotime('+4 days'));
$BOOKING_ID = null;
$r = null;
foreach (array_keys($candidates) as $tryType) {
    $r = apiCall('POST', $BASE_URL.'/bookings', [
        'source' => 'online',
        'booking_rooms' => [
            ['room_type_id' => $tryType, 'check_in' => $D, 'check_out' => $D2, 'extra_beds' => 0],
            ['room_type_id' => $tryType, 'check_in' => $D, 'check_out' => $D2, 'extra_beds' => 0],
        ],
    ], $TOKEN1);
    if ($r['http_code'] === 201) {
        $BOOKING_ID = $r['body']['booking_id'] ?? null;
        break;
    }
    info("Type {$tryType} เต็ม (HTTP {$r['http_code']}) — ลอง type ถัดไป");
}
trackBooking($BOOKING_ID);
$expectedTotal = 2 * $roomRate * 2; // 2 ห้อง × rate × 2 คืน
check('POST /bookings (2 ห้อง draft) → 201', $r['http_code'] === 201 && $BOOKING_ID, 'HTTP '.$r['http_code']);
if (! $BOOKING_ID) {
    bad('สร้าง booking ไม่ได้ — เทสต์ต่อไม่ได้');
    exit(1);
}
check('total_amount ตรงกับสูตร (2×rate×2)', ($r['body']['total_amount'] ?? null) === $expectedTotal,
    "ได้ {$r['body']['total_amount']} คาด {$expectedTotal}");

// ============================================
// 3) GET booking → BR ids
// ============================================
$r = apiCall('GET', $BASE_URL.'/bookings/'.$BOOKING_ID, null, $TOKEN1);
$booking = $r['body']['booking'] ?? [];
$brs = $booking['booking_rooms'] ?? [];
check('GET /bookings/{id} → มี 2 BR', $r['http_code'] === 200 && count($brs) === 2, 'HTTP '.$r['http_code']);
$brA = $brs[0]['id'] ?? null;
$brB = $brs[1]['id'] ?? null;
info("brA={$brA} · brB={$brB}");

// ============================================
// 3.5) BATCH: PUT /bookings/{id}/rooms — แก้หลายห้องพร้อมกัน (19/08/26)
//      จบ section นี้แล้ว restore ทั้งสองห้องเป็นสถานะเดิม (2 คืน ไม่มี addon)
//      เพื่อให้ section 4 ข้างล่างคำนวณ expected เดิมได้
// ============================================
if ($brA && $brB) {
    $D3 = date('Y-m-d', strtotime('+5 days'));

    // 3.5a) batch จริง: brA ยืด 3 คืน + extra_bed + breakfast · brB guests + breakfast
    $r = apiCall('PUT', $BASE_URL."/bookings/{$BOOKING_ID}/rooms", [
        'booking_rooms' => [
            [
                'booking_room_id' => $brA,
                'check_in' => $D, 'check_out' => $D3,
                'extra_beds' => 1,
                'addons' => ['breakfast' => 1],
            ],
            [
                'booking_room_id' => $brB,
                'guests' => [['title' => 'Mr.', 'name' => 'Batch Guest', 'nationality' => 'Thai', 'is_ku_member' => false]],
                'addons' => ['breakfast' => 1],
            ],
        ],
    ], $TOKEN1);
    $expBatchA = ($roomRate * 3) + ($extraBedRate * 1 * 3) + $breakfastRate;
    $expBatchB = ($roomRate * 2) + $breakfastRate;
    $expBatchTotal = $expBatchA + $expBatchB;
    check('BATCH PUT (brA 3 คืน+bed, brB guests+breakfast) → 200', $r['http_code'] === 200, 'HTTP '.$r['http_code']);
    check('BATCH total_amount ถูกต้อง', ($r['body']['total_amount'] ?? null) === $expBatchTotal,
        'ได้ '.($r['body']['total_amount'] ?? '?')." คาด {$expBatchTotal} (A={$expBatchA} + B={$expBatchB})");
    check('BATCH response มี booking_rooms 2 อัน', count($r['body']['booking_rooms'] ?? []) === 2);

    // 3.5b) id ซ้ำใน batch → 422
    $r = apiCall('PUT', $BASE_URL."/bookings/{$BOOKING_ID}/rooms", [
        'booking_rooms' => [
            ['booking_room_id' => $brA, 'billing_comment' => 'A'],
            ['booking_room_id' => $brA, 'billing_comment' => 'A dup'],
        ],
    ], $TOKEN1);
    check('BATCH id ซ้ำใน batch → 422', $r['http_code'] === 422, 'HTTP '.$r['http_code']);

    // 3.5c) มี id ที่ไม่ได้อยู่ใต้ booking นี้ → 404 ทั้ง batch
    $r = apiCall('PUT', $BASE_URL."/bookings/{$BOOKING_ID}/rooms", [
        'booking_rooms' => [
            ['booking_room_id' => $brA, 'billing_comment' => 'Should not apply'],
            ['booking_room_id' => '00000000-0000-4000-8000-000000000000'],
        ],
    ], $TOKEN1);
    check('BATCH มี id แปลกปลอม → 404', $r['http_code'] === 404, 'HTTP '.$r['http_code']);

    // 3.5d) ไม่มี token → 401
    $r = apiCall('PUT', $BASE_URL."/bookings/{$BOOKING_ID}/rooms", [
        'booking_rooms' => [['booking_room_id' => $brA]],
    ], null);
    check('BATCH PUT ไม่มี token → 401', $r['http_code'] === 401, 'HTTP '.$r['http_code']);

    // 3.5e) restore ทั้งสองห้องเป็นสถานะเดิม (2 คืน ไม่มี addon) — ให้ section 4 ทำงานเหมือนเดิม
    $r = apiCall('PUT', $BASE_URL."/bookings/{$BOOKING_ID}/rooms", [
        'booking_rooms' => [
            ['booking_room_id' => $brA, 'check_in' => $D, 'check_out' => $D2, 'extra_beds' => 0, 'addons' => ['breakfast' => 0]],
            ['booking_room_id' => $brB, 'addons' => ['breakfast' => 0]],
        ],
    ], $TOKEN1);
    $restoredTotal = 2 * $roomRate * 2;
    check('BATCH restore สถานะเดิม → 200 + total = 2×rate×2', $r['http_code'] === 200 && ($r['body']['total_amount'] ?? null) === $restoredTotal,
        'HTTP '.$r['http_code'].', total '.($r['body']['total_amount'] ?? '?'));
}

// ============================================
// 4) PUT brA: ขยายเป็น 3 คืน + extra_bed 1 + breakfast 1 → reprice
// ============================================
$D3 = date('Y-m-d', strtotime('+5 days'));
if (! $brA) {
    skipCheck('PUT brA (reprice)', 'brA null');
} else {
    $r = apiCall('PUT', $BASE_URL."/bookings/{$BOOKING_ID}/rooms/{$brA}", [
        'check_in' => $D, 'check_out' => $D3,
        'extra_beds' => 1,
        'addons' => ['breakfast' => 1],
    ], $TOKEN1);
    $expA = ($roomRate * 3) + ($extraBedRate * 1 * 3) + $breakfastRate; // ห้อง A หลังแก้
    $expB = $roomRate * 2;                                              // ห้อง B เดิม
    $expTotal = $expA + $expB;
    check('PUT brA (3 คืน + extra bed + breakfast) → 200', $r['http_code'] === 200, 'HTTP '.$r['http_code']);
    check('total_amount reprice ถูกต้อง', ($r['body']['total_amount'] ?? null) === $expTotal,
        "ได้ {$r['body']['total_amount']} คาด {$expTotal} (A={$expA} + B={$expB})");
    check('BR response มี extra_bed=1', ($r['body']['booking_room']['addon']['extra_bed'] ?? null) === 1);

    // 5) PUT brA: แก้เฉพาะ guests → total ไม่เปลี่ยน
    $r = apiCall('PUT', $BASE_URL."/bookings/{$BOOKING_ID}/rooms/{$brA}", [
        'guests' => [['title' => 'Mr.', 'name' => 'Somchai Updated', 'nationality' => 'Thai', 'is_ku_member' => false]],
    ], $TOKEN1);
    check('PUT brA (guests อย่างเดียว) → 200 + total คงเดิม', $r['http_code'] === 200 && ($r['body']['total_amount'] ?? null) === $expTotal,
        'HTTP '.$r['http_code'].', total '.($r['body']['total_amount'] ?? '?'));

    // 6) PUT invalid: check_out < check_in → 422
    $r = apiCall('PUT', $BASE_URL."/bookings/{$BOOKING_ID}/rooms/{$brA}", [
        'check_in' => $D2, 'check_out' => $D, // กลับข้าง
    ], $TOKEN1);
    check('PUT brA (check_out < check_in) → 422', $r['http_code'] === 422, 'HTTP '.$r['http_code']);
}

// ============================================
// 7) DELETE brB (ไม่ใช่ห้องสุดท้าย) → เหลือ 1 ห้อง, total = ห้อง A
// ============================================
if (! $brB) {
    skipCheck('DELETE brB', 'brB null');
} else {
    $r = apiCall('DELETE', $BASE_URL."/bookings/{$BOOKING_ID}/rooms/{$brB}", null, $TOKEN1);
    check('DELETE brB (ไม่ใช่ห้องสุดท้าย) → 200', $r['http_code'] === 200, 'HTTP '.$r['http_code']);
    check('remaining_rooms = 1', ($r['body']['remaining_rooms'] ?? null) === 1);
    $expA2 = ($roomRate * 3) + ($extraBedRate * 1 * 3) + $breakfastRate;
    check('total_amount = ราคาห้อง A', ($r['body']['total_amount'] ?? null) === $expA2,
        "ได้ {$r['body']['total_amount']} คาด {$expA2}");
}

// ============================================
// 8) DELETE brA (ห้องสุดท้ายของ booking) → 422
// ============================================
if (! $brA) {
    skipCheck('DELETE ห้องสุดท้าย', 'brA null');
} else {
    $r = apiCall('DELETE', $BASE_URL."/bookings/{$BOOKING_ID}/rooms/{$brA}", null, $TOKEN1);
    check('DELETE ห้องสุดท้าย → 422', $r['http_code'] === 422, 'HTTP '.$r['http_code']);
}

// ============================================
// 9) DELETE /bookings/{id} → 200, แล้ว GET → 404
// ============================================
$r = apiCall('DELETE', $BASE_URL.'/bookings/'.$BOOKING_ID, null, $TOKEN1);
check('DELETE /bookings/{id} (draft) → 200', $r['http_code'] === 200, 'HTTP '.$r['http_code']);
$r = apiCall('GET', $BASE_URL.'/bookings/'.$BOOKING_ID, null, $TOKEN1);
check('GET booking ที่ลบไปแล้ว → 404', $r['http_code'] === 404, 'HTTP '.$r['http_code']);
$r = apiCall('DELETE', $BASE_URL.'/bookings/'.'00000000-0000-4000-8000-000000000000', null, $TOKEN1);
check('DELETE booking id ปลอม → 404', $r['http_code'] === 404, 'HTTP '.$r['http_code']);

// ============================================
// 10) Ownership: 403 / cross-booking 404 / admin override
// ============================================
// user1 สร้าง booking2 (1 ห้อง)
$r = apiCall('POST', $BASE_URL.'/bookings', [
    'source' => 'online',
    'booking_rooms' => [
        ['room_type_id' => $ROOM_TYPE_ID, 'check_in' => $D, 'check_out' => $D2, 'extra_beds' => 0],
    ],
], $TOKEN1);
$booking2 = $r['body']['booking_id'] ?? null;
trackBooking($booking2);
check('user1 สร้าง booking2 → 201', $r['http_code'] === 201 && $booking2, 'HTTP '.$r['http_code']);

// user2 สร้าง booking3 (1 ห้อง) → br3
$r = apiCall('POST', $BASE_URL.'/bookings', [
    'source' => 'online',
    'booking_rooms' => [
        ['room_type_id' => $ROOM_TYPE_ID, 'check_in' => $D, 'check_out' => $D2, 'extra_beds' => 0],
    ],
], $TOKEN2);
$booking3 = $r['body']['booking_id'] ?? null;
trackBooking($booking3);
check('user2 สร้าง booking3 → 201', $r['http_code'] === 201 && $booking3, 'HTTP '.$r['http_code']);
$r = apiCall('GET', $BASE_URL.'/bookings/'.$booking3, null, $TOKEN2);
$br3 = $r['body']['booking']['booking_rooms'][0]['id'] ?? null;
info("booking2={$booking2} · booking3={$booking3} · br3={$br3}");

if ($booking2 && $booking3 && $br3) {
    // cross-booking: user1 เอา br3 (ของ booking3) ไป PUT/DELETE ใต้ booking2 → 404
    $r = apiCall('PUT', $BASE_URL."/bookings/{$booking2}/rooms/{$br3}", ['check_in' => $D, 'check_out' => $D2], $TOKEN1);
    check('PUT BR ของ booking อื่นใต้ booking นี้ → 404', $r['http_code'] === 404, 'HTTP '.$r['http_code']);
    $r = apiCall('DELETE', $BASE_URL."/bookings/{$booking2}/rooms/{$br3}", null, $TOKEN1);
    check('DELETE BR ของ booking อื่นใต้ booking นี้ → 404', $r['http_code'] === 404, 'HTTP '.$r['http_code']);

    // user2 พยายามแตะ booking2 ของ user1 → 403
    $r = apiCall('DELETE', $BASE_URL.'/bookings/'.$booking2, null, $TOKEN2);
    check('user2 DELETE booking ของ user1 → 403', $r['http_code'] === 403, 'HTTP '.$r['http_code']);
    $r = apiCall('PUT', $BASE_URL."/bookings/{$booking2}/rooms/{$br3}", ['guests' => []], $TOKEN2);
    check('user2 PUT BR ของ booking1 → 403', $r['http_code'] === 403, 'HTTP '.$r['http_code']);

    // admin override: admin ลบ booking2 ของ user1 ได้ → 200
    if ($ADMIN_TOKEN) {
        $r = apiCall('DELETE', $BASE_URL.'/bookings/'.$booking2, null, $ADMIN_TOKEN);
        check('admin ลบ booking ของคนอื่น → 200', $r['http_code'] === 200, 'HTTP '.$r['http_code']);
    } else {
        warn('ข้าม admin override (ล็อกอิน admin ไม่ได้)');
    }
} else {
    skipCheck('กลุ่มเทสต์ ownership (403/404/admin)', 'สร้าง booking2/booking3 ไม่สำเร็จ');
}

// เจ้าของ (user2) ลบ booking3 เอง → 200
if ($booking3) {
    $r = apiCall('DELETE', $BASE_URL.'/bookings/'.$booking3, null, $TOKEN2);
    check('เจ้าของ (user2) ลบ booking ตัวเอง → 200', $r['http_code'] === 200, 'HTTP '.$r['http_code']);
    $r = apiCall('DELETE', $BASE_URL.'/bookings/'.$booking3, null, $TOKEN2);
    check('DELETE booking ที่ลบไปแล้ว → 404', $r['http_code'] === 404, 'HTTP '.$r['http_code']);
}

// ============================================
// 11) Unauthenticated → 401 (ทั้ง 3 endpoints)
// ============================================
$r = apiCall('POST', $BASE_URL.'/bookings', [
    'source' => 'online',
    'booking_rooms' => [
        ['room_type_id' => $ROOM_TYPE_ID, 'check_in' => $D, 'check_out' => $D2, 'extra_beds' => 0],
    ],
], $TOKEN1);
$booking4 = $r['body']['booking_id'] ?? null;
trackBooking($booking4);
check('สร้าง booking4 (สำหรับเทสต์ 401) → 201', $r['http_code'] === 201 && $booking4, 'HTTP '.$r['http_code']);
if ($booking4) {
    $r = apiCall('GET', $BASE_URL.'/bookings/'.$booking4, null, $TOKEN1);
    $br4 = $r['body']['booking']['booking_rooms'][0]['id'] ?? null;

    $r = apiCall('DELETE', $BASE_URL.'/bookings/'.$booking4, null, null);
    check('DELETE booking ไม่มี token → 401', $r['http_code'] === 401, 'HTTP '.$r['http_code']);
    $r = apiCall('PUT', $BASE_URL."/bookings/{$booking4}/rooms/{$br4}", ['check_in' => $D, 'check_out' => $D2], null);
    check('PUT BR ไม่มี token → 401', $r['http_code'] === 401, 'HTTP '.$r['http_code']);
    $r = apiCall('DELETE', $BASE_URL."/bookings/{$booking4}/rooms/{$br4}", null, null);
    check('DELETE BR ไม่มี token → 401', $r['http_code'] === 401, 'HTTP '.$r['http_code']);

    // cleanup booking4
    $r = apiCall('DELETE', $BASE_URL.'/bookings/'.$booking4, null, $TOKEN1);
    check('cleanup booking4 → 200', $r['http_code'] === 200, 'HTTP '.$r['http_code']);
}

// ============================================
// Cleanup สุดท้าย — ลบทุก booking และ user ที่ยังค้าง (กัน residue บน prod)
// ============================================
out("\n── 🧹 Cleanup ──────────────────────────────────────────");
$leftover = [];
foreach (array_unique($createdBookings) as $bid) {
    $r = apiCall('DELETE', $BASE_URL.'/bookings/'.$bid, null, $ADMIN_TOKEN ?: $TOKEN1);
    if ($r['http_code'] !== 200 && $r['http_code'] !== 404) {
        $leftover[] = $bid;
        warn("ลบ {$bid} ไม่สำเร็จ (HTTP {$r['http_code']}) — ต้องลบมือ");
    }
}

if ($ADMIN_TOKEN) {
    foreach (array_unique($createdUsers) as $uid) {
        $r = apiCall('DELETE', $BASE_URL.'/users/'.$uid, null, $ADMIN_TOKEN);
        if ($r['http_code'] !== 200 && $r['http_code'] !== 404) {
            $leftover[] = $uid;
            warn("ลบ user {$uid} ไม่สำเร็จ (HTTP {$r['http_code']}) — ต้องลบมือ");
        }
    }
}

if (! $leftover) {
    ok('ไม่มี residue ค้างบน prod (ลบทั้ง bookings และ test users ครบถ้วน)');
}
out('');

// ============================================
// Summary
// ============================================
out('══════════════════════════════════════════════════════════');
out("📊 ผลรวม: ✅ PASS {$PASS} · ❌ FAIL {$FAIL} · ⏭️  SKIP {$SKIP}");
out('══════════════════════════════════════════════════════════');
exit($FAIL > 0 ? 1 : 0);
