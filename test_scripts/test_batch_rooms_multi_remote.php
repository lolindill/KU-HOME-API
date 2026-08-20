<?php

/**
 * 🌐 KU HOME API — Remote Test: Batch Room Update (3+ rooms in one request)
 *
 * เทสต์ PUT /bookings/{bookingId}/rooms แบบหลายห้อง (>2) ในคำขอเดียวบน domain จริง
 * โดยเน้น dirty-field/idempotent logic ใหม่ (commit 483265a):
 *   1. POST   /bookings                       — สร้าง draft booking 3 ห้อง (same type)
 *   2. PUT    /bookings/{id}/rooms            — batch 3 ห้องพร้อมกัน คละเคส:
 *        brA: ยืดเป็น 3 คืน + extra_bed 1 + breakfast 1   (shape changed → re-check + reprice)
 *        brB: guests + breakfast 2 (ไม่แตะวันที่)          (data changed → reprice)
 *        brC: ส่งค่าเดิมเป๊ะ (unchanged)                    (no-op → ผ่าน availability โดย invariant)
 *   3. PUT    เดิมซ้ำอีกรอบเป๊ะๆ               — idempotent re-send → 200 + total คงเดิม
 *   4. Error paths: id ซ้ำใน batch → 422 · id แปลกปลอมใน batch → 404 ·
 *                   วันที่กลับข้าง 1 ห้องใน batch → 422 · ไม่มี token → 401
 *
 * ⚠️ Rate limit pacing: 13s ระหว่าง request ต่อ bucket (throttle:5,1 shared per user)
 *
 * วิธีใช้: php test_scripts/test_batch_rooms_multi_remote.php
 */

// ============================================
// ⚙️ Configuration
// ============================================
$BASE_URL = getenv('KUHOME_BASE_URL') ?: 'https://ku-home.ku.ac.th/backend/api/v1';
$ADMIN_EMAIL = getenv('KUHOME_ADMIN_EMAIL') ?: 'admin@kuhome.com';
$ADMIN_PASS = getenv('KUHOME_ADMIN_PASS') ?: 'password123';

$TS = time();
$TEST_EMAIL = "test_batch3_{$TS}@kuhome.test";
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

out('══════════════════════════════════════════════════════════');
out('🌐 KU HOME API — Remote Test: Batch Room Update (3 ห้อง/คำขอ)');
out("   Target: {$BASE_URL}");
out('══════════════════════════════════════════════════════════\n');

// ============================================
// 0) Register test user + admin login
// ============================================
$r = apiCall('POST', $BASE_URL.'/register', [
    'name' => "Batch3 Test {$TS}", 'email' => $TEST_EMAIL, 'password' => $TEST_PASSWORD,
]);
$TOKEN = $r['body']['access_token'] ?? null;
$USER_ID = null;
if ($TOKEN) {
    $rMe = apiCall('GET', $BASE_URL.'/me', null, $TOKEN);
    $USER_ID = $rMe['body']['user']['id'] ?? ($rMe['body']['id'] ?? null);
}
check('Register test user → token', $r['http_code'] === 201 && $TOKEN !== null, 'HTTP '.$r['http_code']);
if (! $TOKEN) {
    bad('สมัคร user ไม่ได้ — เทสต์ต่อไม่ได้');
    exit(1);
}

$r = apiCall('POST', $BASE_URL.'/login', ['email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASS]);
$ADMIN_TOKEN = $r['body']['access_token'] ?? null;
$ADMIN_TOKEN ? ok('Admin login') : warn('Admin login ล้มเหลว (HTTP '.$r['http_code'].') — cleanup user จะไม่ได้');

// ============================================
// 1) Fixtures: room types + rooms + rates — เลือก type ที่มีห้องมากสุด (ต้อง ≥3)
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
    if ($id && ($countByType[$id] ?? 0) >= 3) {
        $candidates[$id] = $countByType[$id];
    }
}
arsort($candidates);
if (! $candidates) {
    bad('ไม่พบ room type ที่มี ≥3 ห้อง — เทสต์ต่อไม่ได้');
    exit(1);
}
$ROOM_TYPE_ID = array_key_first($candidates);
info('ใช้ room type: '.$ROOM_TYPE_ID.' ('.$candidates[$ROOM_TYPE_ID].' ห้อง)');

// rates — call เดียวแล้ว filter ฝั่ง client
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
// 2) สร้าง draft booking 3 ห้อง — ใช้วันที่ไกลๆ (+30..+32) กันชน residue จากเทสต์ก่อนๆ
//    (ถ้า type แรกเต็มให้ไล่ลอง type ถัดไปที่มี ≥3 ห้อง)
// ============================================
$D = date('Y-m-d', strtotime('+30 days'));
$D2 = date('Y-m-d', strtotime('+32 days'));
$D3 = date('Y-m-d', strtotime('+33 days')); // brA ยืด check_out ถึงนี่ (3 คืน)

$BOOKING_ID = null;
$r = null;
foreach (array_keys($candidates) as $tryType) {
    $r = apiCall('POST', $BASE_URL.'/bookings', [
        'source' => 'online',
        'booking_rooms' => [
            ['room_type_id' => $tryType, 'check_in' => $D, 'check_out' => $D2, 'extra_beds' => 0],
            ['room_type_id' => $tryType, 'check_in' => $D, 'check_out' => $D2, 'extra_beds' => 0],
            ['room_type_id' => $tryType, 'check_in' => $D, 'check_out' => $D2, 'extra_beds' => 0],
        ],
    ], $TOKEN);
    if ($r['http_code'] === 201) {
        $BOOKING_ID = $r['body']['booking_id'] ?? null;
        // rates ผูกกับ type — ถ้าได้ type อื่นจาก retry ต้องเจอ daily rate ของ type นั้นใหม่
        foreach ($rates as $g) {
            if (($g['rate_type'] ?? '') === 'daily' && ($g['room_type_id'] ?? null) === $tryType) {
                $roomRate = (int) ($g['default_price'] ?? 0);
            }
        }
        $ROOM_TYPE_ID = $tryType;
        break;
    }
    info("Type {$tryType} เต็ม (HTTP {$r['http_code']}) — ลอง type ถัดไป");
}
check('POST /bookings (3 ห้อง draft) → 201', $r['http_code'] === 201 && $BOOKING_ID, 'HTTP '.$r['http_code']);
if (! $BOOKING_ID || $roomRate <= 0) {
    bad('สร้าง booking 3 ห้องไม่ได้ หรือไม่พบ daily rate — เทสต์ต่อไม่ได้');
    exit(1);
}
$initialTotal = 3 * $roomRate * 2; // 3 ห้อง × rate × 2 คืน
check('total_amount เริ่มต้น = 3×rate×2', ($r['body']['total_amount'] ?? null) === $initialTotal,
    'ได้ '.($r['body']['total_amount'] ?? '?')." คาด {$initialTotal}");

// ============================================
// 3) GET booking → เก็บ BR ids 3 ตัว
// ============================================
$r = apiCall('GET', $BASE_URL.'/bookings/'.$BOOKING_ID, null, $TOKEN);
$brs = $r['body']['booking']['booking_rooms'] ?? [];
check('GET /bookings/{id} → มี 3 BR', $r['http_code'] === 200 && count($brs) === 3, 'HTTP '.$r['http_code'].', '.count($brs).' BRs');
$brA = $brs[0]['id'] ?? null;
$brB = $brs[1]['id'] ?? null;
$brC = $brs[2]['id'] ?? null;
info("brA={$brA}\n     brB={$brB}\n     brC={$brC}");

if (! $brA || ! $brB || ! $brC) {
    bad('ไม่ได้ BR id ครบ 3 ห้อง — เทสต์ต่อไม่ได้');
    exit(1);
}

// ============================================
// 4) ⭐ BATCH PUT 3 ห้องพร้อมกัน (คละเคส: changed + data-only + unchanged)
// ============================================
$batchPayload = [
    'booking_rooms' => [
        [
            // brA: shape changed — ยืด 3 คืน + extra_bed 1 + breakfast 1
            'booking_room_id' => $brA,
            'check_in' => $D, 'check_out' => $D3,
            'extra_beds' => 1,
            'addons' => ['breakfast' => 1],
        ],
        [
            // brB: data-only — guests + breakfast 2 (ไม่แตะวันที่/ประเภท)
            'booking_room_id' => $brB,
            'guests' => [
                ['title' => 'Mr.', 'name' => 'Batch Three A', 'nationality' => 'Thai', 'is_ku_member' => false],
                ['title' => 'Ms.', 'name' => 'Batch Three B', 'nationality' => 'Thai', 'is_ku_member' => false],
            ],
            'addons' => ['breakfast' => 2],
        ],
        [
            // brC: unchanged — ส่งค่าเดิมเป๊ะ (no-op ต้องผ่านและไม่พัง)
            'booking_room_id' => $brC,
            'check_in' => $D, 'check_out' => $D2,
            'extra_beds' => 0,
        ],
    ],
];

$r = apiCall('PUT', $BASE_URL."/bookings/{$BOOKING_ID}/rooms", $batchPayload, $TOKEN);

// คาดราคา: A = (rate×3)+(bed×1×3)+bfast · B = (rate×2)+bfast×2 · C = rate×2
$expA = ($roomRate * 3) + ($extraBedRate * 1 * 3) + ($breakfastRate * 1);
$expB = ($roomRate * 2) + ($breakfastRate * 2);
$expC = ($roomRate * 2);
$expTotal = $expA + $expB + $expC;

check('BATCH PUT 3 ห้อง (mixed changed/data-only/unchanged) → 200', $r['http_code'] === 200, 'HTTP '.$r['http_code']);
check('BATCH total_amount ถูกต้อง', ($r['body']['total_amount'] ?? null) === $expTotal,
    'ได้ '.($r['body']['total_amount'] ?? '?')." คาด {$expTotal} (A={$expA} + B={$expB} + C={$expC})");
check('BATCH response มี booking_rooms 3 อันตามลำดับ request', count($r['body']['booking_rooms'] ?? []) === 3);

// เช็ค per-room ผลการแก้ (วันที่ใน response เป็น ISO datetime — เอา 10 ตัวแรกเทียบเป็น date เท่านั้น)
$dateOnly = fn ($v) => substr((string) $v, 0, 10);
$respA = null;
$respB = null;
$respC = null;
foreach (($r['body']['booking_rooms'] ?? []) as $idx => $brResp) {
    ${['respA', 'respB', 'respC'][$idx]} = $brResp;
}
if ($respA) {
    check('brA check_out ยืดเป็น '.$D3, $dateOnly($respA['check_out'] ?? '') === $D3, 'ได้ '.($respA['check_out'] ?? '?'));
    check('brA extra_bed=1 + breakfast=1', ($respA['addon']['extra_bed'] ?? null) === 1 && ($respA['addon']['breakfast'] ?? null) === 1);
}
if ($respB) {
    $bGuests = $respB['guests'] ?? [];
    check('brB guests บันทึก 2 คน', is_array($bGuests) && count($bGuests) === 2, 'ได้ '.count($bGuests).' คน');
    check('brB breakfast=2', ($respB['addon']['breakfast'] ?? null) === 2);
    check('brB ไม่แตะวันที่ (check_out ยังเป็น '.$D2.')', $dateOnly($respB['check_out'] ?? '') === $D2, 'ได้ '.($respB['check_out'] ?? '?'));
}
if ($respC) {
    check('brC check_out คงเดิม ('.$D2.')', $dateOnly($respC['check_out'] ?? '') === $D2, 'ได้ '.($respC['check_out'] ?? '?'));
    check('brC ไม่มี addon (extra_bed=0, breakfast=0)',
        (int) ($respC['addon']['extra_bed'] ?? 0) === 0 && (int) ($respC['addon']['breakfast'] ?? 0) === 0);
}

// ============================================
// 5) ⭐ Idempotent re-send — ยิง batch เดิมซ้ำเป๊ะๆ → 200 + total คงเดิม
//    (dirty-field logic ต้องทำเป็น no-op ไม่พัง ไม่ดับเบิลราคา)
// ============================================
$r = apiCall('PUT', $BASE_URL."/bookings/{$BOOKING_ID}/rooms", $batchPayload, $TOKEN);
check('Idempotent re-send → 200', $r['http_code'] === 200, 'HTTP '.$r['http_code']);
check('Idempotent re-send → total คงเดิม (ไม่ดับเบิล)', ($r['body']['total_amount'] ?? null) === $expTotal,
    'ได้ '.($r['body']['total_amount'] ?? '?')." คาด {$expTotal}");

// ============================================
// 6) Error paths (batch 3 ห้อง)
// ============================================
// 6a) id ซ้ำใน batch → 422 (distinct rule)
$r = apiCall('PUT', $BASE_URL."/bookings/{$BOOKING_ID}/rooms", [
    'booking_rooms' => [
        ['booking_room_id' => $brA, 'billing_comment' => 'A'],
        ['booking_room_id' => $brB, 'billing_comment' => 'B'],
        ['booking_room_id' => $brA, 'billing_comment' => 'A dup'],
    ],
], $TOKEN);
check('BATCH 3 ห้องมี id ซ้ำ → 422', $r['http_code'] === 422, 'HTTP '.$r['http_code']);

// 6b) มี id แปลกปลอม 1 ตัวใน batch → 404 ทั้ง batch (all-or-nothing)
$r = apiCall('PUT', $BASE_URL."/bookings/{$BOOKING_ID}/rooms", [
    'booking_rooms' => [
        ['booking_room_id' => $brA, 'billing_comment' => 'should not apply'],
        ['booking_room_id' => $brB, 'billing_comment' => 'should not apply'],
        ['booking_room_id' => '00000000-0000-4000-8000-000000000000'],
    ],
], $TOKEN);
check('BATCH มี id แปลกปลอม → 404 ทั้ง batch', $r['http_code'] === 404, 'HTTP '.$r['http_code']);

// 6c) วันที่กลับข้างเฉพาะ 1 ห้องใน batch → 422 ทั้ง batch (all-or-nothing — ห้องอื่นต้องไม่ถูกแก้)
$r = apiCall('PUT', $BASE_URL."/bookings/{$BOOKING_ID}/rooms", [
    'booking_rooms' => [
        ['booking_room_id' => $brA, 'billing_comment' => 'valid row'],
        ['booking_room_id' => $brB, 'billing_comment' => 'valid row'],
        ['booking_room_id' => $brC, 'check_in' => $D2, 'check_out' => $D], // กลับข้าง
    ],
], $TOKEN);
check('BATCH วันที่กลับข้าง 1 ห้อง → 422', $r['http_code'] === 422, 'HTTP '.$r['http_code']);

// 6d) ไม่มี token → 401
$r = apiCall('PUT', $BASE_URL."/bookings/{$BOOKING_ID}/rooms", [
    'booking_rooms' => [['booking_room_id' => $brA]],
], null);
check('BATCH PUT ไม่มี token → 401', $r['http_code'] === 401, 'HTTP '.$r['http_code']);

// 6e) all-or-nothing verify: หลังจาก 6b/6c fail ทั้ง batch — GET กลับมา total/ข้อมูลต้องคงเดิม
$r = apiCall('GET', $BASE_URL.'/bookings/'.$BOOKING_ID, null, $TOKEN);
$afterBrs = $r['body']['booking']['booking_rooms'] ?? [];
$afterTotal = $r['body']['booking']['total_amount'] ?? null;
$afterA = null;
foreach ($afterBrs as $br) {
    if (($br['id'] ?? '') === $brA) {
        $afterA = $br;
    }
}
check('หลัง batch ล้มเหลว ทุกห้องคงเดิม (all-or-nothing) — total ยังเท่าเดิม', $afterTotal === $expTotal,
    'ได้ '.($afterTotal ?? '?')." คาด {$expTotal}");
if ($afterA) {
    check('brA ไม่โดนแก้จาก batch ที่ fail (billing_comment ไม่ถูกเขียน)', ($afterA['billing_comment'] ?? null) === null,
        'billing_comment='.var_export($afterA['billing_comment'] ?? null, true));
}

// ============================================
// 🧹 Cleanup — ลบ booking + test user (กัน residue บน prod)
// ============================================
out("\n── 🧹 Cleanup ──────────────────────────────────────────");
$leftover = [];
if ($BOOKING_ID) {
    $r = apiCall('DELETE', $BASE_URL.'/bookings/'.$BOOKING_ID, null, $TOKEN);
    if ($r['http_code'] !== 200 && $r['http_code'] !== 404) {
        $leftover[] = $BOOKING_ID;
        warn("ลบ booking {$BOOKING_ID} ไม่สำเร็จ (HTTP {$r['http_code']})");
    }
}
if ($USER_ID && $ADMIN_TOKEN) {
    $r = apiCall('DELETE', $BASE_URL.'/users/'.$USER_ID, null, $ADMIN_TOKEN);
    if ($r['http_code'] !== 200 && $r['http_code'] !== 404) {
        $leftover[] = $USER_ID;
        warn("ลบ user {$USER_ID} ไม่สำเร็จ (HTTP {$r['http_code']})");
    }
}
if (! $leftover) {
    ok('ไม่มี residue ค้างบน prod (ลบ booking + test user ครบ)');
}
out('');

// ============================================
// Summary
// ============================================
out('══════════════════════════════════════════════════════════');
out("📊 ผลรวม: ✅ PASS {$PASS} · ❌ FAIL {$FAIL} · ⏭️  SKIP {$SKIP}");
out('══════════════════════════════════════════════════════════');
exit($FAIL > 0 ? 1 : 0);
