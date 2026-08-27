<?php

/**
 * 🎟️ Discount System v2.1 — Real-domain integration test
 *
 * Run from repo root:
 *   KUHOME_BASE_URL=https://ku-home.ku.ac.th/backend/api/v1 \
 *   KUHOME_ADMIN_EMAIL=admin@kuhome.com KUHOME_ADMIN_PASS=password123 \
 *   php test_scripts/test_discount_remote.php
 *
 * Creates timestamped throwaway discount codes + bookings on the target domain,
 * asserts normal flow AND the #42–#45 scrutinize regressions, then toggles the
 * created codes inactive (no DELETE endpoint by design).
 */
$BASE_URL = getenv('KUHOME_BASE_URL') ?: 'https://ku-home.ku.ac.th/backend/api/v1';
$ADMIN_EMAIL = getenv('KUHOME_ADMIN_EMAIL') ?: 'admin@kuhome.com';
$ADMIN_PASS = getenv('KUHOME_ADMIN_PASS') ?: 'password123';

function green($t)
{
    return "\033[32m{$t}\033[0m";
}
function red($t)
{
    return "\033[31m{$t}\033[0m";
}
function cyan($t)
{
    return "\033[36m{$t}\033[0m";
}
function bold($t)
{
    return "\033[1m{$t}\033[0m";
}
function yellow($t)
{
    return "\033[33m{$t}\033[0m";
}

function apiJson($method, $url, $data = null, $token = null)
{
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
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => json_decode($res, true), 'raw' => $res];
}

$PASS = 0;
$FAIL = 0;
function check($name, $cond, $detail = '')
{
    global $PASS, $FAIL;
    if ($cond) {
        $PASS++;
        echo green("   ✅ {$name}\n");
    } else {
        $FAIL++;
        echo red("   ❌ {$name}").($detail ? red(" — {$detail}") : '')."\n";
    }
}

// 🛡️ WAF/throttle-friendly wrapper: pace mutations + retry once on 429
function apiPaced($method, $url, $data = null, $token = null)
{
    usleep(1500000);
    $r = apiJson($method, $url, $data, $token);
    if ($r['code'] === 429) {
        echo yellow('   …429 throttled, waiting 65s then retrying')."\n";
        sleep(65);
        $r = apiJson($method, $url, $data, $token);
    }

    return $r;
}

echo bold("\n🎟️ Testing Discount System v2.1 on Real Domain: {$BASE_URL}\n");
echo yellow("   ⚠️ This creates throwaway data (users/codes/bookings) on the target domain!\n");

// =====================================================================
// 0. Setup: harvest room-type, register a fresh user, login admin
// =====================================================================
$r = apiJson('GET', "{$BASE_URL}/room-types");
$roomTypeId = $r['body']['room_types'][0]['id'] ?? null;
check('GET /room-types', $r['code'] === 200 && $roomTypeId !== null, 'HTTP '.$r['code']);
if (! $roomTypeId) {
    exit(1);
}

$ts = time();
$userEmail = "remote_disc_{$ts}@kuhome.test";
$r = apiJson('POST', "{$BASE_URL}/register", [
    'name' => 'Remote Discount Tester',
    'email' => $userEmail,
    'password' => 'password123',
    'phone' => '08'.str_pad((string) random_int(10000000, 99999999), 8, '0'),
]);
$USER_TOKEN = $r['body']['token'] ?? ($r['body']['access_token'] ?? null);
check('POST /register (user)', in_array($r['code'], [200, 201]) && $USER_TOKEN, "HTTP {$r['code']} :: ".substr($r['raw'], 0, 200));

$r = apiJson('POST', "{$BASE_URL}/login", ['email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASS]);
$ADMIN_TOKEN = $r['body']['token'] ?? ($r['body']['access_token'] ?? null);
check('POST /login (admin)', $r['code'] === 200 && $ADMIN_TOKEN, "HTTP {$r['code']} :: ".substr($r['raw'], 0, 200));
if (! $USER_TOKEN || ! $ADMIN_TOKEN) {
    exit(1);
}

// =====================================================================
// 1. Create draft booking WITHOUT discount → note baseline total
// =====================================================================
$checkIn = date('Y-m-d', strtotime('+5 days'));
$checkOut = date('Y-m-d', strtotime('+7 days'));
$r = apiPaced('POST', "{$BASE_URL}/bookings", [
    'source' => 'online',
    'booking_rooms' => [
        ['room_type_id' => $roomTypeId, 'check_in' => $checkIn, 'check_out' => $checkOut],
    ],
], $USER_TOKEN);
$BOOKING_ID = $r['body']['booking_id'] ?? null;
$baseTotal = $r['body']['total_amount'] ?? 0;
check('POST /bookings (draft, no discount)', $r['code'] === 201 && $BOOKING_ID && $baseTotal > 0,
    "HTTP {$r['code']} :: ".substr($r['raw'], 0, 200));

// =====================================================================
// 2. Validate/preview endpoint (no side effects)
// =====================================================================
$CODE_MAIN = 'REMOTE_'.strtoupper(dechex($ts));
echo cyan("\n2. Preview endpoint:\n");
$r = apiJson('POST', "{$BASE_URL}/discounts/validate", ['code' => strtolower($CODE_MAIN)], $USER_TOKEN);
check('validate unknown code -> 422', $r['code'] === 422, 'HTTP '.$r['code']);

$r = apiPaced('POST', "{$BASE_URL}/discounts", [
    'code' => $CODE_MAIN,
    'type' => 'percent',
    'value' => 25,
    'max_uses_per_user' => 5,
], $ADMIN_TOKEN);
check('POST /discounts (admin create, 201)', $r['code'] === 201 && strtoupper($r['body']['discount']['code'] ?? '') === $CODE_MAIN,
    "HTTP {$r['code']} :: ".substr($r['raw'], 0, 200));
$DISCOUNT_ID = $r['body']['discount']['id'] ?? null;

$r = apiJson('POST', "{$BASE_URL}/discounts/validate", [
    'code' => strtolower($CODE_MAIN), // lowercase input must still resolve (uppercase mutator)
    'check_in' => $checkIn,
    'check_out' => $checkOut,
], $USER_TOKEN);
$quotaOk = isset($r['body']['quota']['global_used']) && array_key_exists('global_remaining', $r['body']['quota'] ?? []);
check("validate '{$CODE_MAIN}' (lowercase input) -> 200 + quota shape", $r['code'] === 200 && $quotaOk,
    "HTTP {$r['code']} :: ".substr($r['raw'], 0, 300));
$thisUserUsed = $r['body']['quota']['per_user_used'] ?? -1;
check('preview did NOT hold any slot (per_user_used unchanged)', $thisUserUsed === 0, 'per_user_used='.(string) $thisUserUsed);

// =====================================================================
// 3. Apply code to draft → reprice math
// =====================================================================
echo cyan("\n3. Apply & reprice:\n");
$r = apiPaced('PUT', "{$BASE_URL}/bookings/{$BOOKING_ID}/discount-code", ['code' => strtolower($CODE_MAIN)], $USER_TOKEN);
$br0 = $r['body']['booking']['booking_rooms'][0] ?? [];
$newTotal = $r['body']['booking']['total_amount'] ?? 0;
$expectedTotal = intdiv($baseTotal * 75, 100); // 25% off room-only base (no addons here)
check('PUT /discount-code -> 200 + snapshot code', $r['code'] === 200 && ($r['body']['booking']['discount_code'] ?? '') === $CODE_MAIN,
    "HTTP {$r['code']} :: ".substr($r['raw'], 0, 300));
check('total_amount discounted correctly ('.$baseTotal.' -> '.$expectedTotal.')', $newTotal === $expectedTotal,
    "got {$newTotal}");
check('BR carries room_amount + discount_amount', ($br0['discount_amount'] ?? 0) === $baseTotal - $expectedTotal
    && ($br0['room_amount'] ?? 0) === $baseTotal, json_encode($br0));

// =====================================================================
// 4. Regression guards (#42–#45)
// =====================================================================
echo cyan("\n4. Scrutinize regressions on real server:\n");

// #42 missing code field -> 422 (NOT 500)
$r = apiPaced('PUT', "{$BASE_URL}/bookings/{$BOOKING_ID}/discount-code", [], $USER_TOKEN);
check('#42 missing code field -> 422 (not 500)', $r['code'] === 422, 'HTTP '.$r['code']);

// #43 rename code while redemption exists -> 422 validation error
$r = apiPaced('PUT', "{$BASE_URL}/discounts/{$DISCOUNT_ID}", ['code' => $CODE_MAIN.'_RENAMED'], $ADMIN_TOKEN);
check('#43 rename while holds exist -> 422 (not 200/not 500)', $r['code'] === 422, 'HTTP '.$r['code']);

// #44 half-set stay window rejected on update -> 422
$r = apiPaced('PUT', "{$BASE_URL}/discounts/{$DISCOUNT_ID}", ['stay_from' => date('Y-m-d', strtotime('+3 days'))], $ADMIN_TOKEN);
check('#44 stay_from without stay_until -> 422', $r['code'] === 422, 'HTTP '.$r['code']);

// #45 duplicate code, different case -> 422 (NOT 500)
$r = apiPaced('POST', "{$BASE_URL}/discounts", [
    'code' => strtolower($CODE_MAIN),
    'type' => 'percent',
    'value' => 10,
], $ADMIN_TOKEN);
check('#45 duplicate code different case -> 422 (not 500)', $r['code'] === 422, 'HTTP '.$r['code']);

// ownership guard
$r = apiPaced('PUT', "{$BASE_URL}/bookings/{$BOOKING_ID}/discount-code", ['code' => $CODE_MAIN], $ADMIN_TOKEN);
// admin is allowed (owner OR admin) — this should SUCCEED as swap, so assert 200
check('admin can apply on behalf (owner-or-admin rule)', $r['code'] === 200, 'HTTP '.$r['code'].' :: '.substr($r['raw'], 0, 150));

// =====================================================================
// 5. Release & reprice back to full rate
// =====================================================================
echo cyan("\n5. Remove code:\n");
$r = apiPaced('DELETE', "{$BASE_URL}/bookings/{$BOOKING_ID}/discount-code", null, $USER_TOKEN);
$rb = $r['body']['booking'] ?? [];
// ⚠️ ห้ามใช้ ?? กับ field ที่ success case คือ null — ?? จะยัด fallback ให้ค่า null เสมอ!
$codeCleared = is_array($rb) && array_key_exists('discount_code', $rb) && $rb['discount_code'] === null;
check('DELETE /discount-code -> 200 + back to full total',
    $r['code'] === 200 && ($rb['total_amount'] ?? 0) === $baseTotal && $codeCleared,
    "HTTP {$r['code']} :: ".substr($r['raw'], 0, 300));

// =====================================================================
// 6. Cleanup: disable throwaway codes (no DELETE by design) + delete draft
// =====================================================================
echo cyan("\n6. Cleanup:\n");
$r = apiPaced('PATCH', "{$BASE_URL}/discounts/{$DISCOUNT_ID}/toggle", null, $ADMIN_TOKEN);
check('toggle throwaway code inactive', $r['code'] === 200 && ($r['body']['discount']['is_active'] ?? true) === false, 'HTTP '.$r['code']);
$r = apiPaced('DELETE', "{$BASE_URL}/bookings/{$BOOKING_ID}", null, $USER_TOKEN);
check('delete draft booking', $r['code'] === 200, 'HTTP '.$r['code']);

// =====================================================================
// Summary
// =====================================================================
echo bold("\n════════════════════════════════════════\n");
if ($FAIL === 0) {
    echo green("🎉 ALL PASSED: {$PASS} checks")." against {$BASE_URL}\n\n";
    exit(0);
}
echo red("💥 FAILED: {$FAIL}/".($PASS + $FAIL).' checks')." against {$BASE_URL}\n\n";
exit(1);
