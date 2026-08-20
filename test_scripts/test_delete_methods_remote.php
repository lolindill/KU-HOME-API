<?php

/**
 * 🌐 KU HOME API — Remote Test: All HTTP DELETE Methods
 *
 * ทดสอบทุก endpoint ที่ใช้ HTTP DELETE method บน domain จริง (https://ku-home.ku.ac.th/backend/api/v1):
 *   1. DELETE /bookings/{bookingId}/rooms/{bookingRoomId}  — ลบ Booking Room รายห้อง
 *      - Happy path: ลบห้องออกจาก multi-room draft booking (200)
 *      - Validation: ห้ามลบห้องสุดท้ายของ booking (422)
 *      - Cross-booking: ลบ BR ที่ไม่ได้อยู่ใต้ booking นั้น (404)
 *      - Authorization: User อื่นมาลบของคนอื่น (403)
 *      - Unauthenticated: ไม่ส่ง token (401)
 *
 *   2. DELETE /bookings/{bookingId}                       — ลบ Draft Booking (cascade hard delete)
 *      - Happy path: เจ้าของลบ draft booking ตัวเอง (200)
 *      - Verification: GET หลังลบต้อง 404 (404)
 *      - Non-existent / Already deleted: ลบซ้ำหรือใส่ uuid ปลอม (404)
 *      - Authorization: User อื่นมาลบของคนอื่น (403)
 *      - Admin override: Admin ลบ draft booking ของคนอื่น (200)
 *      - Unauthenticated: ไม่ส่ง token (401)
 *
 *   3. DELETE /users/{id}                                 — Admin ลบผู้ใช้ (User Delete)
 *      - Happy path: Admin ลบ test user ที่สร้างขึ้นมา (200)
 *      - Verification: GET /users/{id} หลังลบต้อง 404 (404)
 *      - Authorization: Regular user พยายามลบ (403)
 *      - Unauthenticated: ไม่ส่ง token (401)
 *      - Non-existent: ลบ user id ที่ไม่มีอยู่จริง (404)
 *
 * ⚠️ Rate limit pacing: 13s ระหว่าง request ต่อ user bucket (throttle:5,1)
 *
 * วิธีใช้: php test_scripts/test_delete_methods_remote.php
 */

// ============================================
// ⚙️ Configuration
// ============================================
$BASE_URL = getenv('KUHOME_BASE_URL') ?: 'https://ku-home.ku.ac.th/backend/api/v1';
$ADMIN_EMAIL = getenv('KUHOME_ADMIN_EMAIL') ?: 'admin@kuhome.com';
$ADMIN_PASS = getenv('KUHOME_ADMIN_PASS') ?: 'password123';

$TS = time();
$USER_A_EMAIL = "test_del_a_{$TS}@kuhome.test";
$USER_B_EMAIL = "test_del_b_{$TS}@kuhome.test";
$USER_TO_DELETE_EMAIL = "test_del_victim_{$TS}@kuhome.test";
$PASSWORD = 'password123';

// ============================================
// 🎨 Output & Logging Helpers
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

// ⏳ rate-limit pacing
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
    pace($token ?: 'ip');

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
        CURLOPT_TIMEOUT => 20,
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

    return ['http_code' => $httpCode, 'body' => json_decode($response, true), 'raw' => $response, 'error' => null];
}

$cleanupBookings = [];
function trackBooking($id)
{
    global $cleanupBookings;
    if ($id) {
        $cleanupBookings[] = $id;
    }
}

$cleanupUsers = [];
function trackUser($id)
{
    global $cleanupUsers;
    if ($id) {
        $cleanupUsers[] = $id;
    }
}

out('══════════════════════════════════════════════════════════');
out('🗑️  KU HOME API — Remote Test: HTTP DELETE Methods');
out("   Target: {$BASE_URL}");
out("══════════════════════════════════════════════════════════\n");

// ============================================
// Phase 0: Setup Users + Token Retrieval
// ============================================
out('━━━ 🔑 Phase 0: User Setup & Authentication ━━━');

// User A
$r = apiCall('POST', $BASE_URL.'/register', [
    'name' => "Del Test A {$TS}", 'email' => $USER_A_EMAIL, 'password' => $PASSWORD,
]);
$TOKEN_A = $r['body']['access_token'] ?? null;
$USER_A_ID = null;
if ($TOKEN_A) {
    $rMe = apiCall('GET', $BASE_URL.'/me', null, $TOKEN_A);
    $USER_A_ID = $rMe['body']['user']['id'] ?? ($rMe['body']['id'] ?? null);
    trackUser($USER_A_ID);
}
check('Register User A', $r['http_code'] === 201 && $TOKEN_A && $USER_A_ID, "ID: {$USER_A_ID}");

// User B
$r = apiCall('POST', $BASE_URL.'/register', [
    'name' => "Del Test B {$TS}", 'email' => $USER_B_EMAIL, 'password' => $PASSWORD,
]);
$TOKEN_B = $r['body']['access_token'] ?? null;
$USER_B_ID = null;
if ($TOKEN_B) {
    $rMe = apiCall('GET', $BASE_URL.'/me', null, $TOKEN_B);
    $USER_B_ID = $rMe['body']['user']['id'] ?? ($rMe['body']['id'] ?? null);
    trackUser($USER_B_ID);
}
check('Register User B', $r['http_code'] === 201 && $TOKEN_B && $USER_B_ID, "ID: {$USER_B_ID}");

// Victim User (To be deleted by Admin test)
$r = apiCall('POST', $BASE_URL.'/register', [
    'name' => "Victim User {$TS}", 'email' => $USER_TO_DELETE_EMAIL, 'password' => $PASSWORD,
]);
$VICTIM_TOKEN = $r['body']['access_token'] ?? null;
$VICTIM_USER_ID = null;
if ($VICTIM_TOKEN) {
    $r = apiCall('GET', $BASE_URL.'/me', null, $VICTIM_TOKEN);
    $VICTIM_USER_ID = $r['body']['user']['id'] ?? ($r['body']['id'] ?? null);
    trackUser($VICTIM_USER_ID);
}
check('Register Victim User for Delete test', $VICTIM_TOKEN && $VICTIM_USER_ID, "ID: {$VICTIM_USER_ID}");

// Admin Login
$r = apiCall('POST', $BASE_URL.'/login', ['email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASS]);
$ADMIN_TOKEN = $r['body']['access_token'] ?? null;
if ($ADMIN_TOKEN) {
    ok('Admin login — HTTP 200');
} else {
    warn("Admin login ล้มเหลว (HTTP {$r['http_code']})");
}

// Harvest Room Types
$r = apiCall('GET', $BASE_URL.'/room-types');
$types = $r['body']['room_types'] ?? $r['body']['data'] ?? $r['body'] ?? [];
$ROOM_TYPE_ID = $types[0]['id'] ?? null;
check('Harvest Room Type ID', $ROOM_TYPE_ID !== null, "ID: {$ROOM_TYPE_ID}");

out('');

// ============================================
// Phase 1: DELETE /bookings/{bookingId}/rooms/{bookingRoomId}
// ============================================
out('━━━ 🛏️ Phase 1: DELETE /bookings/{id}/rooms/{roomId} ━━━');

// Create multi-room draft booking (2 rooms) for User A
$dIn = date('Y-m-d', strtotime('+3 days'));
$dOut = date('Y-m-d', strtotime('+5 days'));

$r = apiCall('POST', $BASE_URL.'/bookings', [
    'source' => 'online',
    'booking_rooms' => [
        ['room_type_id' => $ROOM_TYPE_ID, 'check_in' => $dIn, 'check_out' => $dOut, 'extra_beds' => 0],
        ['room_type_id' => $ROOM_TYPE_ID, 'check_in' => $dIn, 'check_out' => $dOut, 'extra_beds' => 0],
    ],
], $TOKEN_A);
$BOOKING_A = $r['body']['booking_id'] ?? null;
trackBooking($BOOKING_A);
check('User A สร้าง draft booking 2 ห้อง', $r['http_code'] === 201 && $BOOKING_A, "Booking: {$BOOKING_A}");

// Get BR IDs
$r = apiCall('GET', $BASE_URL.'/bookings/'.$BOOKING_A, null, $TOKEN_A);
$brList = $r['body']['booking']['booking_rooms'] ?? [];
$ROOM_A1 = $brList[0]['id'] ?? null;
$ROOM_A2 = $brList[1]['id'] ?? null;
info("Room 1: {$ROOM_A1} | Room 2: {$ROOM_A2}");

// 1.1 Unauthenticated: DELETE room without token -> 401
$r = apiCall('DELETE', $BASE_URL."/bookings/{$BOOKING_A}/rooms/{$ROOM_A1}", null, null);
check('DELETE room (Unauthenticated) → 401', $r['http_code'] === 401, "HTTP {$r['http_code']}");

// 1.2 Forbidden: User B tries to delete User A's room -> 403
$r = apiCall('DELETE', $BASE_URL."/bookings/{$BOOKING_A}/rooms/{$ROOM_A1}", null, $TOKEN_B);
check('DELETE room (User B deletes User A room) → 403', $r['http_code'] === 403, "HTTP {$r['http_code']}");

// 1.3 Not Found: Cross-booking / fake room ID -> 404
$r = apiCall('DELETE', $BASE_URL."/bookings/{$BOOKING_A}/rooms/00000000-0000-4000-8000-000000000000", null, $TOKEN_A);
check('DELETE room (Fake room ID) → 404', $r['http_code'] === 404, "HTTP {$r['http_code']}");

// 1.4 Happy Path: Delete Room A1 (not the last room) -> 200
$r = apiCall('DELETE', $BASE_URL."/bookings/{$BOOKING_A}/rooms/{$ROOM_A1}", null, $TOKEN_A);
check('DELETE room (Happy path: 1st of 2 rooms) → 200', $r['http_code'] === 200, "HTTP {$r['http_code']}");
check('Response remaining_rooms = 1', ($r['body']['remaining_rooms'] ?? null) === 1);

// 1.5 Validation: Attempt to delete last remaining room (Room A2) -> 422
$r = apiCall('DELETE', $BASE_URL."/bookings/{$BOOKING_A}/rooms/{$ROOM_A2}", null, $TOKEN_A);
check('DELETE room (Last remaining room) → 422', $r['http_code'] === 422, "HTTP {$r['http_code']} — ".($r['body']['message'] ?? ''));

out('');

// ============================================
// Phase 2: DELETE /bookings/{bookingId}
// ============================================
out('━━━ 📅 Phase 2: DELETE /bookings/{id} ━━━');

// 2.1 Unauthenticated: DELETE booking without token -> 401
$r = apiCall('DELETE', $BASE_URL."/bookings/{$BOOKING_A}", null, null);
check('DELETE booking (Unauthenticated) → 401', $r['http_code'] === 401, "HTTP {$r['http_code']}");

// 2.2 Forbidden: User B tries to delete User A's booking -> 403
$r = apiCall('DELETE', $BASE_URL."/bookings/{$BOOKING_A}", null, $TOKEN_B);
check('DELETE booking (User B deletes User A booking) → 403', $r['http_code'] === 403, "HTTP {$r['http_code']}");

// 2.3 Happy Path: Owner deletes draft booking -> 200
$r = apiCall('DELETE', $BASE_URL."/bookings/{$BOOKING_A}", null, $TOKEN_A);
check('DELETE booking (Owner deletes draft booking) → 200', $r['http_code'] === 200, "HTTP {$r['http_code']}");

// 2.4 Verification: GET deleted booking -> 404
$r = apiCall('GET', $BASE_URL."/bookings/{$BOOKING_A}", null, $TOKEN_A);
check('GET deleted booking → 404', $r['http_code'] === 404, "HTTP {$r['http_code']}");

// 2.5 Delete already deleted / fake booking -> 404
$r = apiCall('DELETE', $BASE_URL."/bookings/{$BOOKING_A}", null, $TOKEN_A);
check('DELETE already-deleted booking → 404', $r['http_code'] === 404, "HTTP {$r['http_code']}");

// 2.6 Admin Override: User B creates draft booking, Admin deletes it
$r = apiCall('POST', $BASE_URL.'/bookings', [
    'source' => 'online',
    'booking_rooms' => [
        ['room_type_id' => $ROOM_TYPE_ID, 'check_in' => $dIn, 'check_out' => $dOut, 'extra_beds' => 0],
    ],
], $TOKEN_B);
$BOOKING_B = $r['body']['booking_id'] ?? null;
trackBooking($BOOKING_B);
check('User B สร้าง draft booking', $r['http_code'] === 201 && $BOOKING_B, "Booking: {$BOOKING_B}");

if ($ADMIN_TOKEN && $BOOKING_B) {
    $r = apiCall('DELETE', $BASE_URL."/bookings/{$BOOKING_B}", null, $ADMIN_TOKEN);
    check('DELETE booking (Admin override delete) → 200', $r['http_code'] === 200, "HTTP {$r['http_code']}");
    $r = apiCall('GET', $BASE_URL."/bookings/{$BOOKING_B}", null, $TOKEN_B);
    check('GET booking after Admin delete → 404', $r['http_code'] === 404, "HTTP {$r['http_code']}");
} else {
    skipCheck('Admin override booking delete', 'No admin token or booking B');
}

out('');

// ============================================
// Phase 3: DELETE /users/{id}
// ============================================
out('━━━ 👥 Phase 3: DELETE /users/{id} ━━━');

if ($VICTIM_USER_ID) {
    // 3.1 Unauthenticated: DELETE user without token -> 401
    $r = apiCall('DELETE', $BASE_URL."/users/{$VICTIM_USER_ID}", null, null);
    check('DELETE user (Unauthenticated) → 401', $r['http_code'] === 401, "HTTP {$r['http_code']}");

    // 3.2 Forbidden: Regular user tries to delete user -> 403
    $r = apiCall('DELETE', $BASE_URL."/users/{$VICTIM_USER_ID}", null, $TOKEN_A);
    check('DELETE user (Regular user attempt) → 403', $r['http_code'] === 403, "HTTP {$r['http_code']}");

    // 3.3 Happy Path: Admin deletes victim user -> 200
    if ($ADMIN_TOKEN) {
        $r = apiCall('DELETE', $BASE_URL."/users/{$VICTIM_USER_ID}", null, $ADMIN_TOKEN);
        check('DELETE user (Admin deletes test user) → 200', $r['http_code'] === 200, "HTTP {$r['http_code']}");

        // 3.4 Verification: GET /users/{id} as admin -> 404
        $r = apiCall('GET', $BASE_URL."/users/{$VICTIM_USER_ID}", null, $ADMIN_TOKEN);
        check('GET deleted user as admin → 404', $r['http_code'] === 404, "HTTP {$r['http_code']}");

        // 3.5 Delete already deleted / fake user ID -> 404
        $r = apiCall('DELETE', $BASE_URL."/users/{$VICTIM_USER_ID}", null, $ADMIN_TOKEN);
        check('DELETE already-deleted user → 404', $r['http_code'] === 404, "HTTP {$r['http_code']}");
    } else {
        skipCheck('Admin delete user happy path', 'No admin token');
    }
} else {
    skipCheck('Phase 3: DELETE user tests', 'No victim user ID');
}

out('');

// ============================================
// Phase 4: Cleanup
// ============================================
out('━━━ 🧹 Phase 4: Final Cleanup ━━━');

// 1) Clean up bookings first
$leftovers = 0;
foreach (array_unique($cleanupBookings) as $bId) {
    $r = apiCall('DELETE', $BASE_URL.'/bookings/'.$bId, null, $ADMIN_TOKEN ?: $TOKEN_A);
    if ($r['http_code'] === 200) {
        info("Cleaned up booking {$bId}");
    } elseif ($r['http_code'] === 404) {
        // already cleaned / deleted
    } else {
        warn("Could not delete {$bId} (HTTP {$r['http_code']})");
        $leftovers++;
    }
}
if ($leftovers === 0) {
    ok('All temporary test bookings cleaned up successfully');
}

// 2) Clean up all test users created in Phase 0 (User A, User B, Victim)
if ($ADMIN_TOKEN) {
    $userLeftovers = 0;
    foreach (array_unique($cleanupUsers) as $uId) {
        $r = apiCall('DELETE', $BASE_URL.'/users/'.$uId, null, $ADMIN_TOKEN);
        if ($r['http_code'] === 200) {
            info("Cleaned up test user {$uId}");
        } elseif ($r['http_code'] === 404) {
            // already deleted (e.g. victim user deleted in Phase 3)
        } else {
            warn("Could not delete test user {$uId} (HTTP {$r['http_code']})");
            $userLeftovers++;
        }
    }
    if ($userLeftovers === 0) {
        ok('All temporary test users cleaned up successfully');
    }
} else {
    warn('Skipped user cleanup: No admin token');
}

out('');
out('══════════════════════════════════════════════════════════');
out("📊 ผลการทดสอบ: ✅ PASS: {$PASS} · ❌ FAIL: {$FAIL} · ⏭️  SKIP: {$SKIP}");
out('══════════════════════════════════════════════════════════');

exit($FAIL > 0 ? 1 : 0);
