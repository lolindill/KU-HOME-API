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

    // 🔧 claim จำลอง — ชื่อ/email จริงของ scope `basic` ยัง unverified (amendment ใน ticket 02)
    private const CLAIMS = [
        'sub' => 'ku-user-123',
        'email' => 'somchai.j@ku.th',
        'name' => 'สมชาย ใจดี',
        'thainame' => 'สมชาย ใจดี',
    ];

    private const ID_TOKEN = 'fake-id-token.jwt';

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
        return $this->postJson('/api/v1/auth/sso/exchange', ['code' => $code]);
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
        $this->fakeHappyPath();
        $this->postExchange();

        // ครั้งที่ 2: KU ส่งชื่อใหม่ + email ตัวพิมพ์ใหญ่ — ต้องยังเจอ user เดิม (email normalize lowercase)
        Http::fake([
            '*/protocol/openid-connect/token' => Http::response(['access_token' => 'tok-2', 'id_token' => 'jwt-2']),
            '*/protocol/openid-connect/userinfo' => Http::response([
                'sub' => self::CLAIMS['sub'],
                'email' => 'SOMCHAI.J@KU.TH',
                'name' => 'ชื่อใหม่ล่าสุด',
            ]),
        ]);

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
            // scope `basic` ของ KU อาจไม่คืน email (amendment ticket 02) — fail-closed ก่อน live-verify
            '*/protocol/openid-connect/userinfo' => Http::response([
                'sub' => 'no-email-sub',
                'thainame' => 'ไม่มีอีเมล',
            ]),
        ]);

        $this->postExchange()
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertSame(0, User::count());
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
