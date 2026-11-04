<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 🎫 KU SSO exchange — ชุดทดสอบตาม wayfinder/ku-sso ticket 07 (owner sign-off 2026-09-07)
 *
 *    Http::fake จำลอง Keycloak (token + userinfo) — happy path กับ claims จริงรอ live-verify
 *    หลัง ticket 06 (client จริง) แล้ว manual ตรวจครั้งเดียว
 */
class SsoExchangeTest extends TestCase
{
    use RefreshDatabase;

    // 🔧 claim จำลอง — รูปแบบ claims จริง verified-live แล้ว 2026-09-08 (ticket 02 Amendment 2)
    private const CLAIMS = [
        'sub' => 'ku-user-123',
        'email' => 'somchai.j@ku.th',
        'name' => 'สมชาย ใจดี',
        'thainame' => 'สมชาย ใจดี',
    ];

    private const ID_TOKEN = 'fake-id-token.jwt';

    // 🔑 PKCE verifier (ticket 10) — 64 ตัวอักษร unreserved [A-Za-z0-9-._~] ตาม RFC 7636 §4.1 (ฝั่งจริง SPA สร้างเอง)
    private const CODE_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk-._~0123456789abcdefg';

    private function fakeHappyPath(): void
    {
        Http::fake([
            '*/protocol/openid-connect/token' => Http::response([
                'access_token' => 'ku-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 300,
                'id_token' => self::ID_TOKEN,
                'scope' => 'basic openid',
            ]),
            '*/protocol/openid-connect/userinfo' => Http::response(self::CLAIMS),
        ]);
    }

    private function postExchange(string $code = 'one-time-code')
    {
        return $this->postJson('/api/v1/auth/sso/exchange', [
            'code' => $code,
            'code_verifier' => self::CODE_VERIFIER,
        ]);
    }

    // ✔ สำเร็จ: exchange สร้างใหม่ → role ku_member + password สุ่ม (Hash::check ต้อง fail)
    public function test_exchange_creates_ku_member_user_on_first_login(): void
    {
        $this->fakeHappyPath();

        $response = $this->postExchange();

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['access_token', 'token_type', 'user', 'id_token'])
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'somchai.j@ku.th')
            ->assertJsonPath('user.role', 'ku_member')
            ->assertJsonPath('user.auth_provider', 'ku_sso')
            ->assertJsonPath('id_token', self::ID_TOKEN);

        $user = User::where('email', 'somchai.j@ku.th')->where('auth_provider', 'ku_sso')->firstOrFail();

        // 🔐 password สุ่มทิ้ง (decision ticket 02) — ไม่มีรหัสใด guess ผ่านได้
        $this->assertFalse(Hash::check('password123', $user->password));
        $this->assertStringStartsWith('$2y$', $user->password);
    }

    // ✔ สำเร็จ: SSO ครั้งที่ 2 → find ของเดิม (ไม่ duplicate, ไม่ overwrite name)
    public function test_exchange_second_login_reuses_same_user_without_overwriting_name(): void
    {
        // ⚠️ อย่าเรียก Http::fake() ซ้ำใน test เดียว — stub แบบ array คือ "append" และ stub ตัวแรกชนะเสมอ
        //   (PendingRequest::buildStubHandler ใช้ ->first()) — คำขอครั้งที่ 2 ใช้ Http::sequence() แทน
        Http::fake([
            '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'id_token' => 'jwt']),
            '*/protocol/openid-connect/userinfo' => Http::sequence()
                ->push(self::CLAIMS)
                ->push([
                    'sub' => self::CLAIMS['sub'],
                    'email' => 'SOMCHAI.J@KU.TH',
                    'name' => 'ชื่อใหม่ล่าสุด',
                ]),
        ]);

        $this->postExchange();

        // ครั้งที่ 2: KU ส่งชื่อใหม่ + email ตัวพิมพ์ใหญ่ — ต้องยังเจอ user เดิม (email normalize lowercase)
        $response = $this->postExchange();

        $response->assertStatus(200);
        $this->assertCount(1, User::where('auth_provider', 'ku_sso')->get());
        $this->assertSame('สมชาย ใจดี', User::where('auth_provider', 'ku_sso')->firstOrFail()->name);
    }

    // ✔ split user: email ชนกับ password account → ได้ user ใหม่แยกกัน ไม่ error (ticket 02)
    public function test_exchange_with_email_matching_password_account_creates_separate_user(): void
    {
        $passwordUser = User::factory()->create(['email' => 'dup@ku.th']);

        Http::fake([
            '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'id_token' => 'jwt']),
            '*/protocol/openid-connect/userinfo' => Http::response([
                'sub' => 'other-ku-sub',
                'email' => 'dup@ku.th',
                'name' => 'Split User',
            ]),
        ]);

        $response = $this->postExchange();

        $response->assertStatus(200)->assertJsonPath('user.role', 'ku_member');
        $this->assertNotSame($passwordUser->id, $response->json('user.id'));
        $this->assertSame(2, User::where('email', 'dup@ku.th')->count());

        // password account เดิมต้องไม่ถูกแตะ
        $this->assertSame('user', $passwordUser->fresh()->role);
        $this->assertSame('password', $passwordUser->fresh()->auth_provider);
    }

    // ✔ multi-device: SSO login ซ้ำ → token เก่ายังใช้ได้ (ไม่ revoke — ticket 03)
    public function test_exchange_repeat_login_keeps_previous_tokens_valid(): void
    {
        $this->fakeHappyPath();
        $firstToken = $this->postExchange()->json('access_token');
        $secondToken = $this->postExchange()->json('access_token');

        $this->assertNotSame($firstToken, $secondToken);

        $this->withHeader('Authorization', "Bearer {$firstToken}")
            ->getJson('/api/v1/me')->assertStatus(200);
        $this->withHeader('Authorization', "Bearer {$secondToken}")
            ->getJson('/api/v1/me')->assertStatus(200);
    }

    // ✔ error map: invalid_grant → 422 (ไม่ retry — พร้อมไม่ leak error_description ของ Keycloak)
    public function test_exchange_rejects_replayed_or_expired_code_with_422(): void
    {
        Http::fake([
            '*/protocol/openid-connect/token' => Http::response(
                ['error' => 'invalid_grant', 'error_description' => 'Code already redeemed'],
                400
            ),
        ]);

        $response = $this->postExchange();

        $response->assertStatus(422)->assertJsonPath('status', 'error');
        $this->assertStringNotContainsString('Code already redeemed', (string) $response->json('message'));
        $this->assertSame(0, User::count());
    }

    // ✔ error map: userinfo ไม่มี email → 422 fail-closed (ticket 02)
    public function test_exchange_fails_closed_when_userinfo_has_no_email(): void
    {
        Http::fake([
            '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'id_token' => 'jwt']),
            '*/protocol/openid-connect/userinfo' => Http::response([
                'sub' => 'no-email-sub',
                'thainame' => 'ไม่มีอีเมล',
                // verified-live 2026-09-08 (ticket 02 Amendment 2): KU อาจไม่คืน email-ish claim เลย
                // → chain email→google-mail→office365-mail ไม่เจออะไร → fail-closed 422 ตาม decision ticket 02
            ]),
        ]);

        $this->postExchange()
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertSame(0, User::count());
    }

    // ✔ email chain (ticket 10): นิสิตไม่มี `email` — fallback รับจาก `google-mail` (ticket 02 Amendment 2)
    public function test_exchange_falls_back_to_google_mail_claim_for_students(): void
    {
        Http::fake([
            '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'id_token' => 'jwt']),
            '*/protocol/openid-connect/userinfo' => Http::response([
                'sub' => 'student-sub',
                'name' => 'นิสิต เรียนดี',
                'google-mail' => 'STUDENT.D@ku.th',
            ]),
        ]);

        $this->postExchange()
            ->assertStatus(200)
            ->assertJsonPath('user.email', 'student.d@ku.th') // normalize lowercase เหมือน email หลัก
            ->assertJsonPath('user.role', 'ku_member')
            ->assertJsonPath('user.auth_provider', 'ku_sso');
    }

    // ✔ email chain (ticket 10): ลำดับถูกต้อง — `email` (บุคลากร) ชนะ fallback เสมอ
    public function test_exchange_prefers_email_claim_over_fallback_claims(): void
    {
        Http::fake([
            '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'id_token' => 'jwt']),
            '*/protocol/openid-connect/userinfo' => Http::response([
                'sub' => 'staff-sub',
                'name' => 'อาจารย์ สอนดี',
                'email' => 'staff.e@ku.ac.th',
                'google-mail' => 'staff.e@ku.th',
                'office365-mail' => 'staff.e@live.ku.th',
            ]),
        ]);

        $this->postExchange()
            ->assertStatus(200)
            ->assertJsonPath('user.email', 'staff.e@ku.ac.th');
    }

    // ✔ email chain (ticket 10): fallback สุดท้าย `office365-mail` (@live.ku.th) → ผ่าน
    public function test_exchange_falls_back_to_office365_mail_claim_when_others_missing(): void
    {
        Http::fake([
            '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok', 'id_token' => 'jwt']),
            '*/protocol/openid-connect/userinfo' => Http::response([
                'sub' => 'student2-sub',
                'name' => 'นิสิต สอง',
                'office365-mail' => 'STUDENT.E@live.ku.th',
            ]),
        ]);

        $this->postExchange()
            ->assertStatus(200)
            ->assertJsonPath('user.email', 'student.e@live.ku.th'); // normalize lowercase เหมือน email หลัก
    }

    // ✔ error map: invalid_client → 500 + Log::error (config ฝั่งเราพัง)
    public function test_exchange_invalid_client_returns_500_and_logs_error(): void
    {
        Log::spy();

        Http::fake([
            '*/protocol/openid-connect/token' => Http::response(
                ['error' => 'invalid_client', 'error_description' => 'Invalid client or Invalid client credentials'],
                401
            ),
        ]);

        $response = $this->postExchange();

        $response->assertStatus(500)->assertJsonPath('status', 'error');
        Log::assertLogged('error');
        $this->assertSame(0, User::count());
    }

    // ✔ error map: Keycloak 500 → 502
    public function test_exchange_returns_502_when_keycloak_responds_500(): void
    {
        Http::fake([
            '*/protocol/openid-connect/token' => Http::response('Internal Server Error', 500),
        ]);

        $this->postExchange()
            ->assertStatus(502)
            ->assertJsonPath('status', 'error');
    }

    // ✔ error map: Keycloak ล่ม/timeout/network → 502
    public function test_exchange_returns_502_on_connection_failure(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Connection timed out');
        });

        $this->postExchange()
            ->assertStatus(502)
            ->assertJsonPath('status', 'error');
    }

    // ✔ error map: validation `code` → 422 (ไม่ยิงออกนอก)
    public function test_exchange_validates_code_field(): void
    {
        Http::fake();

        $this->postJson('/api/v1/auth/sso/exchange', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        Http::assertNothingSent();
    }

    // ✔ PKCE relay (ticket 10): ไม่ส่ง code_verifier → 422 ก่อนยิงออกนอก (KU enforce S256)
    public function test_exchange_requires_code_verifier(): void
    {
        Http::fake();

        $this->postJson('/api/v1/auth/sso/exchange', ['code' => 'one-time-code'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code_verifier']);

        Http::assertNothingSent();
    }

    // ✔ PKCE relay (ticket 10): backend ต้องส่ง code_verifier ต่อไปที่ token endpoint พร้อม code
    public function test_exchange_relays_code_verifier_to_token_endpoint(): void
    {
        $this->fakeHappyPath();

        $this->postExchange()->assertStatus(200);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/protocol/openid-connect/token')
                && $request['code_verifier'] === self::CODE_VERIFIER;
        });
    }

    // ✔ logout SSO user → currentAccessToken ถูกลบ (v1 ไม่มี END_SESSION ให้ทดสอบ — defer โดย ticket 03)
    public function test_sso_user_logout_revokes_current_token(): void
    {
        $this->fakeHappyPath();
        $token = $this->postExchange()->json('access_token');
        $user = User::where('email', 'somchai.j@ku.th')->firstOrFail();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/logout')->assertStatus(200);

        // 🧪 assert ที่ DB level — ไม่ยิง request ที่สอง เพราะ Sanctum guard cache user ไว้
        // ข้าม request ภายใน test เดียวกัน (prod เป็น process ใหม่ทุก request จึงไม่มีพฤติกรรมนี้)
        $this->assertSame(0, $user->tokens()->count());

        // เคลียร์ guard cache แล้วยิงซ้ำด้วย token เดิม → ต้อง 401 (revoked จริง)
        $this->app->make('auth')->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')->assertStatus(401);
    }

    // ✔ throttle 5,1: request ที่ 6 → 429
    public function test_exchange_is_throttled_after_5_requests_per_minute(): void
    {
        Http::fake([
            '*/protocol/openid-connect/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postExchange()->assertStatus(422);
        }

        $this->postExchange()->assertStatus(429);
    }

    // 🎫 admin list (ticket 09): โชว์ auth_provider + filter ?auth_provider=
    public function test_admin_user_list_filters_by_auth_provider(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['email' => 'sso-only@ku.th', 'auth_provider' => 'ku_sso']);
        User::factory()->create(['email' => 'pwd@ku.th']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/users?auth_provider=ku_sso')
            ->assertStatus(200)
            ->assertJsonCount(1, 'users.data')
            ->assertJsonPath('users.data.0.auth_provider', 'ku_sso')
            ->assertJsonPath('users.data.0.email', 'sso-only@ku.th');
    }
}
