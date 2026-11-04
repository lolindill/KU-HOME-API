# Research: Gmail transport options for outbound email from Laravel

- **Ticket:** `wayfinder/google-integration/tickets/03-gmail-transport-options.md`
- **Date:** 2026-09-11
- **Status:** RESOLVED — **แนะนำ Option A (Gmail SMTP + App Password) เป็น v1**: ไม่เพิ่ม package, config แค่ `.env`, ใช้ `Mail::queue` กับ queue worker `database` ที่มีอยู่ — โดย **From ต้องเป็น gmail.com จริง** (ส่ง "ในนาม" `@ku.ac.th` ผ่าน Gmail ทำไม่ได้จริง — DMARC alignment และ Gmail จะเลิก support "Send as" third-party address ตั้งแต่ Jan 2027)
- **Scope checked (read-only):** `config/mail.php`, `composer.json`, `composer.lock`, `.env`, `database/migrations/0001_01_01_000002_create_jobs_table.php` — nothing modified.

---

## 0. Repo facts (verified from the repo)

- **`config/mail.php`** = Laravel default ครบถ้วน — `'default' => env('MAIL_MAILER', 'log')`, mailer `smtp` อ่าน `MAIL_SCHEME`/`MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/`MAIL_PASSWORD` (default `127.0.0.1:2525`), global `'from'` อ่าน `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME`. Supported transports: `smtp, sendmail, mailgun, ses, ses-v2, postmark, resend, log, array, failover, roundrobin` — **ไม่มี transport "gmail" พิเศษ** (Laravel ใช้ Symfony Mailer ใต้ hood — https://laravel.com/docs/13.x/mail)
- **`.env` ปัจจุบัน:** `MAIL_MAILER=log`, `QUEUE_CONNECTION=database` → มี queue worker รันอยู่แล้ว (composer run dev มี `queue:listen`)
- **`database/migrations/0001_01_01_000002_create_jobs_table.php`:** มีตาราง `jobs`, `job_batches`, `failed_jobs` ครบ — queued mailable ใช้ได้ทันทีไม่ต้อง migrate
- **`composer.lock`:** ไม่มี `google/apiclient`, ไม่มี mail provider package (`symfony/mailgun-mailer`/`symfony/postmark-mailer` ฯลฯ ไม่มี) — `symfony/mailer` เป็น transitive dep ของ `laravel/framework` อยู่แล้ว (SMTP ใช้ได้เลย ไม่เพิ่ม dependency), มี `guzzlehttp/guzzle` (transitive)
- **ไม่มี `app/Mail/` directory** — ยังไม่เคยมี Mailable (`make:mail` จะสร้างให้เอง ตาม docs: "Don't worry if you don't see this directory... it will be generated for you" — https://laravel.com/docs/13.x/mail#generating-mailables)

---

## 1. Option A — Gmail SMTP + App Password ⭐ (แนะนำสำหรับ v1)

### 1.1 Prerequisites

| ข้อกำหนด | รายละเอียด | Source |
|---|---|---|
| บัญชีต้องเปิด **2-Step Verification** | "App passwords can only be used with accounts that have 2-Step Verification turned on" | https://support.google.com/accounts/answer/185833 |
| สร้าง App Password ที่ `myaccount.google.com/apppasswords` | เป็น "16-digit passcode"; สร้างใหม่ได้เรื่อยๆ, revoke ทีละตัวได้ | เดียวกัน |
| บัญชีองค์กร/school อาจไม่มีทางเลือกนี้ | "You're logged into a work, school, or another organization account" ทำให้เมนูหาย — ใช้ **consumer gmail.com account** ที่ทีมเป็นเจ้าของเองจึงปลอดภัยกว่า | เดียวกัน |
| Advanced Protection / security-key-only 2SV | บัญชีแบบนี้ **บล็อก** App Password | เดียวกัน |
| **ห้ามใช้ password ปกติ** ยิง SMTP | "Gmail no longer supports third-party apps or devices which require you to share your Google username and password" — password ปกติจะโดน reject (`Username and password not accepted` / `Invalid credentials`) | https://support.google.com/mail/answer/7126229 |

**Policy note (สำคัญต่อความยั่งยืน):** Google เปลี่ยน stance เรื่อยมา — ปิด "Less secure apps" ไปแล้ว (เหลือทางเดียวคือ App Password หรือ OAuth) และหน้า official ปัจจุบันระบุชัดว่า *"App passwords aren't recommended and are unnecessary in most cases"* (https://support.google.com/accounts/answer/185833) — แปลว่าทางนี้ **ใช้งานได้จริงวันนี้** แต่เป็น path ที่ Google "ไม่แนะนำ" และอาจถูกตัดสิทธิ์ในอนาคต (ความเสี่ยงระยะยาวของ Option นี้ — ถ้าโดนตัด ต้องย้ายไป OAuth/Option B หรือ provider อื่น)

### 1.2 Laravel config

Host/port: `smtp.gmail.com` — port **587 (STARTTLS)** คือ convention มาตรฐาน (หน้า SMTP relay ของ Google เองใช้ "smtp-relay.gmail.com บนพอร์ต 587" เป็น TLS mode; send-as page ระบุ supported combinations: "SSL with port 465", "TLS with port 25 or 587" — https://knowledge.workspace.google.com/admin/gmail/advanced/route-outgoing-smtp-relay-messages-through-google, https://support.google.com/mail/answer/22370). ใน Laravel 13 ที่ `config/mail.php` มี key `scheme`:

- port **587 + STARTTLS** → ไม่ต้องตั้ง `MAIL_SCHEME` (คง default ของ Symfony = STARTTLS)
- port **465 (implicit TLS)** → ต้องตั้ง `MAIL_SCHEME=tls`

`.env` template (Option A):

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
# MAIL_SCHEME=          # เว้นว่าง = STARTTLS (587); ถ้าใช้ 465 ให้เป็น tls
MAIL_USERNAME=kuhome.noreply@gmail.com   # full address เท่านั้น
MAIL_PASSWORD=<app-password-16-char>     # App Password 16 ตัว เว้นวรรคได้/ไม่ได้ก็ได้
MAIL_FROM_ADDRESS=kuhome.noreply@gmail.com
MAIL_FROM_NAME="${APP_NAME}"
```

> ⚠️ หลังแก้ `.env` ต้อง **restart queue worker** (`queue:listen` ไม่ reload อัตโนมัติ) และ config ถูก cache ถ้ารัน `config:cache` ต้อง re-cache

### 1.3 Limits จริง (consumer Gmail)

| Metric | ค่า | Source |
|---|---|---|
| อีเมล/วัน | **~500 emails/day** | https://support.google.com/mail/answer/22839 |
| ผู้รับ/ฉบับเดียว | **~500 recipients** (รวม To+Cc+Bcc) | เดียวกัน |
| นับ **ทั้ง messages และ recipients** | "Gmail caps the number of emails you can send or get per day, and the number of people you can add as recipients" | เดียวกัน |
| โดน limit แล้ว? | **ถูก block ชั่วคราว 1–24 ชม.** ไม่ใช่ ban ถาวร — "you should be able to send emails again within 1 to 24 hours" | เดียวกัน |
| Error ที่เห็น | UI: "You have reached a limit for sending mail"; ผ่าน SMTP เป็น SMTP error (รหัสที่พบบ่อย `550 5.4.5 Daily user sending quota exceeded` **[UNVERIFIED]** — หน้า official ปัจจุบัน (migrated ไป knowledge.workspace.google.com) ไม่ quote รหัส SMTP ตรงๆ แล้ว แต่ยืนยันว่า lockout ≤ 24 ชม.) | https://support.google.com/mail/answer/22839 |

**สำหรับ Workspace account ถ้าใครได้บัญชี Workspace มาใช้:** 2,000 messages/day, recipients/message ผ่าน SMTP (IMAP client) = **100 RCPT TO เท่านั้น**, ผ่าน Gmail API = 500, trial account = 500/day, รวม recipients/day = 10,000 (external 3,000) — https://knowledge.workspace.google.com/admin/gmail/gmail-sending-limits-in-google-workspace

### 1.4 ความเสี่ยงด้าน security

- App Password คือ **credential จริงที่มีสิทธิ์เต็มของบัญชี Gmail นั้น** (ผ่าน SMTP/IMAP ได้ ไม่ใช่แค่ส่งเมล) — ถ้า `.env`/server โดน leak = ผู้โจมตีอ่าน-ส่ง-ลบเมลทั้งบัญชีได้ จนกว่าจะ revoke
- **Mitigation:** (1) ใช้ **บัญชี gmail.com แยกต่างหาก** ที่ไม่ใช่บัญชีบุคคล/ไม่ผูกข้อมูลอื่น — blast radius เหลือแค่ "ส่งเมลระบบ"; (2) label/revoke ได้ทีละตัวจาก `myaccount.google.com/apppasswords`; (3) **rotate เมื่อ:** พนักงานเปลี่ยน, เตือน Google security alert ผิดปกติ, หรือทุกครั้งที่สงสัย leak — การเปลี่ยน password Google ของบัญชีนั้นจะ **revoke App Password ทั้งหมดโดยอัตโนมัติ** ("Passwords are revoked when your Google password changes" — answer/185833); (4) server ต้องเก็บ `.env` สิทธิ์เข้มงวดตามปกติของ repo
- ไม่มี scope จำกัดแบบ OAuth (App Password ข้าม concept ของ scope ไปเลย) — จุดอ่อนเชิง design ที่ยอมรับได้เพราะบัญชีแยก + low volume

---

## 2. Option B — Gmail API (`google/apiclient`) หรือ SMTP XOAUTH2

### 2.1 กลไก

1. สร้าง **OAuth client (Desktop/Web) ใน Google Cloud Console** + enable Gmail API + scope `https://mail.google.com/` (full) หรือ `.../gmail.send`
2. **Consent dance ครั้งเดียว** (offline access): redirect ไป Google login → user กดยอมรับ → ได้ `authorization code` → exchange เป็น **refresh token** (ต้องขอ scope `offline_access`/`access_type=offline`) → เก็บ refresh token ไว้ใน `.env`/DB ตลอดชีวิต (source: https://developers.google.com/workspace/gmail/api/auth/about-auth — "This credential requires user consent")
3. Runtime: refresh token → access token → เรียก `users.messages.send` (MIME RFC 2822 encode base64url — https://developers.google.com/workspace/gmail/api/guides/sending) **หรือ** ใช้ token ตัวเดียวกันยิง **SMTP XOAUTH2** กับ `smtp.gmail.com`
4. ทางสายกลางใน Symfony/Laravel: มี bridge **`symfony/google-mailer`** ให้ transport `gmail://` (XOAUTH2) ผูกกับ google/apiclient ได้ — Laravel ต่อผ่าน `Mail::extend()` (รูปแบบ custom transport: https://laravel.com/docs/13.x/mail#custom-transports)

### 2.2 Quota จริง

- **Gmail sending limit ยังคง bind เสมอ** ไม่ว่า transport ไหน: Workspace หน้า sending limits ระบุ "ผ่าน Gmail API: 500 recipients/message" และ 2,000 messages/day ต่อ user (https://knowledge.workspace.google.com/admin/gmail/gmail-sending-limits-in-google-workspace) — consumer ก็ 500/day เหมือนกัน → **API ไม่ได้เพิ่ม quota การส่งเลย** แค่เปลี่ยนวิธีเดินทาง
- **Quota units:** อดีต official กำหนด daily quota units ต่อ project + per-user rate limit เป็น quota units/user/second พร้อมตารางต้นทุนต่อ method (`messages.send` แพงสุดพวก write) — **แต่ Google ได้ถอดหน้า public quota doc ออกแล้ว** (`developers.google.com/gmail/api/guides/quota` → 404 ทั้ง live และ archive; quota ปัจจุบันดูได้เฉพาะใน Cloud Console → APIs & Services → Gmail API → Quotas) → ตัวเลข "1,000,000,000 units/day per project · 250 units/user/sec · send = 250 units" ที่ลอยอยู่ใน blog ทั่วไป **[UNVERIFIED]** สำหรับปัจจุบัน — สรุปเชิงปฏิบัติ: ที่ volume ต่ำกว่า 500 ฉบับ/วัน quota units ของ API **ไม่ใช่จุดตาย** จุดตายคือ sending limit ข้อ 2,000/500 นั่นเอง
- เกิน quota API → HTTP `403 rateLimitExceeded` / `429` [UNVERIFIED — หน้า quota public ถูกถอดแล้ว]

### 2.3 Service account ส่งแทน Gmail user ธรรมดาได้ไหม? — **ไม่ได้**

Google's identity docs (official): "By themselves, service accounts cannot be used to access user data customarily accessed using Google Workspace APIs... a service account can access user data by implementing **domain-wide delegation of authority**" ซึ่ง (1) มีเฉพาะ **Google Workspace org** เท่านั้น และ (2) ต้องให้ **super admin** เปิดให้ (https://developers.google.com/identity/protocols/oauth2/service-account, https://developers.google.com/workspace/gmail/api/auth/about-auth) → กับ **consumer gmail.com account ธรรมดา = ทางตัน** ต้องใช้ OAuth แบบ per-user consent เสมอ → ทีมงานต้องมีคนกด consent ครั้งแรกด้วยบัญชีระบบจริง

### 2.4 Dependency weight (verified from packagist)

`google/apiclient` **v2.19.4 (2026-06-29)**, PHP `^8.1`, require: `firebase/php-jwt`, **`google/apiclient-services ~0.350`** (ตัวนี้คือภาระหลัก — service definition ของ Google API ทั้งชุด หลายสิบ MB ทั้งที่ใช้แค่ Gmail), `google/auth`, `guzzlehttp/guzzle`, `guzzlehttp/psr7`, `monolog/monolog` (https://packagist.org/packages/google/apiclient) → เพิ่ม **≥6 packages ใหม่** เพื่อแทนที่สิ่งที่ SMTP ทำได้ด้วย **0 package**

### 2.5 ขัด standing decision "0 package" ของ auth ไหม?

**ไม่ขัดในทาง formal** — decision ใน `wayfinder/ku-sso/research/integration-approach.md` เป็นเรื่อง **auth/SSO domain** (ห้ามดึง OAuth framework เข้าสู่ auth path เพราะ flow สั้น + ต้อง test ด้วย `Http::fake()`) ส่วน mail เป็น domain คนละเรื่อง ตัดสินแยกได้ถูกต้อง ✅ — **แต่** argument ที่ชนะ Option B ไม่ใช่ precedent แต่เป็น: consent dance ต้องมีคนไปกด + เก็บ refresh token เอง + Google ถอด quota doc public ออก (ความชัดเจนของ contract ลดลง) + 6 packages เพิ่ม + **limit การส่งเท่าเดิม** — ได้อะไรคืน? แค่ไม่ต้องพึ่ง App Password ที่ Google "ไม่แนะนำ" — จึงเป็น **plan B เมื่อ App Password โดนตัด** ไม่ใช่ v1

---

## 3. Option C — Google Workspace SMTP relay

ตาม official relay page (https://support.google.com/a/answer/2956491 → migrated: https://knowledge.workspace.google.com/admin/gmail/advanced/route-outgoing-smtp-relay-messages-through-google):

- **ต้องมี Workspace domain + admin console ของ domain นั้น** — config relay อยู่ใน Admin console (Apps → Gmail → Routing) เท่านั้น
- Host `smtp-relay.gmail.com` port **587 + TLS** (หรือ 25/465/587 แบบไม่เข้ารหัส + auth ด้วย **IP whitelist** แทน — "Without TLS encryption... you must authenticate by IP address"); แนะนำใช้ public IP ของ server เอง
- Limits: **10,000 messages/24h per user · 100 recipients per SMTP transaction · org-wide 4.6M/day** — relay limits **แยกอิสระ** จาก Gmail user limits
- Envelope From ต้องเป็น domain ที่ configured (มี option "Any address" แต่ "not recommended")

**ใช้กับ `@ku.ac.th` ได้ไหม?** เทคนิค: ได้ — ถ้า ku.ac.th เป็น Workspace และ admin เปิด relay + whitelist IP ของ KU server ให้ (แถมเมลจะถูก sign ในนาม domain ได้ → ผ่าน DMARC alignment สวยงาม) — **แต่ assumption สำคัญ (state ชัด): ทีม KU HOME ไม่ได้ควบคุม Workspace admin ของ ku.ac.th** (admin console อยู่กับศูนย์คอม/IT กลางของ มก.) → Option C จึงเป็น **"ทางที่ถูกต้องที่สุดในอุดมคติ แต่ไม่อยู่ในอำนาจตัดสินใจของทีม"** — ถ้าวันหน้าขอสิทธิ์นี้ได้ ควรก้าวไป C ทันที (พร้อมชื่อ sender `@ku.ac.th` แท้) ส่วน legacy `aspmx.l.google.com` (Gmail SMTP server แบบเก่า) จำกัดการ deliver เฉพาะผู้รับบนโดเมนที่ Google host ไม่ใช่ relay outbound ทั่วไป → ไม่เข้าทางใช้งานเรา

---

## 4. ส่ง "ในนาม" `@ku.ac.th` ผ่าน Gmail ธรรมดา — ทำไมไม่ realistic

### 4.1 ฟีเจอร์ "Send mail as" มีข้อจำกัดซ้อนกัน 3 ชั้น

1. **ต้อง verify ownership** ของ address นั้น (กดลิงก์จากเมล verification — https://support.google.com/mail/answer/22370)
2. **address แบบ school/work ต้องให้ SMTP credentials ของ domain นั้นด้วย** ("For school or work accounts, enter the SMTP server... plus the username and password on that account" — เดียวกัน) → แปลว่าต้องมี SMTP account ฝั่ง ku.ac.th อยู่แล้ว — ถ้ามีแล้วจะผ่าน Gmail ทำไม?
3. 🚨 **Google ประกาศเลิก support แล้ว:** "Starting **January 2027**, Gmail will no longer support the 'Send as' feature for **third-party email addresses**" (https://support.google.com/mail/answer/22370) → ทางนี้กำลังถูกปิดฝั่ง platform ตรงๆ

### 4.2 ผลจาก SPF/DKIM/DMARC ถ้ายังดันส่ง From: `@ku.ac.th` จาก Gmail servers

Google's sender guidelines (official, บังคับกับผู้ส่งทุกรายตั้งแต่ Feb 2024): **"To pass DMARC authentication, messages must be authenticated by either SPF or DKIM, and the authenticating domain must be the same domain that appears in the message From: header"** (https://support.google.com/mail/answer/81126) — เมลที่ออกจาก Gmail servers จะโดน sign เป็น `d=gmail.com`/SPF ฝั่ง gmail.com ซึ่ง **ไม่ align** กับ From: `@ku.ac.th` → DMARC fail → ปลายทาง (รวมถึง Gmail เอง ซึ่งบังคับ DMARC กับ sender ทุกคน) จะจัดเป็น spam หรือ reject (`5.7.26`) — ยิ่ง ku.ac.th เผยแพร่ DMARC policy `p=reject` (มาตรฐานองค์กรราชการ/มหาวิทยาลัย) เมลตีกลับหมด **[UNVERIFIED — ค่า DNS จริงของ ku.ac.th ต้อง dig ตอน implement]**

### 4.3 สรุปทางเลือก sender สำหรับ v1

| ทางเลือก | ผลลัพธ์ |
|---|---|
| From: `@gmail.com` (บัญชีระบบแยก เช่น `kuhome.noreply@gmail.com`) + `Reply-To: ฝ่ายบริการ@ku.ac.th` | ✅ DMARC ผ่าน (Gmail sign ในนาม gmail.com ของตัวเอง), realistic สุด — ผู้รับเห็น sender เป็น gmail.com (แลกด้วย "หน้าตา" ไม่ใช่ทางการ ม. เท่านั้น) |
| From: `@ku.ac.th` ผ่าน Gmail ธรรมดา | ❌ DMARC fail → spam/reject + เสี่ยง reputation ของ domain ม. |
| From: `@ku.ac.th` ผ่าน Workspace relay (Option C) | ✅ ถูกต้องสุด แต่ต้องได้สิทธิ์ Workspace admin ของ ม. ก่อน (นอก scope v1) |

---

## 5. Laravel 13 config specifics

- **ไม่มี "gmail" transport พิเศษ** — ทุกทางเลือกใช้ `smtp` transport ธรรมดาของ Symfony Mailer (https://laravel.com/docs/13.x/mail — "a clean, simple email API powered by the popular Symfony Mailer component")
- **Queuing:** `Mail::to($u)->queue(new BookingConfirmed($b))` หรือให้ default โดย implement `ShouldQueue` ที่ตัว Mailable ("even if you call the send method... the mailable will still be queued") — เข้ากับ `QUEUE_CONNECTION=database` + worker ที่มีอยู่ (https://laravel.com/docs/13.x/mail#queueing-mail)
- **DB transaction gotcha (สำคัญกับ repo นี้):** flow verify/confirm ของเรา wrap อยู่ใน `DB::beginTransaction()` — queued mailable ต้อง dispatch หลัง commit: `->afterCommit()` ต่อท้าย mailable หรือเรียกใน constructor (docs: "Queued Mailables and Database Transactions") ไม่งั้น worker อาจอ่าน booking ก่อน commit → เมลเนื้อหาเพี้ยน/ไม่พบ row
- **Failure handling:** define `failed(Throwable $e): void` ใน Mailable (log เข้า `Log::error` ตาม convention ของ repo — ห้าม leak message); เกิน retry → row ลง `failed_jobs` (ตารางมีอยู่แล้ว) — ควรกำหนด `$tries`/`backoff` (เช่น tries=3, backoff 60s) เพราะ SMTP Gmail พลาดเป็นครั้งคราว; ดูรายการ fail ได้ผ่าน `queue:failed`
- **Testing:** `Mail::fake()` + `Mail::assertQueued(BookingConfirmed::class)` — native กับ PHPUnit suite เดิม (https://laravel.com/docs/13.x/mail#testing-mailable-sending)

### ตัวอย่าง Mailable ขั้นต่ำ (Thai/UTF-8 ใช้ได้ปกติ — Symfony Mailer encode UTF-8 subject/body ให้เอง)

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Booking;

class BookingConfirmed extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public Booking $booking)
    {
        $this->afterCommit(); // รอ transaction commit ก่อนส่งเข้า worker
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ยืนยันการจองห้องพัก KU HOME — ใบสังกะ ' . $this->booking->confirmation_number,
            replyTo: [new \Illuminate\Mail\Mailables\Address(config('mail.from.reply_to', config('mail.from.address')))],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.booking-confirmed'); // Blade UTF-8 ปกติ
    }

    public function failed(\Throwable $e): void
    {
        \Log::error('BookingConfirmed mail failed', ['booking_id' => $this->booking->id]);
    }
}
```

> 🚧 โค้ดนี้เป็น **ตัวอย่างสำหรับ research เท่านั้น** ยังไม่ได้ implement ใน repo (ticket นี้เป็น research)

---

## 6. Comparison table

| | **A. SMTP + App Password** ⭐ v1 | **B. Gmail API / XOAUTH2** | **C. Workspace SMTP relay** |
|---|---|---|---|
| Setup effort | 🟢 ต่ำสุด — เปิด 2FA + สร้าง App Password + แก้ `.env` (~30 นาที) | 🟡 สูง — GCP project + OAuth consent + flow เก็บ refresh token + wiring transport | 🔴 ต้องมี Workspace admin ของ domain (นอกอำนาจทีม) |
| Packages เพิ่ม | **0** (symfony/mailer มีแล้ว) | ≥6 (`google/apiclient` + `apiclient-services~0.350` + `google/auth` + guzzle + psr7 + monolog) | **0** |
| Daily limit (consumer acct) | ~500 ฉบับ/วัน · 500 recipients/ฉบับ | **เท่ากัน** (API ไม่เพิ่ม quota ส่ง) | 10,000/วัน (ถ้าได้ domain) |
| Lockout เมื่อเกิน | 1–24 ชม. ชั่วคราว | เดียวกัน + API quota layer เพิ่ม | SMTP error, แยกอิสระจาก Gmail user limit |
| Security surface | credential เต็มบัญชีใน `.env` — ลดความเสี่ยงด้วยบัญชีแยก + rotate/revoke ง่าย | token scope จำกัด (gmail.send) ดีกว่าเชิงทฤษฎี แต่ refresh token ต้องเก็บปลอดภัยเช่นกัน + consent เป็นครั้งคราว (token หมดอายุ/ถูก revoke) | ไม่มี credential ใน app (IP whitelist) — สะอาดสุด |
| Reliability ระยะยาว | 🟡 Google "ไม่แนะนำ" App Passwords — ความเสี่ยงถูกตัดสิทธิ์ในอนาคต | 🟢 path ที่ Google สนับสนุน | 🟢 มั่นคงสุด (ถ้าได้สิทธิ์) |
| From ที่ realistic ได้ | gmail.com + Reply-To KU | gmail.com + Reply-To KU | **@ku.ac.th แท้** |
| เหมาะกับ "low volume transactional จาก KU server" | ✅ ใช่ | ❌ over-engineering สำหรับ 500 ฉบับ/วัน | ✅ ใช่ (แต่เป็นอนาคต ไม่ใช่ v1) |

**ข้อความสำหรับ owner (out of scope แต่ควรเห็น):** dedicated providers (Amazon SES / Mailgun / Postmark — มี free tier พอสำหรับ volume นี้ทั้งหมด) จะแก้ปัญหา limit + deliverability + sender domain ได้หมด — แต่ effort นี้ **fix ปลายทางเป็น Gmail** จึงไม่พิจารณา — **สัญญาณว่า Gmail เริ่มไม่พอ:** ใกล้ 500 ฉบับ/วัน · ต้องการ From `@ku.ac.th` แท้ · ต้องการ delivery analytics/bounce handling — ตอนนั้นให้เปิด effort ใหม่ (ย้ายเป็น SES/Postmark หรือดัน Option C กับ ม.)

## 7. คำถามเฉพาะ: consumer SMTP limit นับอะไร และ fail แบบไหน?

- **นับทั้ง "ฉบับ" และ "ผู้รับ":** "more than 500 recipients in a single email" **และ/หรือ** "more than 500 emails sent in a day" (https://support.google.com/mail/answer/22839) → 1 ฉบับที่มี To 3 คน กิน 3 recipients แต่ 1 message quota — สำหรับ transactional ของเรา (1 ผู้รับต่อฉบับ) ตัวที่ bind คือ **~500 ฉบับ/วัน**
- **Fail แบบ soft-lockout ไม่ใช่ bounce ถาวร:** เกิน limit → ส่งไม่ได้ชั่วคราว **1–24 ชม.** ("You have reached a limit for sending mail") จากนั้น reset เอง; ฝั่ง Workspace ระบุ lockout "up to 24 hours" (https://knowledge.workspace.google.com/admin/gmail/gmail-sending-limits-in-google-workspace) รหัส SMTP ที่คนพบบ่อยคือ `550 5.4.5` **[UNVERIFIED — ไม่ปรากฏในหน้า official ปัจจุบัน]**
- **Implication ต่อ repo:** เมื่อโดน limit งานที่อยู่ใน queue จะ retry แล้วพังซ้ำ → ควรตั้ง `$tries` ต่ำ (3) ให้ตกไป `failed_jobs` เร็ว แล้ว re-dispatch วันถัดไปด้วย `queue:retry` — และ log เพื่อนับจำนวนเมล/วันจริง (ที่ volume booking ปัจจุบัน ห่างไกล 500 มาก)

---

## 8. Sources

- https://support.google.com/mail/answer/22839 (Gmail sending limits — consumer)
- https://knowledge.workspace.google.com/admin/gmail/gmail-sending-limits-in-google-workspace (Workspace sending limits; เดิม support.google.com/a/answer/166852)
- https://support.google.com/accounts/answer/185833 (App Passwords)
- https://support.google.com/mail/answer/7126229 (Gmail auth กับ third-party clients — no password sharing; app password fallback)
- https://support.google.com/mail/answer/22370 ("Send mail as" + การ deprecate Jan 2027)
- https://knowledge.workspace.google.com/admin/gmail/advanced/route-outgoing-smtp-relay-messages-through-google (SMTP relay service; เดิม support.google.com/a/answer/2956491)
- https://support.google.com/mail/answer/81126 (Email sender guidelines — SPF/DKIM/DMARC alignment, 5.7.26)
- https://developers.google.com/workspace/gmail/api/guides/sending (Gmail API sending)
- https://developers.google.com/workspace/gmail/api/auth/about-auth + https://developers.google.com/identity/protocols/oauth2/service-account (service account ต้อง domain-wide delegation)
- https://packagist.org/packages/google/apiclient (deps + version)
- https://laravel.com/docs/13.x/mail (Symfony Mailer, queueing, ShouldQueue, afterCommit, failed(), Mail::fake, custom transports)
