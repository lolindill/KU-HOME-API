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
 * 🎫 Google SSO exchange — mirror ชุดทดสอบ KU SSO ตาม wayfinder/google-integration ticket 06
 * (owner sign-off 2026-10-06) · Http::fake จำลอง Google token + userinfo endpoints
 *
 *    เคสเฉพาะ Google: email_verified fail-closed (research ticket 01 §3) · role = 'user'
 *    (ไม่ใช่ ku_member — Google ไม่พิสูจน์สถานะ มก.) · redirect_uri_mismatch → 500
 */
class GoogleSsoExchangeTest extends TestCase
{
    use RefreshDatabase;

    // 🔧 claim จำลอง — OIDC standard claims (research ticket 01 §3) · email_verified=true ทุกบัญชี Gmail แท้
    private const CLAIMS = [
        'sub' => 'google-sub-123',
        'email' => 'guest@gmail.com',
        'email_verified' => true,
        'name' => 'สมหญิง เดินทาง',
        'given_name' => 'สมหญิง',
        'family_name' => 'เดินทาง',
    ];

    private const ID_TOKEN = 'fake-google-id-token.jwt';

    // 🔑 PKCE verifier — 64 ตัวอักษร unreserved ตาม RFC 7636 §4.1 (ฝั่งจริง SPA สร้างเอง)
    private const CODE_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk-._~0123456789abcdefg';

    private function fakeHappyPath(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3599,
                'id_token' => self::ID_TOKEN,
                'scope' => 'openid https://www.googleapis.com/auth/userinfo.email profile',
            ]),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(self::CLAIMS),
        ]);
    }

    private function postExchange(string $code = 'one-time-code')
    {
        return $this->postJson('/api/v1/auth/sso/google/exchange', [
            'code' => $code,
            'code_verifier' => self::CODE_VERIFIER,
        ]);
    }

    // ✔ สำเร็จ: exchange สร้างใหม่ → role 'user' (ไม่ใช่ ku_member — ticket 06 ข้อ 1) + password สุ่ม
    public function test_exchange_creates_plain_user_on_first_login(): void
    {
        $this->fakeHappyPath();

        $response = $this->postExchange();

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['access_token', 'token_type', 'user', 'id_token'])
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'guest@gmail.com')
            ->assertJsonPath('user.role', 'user')
            ->assertJsonPath('user.auth_provider', 'google')
            ->assertJsonPath('id_token', self::ID_TOKEN);

        $user = User::where('email', 'guest@gmail.com')->where('auth_provider', 'google')->firstOrFail();

        // 🔐 password สุ่มทิ้ง — ไม่มีรหัสใด guess ผ่านได้
        $this->assertFalse(Hash::check('password123', $user->password));
        $this->assertStringStartsWith('$2y$', $user->password);
    }

    // ✔ สำเร็จ: login ครั้งที่ 2 → find ของเดิม (ไม่ duplicate, ไม่ overwrite name)
    public function test_exchange_second_login_reuses_same_user_without_overwriting_name(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'id_token' => 'jwt']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::sequence()
                ->push(self::CLAIMS)
                ->push([
                    'sub' => self::CLAIMS['sub'],
                    'email' => 'GUEST@GMAIL.COM',
                    'email_verified' => true,
                    'name' => 'ชื่อใหม่ล่าสุด',
                ]),
        ]);

        $this->postExchange();

        // ครั้งที่ 2: Google ส่งชื่อใหม่ + email ตัวพิมพ์ใหญ่ — ต้องยังเจอ user เดิม (email normalize lowercase)
        $response = $this->postExchange();

        $response->assertStatus(200);
        $this->assertCount(1, User::where('auth_provider', 'google')->get());
        $this->assertSame('สมหญิง เดินทาง', User::where('auth_provider', 'google')->firstOrFail()->name);
    }

    // ✔ split user: email ชนกับ password account → ได้ user ใหม่แยกกัน ไม่ error (req change 2026-09-01)
    public function test_exchange_with_email_matching_password_account_creates_separate_user(): void
    {
        $passwordUser = User::factory()->create(['email' => 'dup@gmail.com']);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'id_token' => 'jwt']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'other-google-sub',
                'email' => 'dup@gmail.com',
                'email_verified' => true,
                'name' => 'Split User',
            ]),
        ]);

        $response = $this->postExchange();

        $response->assertStatus(200)->assertJsonPath('user.role', 'user');
        $this->assertNotSame($passwordUser->id, $response->json('user.id'));
        $this->assertSame(2, User::where('email', 'dup@gmail.com')->count());

        // password account เดิมต้องไม่ถูกแตะ
        $this->assertSame('user', $passwordUser->fresh()->role);
        $this->assertSame('password', $passwordUser->fresh()->auth_provider);
    }

    // ✔ split user: email ชนกับ KU SSO account → แยกกัน (แต่ละ provider คนละ row เสมอ)
    public function test_exchange_with_email_matching_ku_sso_account_creates_separate_user(): void
    {
        User::factory()->create(['email' => 'dup@ku.th', 'auth_provider' => 'ku_sso']);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'id_token' => 'jwt']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'another-sub',
                'email' => 'dup@ku.th',
                'email_verified' => true,
                'name' => 'Both Providers',
            ]),
        ]);

        $this->postExchange()->assertStatus(200);

        $this->assertSame(2, User::where('email', 'dup@ku.th')->count());
    }

    // ✔ multi-device: login ซ้ำ → token เก่ายังใช้ได้ (ไม่ revoke — นโยบายเดียวกับ password login)
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

    // ✔ error map: invalid_grant → 422 (ไม่ retry — พร้อมไม่ leak error_description ของ Google)
    public function test_exchange_rejects_replayed_or_expired_code_with_422(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(
                ['error' => 'invalid_grant', 'error_description' => 'Code was already redeemed.'],
                400
            ),
        ]);

        $response = $this->postExchange();

        $response->assertStatus(422)->assertJsonPath('status', 'error');
        $this->assertStringNotContainsString('already redeemed', (string) $response->json('message'));
        $this->assertSame(0, User::count());
    }

    // ✔ email fail-closed (ticket 06 ข้อ 5): email_verified=false → 422 ไม่สร้าง user
    public function test_exchange_fails_closed_when_email_not_verified(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'id_token' => 'jwt']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'unverified-sub',
                'email' => 'unverified@gmail.com',
                'email_verified' => false,
                'name' => 'ยังไม่ยืนยัน',
            ]),
        ]);

        $this->postExchange()
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertSame(0, User::count());
    }

    // ✔ email fail-closed: ไม่มี claim email เลย → 422 (claim ทุกตัวยกเว้น sub nullable ได้)
    public function test_exchange_fails_closed_when_userinfo_has_no_email(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'id_token' => 'jwt']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'no-email-sub',
                'name' => 'ไม่มีอีเมล',
            ]),
        ]);

        $this->postExchange()
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertSame(0, User::count());
    }

    // ✔ email_verified tolerant boolean: string "true" ผ่าน (contract ticket 06 ข้อ 5)
    public function test_exchange_accepts_string_true_email_verified(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'id_token' => 'jwt']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'string-bool-sub',
                'email' => 'string.bool@gmail.com',
                'email_verified' => 'true',
                'name' => 'String Bool',
            ]),
        ]);

        $this->postExchange()
            ->assertStatus(200)
            ->assertJsonPath('user.auth_provider', 'google');
    }

    // ✔ error map: invalid_client → 500 + Log::error (config ฝั่งเราพัง)
    public function test_exchange_invalid_client_returns_500_and_logs_error(): void
    {
        Log::spy();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(
                ['error' => 'invalid_client', 'error_description' => 'Unauthorized'],
                401
            ),
        ]);

        $response = $this->postExchange();

        $response->assertStatus(500)->assertJsonPath('status', 'error');
        Log::assertLogged('error');
        $this->assertSame(0, User::count());
    }

    // ✔ error map: redirect_uri_mismatch ที่ token endpoint → 500 (config ไม่ตรงกันระหว่าง SPA/API — ไม่ใช่ความผิด user)
    public function test_exchange_redirect_uri_mismatch_returns_500(): void
    {
        Log::spy();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(
                ['error' => 'redirect_uri_mismatch', 'error_description' => 'Bad Request'],
                400
            ),
        ]);

        $response = $this->postExchange();

        $response->assertStatus(500)->assertJsonPath('status', 'error');
        Log::assertLogged('error');
        $this->assertSame(0, User::count());
    }

    // ✔ error map: Google 500 → 502
    public function test_exchange_returns_502_when_google_responds_500(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response('Internal Server Error', 500),
        ]);

        $this->postExchange()
            ->assertStatus(502)
            ->assertJsonPath('status', 'error');
    }

    // ✔ error map: Google ล่ม/timeout/network → 502
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

        $this->postJson('/api/v1/auth/sso/google/exchange', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        Http::assertNothingSent();
    }

    // ✔ PKCE relay: ไม่ส่ง code_verifier → 422 ก่อนยิงออกนอก
    public function test_exchange_requires_code_verifier(): void
    {
        Http::fake();

        $this->postJson('/api/v1/auth/sso/google/exchange', ['code' => 'one-time-code'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code_verifier']);

        Http::assertNothingSent();
    }

    // ✔ PKCE relay: backend ต้องส่ง code_verifier + client_secret ต่อไปที่ token endpoint (client_secret_post)
    public function test_exchange_relays_code_verifier_and_secret_to_token_endpoint(): void
    {
        config()->set('google_sso.client_id', 'test-client-id');
        config()->set('google_sso.client_secret', 'test-client-secret');
        config()->set('google_sso.redirect_uri', 'https://www.ku-home.ku.ac.th');
        $this->fakeHappyPath();

        $this->postExchange()->assertStatus(200);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['code_verifier'] === self::CODE_VERIFIER
                && $request['client_id'] === 'test-client-id'
                && $request['client_secret'] === 'test-client-secret'
                && $request['redirect_uri'] === 'https://www.ku-home.ku.ac.th'
                && $request['grant_type'] === 'authorization_code';
        });
    }

    // ✔ userinfo ต้องถูกยิงด้วย Bearer access_token ที่ได้จาก token endpoint
    public function test_exchange_calls_userinfo_with_bearer_token(): void
    {
        $this->fakeHappyPath();

        $this->postExchange()->assertStatus(200);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://openidconnect.googleapis.com/v1/userinfo'
                && $request->header('Authorization')[0] === 'Bearer google-access-token';
        });
    }

    // ✔ logout SSO user → currentAccessToken ถูกลบ (Google ไม่มี END_SESSION — logout purely local)
    public function test_google_sso_user_logout_revokes_current_token(): void
    {
        $this->fakeHappyPath();
        $token = $this->postExchange()->json('access_token');
        $user = User::where('email', 'guest@gmail.com')->firstOrFail();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/logout')->assertStatus(200);

        // 🧪 assert ที่ DB level — ข้าม request ภายใน test เดียวกัน (Sanctum guard cache user ไว้)
        $this->assertSame(0, $user->tokens()->count());

        $this->app->make('auth')->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')->assertStatus(401);
    }

    // ✔ throttle 5,1: request ที่ 6 → 429
    public function test_exchange_is_throttled_after_5_requests_per_minute(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postExchange()->assertStatus(422);
        }

        $this->postExchange()->assertStatus(429);
    }
}
