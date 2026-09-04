# ku_member ควรเป็น role, tag/boolean หรือ derive จาก KU linkage

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** —
- **assignee:** (ว่าง)
- **born:** 2026-09-01 — แตกจาก resolution ของ [ticket 01](./01-users-who-signs-in-via-ku-sso.md) ("need to think about this design again")

## Question

Owner ตั้งคำถามเอง: *"ku_member is only user with tag with only have effect on ku member discount rate — need to role it ku_member? user can be ku member if have link acc with ku login"*

ข้อเท็จจริงที่ต้องใช้ตัดสิน (เช็คแล้ว 2026-09-01):

- ตอนนี้ระบบมี **ตัวแทนคู่**: role `ku_member` (enum ใน `CheckRole`/`Booking`/`FrontDeskController` ฯลฯ) **และ** boolean `users.is_ku_member` (มีมาแต่ base migration, cast `PgBoolean`, default false) — สองอันนี้ไม่เคยถูก reconcile
- ผลจริงทาง business ของ "being a KU member" ตอนนี้ = ส่วนลด/อัตราสมาชิกเท่านั้น (owner ยืนยัน)
- commit `06e6c43` เพิ่งให้ admin assign role `staff|housekeeping|ku_member` ได้

ตัวเลือกที่ต้องชั่ง:

1. **role ตามเดิม** — SSO link = assign role `ku_member` (แต่ role ปนกับ authorization จริง เช่น staff/admin ใน enum เดียวกัน)
2. **boolean `is_ku_member` เป็น source of truth** — SSO link = set `is_ku_member=true` (column มีอยู่แล้ว!) · role เหลือไว้เฉพาะ authorization (user/staff/admin/...) · เช็คส่วนลด member จาก boolean
3. **derive จาก provenance** — ไม่เก็บสถานะซ้ำ: เป็น member ก็ต่อเมื่อ user นั้น**เกิดจาก KU SSO** (ดู `auth_provider` ที่อาจเกิดใน ticket 02) — *(req change 2026-09-01: ไม่มีการ link ข้าม account แล้ว จึงไม่มี concept "unlink"; user ที่เกิดจาก KU SSO ถือสถานะตลอดชีวิตของ account นั้น)*

คำถามกำกับ: กระทบ `CheckRole`/discount eligibility/`UserController` assign-role ยังไง · ผู้ใช้ `ku_member` เดิม (ที่ admin assign มา) migrate ยังไง · guest ที่เป็น "คน KU" แต่จองแบบไม่ login ผ่าน KU ได้สิทธิ์ไหม
