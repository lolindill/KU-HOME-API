#!/usr/bin/env bash
# =========================================================
# 🌿 sync-main.sh — ซิงก์ dev → main (backend-only tree)
#
#    main = branch สำหรับ deploy/prod — เก็บเฉพาะไฟล์ backend service
#    dev  = branch พัฒนาฉบับเต็ม (test + doc + ของประกอบ)
#
#    กติกา (ดู AGENTS.md หัวข้อ "Branch strategy"):
#    - sync ได้เฉพาะผ่าน script นี้ — ห้าม merge dev→main ผ่านปุ่ม PR
#      บน GitHub (ไฟล์ test/doc จะไหลกลับมาทั้งชุด)
#    - ห้าม force-push main (script นี้ merge จาก dev เสมอ → เดินหน้าอย่างเดียว)
#    - deploy จาก main ด้วย composer install --no-dev
#
#    ใช้จาก repo root:   bash scripts/sync-main.sh [--push]
#    ถ้า merge ชน conflict → script หยุด ให้แก้มือแล้ว commit เอง แล้วรัน
#    สคริปต์ใหม่ (merge จะเดินต่อจากที่ค้าง ไม่ซ้ำซ้อน)
# =========================================================
set -euo pipefail

# ไฟล์/โฟลเดอร์ที่ "ไม่จำเป็นต่อการรัน backend" — ถูกลบออกจาก main เสมอ
EXCLUDED=(
  tests
  phpunit.xml
  docs
  wayfinder
  report-samples
  process
  postman
  scripts
  AGENTS.md
  cline.md
  house_keep_plan.md
  ReadMeToSetup.txt
  .zcodeignore
)

# ต้นต้อง clean — กัน merge ค้างกลางทาง
if [[ -n "$(git status --porcelain)" ]]; then
  echo "❌ working tree ไม่ clean — commit หรือ stash ก่อนรัน sync นะคะ" >&2
  exit 1
fi

# ถ้ายังไม่มี local main (เครื่องใหม่) → ดึงจาก origin/main
if ! git show-ref --verify --quiet refs/heads/main; then
  echo "🌱 ไม่มี local main — สร้างจาก origin/main"
  git branch main origin/main
fi

start_branch=$(git branch --show-current)

git checkout main
git merge --no-ff dev -m "release: merge dev @ $(git rev-parse --short dev)"

# 🧹 Scrub ไฟล์ dev-only ที่ merge พากลับมา (ไฟล์ที่ dev ไม่ได้แตะตั้งแต่ sync ก่อน
#    จะถูก deletion ฝั่ง main เก็บไว้เอง — rm จะแตะเฉพาะไฟล์ที่หวนคืนจริง)
for p in "${EXCLUDED[@]}"; do
  git rm -rq --ignore-unmatch "$p"
done

# ถ้าไม่มีอะไรให้ scrub (dev ไม่ได้แก้ไฟล์ excluded เลยช่วงนี้) → ข้าม commit
if ! git diff --cached --quiet; then
  git commit -m "chore(release): strip dev-only files @ $(git rev-parse --short dev)"
fi

if [[ "${1:-}" == "--push" ]]; then
  git push origin main
fi

git checkout "$start_branch"
echo "✅ main synced from dev @ $(git rev-parse --short dev)"
