// ============================================================
// 🌱🤝🎯 Seed × Case Pairs Test Harness
//   17 SEEDS (pre-existing reservations) × 16 CASES (bookings to assign)
//   = 272 pairs cartesian product
//   ⛔ Auto-skip pairs that are impossible from the start (no need to compute)
//   ✅ Run A/B/C/D/E on the rest
//
//   Single-shot cases: run 5 algos (A-E)
//   Sequence cases:    simulate processQueue (commit each booking), aggregate
//
//   รัน: node docs/test-seed-pairs.cjs
// ============================================================
const fs = require('fs');
const vm = require('vm');

const html = fs.readFileSync(__dirname + '/room-algorithm-playground.html', 'utf8');
const scriptMatch = html.match(/<script>([\s\S]*?)<\/script>/);
if (!scriptMatch) {
  console.error('❌ ไม่พบ <script> ใน playground');
  process.exit(1);
}
const scriptCode = scriptMatch[1];

// ---- Stub DOM & browser globals (copy pattern จาก test-presets.cjs) ----
function makeEl() {
  const el = {
    _val: '', _text: '', _html: '', _children: [], _listeners: {},
    style: {},
    classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } },
    set value(v) { this._val = v; }, get value() { return this._val; },
    set textContent(v) { this._text = v; }, get textContent() { return this._text; },
    set innerHTML(v) { this._html = v; }, get innerHTML() { return this._html; },
    appendChild(child) { if (child) this._children.push(child); return child; },
    addEventListener(ev, fn) { this._listeners[ev] = fn; },
    querySelector(sel) {
      if (typeof sel === 'string' && sel.includes('name="algo"')) return { value: 'E', checked: true };
      return makeEl();
    },
    querySelectorAll() { return []; },
    setAttribute() {}, insertAdjacentHTML() {},
  };
  return el;
}
const elements = {};
function getEl(id) { if (!elements[id]) elements[id] = makeEl(); return elements[id]; }
const documentStub = {
  getElementById(id) { return getEl(id); },
  querySelector(sel) {
    if (typeof sel === 'string' && sel.includes('name="algo"')) return { value: 'E' };
    if (typeof sel === 'string' && sel.startsWith('#')) return getEl(sel.slice(1));
    return makeEl();
  },
  querySelectorAll() { return []; },
  createElement() { return makeEl(); },
  addEventListener() {},
};
const perfNow = () => Date.now();
const sandbox = {
  document: documentStub, window: {}, performance: { now: perfNow },
  alert: () => {}, console,
  setTimeout, clearTimeout, setInterval, clearInterval,
  Date, Math, JSON, Set, Map, Array, Object, Number, String, Boolean,
  parseInt, parseFloat, isNaN, Infinity, NaN,
};
sandbox.window = sandbox;
vm.createContext(sandbox);
vm.runInContext(
  scriptCode + '\n;this.__expose = function(){return{' +
  'state:state,ALGOS:ALGOS,loadPreset:loadPreset,resetAll:resetAll,' +
  'computeMetrics:computeMetrics,costBreakdown:costBreakdown,' +
  'countContiguousPairs:countContiguousPairs,isAvailable:isAvailable,' +
  'addDays:addDays,todayISO:todayISO,clearAssigned:clearAssigned' +
  '};};',
  sandbox,
);
const exposed = sandbox.__expose();

// ============================================================
// 📅 Local date helpers (TZ-safe) — ใช้แทน exposed.addDays
//   ปัญหา: addDays ใน playground ใช้ toISOString() ซึ่งเปลี่ยนตาม timezone
//          ในเครื่อง TZ+7, addDays('2026-07-07',1) คืน '2026-07-07' (ผิด)
//          แกะด้วย UTC-anchored math แทน
// ============================================================
function addDaysLocal(dateStr, n) {
  // parse as UTC midnight to avoid DST/TZ drift
  const [y, m, d] = dateStr.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d));
  dt.setUTCDate(dt.getUTCDate() + n);
  return dt.toISOString().split('T')[0];
}
function todayLocal() {
  const dt = new Date();
  return new Date(Date.UTC(dt.getFullYear(), dt.getMonth(), dt.getDate())).toISOString().split('T')[0];
}
const T = (n) => addDaysLocal(todayLocal(), n);

// ============================================================
// 🎲 Deterministic PRNG (mulberry32) — reproducible random seeds
// ============================================================
function mulberry32(a) {
  return function () {
    a |= 0; a = (a + 0x6D2B79F5) | 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

// ============================================================
// 🌱 SEEDS — pre-existing reservations (ห้องถูกจองไว้ก่อน)
//   แต่ละ seed: (state, ci, co) => void  — push reservations ลง state.rooms
//   ci/co = checkIn/checkOut ของ currentBooking (sync evalDates)
// ============================================================
const TYPE_WEIGHTS = [['Deluxe', 0.4], ['Superior', 0.4], ['Suite', 0.2]];

function pickType(rng) {
  const r = rng();
  let acc = 0;
  for (const [t, w] of TYPE_WEIGHTS) { acc += w; if (r < acc) return t; }
  return 'Deluxe';
}

// random seed ทั่วไป: จอง N ห้อง สุ่ม type+วัน overlap ในช่วง ci..ci+7
function randomSeed(rng, count) {
  return function (state, ci, co) {
    const today = todayLocal();
    const pool = [...state.rooms];
    // shuffle deterministic
    for (let i = pool.length - 1; i > 0; i--) {
      const j = Math.floor(rng() * (i + 1));
      [pool[i], pool[j]] = [pool[j], pool[i]];
    }
    const targets = pool.slice(0, Math.min(count, pool.length));
    targets.forEach(r => {
      // วัน random ในช่วง today+1..today+7 (overlap กับ ci..co บางส่วน)
      const start = addDaysLocal(today, 1 + Math.floor(rng() * 5));
      const nights = 1 + Math.floor(rng() * 4);
      const end = addDaysLocal(start, nights);
      r.reservations.push({
        checkIn: start, checkOut: end,
        label: 'Existing Guest', type: 'seed',
      });
    });
  };
}

// random seed แบบ narrow date: จอง N ห้อง แต่วันทับซ้อนกับ case (ci-1 .. ci+3) แน่นอน
//   → ใช้เมื่อต้องการ stress occupancy สูง (seed overlap กับ booking เกือบทั้งหมด)
//   dateOffset: ระยะวันที่สุ่ม start จาก ci (default ±1 วัน รอบ ci)
//   nights: จำนวนคืนที่สุ่ม (default 2-4)
function randomSeedNarrow(rng, count, dateOffset = 1, nightsMax = 4) {
  return function (state, ci, co) {
    const pool = [...state.rooms];
    for (let i = pool.length - 1; i > 0; i--) {
      const j = Math.floor(rng() * (i + 1));
      [pool[i], pool[j]] = [pool[j], pool[i]];
    }
    const targets = pool.slice(0, Math.min(count, pool.length));
    targets.forEach(r => {
      // start ในช่วง ci-1 .. ci+dateOffset (ทับซ้อนกับ ci..co แน่นอน)
      const start = addDaysLocal(ci, -dateOffset + Math.floor(rng() * (dateOffset * 2 + 1)));
      const nights = 2 + Math.floor(rng() * (nightsMax - 1)); // 2..nightsMax
      const end = addDaysLocal(start, nights);
      r.reservations.push({
        checkIn: start, checkOut: end,
        label: 'Peak Guest', type: 'seed',
      });
    });
  };
}

const SEEDS = {
  // ===== Handcrafted (7) =====
  'S-CHK': { desc: 'Checkerboard Deluxe ชั้น 5', fn(state, ci, co) {
    ['508', '510', '512', '514', '516'].forEach(num => {
      state.rooms.find(r => r.num === num).reservations.push(
        { checkIn: ci, checkOut: co, label: 'Existing', type: 'seed' });
    });
  }},
  'S-HALF': { desc: 'ฝั่งซ้าย Deluxe ชั้น 5', fn(state, ci, co) {
    ['508', '509', '510', '511', '512'].forEach(num => {
      state.rooms.find(r => r.num === num).reservations.push(
        { checkIn: ci, checkOut: co, label: 'Existing', type: 'seed' });
    });
  }},
  'S-ADJ': { desc: 'Block 3 ห้องติดกัน (รวม X09)', fn(state, ci, co) {
    ['508', '509', '510'].forEach(num => {
      state.rooms.find(r => r.num === num).reservations.push(
        { checkIn: ci, checkOut: co, label: 'Existing', type: 'seed' });
    });
  }},
  'S-CONFLICT': { desc: '508 จอง 2 ช่วง (วัน 1-3 & 5-7)', fn(state, ci, co) {
    const r = state.rooms.find(x => x.num === '508');
    r.reservations.push({ checkIn: ci, checkOut: co, label: 'Guest A', type: 'seed' });
    r.reservations.push({
      checkIn: addDaysLocal(ci, 4), checkOut: addDaysLocal(ci, 6),
      label: 'Guest B', type: 'seed',
    });
  }},
  'S-PREMIUM': { desc: 'Suite ทุกชั้นเต็ม', fn(state, ci, co) {
    ['507', '518', '607', '618', '707', '718', '807', '818', '907', '918'].forEach(num => {
      state.rooms.find(r => r.num === num).reservations.push(
        { checkIn: ci, checkOut: co, label: 'VIP', type: 'seed' });
    });
  }},
  'S-WEEKEND': { desc: 'Deluxe ชั้น 5-8 เต็ม + 5 ห้องชั้น 9', fn(state, ci, co) {
    state.rooms.filter(r => r.type === 'Deluxe' && r.floor <= 8).forEach(r => {
      r.reservations.push({ checkIn: ci, checkOut: co, label: 'Weekend', type: 'seed' });
    });
    ['908', '909', '910', '911', '912'].forEach(num => {
      state.rooms.find(r => r.num === num).reservations.push(
        { checkIn: ci, checkOut: co, label: 'Weekend', type: 'seed' });
    });
  }},
  'S-TWIN': { desc: 'Deluxe ชั้น 8 (twin) ทั้งชั้น', fn(state, ci, co) {
    state.rooms.filter(r => r.type === 'Deluxe' && r.floor === 8).forEach(r => {
      r.reservations.push({ checkIn: ci, checkOut: co, label: 'Twin taken', type: 'seed' });
    });
  }},

  // ===== Random (10) — deterministic 50-70 ห้อง =====
  'S-RND1':  { desc: '~60 mixed spread',    fn: randomSeed(mulberry32(42), 60) },
  'S-RND2':  { desc: '~55 Deluxe-heavy',    fn: randomSeed(mulberry32(7),  55) },
  'S-RND3':  { desc: '~65 ชั้น 5,6 heavy',  fn: randomSeedNarrow(mulberry32(99), 65, 2) },
  'S-RND4':  { desc: '~50 checkerboard-ish',fn: randomSeedNarrow(mulberry32(13), 50, 2) },
  'S-RND5':  { desc: '~70 peak',            fn: randomSeedNarrow(mulberry32(55), 70, 2) },
  'S-RND6':  { desc: '~60 mixed types',     fn: randomSeedNarrow(mulberry32(3),  60, 2) },
  'S-RND7':  { desc: '~58 Superior-heavy',  fn: randomSeedNarrow(mulberry32(88), 58, 2) },
  'S-RND8':  { desc: '~62 Suite-heavy',     fn: randomSeedNarrow(mulberry32(21), 62, 2) },
  'S-RND9':  { desc: '~67 clustered',       fn: randomSeedNarrow(mulberry32(66), 67, 2) },
  'S-RND10': { desc: '~53 spread',          fn: randomSeedNarrow(mulberry32(11), 53, 2) },

  // ===== High-stress Random (3) — 65+ booked rooms, push occupancy toward 95 =====
  'S-RND11': { desc: '~72 heavy mixed',     fn: randomSeedNarrow(mulberry32(777), 72, 2) },
  'S-RND12': { desc: '~68 clustered',       fn: randomSeedNarrow(mulberry32(1234), 68, 2) },
  'S-RND13': { desc: '~75 peak stress',     fn: randomSeedNarrow(mulberry32(2026), 75, 2) },
};

// ============================================================
// 🎯 CASES — booking ที่จะ assign ในวันนั้น
//   single: { checkIn, checkOut, rooms }
//   sequence: { queue: [{checkIn, checkOut, rooms}, ...] }
// ============================================================

const CASES = {
  // ===== Single-shot (6) =====
  'C-SOLO': { kind: 'single', desc: 'Solo Deluxe', checkIn: T(1), checkOut: T(3),
    rooms: [{ type: 'Deluxe', beds: 0 }] },
  'C-G4': { kind: 'single', desc: 'Group×4 Deluxe', checkIn: T(1), checkOut: T(3),
    rooms: Array(4).fill({ type: 'Deluxe', beds: 0 }) },
  'C-MIX': { kind: 'single', desc: 'Mixed Suite/Deluxe/Superior', checkIn: T(1), checkOut: T(3),
    rooms: [
      { type: 'Suite', beds: 0 },
      { type: 'Deluxe', beds: 0 }, { type: 'Deluxe', beds: 0 },
      { type: 'Superior', beds: 0 },
    ] },
  'C-FAM': { kind: 'single', desc: 'Family (extra-bed)', checkIn: T(1), checkOut: T(3),
    rooms: [{ type: 'Deluxe', beds: 0 }, { type: 'Deluxe', beds: 1 }] },
  'C-L10': { kind: 'single', desc: 'Large×10 Deluxe', checkIn: T(1), checkOut: T(3),
    rooms: Array(10).fill({ type: 'Deluxe', beds: 0 }) },
  'C-TWIN': { kind: 'single', desc: '2× Deluxe pref=twin', checkIn: T(1), checkOut: T(3),
    rooms: Array(2).fill({ type: 'Deluxe', beds: 0, bed_preference: 'twin' }) },

  // ===== Sequence/Queue (10) — 4-7 booking ต่อ queue (ทุก booking overlap ±1-2 วันรอบ T1-T4) =====
  'SEQ1': { kind: 'sequence', desc: '4× Deluxe×3 overlap ±2 วัน', queue: [
    { checkIn: T(1), checkOut: T(4), rooms: Array(3).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(2), checkOut: T(5), rooms: Array(3).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(4), rooms: Array(3).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(3), checkOut: T(6), rooms: Array(3).fill({ type: 'Deluxe', beds: 0 }) },
  ]},
  'SEQ2': { kind: 'sequence', desc: '5× mixed overlap วันเดียว', queue: [
    { checkIn: T(1), checkOut: T(4), rooms: [{ type: 'Suite', beds: 0 }, { type: 'Deluxe', beds: 0 }] },
    { checkIn: T(1), checkOut: T(4), rooms: Array(3).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(4), rooms: [{ type: 'Superior', beds: 0 }, { type: 'Superior', beds: 0 }] },
    { checkIn: T(1), checkOut: T(4), rooms: [{ type: 'Deluxe', beds: 1 }] },
    { checkIn: T(1), checkOut: T(4), rooms: Array(2).fill({ type: 'Deluxe', beds: 0 }) },
  ]},
  'SEQ3': { kind: 'sequence', desc: '6× Deluxe แล้ว Suite+Superior', queue: [
    { checkIn: T(1), checkOut: T(3), rooms: Array(3).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(2), checkOut: T(4), rooms: Array(2).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(3), rooms: [{ type: 'Suite', beds: 0 }] },
    { checkIn: T(2), checkOut: T(4), rooms: [{ type: 'Suite', beds: 0 }] },
    { checkIn: T(1), checkOut: T(3), rooms: Array(2).fill({ type: 'Superior', beds: 0 }) },
    { checkIn: T(2), checkOut: T(4), rooms: Array(2).fill({ type: 'Superior', beds: 0 }) },
  ]},
  'SEQ4': { kind: 'sequence', desc: '4× ทุก booking วันเดียว (peak)', queue: [
    { checkIn: T(1), checkOut: T(3), rooms: Array(4).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(3), rooms: Array(3).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(3), rooms: Array(3).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(3), rooms: Array(4).fill({ type: 'Deluxe', beds: 0 }) },
  ]},
  'SEQ5': { kind: 'sequence', desc: '7× family extra-bed หลายชุด', queue: [
    { checkIn: T(1), checkOut: T(3), rooms: [{ type: 'Deluxe', beds: 1 }] },
    { checkIn: T(2), checkOut: T(4), rooms: [{ type: 'Deluxe', beds: 1 }] },
    { checkIn: T(1), checkOut: T(3), rooms: [{ type: 'Deluxe', beds: 0 }, { type: 'Deluxe', beds: 1 }] },
    { checkIn: T(2), checkOut: T(4), rooms: [{ type: 'Deluxe', beds: 1 }] },
    { checkIn: T(3), checkOut: T(5), rooms: [{ type: 'Deluxe', beds: 1 }] },
    { checkIn: T(1), checkOut: T(3), rooms: [{ type: 'Deluxe', beds: 0 }] },
    { checkIn: T(2), checkOut: T(4), rooms: [{ type: 'Deluxe', beds: 1 }] },
  ]},
  'SEQ6': { kind: 'sequence', desc: '5× overlap ±1 วัน (co→ci ก่อนหน้า)', queue: [
    { checkIn: T(1), checkOut: T(4), rooms: Array(2).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(3), checkOut: T(6), rooms: Array(2).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(2), checkOut: T(5), rooms: Array(2).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(4), checkOut: T(7), rooms: Array(2).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(3), checkOut: T(6), rooms: Array(2).fill({ type: 'Deluxe', beds: 0 }) },
  ]},
  'SEQ7': { kind: 'sequence', desc: '4× twin pref ทุก booking', queue: [
    { checkIn: T(1), checkOut: T(3), rooms: Array(2).fill({ type: 'Deluxe', beds: 0, bed_preference: 'twin' }) },
    { checkIn: T(2), checkOut: T(4), rooms: Array(2).fill({ type: 'Deluxe', beds: 0, bed_preference: 'twin' }) },
    { checkIn: T(1), checkOut: T(3), rooms: Array(2).fill({ type: 'Deluxe', beds: 0, bed_preference: 'twin' }) },
    { checkIn: T(2), checkOut: T(4), rooms: Array(2).fill({ type: 'Deluxe', beds: 0, bed_preference: 'twin' }) },
  ]},
  'SEQ8': { kind: 'sequence', desc: '6× มี booking ยาว 5 คืน', queue: [
    { checkIn: T(1), checkOut: T(3), rooms: Array(2).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(6), rooms: Array(3).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(2), checkOut: T(4), rooms: [{ type: 'Suite', beds: 0 }] },
    { checkIn: T(3), checkOut: T(5), rooms: Array(2).fill({ type: 'Superior', beds: 0 }) },
    { checkIn: T(4), checkOut: T(6), rooms: [{ type: 'Deluxe', beds: 0 }] },
    { checkIn: T(5), checkOut: T(7), rooms: Array(2).fill({ type: 'Deluxe', beds: 0 }) },
  ]},
  'SEQ9': { kind: 'sequence', desc: '5× Deluxe×5 ทุก booking (stress)', queue: [
    { checkIn: T(1), checkOut: T(3), rooms: Array(5).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(3), rooms: Array(5).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(2), checkOut: T(4), rooms: Array(5).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(2), checkOut: T(4), rooms: Array(5).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(3), rooms: Array(5).fill({ type: 'Deluxe', beds: 0 }) },
  ]},
  'SEQ10': { kind: 'sequence', desc: '7× random mixed สมจริง', queue: [
    { checkIn: T(1), checkOut: T(3), rooms: [{ type: 'Deluxe', beds: 0 }, { type: 'Deluxe', beds: 1 }] },
    { checkIn: T(2), checkOut: T(4), rooms: [{ type: 'Suite', beds: 0 }, { type: 'Superior', beds: 0 }] },
    { checkIn: T(1), checkOut: T(3), rooms: Array(3).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(3), checkOut: T(5), rooms: Array(2).fill({ type: 'Superior', beds: 0 }) },
    { checkIn: T(2), checkOut: T(4), rooms: [{ type: 'Deluxe', beds: 0 }] },
    { checkIn: T(4), checkOut: T(6), rooms: [{ type: 'Deluxe', beds: 0 }, { type: 'Deluxe', beds: 0 }] },
    { checkIn: T(1), checkOut: T(3), rooms: [{ type: 'Deluxe', beds: 0, bed_preference: 'twin' }] },
  ]},

  // ===== Heavy Sequence (3) — 5+ booking × 3-5 rooms each (push concurrent occupancy) =====
  'SEQ11': { kind: 'sequence', desc: '6× Deluxe×4 วันเดียวกัน (peak 24)', queue: [
    { checkIn: T(1), checkOut: T(3), rooms: Array(4).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(3), rooms: Array(4).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(3), rooms: Array(4).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(3), rooms: Array(4).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(3), rooms: Array(4).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(3), rooms: Array(4).fill({ type: 'Deluxe', beds: 0 }) },
  ]},
  'SEQ12': { kind: 'sequence', desc: '7× mixed 3-5 rooms overlap วันเดียว', queue: [
    { checkIn: T(1), checkOut: T(3), rooms: Array(5).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(3), rooms: Array(3).fill({ type: 'Suite', beds: 0 }) },
    { checkIn: T(1), checkOut: T(3), rooms: Array(4).fill({ type: 'Superior', beds: 0 }) },
    { checkIn: T(1), checkOut: T(3), rooms: Array(5).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(3), rooms: Array(4).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(3), rooms: Array(3).fill({ type: 'Suite', beds: 0 }) },
    { checkIn: T(1), checkOut: T(3), rooms: Array(5).fill({ type: 'Superior', beds: 0 }) },
  ]},
  'SEQ13': { kind: 'sequence', desc: '5× 3-5 rooms overlap ±1 วัน', queue: [
    { checkIn: T(1), checkOut: T(4), rooms: Array(5).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(2), checkOut: T(5), rooms: Array(4).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(1), checkOut: T(4), rooms: Array(5).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(3), checkOut: T(6), rooms: Array(3).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(2), checkOut: T(5), rooms: Array(4).fill({ type: 'Deluxe', beds: 0 }) },
  ]},
};

const ALGO_KEYS = ['A', 'B', 'C', 'D', 'E'];

// ============================================================
// 📊 Availability & skip helpers
// ============================================================

// นับห้อง type ที่ว่างในช่วงวัน [ci,co) ทั้ง state.rooms (รวม seed reservations)
function countAvailByType(state, ci, co) {
  const out = { Deluxe: 0, Suite: 0, Superior: 0 };
  state.rooms.forEach(r => {
    if (exposed.isAvailable(r, ci, co)) out[r.type]++;
  });
  return out;
}

// required[type] รวมทุก booking ใน case (single หรือ sequence)
function requiredByType(caseObj) {
  const out = { Deluxe: 0, Suite: 0, Superior: 0 };
  const items = caseObj.kind === 'sequence' ? caseObj.queue : [caseObj];
  items.forEach(b => b.rooms.forEach(r => { out[r.type] = (out[r.type] || 0) + 1; }));
  return out;
}

// ⚠ หมายเหตุ: availability check แบบ "รวมทุก booking ใน case" ใช้ worst-case
//   คือ required[type] สูงสุดใน any overlapping interval — เพราะ online assignment
//   commit ไปเรื่อย ๆ availability ลดลง เราจึง check แบบ conservative:
//   ถ้าในช่วงวันที่คุกคุกที่สุด (max simultaneous) type ไม่พอ → skip
//
//   สำหรับ single: avail[type] ณ ci..co
//   สำหรับ sequence: avail[type] ณ แต่ละ booking interval, required สะสมในช่วง overlap
//     → worst case คือ max concurrent required ของ type นั้น
function shouldSkip(state, caseObj) {
  if (caseObj.kind === 'single') {
    const avail = countAvailByType(state, caseObj.checkIn, caseObj.checkOut);
    const req = requiredByType(caseObj);
    for (const t of ['Deluxe', 'Suite', 'Superior']) {
      if (req[t] > avail[t]) return `${t} required=${req[t]} avail=${avail[t]}`;
    }
    return null;
  }
  // sequence: จำลองแบบ conservative — สะสม required ในแต่ละ interval overlap
  // ใช้ "max required[type] ที่วันใดวันหนึ่งต้องการพร้อมกัน"
  const days = {};
  caseObj.queue.forEach(b => {
    let d = b.checkIn;
    let guard = 0;
    while (d !== b.checkOut && guard++ < 100) {
      if (!days[d]) days[d] = { Deluxe: 0, Suite: 0, Superior: 0 };
      b.rooms.forEach(r => { days[d][r.type]++; });
      d = addDaysLocal(d, 1);
    }
  });
  // avail ณ แต่ละวัน (overlapping check) — เรา check แบบ max-day required
  for (const d of Object.keys(days)) {
    const avail = countAvailByType(state, d, addDaysLocal(d, 1));
    for (const t of ['Deluxe', 'Suite', 'Superior']) {
      if (days[d][t] > avail[t]) {
        return `${t} on ${d}: required=${days[d][t]} avail=${avail[t]}`;
      }
    }
  }
  return null;
}

// ============================================================
// 🏃 Run helpers
// ============================================================

// run single-shot: คืน { results: {A:res,...}, times: {A:ms,...}, summary }
function runSingle(state, caseObj) {
  state.currentBooking.checkIn = caseObj.checkIn;
  state.currentBooking.checkOut = caseObj.checkOut;
  state.currentBooking.rooms = caseObj.rooms;
  state.evalDates = { checkIn: caseObj.checkIn, checkOut: caseObj.checkOut };
  const out = {};
  const times = {};
  ALGO_KEYS.forEach(k => {
    exposed.clearAssigned();
    state.evalDates = { checkIn: caseObj.checkIn, checkOut: caseObj.checkOut };
    const booking = JSON.parse(JSON.stringify(caseObj.rooms));
    const t0 = Date.now();
    const res = exposed.ALGOS[k].fn(booking);
    times[k] = Date.now() - t0;
    const m = res.ok ? exposed.computeMetrics(res, caseObj.rooms) : null;
    out[k] = { res, m };
  });
  return { results: out, times };
}

// run sequence: จำลอง processQueue (commit reservation ทุก success) ด้วย algo E เป็นหลัก
//   คืน { perBooking: [{ok,cost,rooms,booking,ms}], allOk, totalCost, times:[ms] }
function runSequence(state, caseObj) {
  const perBooking = [];
  const times = [];
  let totalCost = 0;
  let allOk = true;
  caseObj.queue.forEach((b, i) => {
    state.currentBooking.checkIn = b.checkIn;
    state.currentBooking.checkOut = b.checkOut;
    state.currentBooking.rooms = b.rooms;
    state.evalDates = { checkIn: b.checkIn, checkOut: b.checkOut };
    exposed.clearAssigned();
    const booking = JSON.parse(JSON.stringify(b.rooms));
    const t0 = Date.now();
    const res = exposed.ALGOS.E.fn(booking);
    const ms = Date.now() - t0;
    times.push(ms);
    if (res.ok) {
      const m = exposed.computeMetrics(res, b.rooms);
      // commit reservation (เหมือน processQueue)
      res.assignments.forEach((r, idx) => {
        if (r) {
          const room = state.rooms.find(x => x.num === r.num);
          if (room) {
            const br = b.rooms[idx] || {};
            room.reservations.push({
              checkIn: br.checkIn || b.checkIn,
              checkOut: br.checkOut || b.checkOut,
              label: `Q#${i + 1}`, type: 'queue',
            });
          }
        }
      });
      totalCost += res.cost;
      perBooking.push({
        ok: true, cost: res.cost, floors: m.floors, contig: m.contiguous, ms,
        rooms: res.assignments.filter(x => x).map(r => r.num),
      });
    } else {
      allOk = false;
      perBooking.push({ ok: false, cost: Infinity, rooms: [], ms });
    }
  });
  return { perBooking, allOk, totalCost, times };
}

// ============================================================
// 🎯 Main loop — cartesian product
// ============================================================
const seedNames = Object.keys(SEEDS);
const caseNames = Object.keys(CASES);

console.log('='.repeat(76));
console.log(`🌱🤝🎯 SEED × CASE = ${seedNames.length} × ${caseNames.length} = ${seedNames.length * caseNames.length} pairs`);
console.log(`   Algorithms per single: ${ALGO_KEYS.join(',')}`);
console.log(`   Sequence: processQueue-style (algo E), commit each booking`);
console.log('='.repeat(76));

let runCount = 0, skipCount = 0, failCount = 0;
const matrix = {}; // matrix[seed][case] = string (cell)

for (const sName of seedNames) {
  matrix[sName] = {};
  for (const cName of caseNames) {
    const caseObj = CASES[cName];
    console.log(`\n════ ${sName} (${SEEDS[sName].desc})  ×  ${cName} (${CASES[cName].desc}) ════`);

    exposed.resetAll();
    const ci = caseObj.kind === 'single' ? caseObj.checkIn : caseObj.queue[0].checkIn;
    const co = caseObj.kind === 'single' ? caseObj.checkOut : caseObj.queue[0].checkOut;
    // apply seed — sync ci/co กับ currentBooking ก่อน
    exposed.state.currentBooking.checkIn = ci;
    exposed.state.currentBooking.checkOut = co;
    exposed.state.evalDates = { checkIn: ci, checkOut: co };
    SEEDS[sName].fn(exposed.state, ci, co);

    // availability snapshot
    const avail = countAvailByType(exposed.state, ci, co);
    console.log(`   availability @${ci}..${co}: Deluxe=${avail.Deluxe} Suite=${avail.Suite} Superior=${avail.Superior}`);

    // skip check
    const reason = shouldSkip(exposed.state, caseObj);
    if (reason) {
      console.log(`   ⛔ SKIP — ${reason}`);
      matrix[sName][cName] = '⛔';
      skipCount++;
      continue;
    }

    if (caseObj.kind === 'single') {
      const { results: out, times } = runSingle(exposed.state, caseObj);
      let cellParts = [];
      ALGO_KEYS.forEach(k => {
        const { res, m } = out[k];
        const ms = times[k];
        const slow = ms > 500 ? ' ⏰SLOW' : '';
        if (!res.ok) {
          console.log(`   ${k}: ❌ FAIL${slow ? ` (${ms}ms)` : ''}`);
          cellParts.push(`${k}:✗`);
          failCount++;
        } else {
          const rooms = res.assignments.filter(x => x).map(r => r.num).join(',');
          console.log(
            `   ${k}: ✅ cost=${res.cost.toFixed(1)} floors=${m.floors} contig=${m.contiguous}% ` +
            `bedWaste=${m.bedWaste}${ms > 50 ? ` (${ms}ms)` : ''}${slow} rooms=[${rooms}]`,
          );
          cellParts.push(`${k}:${res.cost.toFixed(0)}`);
          runCount++;
        }
      });
      // cell = E cost (primary)
      const e = out.E;
      matrix[sName][cName] = e.res.ok ? `E${e.res.cost.toFixed(0)}` : '✗';
    } else {
      // sequence
      const seq = runSequence(exposed.state, caseObj);
      seq.perBooking.forEach((pb, i) => {
        const slow = pb.ms > 500 ? ' ⏰SLOW' : '';
        if (pb.ok) {
          console.log(`   Q#${i + 1}: ✅ cost=${pb.cost.toFixed(1)} floors=${pb.floors} contig=${pb.contig}%${pb.ms > 50 ? ` (${pb.ms}ms)` : ''}${slow} rooms=[${pb.rooms.join(',')}]`);
        } else {
          console.log(`   Q#${i + 1}: ❌ FAIL${slow ? ` (${pb.ms}ms)` : ''}`);
        }
      });
      const seqMs = seq.times.reduce((s, x) => s + x, 0);
      console.log(`   Σ totalCost=${seq.allOk ? seq.totalCost.toFixed(1) : '∞'} allOk=${seq.allOk} (${seqMs}ms total)`);
      if (seq.allOk) { runCount++; matrix[sName][cName] = `Σ${seq.totalCost.toFixed(0)}`; }
      else { failCount++; matrix[sName][cName] = '✗'; }
    }
  }
}

// ============================================================
// 📋 Summary
// ============================================================
console.log('\n' + '='.repeat(76));
console.log('📋 SUMMARY');
console.log('='.repeat(76));
console.log(`   ✓ run:        ${runCount}`);
console.log(`   ⛔ skipped:    ${skipCount}`);
console.log(`   ❌ fail:       ${failCount}`);
console.log(`   total pairs:  ${seedNames.length * caseNames.length}`);

console.log('\n📊 Matrix (cell = E cost / Σ total for sequence / ⛔ skip / ✗ fail)');
// header
const colW = 8;
const header = 'SEED'.padEnd(10) + caseNames.map(c => c.padEnd(colW)).join('');
console.log(header);
console.log('-'.repeat(header.length));
for (const sName of seedNames) {
  const row = sName.padEnd(10) + caseNames.map(c => (matrix[sName][c] || '').padEnd(colW)).join('');
  console.log(row);
}
