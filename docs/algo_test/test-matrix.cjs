// ============================================================
// 🎯 Canonical Test Harness — Compose Model (seed → sequence → case)
//
//   seed (pre-booked state) ──→ sequence (list of cases) ──→ case (one booking)
//
//   Harness = cartesian product: SEED × CASE
//     - case 'single'    = 1 booking → run A/B/C/D/E
//     - case 'sequence'  = list of cases → simulate processQueue (algo E), commit each
//
//   3 output sections:
//     1. Matrix run (per-pair detail + matrix grid)
//     2. 🛡️ Regression checks (migrated from old test-presets, 11 assertions)
//     3. 🔬 Special per-room-date checks (D2/D3, 2 assertions) — coupled, ใช้ loadPreset
//
//   รัน: node docs/test-matrix.cjs
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

// ---- Stub DOM & browser globals ----
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
// 📅 Local date helpers (TZ-safe) — toISOString drifts under TZ+7
// ============================================================
function addDaysLocal(dateStr, n) {
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
// 🌱 SEEDS — pre-existing reservations (pre-booked state)
//   signature: (state, ci, co) => void — push reservations into state.rooms
//   ci/co = currentBooking checkIn/checkOut (sync with evalDates)
// ============================================================
const TYPE_WEIGHTS = [['Deluxe', 0.4], ['Superior', 0.4], ['Suite', 0.2]];
function pickType(rng) {
  const r = rng();
  let acc = 0;
  for (const [t, w] of TYPE_WEIGHTS) { acc += w; if (r < acc) return t; }
  return 'Deluxe';
}

// random seed: จอง N ห้อง สุ่ม type+วัน overlap ในช่วง today+1..today+5
function randomSeed(rng, count) {
  return function (state, ci, co) {
    const today = todayLocal();
    const pool = [...state.rooms];
    for (let i = pool.length - 1; i > 0; i--) {
      const j = Math.floor(rng() * (i + 1));
      [pool[i], pool[j]] = [pool[j], pool[i]];
    }
    const targets = pool.slice(0, Math.min(count, pool.length));
    targets.forEach(r => {
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

// RS1-RS10 equivalent: deterministic 40-70 rooms based on rsN (mulberry32 × knuth constant)
//   ใช้ logic เดียวกับ loadPreset('RSn') เพื่อให้ reproducible เหมือนเดิม
function rsSeed(rsNum) {
  const prng = mulberry32(rsNum * 2654435761);
  const bookedCount = 40 + Math.floor(prng() * 31); // 40..70
  return function (state, ci, co) {
    const pool = [...state.rooms];
    for (let i = pool.length - 1; i > 0; i--) {
      const j = Math.floor(prng() * (i + 1));
      [pool[i], pool[j]] = [pool[j], pool[i]];
    }
    pool.slice(0, bookedCount).forEach(r => {
      const offset = Math.floor(prng() * 5) - 2; // -2..2
      const start = addDaysLocal(ci, offset);
      const nights = 1 + Math.floor(prng() * 3); // 1-3 คืน
      const end = addDaysLocal(start, nights);
      r.reservations.push({ checkIn: start, checkOut: end, label: 'Pre-booked', type: 'seed' });
    });
  };
}

const SEEDS = {
  // ===== Handcrafted (7) — original from seed-pairs =====
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

  // ===== Regression-derived (7) — migrate from test-presets =====
  'S-NONE': { desc: 'ไม่มี pre-booked (สะอาด)', fn(state, ci, co) {} },
  'S-508': { desc: 'จอง 508 เต็มช่วง currentBooking', fn(state, ci, co) {
    state.rooms.find(r => r.num === '508').reservations.push(
      { checkIn: ci, checkOut: co, label: 'Existing', type: 'seed' });
  }},
  'S-508BK2': { desc: '508 back-to-back (checkout=checkin ของ booking)', fn(state, ci, co) {
    state.rooms.find(r => r.num === '508').reservations.push({
      checkIn: addDaysLocal(ci, -2), checkOut: ci,
      label: 'Checkout today', type: 'seed',
    });
  }},
  'S-F8': { desc: 'Deluxe ทุกห้องยกเว้นชั้น 8 เต็ม', fn(state, ci, co) {
    state.rooms.filter(r => r.type === 'Deluxe' && r.floor !== 8).forEach(r => {
      r.reservations.push({ checkIn: ci, checkOut: co, label: 'Full', type: 'seed' });
    });
  }},
  'S-X09ONLY': { desc: 'Deluxe ทุกห้องยกเว้น X09 เต็ม', fn(state, ci, co) {
    state.rooms.filter(r => r.type === 'Deluxe' && !r.num.endsWith('09')).forEach(r => {
      r.reservations.push({ checkIn: ci, checkOut: co, label: 'Full', type: 'seed' });
    });
  }},
  'S-D40': { desc: 'Deluxe 40 ห้องแรกเต็ม', fn(state, ci, co) {
    state.rooms.filter(r => r.type === 'Deluxe').slice(0, 40).forEach(r => {
      r.reservations.push({ checkIn: ci, checkOut: co, label: 'Existing', type: 'seed' });
    });
  }},
  'S-D5K-': { desc: 'Deluxe ชั้น 5,6,7 ครึ่ง (index เลขคู่)', fn(state, ci, co) {
    state.rooms.filter(r => r.type === 'Deluxe' && [5, 6, 7].includes(r.floor)).forEach((r, i) => {
      if (i % 2 === 0) {
        r.reservations.push({ checkIn: ci, checkOut: co, label: 'Existing', type: 'seed' });
      }
    });
  }},

  // ===== Random deterministic (20) =====
  // S-RND1..S-RND10 (original seed-pairs, ~50-70 mixed)
  'S-RND1':  { desc: '~60 mixed spread',     fn: randomSeed(mulberry32(42), 60) },
  'S-RND2':  { desc: '~55 Deluxe-heavy',     fn: randomSeed(mulberry32(7),  55) },
  'S-RND3':  { desc: '~65 ชั้น 5,6 heavy',   fn: randomSeed(mulberry32(99), 65) },
  'S-RND4':  { desc: '~50 checkerboard-ish', fn: randomSeed(mulberry32(13), 50) },
  'S-RND5':  { desc: '~70 peak',             fn: randomSeed(mulberry32(55), 70) },
  'S-RND6':  { desc: '~60 mixed types',      fn: randomSeed(mulberry32(3),  60) },
  'S-RND7':  { desc: '~58 Superior-heavy',   fn: randomSeed(mulberry32(88), 58) },
  'S-RND8':  { desc: '~62 Suite-heavy',      fn: randomSeed(mulberry32(21), 62) },
  'S-RND9':  { desc: '~67 clustered',        fn: randomSeed(mulberry32(66), 67) },
  'S-RND10': { desc: '~53 spread',           fn: randomSeed(mulberry32(11), 53) },
  // S-RND11..S-RND20 (RS1-RS10 migration: deterministic 40-70, knuth-constant seeded)
  'S-RND11': { desc: 'RS1 equivalent (40-70)', fn: rsSeed(1) },
  'S-RND12': { desc: 'RS2 equivalent (40-70)', fn: rsSeed(2) },
  'S-RND13': { desc: 'RS3 equivalent (40-70)', fn: rsSeed(3) },
  'S-RND14': { desc: 'RS4 equivalent (40-70)', fn: rsSeed(4) },
  'S-RND15': { desc: 'RS5 equivalent (40-70)', fn: rsSeed(5) },
  'S-RND16': { desc: 'RS6 equivalent (40-70)', fn: rsSeed(6) },
  'S-RND17': { desc: 'RS7 equivalent (40-70)', fn: rsSeed(7) },
  'S-RND18': { desc: 'RS8 equivalent (40-70)', fn: rsSeed(8) },
  'S-RND19': { desc: 'RS9 equivalent (40-70)', fn: rsSeed(9) },
  'S-RND20': { desc: 'RS10 equivalent (40-70)', fn: rsSeed(10) },
};

// ============================================================
// 🎯 CASES — booking ที่จะ assign
//   single:   { checkIn, checkOut, rooms }
//   sequence: { queue: [{checkIn, checkOut, rooms}, ...] }
// ============================================================
const CASES = {
  // ===== Single-shot (12) =====
  'C-SOLO':  { kind: 'single', desc: 'Solo Deluxe', checkIn: T(1), checkOut: T(3),
    rooms: [{ type: 'Deluxe', beds: 0 }] },
  'C-G4':    { kind: 'single', desc: 'Group×4 Deluxe', checkIn: T(1), checkOut: T(3),
    rooms: Array(4).fill({ type: 'Deluxe', beds: 0 }) },
  'C-MIX':   { kind: 'single', desc: 'Mixed Suite/Deluxe/Superior', checkIn: T(1), checkOut: T(3),
    rooms: [
      { type: 'Suite', beds: 0 },
      { type: 'Deluxe', beds: 0 }, { type: 'Deluxe', beds: 0 },
      { type: 'Superior', beds: 0 },
    ] },
  'C-FAM':   { kind: 'single', desc: 'Family (extra-bed)', checkIn: T(1), checkOut: T(3),
    rooms: [{ type: 'Deluxe', beds: 0 }, { type: 'Deluxe', beds: 1 }] },
  'C-L10':   { kind: 'single', desc: 'Large×10 Deluxe', checkIn: T(1), checkOut: T(3),
    rooms: Array(10).fill({ type: 'Deluxe', beds: 0 }) },
  'C-TWIN':  { kind: 'single', desc: '2× Deluxe pref=twin', checkIn: T(1), checkOut: T(3),
    rooms: Array(2).fill({ type: 'Deluxe', beds: 0, bed_preference: 'twin' }) },
  // regression-derived
  'C-SD':    { kind: 'single', desc: 'Suite + Deluxe', checkIn: T(1), checkOut: T(3),
    rooms: [{ type: 'Suite', beds: 0 }, { type: 'Deluxe', beds: 0 }] },
  'C-S3':    { kind: 'single', desc: 'Suite×3', checkIn: T(1), checkOut: T(3),
    rooms: Array(3).fill({ type: 'Suite', beds: 0 }) },
  'C-D5':    { kind: 'single', desc: 'Deluxe×5', checkIn: T(1), checkOut: T(3),
    rooms: Array(5).fill({ type: 'Deluxe', beds: 0 }) },
  'C-SUP2':  { kind: 'single', desc: 'Superior×2', checkIn: T(1), checkOut: T(3),
    rooms: Array(2).fill({ type: 'Superior', beds: 0 }) },
  'C-TWIN1': { kind: 'single', desc: 'Deluxe×1 pref=twin', checkIn: T(1), checkOut: T(3),
    rooms: [{ type: 'Deluxe', beds: 0, bed_preference: 'twin' }] },
  'C-D20':   { kind: 'single', desc: 'Deluxe×20 (impossible)', checkIn: T(1), checkOut: T(3),
    rooms: Array(20).fill({ type: 'Deluxe', beds: 0 }) },

  // ===== Per-Room Date cases (single) — แต่ละ room มี checkIn/Out ของตัวเอง =====
  //   ทดสอบว่า algorithm + bipartite matching จัดการกรณีที่ทุก room ใน booking
  //   มี availability mask (เกราะ) ต่างกันได้จริง — ไม่ใช่ union วันเดียว
  //
  //   checkIn/Out ระดับ booking (T(1)/T(3)) ใช้เป็น fallback + evalDates เท่านั้น
  //   algo อ่าน date จาก br.checkIn/Out ผ่าน brDates(br) ใน playground
  'C-PRD1':  { kind: 'single', desc: 'PRD Deluxe×3 slot2 คนละวัน', checkIn: T(1), checkOut: T(3),
    rooms: [
      { type: 'Deluxe', beds: 0, checkIn: T(1), checkOut: T(3) },
      { type: 'Deluxe', beds: 0, checkIn: T(1), checkOut: T(3) },
      { type: 'Deluxe', beds: 0, checkIn: T(4), checkOut: T(6) },   // ← คนละช่วง
    ] },
  'C-PRD2':  { kind: 'single', desc: 'PRD Mixed×4 ทุก room คนละวัน', checkIn: T(1), checkOut: T(3),
    rooms: [
      { type: 'Suite',    beds: 0, checkIn: T(1), checkOut: T(3) },
      { type: 'Deluxe',   beds: 0, checkIn: T(2), checkOut: T(4) },
      { type: 'Deluxe',   beds: 0, checkIn: T(3), checkOut: T(5) },
      { type: 'Superior', beds: 0, checkIn: T(1), checkOut: T(3) },
    ] },
  'C-PRD3':  { kind: 'single', desc: 'PRD Deluxe×4 2 cohort วัน A/B', checkIn: T(1), checkOut: T(3),
    rooms: [
      { type: 'Deluxe', beds: 0, checkIn: T(1), checkOut: T(3) },
      { type: 'Deluxe', beds: 0, checkIn: T(1), checkOut: T(3) },
      { type: 'Deluxe', beds: 0, checkIn: T(4), checkOut: T(6) },
      { type: 'Deluxe', beds: 0, checkIn: T(4), checkOut: T(6) },
    ] },
  'C-PRD4':  { kind: 'single', desc: 'PRD Deluxe×5 staggered dates', checkIn: T(1), checkOut: T(3),
    rooms: [
      { type: 'Deluxe', beds: 0, checkIn: T(1), checkOut: T(3) },
      { type: 'Deluxe', beds: 0, checkIn: T(2), checkOut: T(4) },
      { type: 'Deluxe', beds: 0, checkIn: T(3), checkOut: T(5) },
      { type: 'Deluxe', beds: 0, checkIn: T(4), checkOut: T(6) },
      { type: 'Deluxe', beds: 0, checkIn: T(5), checkOut: T(7) },
    ] },

  // ===== Sequence/Queue (10) — list of cases =====
  'SEQ1': { kind: 'sequence', desc: '4× Deluxe×3 ไม่ overlap', queue: [
    { checkIn: T(1), checkOut: T(4), rooms: Array(3).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(5), checkOut: T(8), rooms: Array(3).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(9), checkOut: T(12), rooms: Array(3).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(13), checkOut: T(16), rooms: Array(3).fill({ type: 'Deluxe', beds: 0 }) },
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
  'SEQ6': { kind: 'sequence', desc: '5× back-to-back (co=ci ถัดไป)', queue: [
    { checkIn: T(1), checkOut: T(4), rooms: Array(2).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(4), checkOut: T(7), rooms: Array(2).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(7), checkOut: T(10), rooms: Array(2).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(10), checkOut: T(13), rooms: Array(2).fill({ type: 'Deluxe', beds: 0 }) },
    { checkIn: T(13), checkOut: T(16), rooms: Array(2).fill({ type: 'Deluxe', beds: 0 }) },
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
  // ===== Per-Room Date sequences — ทุก booking มี room ที่ date ของตัวเอง =====
  'SEQ11': { kind: 'sequence', desc: '3× Deluxe×3 intra-booking คนละวัน', queue: [
    { checkIn: T(1), checkOut: T(3), rooms: [
      { type: 'Deluxe', beds: 0, checkIn: T(1), checkOut: T(3) },
      { type: 'Deluxe', beds: 0, checkIn: T(1), checkOut: T(3) },
      { type: 'Deluxe', beds: 0, checkIn: T(4), checkOut: T(6) },
    ] },
    { checkIn: T(2), checkOut: T(4), rooms: [
      { type: 'Deluxe', beds: 0, checkIn: T(2), checkOut: T(4) },
      { type: 'Deluxe', beds: 0, checkIn: T(5), checkOut: T(7) },
      { type: 'Deluxe', beds: 0, checkIn: T(2), checkOut: T(4) },
    ] },
    { checkIn: T(3), checkOut: T(5), rooms: [
      { type: 'Deluxe', beds: 0, checkIn: T(3), checkOut: T(5) },
      { type: 'Deluxe', beds: 0, checkIn: T(6), checkOut: T(8) },
      { type: 'Deluxe', beds: 0, checkIn: T(3), checkOut: T(5) },
    ] },
  ]},
  'SEQ12': { kind: 'sequence', desc: '4× mixed intra+inter per-room overlap', queue: [
    { checkIn: T(1), checkOut: T(3), rooms: [
      { type: 'Suite',  beds: 0, checkIn: T(1), checkOut: T(3) },
      { type: 'Deluxe', beds: 0, checkIn: T(2), checkOut: T(4) },
    ] },
    { checkIn: T(1), checkOut: T(3), rooms: [
      { type: 'Deluxe',   beds: 0, checkIn: T(1), checkOut: T(3) },
      { type: 'Superior', beds: 0, checkIn: T(2), checkOut: T(4) },
    ] },
    { checkIn: T(2), checkOut: T(4), rooms: [
      { type: 'Deluxe', beds: 0, checkIn: T(2), checkOut: T(4) },
      { type: 'Deluxe', beds: 0, checkIn: T(5), checkOut: T(7) },
    ] },
    { checkIn: T(3), checkOut: T(5), rooms: [
      { type: 'Suite',    beds: 0, checkIn: T(3), checkOut: T(5) },
      { type: 'Superior', beds: 0, checkIn: T(6), checkOut: T(8) },
    ] },
  ]},
  'SEQ13': { kind: 'sequence', desc: '5× Deluxe×2 หลายช่วง multi-date', queue: [
    { checkIn: T(1), checkOut: T(3), rooms: [
      { type: 'Deluxe', beds: 0, checkIn: T(1), checkOut: T(3) },
      { type: 'Deluxe', beds: 0, checkIn: T(4), checkOut: T(6) },
    ] },
    { checkIn: T(2), checkOut: T(4), rooms: [
      { type: 'Deluxe', beds: 0, checkIn: T(2), checkOut: T(4) },
      { type: 'Deluxe', beds: 0, checkIn: T(5), checkOut: T(7) },
    ] },
    { checkIn: T(3), checkOut: T(5), rooms: [
      { type: 'Deluxe', beds: 0, checkIn: T(3), checkOut: T(5) },
      { type: 'Deluxe', beds: 0, checkIn: T(6), checkOut: T(8) },
    ] },
    { checkIn: T(4), checkOut: T(6), rooms: [
      { type: 'Deluxe', beds: 0, checkIn: T(4), checkOut: T(6) },
      { type: 'Deluxe', beds: 0, checkIn: T(7), checkOut: T(9) },
    ] },
    { checkIn: T(5), checkOut: T(7), rooms: [
      { type: 'Deluxe', beds: 0, checkIn: T(5), checkOut: T(7) },
      { type: 'Deluxe', beds: 0, checkIn: T(8), checkOut: T(10) },
    ] },
  ]},
};

const ALGO_KEYS = ['A', 'B', 'C', 'D', 'E'];

// ============================================================
// 📊 Availability & skip helpers
// ============================================================
function countAvailByType(state, ci, co) {
  const out = { Deluxe: 0, Suite: 0, Superior: 0 };
  state.rooms.forEach(r => {
    if (exposed.isAvailable(r, ci, co)) out[r.type]++;
  });
  return out;
}

function requiredByType(caseObj) {
  const out = { Deluxe: 0, Suite: 0, Superior: 0 };
  const items = caseObj.kind === 'sequence' ? caseObj.queue : [caseObj];
  items.forEach(b => b.rooms.forEach(r => { out[r.type] = (out[r.type] || 0) + 1; }));
  return out;
}

function shouldSkip(state, caseObj) {
  // [Per-Room Dates] แต่ละ room อาจมี checkIn/Out ของตัวเอง → แยก availability ตาม (date-range, type)
  //   รวบรวม demand ทุก room โดยใช้ date ของ room นั้น (fallback ไป booking-level)
  //   สำหรับ sequence: commit ทำให้ availability ลดลงตามลำดับ แต่ shouldSkip เป็น pre-check
  //   แบบ worst-case (รวม demand ทุก booking ใน queue) — เพื่อ skip pair ที่เป็นไปไม่ได้แน่ ๆ
  const bookings = caseObj.kind === 'single' ? [caseObj] : caseObj.queue;
  // demand[key] = { Deluxe, Suite, Superior } โดย key = `${ci}_${co}`
  const demand = {};
  bookings.forEach(b => {
    b.rooms.forEach(r => {
      const ci = r.checkIn || b.checkIn;
      const co = r.checkOut || b.checkOut;
      const key = `${ci}_${co}`;
      if (!demand[key]) demand[key] = { Deluxe: 0, Suite: 0, Superior: 0, ci, co };
      demand[key][r.type]++;
    });
  });
  for (const key of Object.keys(demand)) {
    const d = demand[key];
    const avail = countAvailByType(state, d.ci, d.co);
    for (const t of ['Deluxe', 'Suite', 'Superior']) {
      if (d[t] > avail[t]) {
        return `${t} on ${d.ci}..${d.co}: required=${d[t]} avail=${avail[t]}`;
      }
    }
  }
  return null;
}

// ============================================================
// 🏃 Run helpers
// ============================================================
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
        res, m,
      });
    } else {
      allOk = false;
      perBooking.push({ ok: false, cost: Infinity, rooms: [], ms, res, m: null });
    }
  });
  return { perBooking, allOk, totalCost, times };
}

// ============================================================
// 🎯 Main loop — cartesian product (seed × case)
// ============================================================
const seedNames = Object.keys(SEEDS);
const caseNames = Object.keys(CASES);
const matrixCache = {}; // matrixCache[seed][case] = { result: {A:{res,m},...} | {E:{res,m}, perBooking, allOk} }

console.log('='.repeat(76));
console.log(`🌱🤝🎯 COMPOSE TEST MATRIX = ${seedNames.length} seeds × ${caseNames.length} cases = ${seedNames.length * caseNames.length} pairs`);
console.log(`   Algorithms per single: ${ALGO_KEYS.join(',')}`);
console.log(`   Sequence: processQueue-style (algo E), commit each booking`);
console.log('='.repeat(76));

let runCount = 0, skipCount = 0, failCount = 0;
const matrix = {};

for (const sName of seedNames) {
  matrix[sName] = {};
  matrixCache[sName] = {};
  for (const cName of caseNames) {
    const caseObj = CASES[cName];
    console.log(`\n════ ${sName} (${SEEDS[sName].desc})  ×  ${cName} (${CASES[cName].desc}) ════`);

    exposed.resetAll();
    const ci = caseObj.kind === 'single' ? caseObj.checkIn : caseObj.queue[0].checkIn;
    const co = caseObj.kind === 'single' ? caseObj.checkOut : caseObj.queue[0].checkOut;
    exposed.state.currentBooking.checkIn = ci;
    exposed.state.currentBooking.checkOut = co;
    exposed.state.evalDates = { checkIn: ci, checkOut: co };
    SEEDS[sName].fn(exposed.state, ci, co);

    const avail = countAvailByType(exposed.state, ci, co);
    console.log(`   availability @${ci}..${co}: Deluxe=${avail.Deluxe} Suite=${avail.Suite} Superior=${avail.Superior}`);

    const reason = shouldSkip(exposed.state, caseObj);
    if (reason) {
      console.log(`   ⛔ SKIP — ${reason}`);
      matrix[sName][cName] = '⛔';
      matrixCache[sName][cName] = { skipped: true, reason };
      skipCount++;
      continue;
    }

    if (caseObj.kind === 'single') {
      const { results: out, times } = runSingle(exposed.state, caseObj);
      matrixCache[sName][cName] = { result: out };
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
      const e = out.E;
      matrix[sName][cName] = e.res.ok ? `E${e.res.cost.toFixed(0)}` : '✗';
    } else {
      const seq = runSequence(exposed.state, caseObj);
      matrixCache[sName][cName] = {
        result: { E: seq.perBooking.length ? seq.perBooking[seq.perBooking.length - 1] : null },
        perBooking: seq.perBooking, allOk: seq.allOk, totalCost: seq.totalCost,
      };
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
// 📋 Matrix rendering
// ============================================================
console.log('\n' + '='.repeat(76));
console.log('📊 MATRIX (cell = E cost / Σ total for sequence / ⛔ skip / ✗ fail)');
console.log('='.repeat(76));
const colW = 8;
const header = 'SEED'.padEnd(11) + caseNames.map(c => c.padEnd(colW)).join('');
console.log(header);
console.log('-'.repeat(header.length));
for (const sName of seedNames) {
  const row = sName.padEnd(11) + caseNames.map(c => (matrix[sName][c] || '').padEnd(colW)).join('');
  console.log(row);
}

// ============================================================
// 🛡️ Regression Checks — migrated from old test-presets (seed × case pairs)
//   ทุก pair นี้ต้อง force-run (ข้าม shouldSkip) เพราะเป็น regression ที่คาดหวังผลเฉพาะ
// ============================================================
console.log('\n' + '='.repeat(76));
console.log('🛡️ Regression Checks (migrated from test-presets)');
console.log('='.repeat(76));

function runRegressionPair(seedName, caseName, algo) {
  // force-run: ข้าม shouldSkip (regression คาดหวังผลเฉพาะ เช่น S4 ต้อง FAIL)
  exposed.resetAll();
  const caseObj = CASES[caseName];
  const ci = caseObj.checkIn, co = caseObj.checkOut;
  exposed.state.currentBooking.checkIn = ci;
  exposed.state.currentBooking.checkOut = co;
  exposed.state.evalDates = { checkIn: ci, checkOut: co };
  SEEDS[seedName].fn(exposed.state, ci, co);
  const { results } = runSingle(exposed.state, caseObj);
  return { res: results[algo].res, m: results[algo].m };
}

const REGRESSIONS = [
  { seed: 'S-NONE', case: 'C-SD', algo: 'E', label: 'R1 cost ≈ 3',
    check: ({ res }) => res && res.ok && Math.abs(res.cost - 3) < 1,
    detail: ({ res }) => `cost=${res.cost.toFixed(1)}` },
  { seed: 'S-NONE', case: 'C-SD', algo: 'E', label: 'R1 contiguous 100%',
    check: ({ m }) => m && m.contiguous === 100,
    detail: ({ m }) => `contig=${m.contiguous}%` },
  { seed: 'S-508', case: 'C-SD', algo: 'E', label: 'R2 หลีกห้อง 508 (reserved)',
    check: ({ res }) => res && res.ok && !res.assignments.some(a => a && a.num === '508'),
    detail: ({ res }) => `rooms=[${res.assignments.filter(a => a).map(a => a.num).join(',')}]` },
  { seed: 'S-NONE', case: 'C-S3', algo: 'E', label: 'R3 floors = 2 (not 3)',
    check: ({ m }) => m && m.floors === 2,
    detail: ({ m }) => `floors=${m.floors}` },
  { seed: 'S-NONE', case: 'C-G4', algo: 'E', label: 'R4 floors = 1',
    check: ({ m }) => m && m.floors === 1,
    detail: ({ m }) => `floors=${m.floors}` },
  { seed: 'S-NONE', case: 'C-SUP2', algo: 'E', label: 'R5 side breakdown = 0 (V2 รวม)',
    check: ({ m }) => m && m.breakdown.side === 0,
    detail: ({ m }) => `side=${m.breakdown.side}` },
  { seed: 'S-NONE', case: 'C-TWIN1', algo: 'E', label: 'R6 ได้ห้องชั้น 8 (twin)',
    check: ({ res }) => res && res.ok && res.assignments.filter(a => a).every(a => a.floor === 8),
    detail: ({ res }) => `floors=[${[...new Set(res.assignments.filter(a => a).map(a => a.floor))].join(',')}]` },
  { seed: 'S-D40', case: 'C-D20', algo: 'E', label: 'S4 impossible → FAIL ไม่ crash',
    check: ({ res }) => res && !res.ok,
    detail: ({ res }) => res && res.ok ? `unexpectedly ok cost=${res.cost.toFixed(1)}` : 'correctly failed' },
  { seed: 'S-NONE', case: 'C-SOLO', algo: 'E', label: 'E1 single room cost = 0',
    check: ({ res }) => res && res.ok && res.cost === 0,
    detail: ({ res }) => `cost=${res.cost.toFixed(1)}` },
  { seed: 'S-508BK2', case: 'C-SOLO', algo: 'E', label: 'E2 ใช้ 508 ได้ (back-to-back ไม่ overlap)',
    check: ({ res }) => res && res.ok && res.assignments.some(a => a && a.num === '508'),
    detail: ({ res }) => `rooms=[${res.assignments.filter(a => a).map(a => a.num).join(',')}]` },
  { seed: 'S-X09ONLY', case: 'C-D5', algo: 'E', label: 'E4 bed waste > 0',
    check: ({ m }) => m && m.bedWaste > 0,
    detail: ({ m }) => `bedWaste=${m.bedWaste}` },
  // ===== Per-Room Date regressions — ทดสอบว่า algo จัดการ br.checkIn/Out ของแต่ละ room ได้ =====
  { seed: 'S-NONE', case: 'C-PRD1', algo: 'E', label: 'PRD1 slot2 คนละวัน ยังจัดได้ (ok)',
    check: ({ res }) => res && res.ok,
    detail: ({ res }) => res.ok ? `ok cost=${res.cost.toFixed(1)}` : 'FAIL' },
  { seed: 'S-NONE', case: 'C-PRD1', algo: 'E', label: 'PRD1 contiguous 100% (matching หา block)',
    check: ({ m }) => m && m.contiguous === 100,
    detail: ({ m }) => `contig=${m.contiguous}%` },
  { seed: 'S-NONE', case: 'C-PRD2', algo: 'E', label: 'PRD2 Mixed per-room date จัดได้',
    check: ({ res }) => res && res.ok,
    detail: ({ res }) => res.ok ? `ok cost=${res.cost.toFixed(1)}` : 'FAIL' },
  { seed: 'S-NONE', case: 'C-PRD3', algo: 'E', label: 'PRD3 2 cohort วัน A/B จัดได้',
    check: ({ res }) => res && res.ok,
    detail: ({ res }) => res.ok ? `ok cost=${res.cost.toFixed(1)}` : 'FAIL' },
];

let regPass = 0, regFail = 0;
for (const reg of REGRESSIONS) {
  let ok = false, detail = '';
  try {
    const pair = runRegressionPair(reg.seed, reg.case, reg.algo);
    ok = reg.check(pair);
    detail = reg.detail(pair);
  } catch (e) {
    detail = `CRASH: ${e.message}`;
  }
  if (ok) {
    console.log(`  ✅ ${reg.label} — ${detail}`);
    regPass++;
  } else {
    console.log(`  ❌ ${reg.label} — ${detail}`);
    regFail++;
  }
}

// ============================================================
// 🔬 Special: per-room-date cases (D2/D3) — coupled, ไม่ decompose เป็น (seed,case)
//   ใช้ loadPreset จาก playground (preset switch ยังเก็บไว้เพื่อ UI)
// ============================================================
console.log('\n' + '='.repeat(76));
console.log('🔬 Special per-room-date checks (D2/D3) — coupled, uses loadPreset');
console.log('='.repeat(76));

let specialPass = 0, specialFail = 0;
function runSpecial(presetName, label, check, getDetail) {
  exposed.resetAll();
  exposed.loadPreset(presetName);
  const booking = exposed.state.currentBooking.rooms;
  exposed.state.evalDates = { checkIn: exposed.state.currentBooking.checkIn, checkOut: exposed.state.currentBooking.checkOut };
  let res, m, ok = false, detail = '';
  try {
    exposed.clearAssigned();
    res = exposed.ALGOS.E.fn(JSON.parse(JSON.stringify(booking)));
    m = res.ok ? exposed.computeMetrics(res, booking) : null;
    ok = check(res, m);
    detail = getDetail(res, m);
  } catch (e) {
    detail = `CRASH: ${e.message}`;
  }
  if (ok) { console.log(`  ✅ ${label} — ${detail}`); specialPass++; }
  else { console.log(`  ❌ ${label} — ${detail}`); specialFail++; }
}

// D1 baseline: Deluxe×3 same date → contiguous 100% + cost ≤ 9
runSpecial('D1', 'D1 contiguous 100% (same-date baseline)',
  (res, m) => res && res.ok && m.contiguous === 100,
  (res, m) => `contig=${m.contiguous}%`);
runSpecial('D1', 'D1 cost ≤ 9',
  (res, m) => res && res.ok && res.cost <= 9,
  (res, m) => `cost=${res.cost.toFixed(1)}`);

// D2: slot 2 คนละวัน ยังหา block ได้ → contiguous 100%
runSpecial('D2', 'D2 contiguous 100% (different-date still block)',
  (res, m) => res && res.ok && m.contiguous === 100,
  (res, m) => `contig=${m.contiguous}%`);

// D3: slot 2 ใช้ห้องที่ union availability จะปฏิเสธ (510/511/512)
runSpecial('D3', 'D3 slot 2 ใช้ห้องที่ union availability จะปฏิเสธ (510/511/512)',
  (res, m) => res && res.ok && res.assignments.some(a => a && ['510', '511', '512'].includes(a.num)),
  (res, m) => `rooms=[${res.assignments.filter(a => a).map(a => a.num).join(',')}]`);

// ============================================================
// 📋 Final Summary
// ============================================================
console.log('\n' + '='.repeat(76));
console.log('📋 FINAL SUMMARY');
console.log('='.repeat(76));
console.log(`   Matrix:  ✓ run=${runCount}  ⛔ skip=${skipCount}  ❌ fail=${failCount}  (of ${seedNames.length * caseNames.length} pairs)`);
console.log(`   🛡️ Regression: ${regPass}/${REGRESSIONS.length} passed`);
console.log(`   🔬 Special:    ${specialPass}/4 passed`);
console.log(`   TOTAL assertions: ${regPass + specialPass}/${REGRESSIONS.length + 4} passed`);
