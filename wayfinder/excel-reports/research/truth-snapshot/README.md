# Truth snapshot — Google Sheets "เอกสารระบบที่พัก_Document"

- **Google Sheets id:** `1v8euwEZZMnsSHmJuV5ZDu3bRE5NI0TTplAW8o_1m3bw`
- **URL:** https://docs.google.com/spreadsheets/d/1v8euwEZZMnsSHmJuV5ZDu3bRE5NI0TTplAW8o_1m3bw/edit (แท็บ "ใบรายงาน (Reports)" = `gid=1127367576`)
- **สถานะ:** owner ประกาศ (2026-09-22) ว่า **ชีตนี้คือ truth** — template JSON ใน `docs/report_docAndSample/report-templates/` เป็นตัวกลางที่แปลงจากชีต (`_index.json` ระบุ `google_sheets_id` ตรงกัน) · ถ้าเนื้อหา/เลย์เอาต์ขัดกัน → **ชีต wins** แล้ว re-convert
- **การเข้าถึง:** public view-only (ผ่านเว็บได้ทุกเครื่อง ไม่ต้องสิทธิ์)
- **Snapshot:** `truth-spreadsheet.xlsx` (export ทั้งเล่ม 2026-09-22, 17 แท็บ)

## วิธี re-export snapshot

```bash
curl -sL -o truth-spreadsheet.xlsx \
  "https://docs.google.com/spreadsheets/d/1v8euwEZZMnsSHmJuV5ZDu3bRE5NI0TTplAW8o_1m3bw/export?format=xlsx"
```

## แท็บในเล่ม (17) ↔ template JSON

| # | แท็บ | state | template JSON |
|---|---|---|---|
| 1 | เอกสารระบบที่พัก | visible | — (เอกสารหลักของระบบ) |
| 2 | ใบรายงาน (Reports) | visible | `_index.json` (ทะเบียนรายงาน) |
| 3 | ตย.รายงานห้องเข้าพัก | visible | `check-in-report.json` |
| 4 | ตย.รายงานห้องขาออก | visible | `check-out-report.json` |
| 5 | ตย.รายงานการเงินรายวัน | visible | `daily-financial-report.json` |
| 6 | ตย.รายงานการมัดจำ | visible | `deposit-report.json` |
| 7 | ตย.การเข้าพัก | visible | `occupancy-report.json` |
| 8 | ตย.รายงานอาหารเช้า | visible | `breakfast-report.json` |
| 9 | ตย.รายงาน โอนระหว่างหน่วยงาน | visible | `erp-transfer-report.json` |
| 10 | ตย.รายงานแม่บ้าน | **hidden** | `housekeeping-report.json` (v1 เก่า — ต้นฉบับ hide ไว้) |
| 11 | ตย.รายงานแม่บ้าน (มีช่องว่างท้ายชื่อ) | visible | `housekeeping-report-v2.json` |
| 12 | ตย.รายงาน Room Status | visible | `room-status-report.json` |
| 13 | ตย.เตียงเสริม | visible | `extra-bed-report.json` |
| 14 | ตย.วัสดุสินเปลื้อง | visible | `supplies-report.json` |
| 15 | ตย.รายงานห้องชำรุด | visible | `out-of-service-room-report.json` |
| 16 | ตย.ค่าปรับยืมอุปกรณ์เพิ่มเติม | visible | `additional-charges-report.json` |
| 17 | ตย.รายงานผู้จัดการ | visible | `manager-report.json` |

> หมายเหตุ: ชื่อแท็บบางแท็บมีช่องว่างต่อท้าย (เช่น `ตย.รายงานแม่บ้าน ` และ `ตย.ค่าปรับยืมอุปกรณ์เพิ่มเติม `) — อ้างอิงด้วยระวัง trailing space
