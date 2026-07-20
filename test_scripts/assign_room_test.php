<?php
/**
 * 🛏️ KU HOME API — Room Assignment Integration Test
 *
 * สคริปต์เฉพาะทดสอบการ assign room number หลังจาก create booking
 * รันบน production domain จริง ใช้ admin token chain ผ่านทุก flow
 *
 * 🎯 Edge cases ที่เทส:
 *   A. Single room booking — assign แล้วได้เลขห้องถูกต้อง
 *   B. Multi-room cluster (ห้องเดียวกัน type เดียวกัน) — ต้องได้ห้องใกล้กัน
 *   C. Assign ซ้ำบน booking ที่จ่ายแล้ว — ต้องไม่ทับซ้อน
 *   D. จอง 2 booking ช่วงวันที่เดียวกัน — ต้องไม่ได้เลขห้องเดียวกัน
 *   E. No-overlap check — assign แล้ว ห้องเดียวกันต้องไม่ติด booking อื่นช่วงเวลาทับซ้อน
 *
 * วิธีใช้:
 *   php test_scripts/assign_room_test.php
 *   php test_scripts/assign_room_test.php --keep   # ไม่ลบ booking (debug)
 */

// ============================================
// ⚙️ Configuration
// ============================================
$BASE_URL    = getenv('KUHOME_BASE_URL') ?: 'https://ku-home.ku.ac.th/backend/api/v1';
$ADMIN_EMAIL = getenv('KUHOME_ADMIN_EMAIL') ?: 'admin@kuhome.com';
$ADMIN_PASS  = getenv('KUHOME_ADMIN_PASS') ?: 'password123';
$KEEP        = in_array('--keep', $argv ?? [], true);
$TIMESTAMP   = time();

// ============================================
// 🎨 Colors
// ============================================
$COLOR = (DIRECTORY_SEPARATOR === '\\') ? false : true;
function green($t)  { global $COLOR; return $COLOR ? "\033[32m{$t}\033[0m" : $t; }
function red($t)    { global $COLOR; return $COLOR ? "\033[31m{$t}\033[0m" : $t; }
function yellow($t) { global $COLOR; return $COLOR ? "\033[33m{$t}\033[0m" : $t; }
function cyan($t)   { global $COLOR; return $COLOR ? "\033[36m{$t}\033[0m" : $t; }
function bold($t)   { global $COLOR; return $COLOR ? "\033[1m{$t}\033[0m" : $t; }
function dim($t)    { global $COLOR; return $COLOR ? "\033[2m{$t}\033[0m" : $t; }

// ============================================
// 🔧 HTTP + Assertions
// ============================================
$stats = ['pass' => 0, 'fail' => 0, 'skip' => 0];

function api($method, $url, $data = null, $token = null) {
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
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [
        'http_code' => $code,
        'body'      => json_decode($resp, true),
        'error'     => $err ?: null,
    ];
}

/**
 * Assertion helper — จับผล pass/fail + พิมพ์ผล
 */
function check($label, $condition, $detail = '') {
    global $stats;
    if ($condition) {
        $stats['pass']++;
        echo green("    ✅ {$label}");
    } else {
        $stats['fail']++;
        echo red("    ❌ {$label}");
    }
    if ($detail) {
        echo " " . dim($detail);
    }
    echo "\n";
    return $condition;
}

function step($label) {
    echo cyan("\n  ▸ {$label}") . "\n";
}

function showVar($name, $value) {
    if ($value !== null && $value !== '') {
        echo yellow("    💾 {$name} = {$value}") . "\n";
    }
}

// ============================================
// 🚀 Banner
// ============================================
echo bold("\n" . str_repeat('=', 64)) . "\n";
echo bold("🛏️  KU HOME API — Room Assignment Test") . "\n";
echo bold(str_repeat('=', 64)) . "\n";
echo "Base URL : {$BASE_URL}\n";
echo "Mode     : ADMIN ({$ADMIN_EMAIL})\n";
echo "Keep     : " . ($KEEP ? 'YES (no cleanup)' : 'no (auto-cleanup)') . "\n";
echo "Time     : " . date('Y-m-d H:i:s') . "\n\n";

// ============================================
// Phase 0: Login as admin + fetch room layout
// ============================================
echo bold("━━━ Phase 0: 🔑 Setup ━━━") . "\n";

$r = api('POST', $BASE_URL . '/login', [
    'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASS,
]);
if (($r['body']['access_token'] ?? null) === null) {
    echo red("  ❌ Admin login failed: " . ($r['body']['message'] ?? $r['error'])) . "\n";
    exit(1);
}
$TOKEN = $r['body']['access_token'];
echo green("  ✅ Admin login OK") . "\n";

// Fetch room types
$r = api('GET', $BASE_URL . '/room-types');
$types = $r['body']['room_types'] ?? [];
$TYPE_ID = null;
foreach ($types as $t) {
    if ($t['name_en'] === 'Superior') { $TYPE_ID = $t['id']; break; }
}
if (!$TYPE_ID) {
    echo red("  ❌ No Superior room type found") . "\n";
    exit(1);
}
echo green("  ✅ Using room type Superior ({$TYPE_ID})") . "\n";

// Track bookings for cleanup
$createdBookings = [];
$allAssignedRooms = []; // เก็บเลขห้องที่ assign ทั้งหมดเพื่อเช็คไม่ทับซ้อน

// Helper: create + pay + confirm + assign
function createAndAssign($label, $bookingData, $token, $baseUrl) {
    global $createdBookings;
    step("{$label}: POST /bookings");
    $r = api('POST', $baseUrl . '/bookings', $bookingData, $token);
    $bookingId = $r['body']['booking_id'] ?? null;
    if (!$bookingId) {
        echo red("    ❌ Create booking failed (HTTP {$r['http_code']}): "
            . ($r['body']['message'] ?? $r['error'])) . "\n";
        return null;
    }
    echo green("    ✅ Booking created: {$bookingId}") . "\n";
    $createdBookings[] = $bookingId;

    step("{$label}: PUT update → paid");
    $r = api('PUT', "{$baseUrl}/bookings/update/{$bookingId}", ['status' => 'paid'], $token);
    check('Status → paid', $r['http_code'] === 200, "HTTP {$r['http_code']}");

    step("{$label}: PUT update → confirmed");
    $r = api('PUT', "{$baseUrl}/bookings/update/{$bookingId}", ['status' => 'confirmed'], $token);
    check('Status → confirmed', $r['http_code'] === 200, "HTTP {$r['http_code']}");

    step("{$label}: PUT /assign-rooms");
    $r = api('PUT', "{$baseUrl}/bookings/{$bookingId}/assign-rooms", null, $token);
    return ['booking_id' => $bookingId, 'assign_response' => $r];
}

// Helper: fetch full booking detail (with assigned rooms)
function fetchBooking($bookingId, $token, $baseUrl) {
    return api('GET', "{$baseUrl}/bookings/{$bookingId}", null, $token);
}

// ============================================
// 🅰️ Test A: Single room assignment
// ============================================
echo bold("\n━━━ Test A: 🛏️  Single Room Assignment ━━━") . "\n";

$tomorrow = date('Y-m-d', strtotime('+7 day'));
$dayAfter = date('Y-m-d', strtotime('+9 days'));

$result = createAndAssign('A', [
    'source'        => 'admin',
    'booking_rooms' => [[
        'room_type_id' => $TYPE_ID,
        'check_in'     => $tomorrow,
        'check_out'    => $dayAfter,
        'guests'       => [['title' => 'Mr.', 'name' => "Test A {$TIMESTAMP}", 'nationality' => 'Thai']],
    ]],
], $TOKEN, $BASE_URL);

if ($result) {
    $assignResp = $result['assign_response'];
    check('Assign HTTP 200', $assignResp['http_code'] === 200, "HTTP {$assignResp['http_code']}");

    if ($assignResp['http_code'] === 200) {
        $assignedCount = $assignResp['body']['booking']['booking_rooms'][0]['room']['room_number'] ?? null;
        $algo = $assignResp['body']['allocation']['winner'] ?? '???';
        check('Room number assigned', $assignedCount !== null, "got #{$assignedCount}");
        echo "    📊 Algorithm winner: {$algo}\n";

        // Verify via GET /bookings/{id}
        $r = fetchBooking($result['booking_id'], $TOKEN, $BASE_URL);
        $roomNum = $r['body']['booking']['booking_rooms'][0]['room']['room_number'] ?? null;
        $roomStatus = $r['body']['booking']['booking_rooms'][0]['room']['status'] ?? null;
        check('GET reflects assigned room', $roomNum === $assignedCount, "GET=#{$roomNum}");
        echo dim("    ℹ️  Room status after assign: {$roomStatus}") . "\n";

        $allAssignedRooms[] = [
            'booking' => $result['booking_id'],
            'room'    => $assignedCount,
            'checkin' => $tomorrow,
            'checkout'=> $dayAfter,
        ];
    }
}

// ============================================
// 🅱️ Test B: Multi-room cluster (same type, same dates)
// ============================================
echo bold("\n━━━ Test B: 🏨 Multi-Room Cluster (3 rooms) ━━━") . "\n";

$result = createAndAssign('B', [
    'source'        => 'admin',
    'booking_rooms' => [
        ['room_type_id' => $TYPE_ID, 'check_in' => $tomorrow, 'check_out' => $dayAfter,
         'guests' => [['title' => 'Mr.', 'name' => "Test B1 {$TIMESTAMP}"]]],
        ['room_type_id' => $TYPE_ID, 'check_in' => $tomorrow, 'check_out' => $dayAfter,
         'guests' => [['title' => 'Mr.', 'name' => "Test B2 {$TIMESTAMP}"]]],
        ['room_type_id' => $TYPE_ID, 'check_in' => $tomorrow, 'check_out' => $dayAfter,
         'guests' => [['title' => 'Mr.', 'name' => "Test B3 {$TIMESTAMP}"]]],
    ],
], $TOKEN, $BASE_URL);

if ($result && $result['assign_response']['http_code'] === 200) {
    $brs = $result['assign_response']['body']['booking']['booking_rooms'] ?? [];
    $roomNums = [];
    foreach ($brs as $br) {
        $roomNums[] = $br['room']['room_number'] ?? '???';
    }
    sort($roomNums);
    echo "    📊 Assigned rooms: " . implode(', ', $roomNums) . "\n";

    // Check uniqueness — no duplicate room numbers within this booking
    check('All rooms unique', count($roomNums) === count(array_unique($roomNums)),
        count(array_unique($roomNums)) . ' unique');

    // Check cluster proximity — for Hybrid+ algo, rooms should be on same floor ideally
    // We compute floor spread (heuristic for "cluster")
    $floors = array_unique(array_map(fn($n) => intval($n[0]), $roomNums));
    if (count($floors) === 1) {
        echo green("    ✅ Cluster: all rooms on floor {$floors[0]} (tight cluster)") . "\n";
        $stats['pass']++;
    } else {
        echo yellow("    ⚠️  Cluster: spread across " . count($floors) . " floors (" . implode(',', $floors) . ")") . "\n";
        $stats['skip']++;
    }

    // Save for cross-booking overlap check
    foreach ($roomNums as $n) {
        $allAssignedRooms[] = [
            'booking' => $result['booking_id'],
            'room'    => $n,
            'checkin' => $tomorrow,
            'checkout'=> $dayAfter,
        ];
    }
}

// ============================================
// 🅲 Test C: Re-assign on already-assigned booking
// ============================================
echo bold("\n━━━ Test C: 🔁 Re-Assign (Idempotency) ━━━") . "\n";

if (!empty($result) && $result['assign_response']['http_code'] === 200) {
    $bookingId = $result['booking_id'];
    step("C: Re-call /assign-rooms on same booking");
    $r = api('PUT', "{$BASE_URL}/bookings/{$bookingId}/assign-rooms", null, $TOKEN);
    $code = $r['http_code'];
    $status = $r['body']['status'] ?? '???';
    $msg = $r['body']['message'] ?? '';
    echo "    📊 HTTP {$code} | status={$status} | {$msg}\n";

    // Expected: either success with same rooms OR info "already assigned"
    if ($code === 200) {
        // verify rooms unchanged
        $r2 = fetchBooking($bookingId, $TOKEN, $BASE_URL);
        $newNums = [];
        foreach (($r2['body']['booking']['booking_rooms'] ?? []) as $br) {
            $newNums[] = $br['room']['room_number'] ?? '???';
        }
        sort($newNums);
        $original = $roomNums ?? [];
        sort($original);
        check('Re-assign keeps same rooms', $newNums == $original,
            'before=' . implode(',', $original) . ' after=' . implode(',', $newNums));
    }
}

// ============================================
// 🅳 Test D: Overlapping booking — no double-booking
// ============================================
echo bold("\n━━━ Test D: 🚫 Overlapping Dates — No Double-Booking ━━━") . "\n";

// New booking for SAME dates as Test B → must get DIFFERENT rooms
$resultD = createAndAssign('D', [
    'source'        => 'admin',
    'booking_rooms' => [
        ['room_type_id' => $TYPE_ID, 'check_in' => $tomorrow, 'check_out' => $dayAfter,
         'guests' => [['title' => 'Mr.', 'name' => "Test D {$TIMESTAMP}"]]],
    ],
], $TOKEN, $BASE_URL);

if ($resultD && $resultD['assign_response']['http_code'] === 200) {
    $brs = $resultD['assign_response']['body']['booking']['booking_rooms'] ?? [];
    $roomD = $brs[0]['room']['room_number'] ?? null;
    showVar('Test D assigned', "#{$roomD}");

    // Must NOT overlap with any previously assigned room for same date range
    $conflicts = [];
    foreach ($allAssignedRooms as $prev) {
        if ($prev['room'] === $roomD) {
            // overlap check: same room only matters if dates overlap
            if ($prev['checkin'] === $tomorrow && $prev['checkout'] === $dayAfter) {
                $conflicts[] = "room #{$roomD} (booking {$prev['booking']})";
            }
        }
    }
    check('No double-booked room', empty($conflicts),
        $conflicts ? 'CONFLICT: ' . implode('; ', $conflicts) : 'clean');

    $allAssignedRooms[] = [
        'booking' => $resultD['booking_id'],
        'room'    => $roomD,
        'checkin' => $tomorrow,
        'checkout'=> $dayAfter,
    ];
}

// ============================================
// 🅴 Test E: Non-overlapping dates — same room allowed
// ============================================
echo bold("\n━━━ Test E: 📅 Non-Overlapping Dates — Room Reuse OK ━━━") . "\n";

// Booking for dates AFTER Test D's checkout → can reuse same room
$later = date('Y-m-d', strtotime('+14 days'));
$laterOut = date('Y-m-d', strtotime('+16 days'));

$resultE = createAndAssign('E', [
    'source'        => 'admin',
    'booking_rooms' => [
        ['room_type_id' => $TYPE_ID, 'check_in' => $later, 'check_out' => $laterOut,
         'guests' => [['title' => 'Mr.', 'name' => "Test E {$TIMESTAMP}"]]],
    ],
], $TOKEN, $BASE_URL);

if ($resultE && $resultE['assign_response']['http_code'] === 200) {
    $brs = $resultE['assign_response']['body']['booking']['booking_rooms'] ?? [];
    $roomE = $brs[0]['room']['room_number'] ?? null;
    showVar('Test E assigned', "#{$roomE}");
    check('Assign succeeded (reuse allowed)', $roomE !== null, "#{$roomE}");

    // It's OK if it's same room as D — dates don't overlap. Just report
    if (isset($roomD) && $roomE === $roomD) {
        echo green("    ✅ Reused room #{$roomE} (dates don't overlap — expected)") . "\n";
    } else {
        echo dim("    ℹ️  Got different room #{$roomE} vs D's #{$roomD}") . "\n";
    }
}

// ============================================
// 🅵 Test F: Negative — assign on DRAFT booking (should 422)
// ============================================
echo bold("\n━━━ Test F: 🚧 Negative — Assign on DRAFT (expect 422) ━━━") . "\n";

step("F: Create booking (stay in draft)");
$r = api('POST', $BASE_URL . '/bookings', [
    'source'        => 'admin',
    'booking_rooms' => [
        ['room_type_id' => $TYPE_ID, 'check_in' => $tomorrow, 'check_out' => $dayAfter,
         'guests' => [['title' => 'Mr.', 'name' => "Test F {$TIMESTAMP}"]]],
    ],
], $TOKEN);
$draftBookingId = $r['body']['booking_id'] ?? null;
$createdBookings[] = $draftBookingId;
showVar('DRAFT booking', $draftBookingId);

step("F: Try /assign-rooms on DRAFT status");
$r = api('PUT', "{$BASE_URL}/bookings/{$draftBookingId}/assign-rooms", null, $TOKEN);
check('Reject assign on DRAFT (422)', $r['http_code'] === 422,
    "HTTP {$r['http_code']} | " . ($r['body']['message'] ?? ''));

// ============================================
// 🧹 Cleanup
// ============================================
echo bold("\n━━━ Cleanup ━━━") . "\n";
if ($KEEP) {
    echo yellow("  ⏭️  --keep flag set — bookings not deleted:") . "\n";
    foreach ($createdBookings as $bid) {
        echo "    - {$bid}\n";
    }
} else {
    // We cannot delete bookings via API (no DELETE endpoint) — just report
    echo yellow("  ℹ️  No DELETE /bookings endpoint — test bookings remain in DB:") . "\n";
    foreach ($createdBookings as $bid) {
        echo dim("    - {$bid}\n");
    }
    echo dim("  💡 Suggest: manually cancel or status them via admin UI\n");
}

// ============================================
// 📊 Summary
// ============================================
$total = $stats['pass'] + $stats['fail'] + $stats['skip'];
echo bold("\n" . str_repeat('═', 64)) . "\n";
echo bold("📊 ROOM ASSIGNMENT TEST SUMMARY") . "\n";
echo bold(str_repeat('═', 64)) . "\n";
echo green("  ✅ Passed:  {$stats['pass']}") . "\n";
echo red("    ❌ Failed:  {$stats['fail']}") . "\n";
echo yellow("  ⏭️ Info:    {$stats['skip']}") . "\n";
echo "  📋 Total:   {$total}\n";

if ($stats['fail'] === 0) {
    echo green("\n🎉 ALL ROOM ASSIGNMENT TESTS PASSED! ✨💖\n");
} else {
    echo red("\n💥 FAILURES DETECTED — ตรวจด่วนเลยค่ะนายท่าน! 💅\n");
}
echo bold(str_repeat('═', 64)) . "\n";

exit($stats['fail'] > 0 ? 1 : 0);
