// ============================================================
// 📅🔍 Occupancy ≈ 95 Finder
//   วิเคราะห์ทุก seed × case pair จาก test-seed-pairs.cjs
//   คำนวณ "max simultaneous occupancy" = จำนวนห้องที่ occupied พร้อมกัน
//   ในวันใดวันหนึ่ง (seed reservations + booking rooms ที่ overlap จริงในวันนั้น)
//
//   เป้า: หา pair ที่ occupancy ≈ 95 (จาก 100 ห้อง) พร้อมระบุ date ที่ peak
//
//   รัน: node docs/test-occupancy-95.cjs
// ============================================================
const fs = require('fs');
const vm = require('vm');

// ---- reuse setup (DOM stub + playground eval) + SEEDS + CASES จาก test-seed-pairs.cjs ----
// ตัดเฉพาะส่วนก่อน "Main loop" ออกมา eval (ไม่แตะไฟล์เดิม)
const pairSrc = fs.readFileSync(__dirname + '/test-seed-pairs.cjs', 'utf8');
const cutMarker = '// 🎯 Main loop';
const cutIdx = pairSrc.indexOf(cutMarker);
if (cutIdx < 0) { console.error('❌ ไม่พบ Main loop marker ใน test-seed-pairs.cjs'); process.exit(1); }
const setupCode = pairSrc.slice(0, cutIdx);

const ctx = {
  require, module, exports, __dirname, __filename,
  console, setTimeout, clearTimeout, setInterval, clearInterval,
  Date, Math, JSON, Set, Map, Array, Object, Number, String, Boolean,
  parseInt, parseFloat, isNaN, isFinite, Infinity, NaN, process,
};
ctx.global = ctx;
ctx.window = ctx;
vm.createContext(ctx);
vm.runInContext(
  setupCode + '\n;this.__g=function(){return{exposed,SEEDS,CASES,addDaysLocal,todayLocal,mulberry32};};',
  ctx,
);
const { exposed, SEEDS, CASES, addDaysLocal } = ctx.__g();

// ============================================================
// 📊 computeOccupancy — นับห้องที่ occupied รายวัน แล้วหา peak
//   seed: reservations ถูก push ลง state.rooms แล้ว (actual room occupied)
//   booking: rooms ใน case (ยังไม่ assign แต่ assume assign ได้ → +count ต่อ room)
// ============================================================
function computeOccupancy(state, caseObj) {
  const days = {};
  const bump = (ci, co) => {
    let d = ci, g = 0;
    while (d !== co && g++ < 400) { days[d] = (days[d] || 0) + 1; d = addDaysLocal(d, 1); }
  };
  // seed reservations (actual rooms occupied)
  let seedRooms = 0;
  state.rooms.forEach(r => {
    r.reservations.forEach(rv => { bump(rv.checkIn, rv.checkOut); seedRooms++; });
  });
  // booking rooms (single or sequence) — +1 ต่อ room ในช่วง ci..co ของ booking นั้น
  let bookingRooms = 0;
  const bookings = caseObj.kind === 'sequence' ? caseObj.queue : [caseObj];
  bookings.forEach(b => b.rooms.forEach(() => { bump(b.checkIn, b.checkOut); bookingRooms++; }));

  let maxOcc = 0, peakDates = [];
  for (const [d, c] of Object.entries(days)) {
    if (c > maxOcc) { maxOcc = c; peakDates = [d]; }
    else if (c === maxOcc) peakDates.push(d);
  }
  return { maxOcc, peakDates, seedRooms, bookingRooms, dayCount: Object.keys(days).length };
}

// ============================================================
// 🏃 Main — วนทุก pair
// ============================================================
const results = [];
for (const [sName, seed] of Object.entries(SEEDS)) {
  for (const [cName, caseObj] of Object.entries(CASES)) {
    exposed.resetAll();
    const ci = caseObj.kind === 'single' ? caseObj.checkIn : caseObj.queue[0].checkIn;
    const co = caseObj.kind === 'single' ? caseObj.checkOut : caseObj.queue[0].checkOut;
    exposed.state.currentBooking.checkIn = ci;
    exposed.state.currentBooking.checkOut = co;
    exposed.state.evalDates = { checkIn: ci, checkOut: co };
    seed.fn(exposed.state, ci, co);

    const occ = computeOccupancy(exposed.state, caseObj);
    results.push({
      seed: sName, case: cName,
      seedDesc: seed.desc, caseDesc: caseObj.desc,
      kind: caseObj.kind,
      ...occ,
    });
  }
}

// ============================================================
// 📋 Report
// ============================================================
const total = results.length;
const reach95 = results.filter(r => r.maxOcc >= 95);
const reach90 = results.filter(r => r.maxOcc >= 90);

console.log('='.repeat(78));
console.log(`📅🔍 OCCUPANCY ANALYSIS — ${total} pairs (SEEDS ${Object.keys(SEEDS).length} × CASES ${Object.keys(CASES).length})`);
console.log('='.repeat(78));
console.log(`   ≥95 occupancy : ${reach95.length} pairs`);
console.log(`   ≥90 occupancy : ${reach90.length} pairs`);
console.log(`   max ในไฟล์    : ${Math.max(...results.map(r => r.maxOcc))}`);
console.log(`   min ในไฟล์    : ${Math.min(...results.map(r => r.maxOcc))}`);

// เรียงตามใกล้ 95 ที่สุด
const sorted = [...results].sort((a, b) => Math.abs(a.maxOcc - 95) - Math.abs(b.maxOcc - 95));

console.log('\n' + '─'.repeat(78));
console.log('🎯 TOP 25 PAIRS ที่ใกล้ 95 ที่สุด (เรียงตาม |maxOcc − 95|)');
console.log('─'.repeat(78));
console.log('rank  occ  seed      × case    kind     peak date        seed×case');
console.log('─'.repeat(78));
sorted.slice(0, 25).forEach((r, i) => {
  const peak = r.peakDates.slice(0, 2).join(',') + (r.peakDates.length > 2 ? ` (+${r.peakDates.length - 2})` : '');
  const flag = r.maxOcc === 95 ? '✅' : (r.maxOcc > 100 ? '⛔>100' : (r.maxOcc >= 95 ? '🟡' : '  '));
  console.log(
    `${String(i + 1).padStart(3)}  ${String(r.maxOcc).padStart(3)} ${r.seed.padEnd(9)} ${r.case.padEnd(8)} ${r.kind.padEnd(8)} ${peak.padEnd(16)} ${r.seedDesc} × ${r.caseDesc}  ${flag}`,
  );
});

// เฉพาะที่ reach 95+
console.log('\n' + '─'.repeat(78));
console.log('✅ PAIRS ที่ occupancy ≥ 95 (พร้อม date)');
console.log('─'.repeat(78));
if (reach95.length === 0) {
  console.log('   (ไม่มี pair ใดถึง 95 — ดู TOP ใกล้สุดด้านบน)');
} else {
  reach95.sort((a, b) => b.maxOcc - a.maxOcc);
  reach95.forEach(r => {
    const peak = r.peakDates.slice(0, 5).join(', ') + (r.peakDates.length > 5 ? ` (+${r.peakDates.length - 5} วัน)` : '');
    console.log(`   occ=${r.maxOcc}  ${r.seed} × ${r.case} (${r.kind})`);
    console.log(`     peak: ${peak}`);
    console.log(`     desc: ${r.seedDesc} × ${r.caseDesc}`);
    console.log(`     seed rooms=${r.seedRooms}  booking rooms=${r.bookingRooms}  วันที่มีข้อมูล=${r.dayCount}`);
  });
}

// สถิติ date coverage: แต่ละ pair ครอบวันอะไรบ้าง
console.log('\n' + '─'.repeat(78));
console.log('📆 DATE COVERAGE — วันที่ occupancy ≥ 90 (pair ใดมี "date control" จริง)');
console.log('─'.repeat(78));
const dateControl = results.filter(r => r.maxOcc >= 90).sort((a, b) => b.maxOcc - a.maxOcc);
if (dateControl.length === 0) {
  console.log('   (ไม่มี pair ที่ ≥90)');
} else {
  dateControl.forEach(r => {
    console.log(`   occ=${r.maxOcc}  ${r.seed} × ${r.case}  → วัน ${r.peakDates.slice(0, 6).join(', ')}`);
  });
}
