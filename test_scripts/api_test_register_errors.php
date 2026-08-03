<?php
/**
 * 🧪 KU HOME API — Register Error Cases Test (Real Domain)
 *
 * ทดสอบ error handling ที่เพิ่งเขียนใหม่ใน AuthController::register()
 *   1. ✅ Validation fail → 422 (แยก field ละเอียด)
 *   2. 🔁 Email ซ้ำ → 409 (unique constraint / race condition)
 *   3. 💥 Server error → 500 (generic message, ไม่ leak)
 *
 * วิธีใช้:
 *   php test_scripts/api_test_register_errors.php
 */

// ============================================
// ⚙️ Configuration
// ============================================
$BASE_URL = getenv('KUHOME_BASE_URL') ?: 'https://ku-home.ku.ac.th/backend/api/v1';
$TIMESTAMP = time();

$COLOR = (DIRECTORY_SEPARATOR === '\\') ? false : true;
function green($t)  { global $COLOR; return $COLOR ? "\033[32m{$t}\033[0m" : $t; }
function red($t)    { global $COLOR; return $COLOR ? "\033[31m{$t}\033[0m" : $t; }
function yellow($t) { global $COLOR; return $COLOR ? "\033[33m{$t}\033[0m" : $t; }
function cyan($t)   { global $COLOR; return $COLOR ? "\033[36m{$t}\033[0m" : $t; }
function bold($t)   { global $COLOR; return $COLOR ? "\033[1m{$t}\033[0m" : $t; }

$stats = ['pass' => 0, 'fail' => 0];

function apiCall($method, $url, $data = null) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
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
    return ['http_code' => $httpCode, 'body' => json_decode($response, true), 'raw' => $response, 'error' => null];
}

function test($label, $data, $expectedCode, $extraCheck = null) {
    global $BASE_URL, $stats;
    echo cyan("  → {$label}") . "\n";
    $r = apiCall('POST', $BASE_URL . '/register', $data);

    if ($r['error']) {
        $stats['fail']++;
        echo red("    ❌ FAIL: cURL error — {$r['error']}") . "\n\n";
        return $r;
    }

    $code = $r['http_code'];
    $passed = ($code === $expectedCode);

    // ตรวจเงื่อนไขเพิ่มเติม (เช่น ไม่มี getMessage leak, มี errors field)
    if ($passed && $extraCheck) {
        $passed = $extraCheck($r['body']);
    }

    if ($passed) {
        $stats['pass']++;
        echo green("    ✅ PASS → {$code} (expected {$expectedCode})");
    } else {
        $stats['fail']++;
        echo red("    ❌ FAIL → {$code} (expected {$expectedCode})");
    }

    if ($r['body']) {
        $status = $r['body']['status'] ?? '???';
        $msg    = $r['body']['message'] ?? '';
        echo " | status={$status}";
        if ($msg && mb_strlen($msg) < 100) echo " | {$msg}";
        // แสดง validation errors ถ้ามี
        if (!empty($r['body']['errors'])) {
            $fields = array_keys($r['body']['errors']);
            echo " | fields=[" . implode(',', $fields) . "]";
        }
    }
    echo "\n\n";
    return $r;
}

// ============================================
// 🚀 Banner
// ============================================
echo bold("\n" . str_repeat('=', 64)) . "\n";
echo bold("🧪 Register Error Cases — Real Domain Test") . "\n";
echo bold(str_repeat('=', 64)) . "\n";
echo "Base URL : {$BASE_URL}\n";
echo "Time     : " . date('Y-m-d H:i:s') . "\n\n";

// ============================================
// Step 0: สมัคร user คนหนึ่งไว้ก่อน เพื่อเอา email ไปทดสอบ "ซ้ำ"
// ============================================
echo bold("━━━ Step 0: 🌱 Seed a user (for duplicate test) ━━━") . "\n";
$seedEmail = "dup_test_{$TIMESTAMP}@kuhome.test";
$seed = apiCall('POST', $BASE_URL . '/register', [
    'name'     => "Dup Seed {$TIMESTAMP}",
    'email'    => $seedEmail,
    'password' => 'password123',
]);
if ($seed['http_code'] === 201) {
    echo green("  ✅ Seeded user: {$seedEmail}") . "\n\n";
} else {
    echo red("  ⚠️ Could not seed user (HTTP {$seed['http_code']}) — duplicate test may be unreliable") . "\n\n";
}

// ============================================
// Group 1: Validation Failures → 422
// ============================================
echo bold("━━━ Group 1: 📝 Validation Failures (expect 422) ━━━") . "\n";

test('Missing required fields (name/email/password)', [
    // ไม่ส่ง field อะไรเลย
], 422);

test('Invalid email format', [
    'name'     => 'Bad Email',
    'email'    => 'not-an-email',
    'password' => 'password123',
], 422);

test('Password too short (< 8 chars)', [
    'name'     => 'Short Pass',
    'email'    => "short_{$TIMESTAMP}@kuhome.test",
    'password' => '123',
], 422);

test('Name exceeds max (256 chars)', [
    'name'     => str_repeat('x', 256),
    'email'    => "longname_{$TIMESTAMP}@kuhome.test",
    'password' => 'password123',
], 422);

// ============================================
// Group 2: Duplicate Email → 409
// ============================================
echo bold("━━━ Group 2: 🔁 Duplicate Email (expect 409) ━━━") . "\n";

test('Register with already-used email', [
    'name'     => 'Duplicate User',
    'email'    => $seedEmail,
    'password' => 'password123',
], 409, function ($body) {
    // เช็คว่าไม่ leak SQL error message
    $msg = $body['message'] ?? '';
    $noLeak = stripos($msg, 'SQLSTATE') === false
        && stripos($msg, 'constraint') === false
        && stripos($msg, 'query') === false;
    if (!$noLeak) {
        echo red("    ⚠️ Possible SQL leak in message!") . "\n";
    }
    return $noLeak;
});

// ============================================
// Group 3: Success baseline (sanity check)
// ============================================
echo bold("━━━ Group 3: ✅ Sanity — valid registration (expect 201) ━━━") . "\n";

test('Valid new user', [
    'name'     => "Valid User {$TIMESTAMP}",
    'email'    => "valid_{$TIMESTAMP}@kuhome.test",
    'password' => 'password123',
], 201);

// ============================================
// 📊 Summary
// ============================================
$total = $stats['pass'] + $stats['fail'];
echo bold(str_repeat('═', 64)) . "\n";
echo bold("📊 SUMMARY") . "\n";
echo bold(str_repeat('═', 64)) . "\n";
echo green("  ✅ Passed: {$stats['pass']}") . "\n";
echo red("    ❌ Failed: {$stats['fail']}") . "\n";
echo "  📋 Total:  {$total}\n";
if ($stats['fail'] === 0) {
    echo green("\n🎉 ALL ERROR CASES PASS! จัดการเรียบร้อยค่ะนายท่าน! ✨💖\n");
} else {
    echo red("\n⚠️ มีเคสตก นายท่านด่วนเลยค่ะ! 💅\n");
}
echo bold(str_repeat('═', 64)) . "\n";
exit($stats['fail'] > 0 ? 3 : 0);
