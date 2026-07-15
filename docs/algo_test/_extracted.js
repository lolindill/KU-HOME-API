

// ============================================================
// 📅 Date Utilities
// ============================================================
function todayISO() {
  return new Date().toISOString().split('T')[0];
}
function addDays(dateStr, n) {
  const d = new Date(dateStr + 'T00:00:00');
  d.setDate(d.getDate() + n);
  return d.toISOString().split('T')[0];
}
function daysBetween(d1, d2) {
  const a = new Date(d1 + 'T00:00:00');
  const b = new Date(d2 + 'T00:00:00');
  return Math.round((b - a) / 86400000);
}
function fmtDate(dateStr) {
  return dateStr.substring(5); // MM-DD
}
function getNextFriday() {
  const d = new Date();
  const day = d.getDay();
  const diff = (5 - day + 7) % 7 || 7;
  d.setDate(d.getDate() + diff);
  return d.toISOString().split('T')[0];
}

// ============================================================
// 🔄 Overlap / Availability Logic
// ============================================================
const TIMELINE_DAYS = 14;

function rangesOverlap(aIn, aOut, bIn, bOut) {
  return aIn < bOut && bIn < aOut;
}

function hasOverlap(room, checkIn, checkOut) {
  return room.reservations.some(res => rangesOverlap(checkIn, checkOut, res.checkIn, res.checkOut));
}

function getOverlap(room, checkIn, checkOut) {
  return room.reservations.find(res => rangesOverlap(checkIn, checkOut, res.checkIn, res.checkOut));
}

function isAvailable(room, checkIn, checkOut) {
  return !hasOverlap(room, checkIn, checkOut);
}

function isRoomOccupiedOn(room, date) {
  return room.reservations.some(res => res.checkIn <= date && date < res.checkOut);
}

function getTimelineStart() {
  return todayISO();
}

// ============================================================
// 🏢 KU HOME Topology Data
// ============================================================
const FLOORS = [5, 6, 7, 8, 9];

function buildRooms() {
  const rooms = [];
  FLOORS.forEach(floor => {
    const v1 = [
      { num: `${floor}07`, type: 'Suite',    side: 'V1', pos: 1, beds: 1 },
      { num: `${floor}08`, type: 'Deluxe',   side: 'V1', pos: 2, beds: 1 },
      { num: `${floor}09`, type: 'Deluxe',   side: 'V1', pos: 3, beds: 3 },
      { num: `${floor}10`, type: 'Deluxe',   side: 'V1', pos: 4, beds: 1 },
      { num: `${floor}11`, type: 'Deluxe',   side: 'V1', pos: 5, beds: 1 },
      { num: `${floor}12`, type: 'Deluxe',   side: 'V1', pos: 6, beds: 1 },
      { num: `${floor}13`, type: 'Deluxe',   side: 'V1', pos: 7, beds: 1 },
      { num: `${floor}14`, type: 'Deluxe',   side: 'V1', pos: 8, beds: 1 },
      { num: `${floor}15`, type: 'Deluxe',   side: 'V1', pos: 9, beds: 1 },
      { num: `${floor}16`, type: 'Deluxe',   side: 'V1', pos: 10, beds: 1 },
      { num: `${floor}17`, type: 'Deluxe',   side: 'V1', pos: 11, beds: 1 },
      { num: `${floor}18`, type: 'Suite',    side: 'V1', pos: 12, beds: 1 },
    ];
    const v2a = [
      { num: `${floor}06`, type: 'Superior', side: 'V2A', pos: 1, beds: 1 },
      { num: `${floor}05`, type: 'Superior', side: 'V2A', pos: 2, beds: 1 },
      { num: `${floor}04`, type: 'Superior', side: 'V2A', pos: 3, beds: 1 },
      { num: `${floor}03`, type: 'Superior', side: 'V2A', pos: 4, beds: 1 },
      { num: `${floor}02`, type: 'Superior', side: 'V2A', pos: 5, beds: 1 },
      { num: `${floor}01`, type: 'Superior', side: 'V2A', pos: 6, beds: 1 },
    ];
    const v2b = [
      { num: `${floor}20`, type: 'Superior', side: 'V2B', pos: 1, beds: 1 },
      { num: `${floor}19`, type: 'Superior', side: 'V2B', pos: 2, beds: 1 },
    ];
    rooms.push(...v1, ...v2a, ...v2b);
  });
  return rooms.map(r => ({
    ...r, floor: parseInt(r.num[0]),
    // [FIX v2] เพิ่ม bed_type — ชั้น 8 = twin (เตียงคู่), ชั้นอื่น = double
    bed_type: parseInt(r.num[0]) === 8 ? 'twin' : 'double',
    reservations: [],
    assigned: false, isSeed: false, state: null
  }));
}

// ============================================================
// 🎮 State
// ============================================================
let state = {
  rooms: buildRooms(),
  currentBooking: {
    checkIn: addDays(todayISO(), 1),
    checkOut: addDays(todayISO(), 3),
    rooms: [],
  },
  bookingQueue: [],
  evalDates: {
    checkIn: addDays(todayISO(), 1),
    checkOut: addDays(todayISO(), 3),
  },
  weights: { floor: 50, side: 8, pos: 3, bed: 5 },
  lastResults: null,
  selectedAlgo: 'E',
  modalRoom: null,
};

// Step Explorer State
let stepState = {
  steps: [],
  current: -1,
  playing: false,
  interval: null,
  speed: 600,
  activeResult: null,
};

// ============================================================
// 🎨 Render
// ============================================================
function render() {
  // Sync evalDates with currentBooking
  state.evalDates.checkIn = state.currentBooking.checkIn;
  state.evalDates.checkOut = state.currentBooking.checkOut;

  // Date display
  const nights = daysBetween(state.currentBooking.checkIn, state.currentBooking.checkOut);
  document.getElementById('date-display').textContent =
    `📅 ${state.currentBooking.checkIn} → ${state.currentBooking.checkOut} (${nights} night${nights !== 1 ? 's' : ''})`;

  const container = document.getElementById('floors');
  container.innerHTML = '';
  FLOORS.forEach(floor => {
    const floorRooms = state.rooms.filter(r => r.floor === floor);
    const div = document.createElement('div');
    div.className = 'floor-block';
    const occCount = floorRooms.filter(r => hasOverlap(r, state.evalDates.checkIn, state.evalDates.checkOut)).length;
    const assignedCount = floorRooms.filter(r => r.assigned).length;
    const resCount = floorRooms.reduce((s, r) => s + r.reservations.length, 0);
    div.innerHTML = `
      <div class="floor-title">
        ชั้น ${floor}
        <span class="floor-stats">${floorRooms.length} ห้อง · 🔒${occCount} occupied · ✅${assignedCount} assigned · 📋${resCount} reservations</span>
      </div>
      <div class="side-label">ฝั่งวิว 1 (V1) — Suite · Deluxe · Suite</div>
      <div class="room-row" id="v1-${floor}"></div>
      <div class="corridor">═════════ ทางเดิน (CORRIDOR) ═════════</div>
      <div class="side-label">ฝั่งวิว 2 (V2)</div>
      <div class="room-row" id="v2-${floor}"></div>
    `;
    container.appendChild(div);
    const v1Row = div.querySelector(`#v1-${floor}`);
    floorRooms.filter(r => r.side === 'V1').sort((a,b) => a.pos - b.pos).forEach(r => v1Row.appendChild(makeRoomEl(r)));
    const v2Row = div.querySelector(`#v2-${floor}`);
    // Layout ตามแผนผังจริง: [🪜 บันได] [V2A: X06..X01] [🛗 ลิฟต์][🛗 ลิฟต์] [V2B: X20,X19] [🪜 บันได]
    const stairL = document.createElement('span');
    stairL.className = 'stair'; stairL.textContent = '🪜 บันได';
    v2Row.appendChild(stairL);
    floorRooms.filter(r => r.side === 'V2A').sort((a,b) => a.pos - b.pos).forEach(r => v2Row.appendChild(makeRoomEl(r)));
    const elev1 = document.createElement('span');
    elev1.className = 'elevator'; elev1.textContent = '🛗 ลิฟต์';
    const elev2 = document.createElement('span');
    elev2.className = 'elevator'; elev2.textContent = '🛗 ลิฟต์';
    v2Row.appendChild(elev1);
    v2Row.appendChild(elev2);
    floorRooms.filter(r => r.side === 'V2B').sort((a,b) => a.pos - b.pos).forEach(r => v2Row.appendChild(makeRoomEl(r)));
    const stairR = document.createElement('span');
    stairR.className = 'stair'; stairR.textContent = '🪜 บันได';
    v2Row.appendChild(stairR);
  });
  renderBookingList();
  renderQueue();
}

function makeRoomEl(r) {
  const el = document.createElement('div');
  el.className = `room ${r.type}`;
  const isOcc = hasOverlap(r, state.evalDates.checkIn, state.evalDates.checkOut);
  if (isOcc) el.classList.add('occupied');
  if (r.assigned) el.classList.add('assigned');
  if (r.isSeed) el.classList.add('seed');
  if (r.state === 'considering') el.classList.add('considering');
  if (r.state === 'rejected') el.classList.add('rejected');
  if (r.state === 'accepted') el.classList.add('accepted');

  const numDiv = document.createElement('div');
  numDiv.className = 'num';
  // [FIX v2] แสดง badge T สำหรับห้อง twin (ชั้น 8)
  numDiv.textContent = r.bed_type === 'twin' ? `${r.num} T` : r.num;
  const bedsDiv = document.createElement('div');
  bedsDiv.className = 'beds';
  bedsDiv.textContent = `🛏${r.beds}`;
  el.appendChild(numDiv);
  el.appendChild(bedsDiv);

  // Mini timeline
  const tl = document.createElement('div');
  tl.className = 'room-timeline';
  const start = getTimelineStart();
  for (let i = 0; i < TIMELINE_DAYS; i++) {
    const date = addDays(start, i);
    const dayEl = document.createElement('div');
    dayEl.className = 'tl-day ' + (isRoomOccupiedOn(r, date) ? 'occ' : 'free');
    tl.appendChild(dayEl);
  }
  el.appendChild(tl);

  el.title = `${r.num} · ${r.type} · ${r.side}/P${r.pos} · ${r.beds} bed · ${r.bed_type || 'double'} · ${r.reservations.length} res — คลิกเพื่อจัดการ`;
  el.onclick = (e) => { e.stopPropagation(); openRoomModal(r); };
  return el;
}

function renderBookingList() {
  const list = document.getElementById('booking-list');
  if (state.currentBooking.rooms.length === 0) {
    list.innerHTML = '<div style="color:var(--muted);font-size:10px;padding:4px;">ยังไม่มีห้องใน booking — เพิ่มเลยค่ะ</div>';
    return;
  }
  list.innerHTML = state.currentBooking.rooms.map((br, i) => {
    const prefBadge = br.bed_preference && br.bed_preference !== 'any'
      ? `<span class="tag pref">${br.bed_preference === 'twin' ? 'T' : 'D'}</span>` : '';
    return `
    <div class="booking-item">
      <span><span class="tag ${br.type}">${br.type}</span> +${br.beds}bed ${prefBadge}</span>
      <button class="small danger" onclick="removeBookingRoom(${i})">✕</button>
    </div>`;
  }).join('');
}

function renderQueue() {
  const list = document.getElementById('queue-list');
  if (state.bookingQueue.length === 0) {
    list.innerHTML = '<div style="color:var(--muted);font-size:10px;padding:4px;">Queue ว่าง — เพิ่ม booking แล้วกด "Add to Queue"</div>';
    return;
  }
  list.innerHTML = state.bookingQueue.map((b, i) => {
    const statusIcon = b.status === 'done' ? '✅' : b.status === 'failed' ? '❌' : b.status === 'processing' ? '⏳' : '⏸';
    const roomsStr = b.rooms.map(r => `<span class="tag ${r.type}">${r.type}</span>`).join(' ');
    let resultStr = '';
    if (b.status === 'done' && b.result) {
      const assigned = b.result.assignments.filter(x => x).map(r => r.num).join(',');
      resultStr = `<div class="queue-result">→ ${assigned} · cost=${b.result.cost.toFixed(1)}</div>`;
    } else if (b.status === 'failed' && b.result) {
      resultStr = `<div class="queue-result fail">→ FAILED: ไม่มีห้องเพียงพอ</div>`;
    }
    return `<div class="queue-item ${b.status || ''}">
      <div class="queue-header">
        <span>${statusIcon} #${i+1}: ${b.checkIn.substring(5)}→${b.checkOut.substring(5)}</span>
        <button class="small danger" onclick="removeFromQueue(${i})">✕</button>
      </div>
      <div class="queue-rooms">${roomsStr}</div>
      ${resultStr}
    </div>`;
  }).join('');
}

// ============================================================
// 🎮 Controls — Booking Request
// ============================================================
function updateDates() {
  state.currentBooking.checkIn = document.getElementById('br-checkin').value;
  state.currentBooking.checkOut = document.getElementById('br-checkout').value;
  state.evalDates.checkIn = state.currentBooking.checkIn;
  state.evalDates.checkOut = state.currentBooking.checkOut;
  render();
}

function addBookingRoom() {
  const type = document.getElementById('br-type').value;
  const beds = parseInt(document.getElementById('br-bed').value);
  const bed_preference = document.getElementById('br-bedpref').value;
  state.currentBooking.rooms.push({ type, beds, bed_preference });
  render();
}
function removeBookingRoom(i) {
  state.currentBooking.rooms.splice(i, 1);
  render();
}

// ============================================================
// 🎮 Controls — Queue
// ============================================================
function addToQueue() {
  if (state.currentBooking.rooms.length === 0) {
    alert('เพิ่มห้องใน booking ก่อนค่ะ!');
    return;
  }
  if (state.currentBooking.checkIn >= state.currentBooking.checkOut) {
    alert('Check-out ต้องหลัง check-in ค่ะ!');
    return;
  }
  state.bookingQueue.push({
    checkIn: state.currentBooking.checkIn,
    checkOut: state.currentBooking.checkOut,
    rooms: JSON.parse(JSON.stringify(state.currentBooking.rooms)),
    status: 'pending',
  });
  state.currentBooking.rooms = [];
  render();
}

function removeFromQueue(i) {
  state.bookingQueue.splice(i, 1);
  renderQueue();
}

function processQueue() {
  if (state.bookingQueue.length === 0) {
    alert('Queue ว่าง! เพิ่ม booking ก่อนค่ะ');
    return;
  }
  const algo = document.querySelector('input[name="algo"]:checked').value;
  const btn = document.getElementById('btn-process-queue');
  btn.textContent = '⏳ Processing...';
  btn.disabled = true;

  let lastResult = null;
  let lastBooking = null;

  state.bookingQueue.forEach((booking, i) => {
    booking.status = 'processing';
    renderQueue();

    // [FIX Bug #6] เก็บ booking context ใน result เพื่อให้ step view sync กับ map
    const bookingDates = { checkIn: booking.checkIn, checkOut: booking.checkOut };
    state.evalDates = bookingDates;
    clearAssigned();
    const t0 = performance.now();
    const res = ALGOS[algo].fn(JSON.parse(JSON.stringify(booking.rooms)));
    res.time = performance.now() - t0;
    res.algoKey = algo;
    res.bookingDates = bookingDates; // [FIX Bug #6]

    if (res.ok) {
      res.assignments.forEach(r => {
        if (r) {
          const room = state.rooms.find(x => x.num === r.num);
          if (room) {
            room.reservations.push({
              checkIn: booking.checkIn,
              checkOut: booking.checkOut,
              label: `Queue #${i + 1}`,
              type: 'queue',
            });
          }
        }
      });
      booking.status = 'done';
      booking.result = res;
    } else {
      booking.status = 'failed';
      booking.result = res;
    }
    lastResult = res;
    lastBooking = booking;
    renderQueue();
  });

  // Show last result
  if (lastResult) {
    state.lastResults = { [algo]: lastResult };
    stepState.activeResult = lastResult;
    // [FIX Bug #6] sync evalDates กับ booking ล่าสุดก่อน step mode
    state.evalDates = lastResult.bookingDates;
    startStepMode(lastResult);
    renderResults(state.lastResults);
    renderPrompt(lastResult, lastBooking);
  }

  clearAssigned();
  // [FIX Bug #6] หลัง step mode เริ่มแล้ว evalDates ต้องตรงกับ active result
  if (stepState.activeResult && stepState.activeResult.bookingDates) {
    state.evalDates = stepState.activeResult.bookingDates;
  } else {
    state.evalDates = { checkIn: state.currentBooking.checkIn, checkOut: state.currentBooking.checkOut };
  }
  render();

  btn.textContent = '▶ Process Queue';
  btn.disabled = false;

  const doneCount = state.bookingQueue.filter(b => b.status === 'done').length;
  const failCount = state.bookingQueue.filter(b => b.status === 'failed').length;
  document.getElementById('result-summary').textContent =
    `Queue: ${doneCount}✅ / ${failCount}❌ / ${state.bookingQueue.length} total`;
}

// ============================================================
// 🎮 Controls — Weights & Reset
// ============================================================
function updateWeights() {
  state.weights.floor = parseInt(document.getElementById('w-floor').value);
  state.weights.side = parseInt(document.getElementById('w-side').value);
  state.weights.pos = parseInt(document.getElementById('w-pos').value);
  state.weights.bed = parseInt(document.getElementById('w-bed').value);
  document.getElementById('w-floor-val').textContent = state.weights.floor;
  document.getElementById('w-side-val').textContent = state.weights.side;
  document.getElementById('w-pos-val').textContent = state.weights.pos;
  document.getElementById('w-bed-val').textContent = state.weights.bed;
}

function resetAll() {
  stepStop();
  state.rooms = buildRooms();
  state.currentBooking = {
    checkIn: addDays(todayISO(), 1),
    checkOut: addDays(todayISO(), 3),
    rooms: [],
  };
  state.bookingQueue = [];
  state.evalDates = { checkIn: state.currentBooking.checkIn, checkOut: state.currentBooking.checkOut };
  state.lastResults = null;
  stepState.steps = [];
  stepState.current = -1;
  stepState.activeResult = null;
  document.getElementById('br-checkin').value = state.currentBooking.checkIn;
  document.getElementById('br-checkout').value = state.currentBooking.checkOut;
  document.getElementById('results-grid').innerHTML = '';
  document.getElementById('prompt-box').textContent = '— reset แล้วค่ะ ลองใหม่ได้เลย —';
  document.getElementById('result-summary').textContent = '';
  document.getElementById('step-counter').textContent = '0 / 0';
  document.getElementById('current-cost').textContent = '—';
  document.getElementById('thought-bubble').innerHTML = '<span style="color:var(--muted)">กด "Run + Step Mode" เพื่อเริ่มดู algorithm ทำงานทีละขั้นค่ะ ✨</span>';
  document.getElementById('step-log').innerHTML = '<div style="color:var(--muted);padding:4px;">Algorithm log จะปรากฏที่นี่...</div>';
  render();
}

// ============================================================
// 🎮 Presets
// ============================================================
function loadPreset(name) {
  resetAll();
  const today = todayISO();

  switch (name) {
    case 'simple':
      state.currentBooking.rooms = [{ type: 'Deluxe', beds: 0 }];
      break;

    case 'mix3':
      state.currentBooking.rooms = [
        { type: 'Suite', beds: 0 },
        { type: 'Deluxe', beds: 0 },
        { type: 'Superior', beds: 0 },
      ];
      break;

    case 'group5':
      state.currentBooking.rooms = Array(5).fill({ type: 'Deluxe', beds: 0 });
      break;

    case 'extrabed':
      state.currentBooking.rooms = [{ type: 'Deluxe', beds: 1 }, { type: 'Deluxe', beds: 0 }];
      break;

    case 'highocc':
      // Seed 60% rooms with random reservations overlapping current booking dates
      state.currentBooking.rooms = [{ type: 'Deluxe', beds: 0 }, { type: 'Superior', beds: 0 }];
      state.rooms.forEach(r => {
        if (Math.random() < 0.6) {
          const offset = Math.floor(Math.random() * 4) - 1; // -1..2
          r.reservations.push({
            checkIn: addDays(today, 1 + offset),
            checkOut: addDays(today, 3 + offset),
            label: 'Existing',
            type: 'seed',
          });
        }
      });
      break;

    case 'sequential':
      // 3-5 bookings with partial overlap in queue
      state.bookingQueue = [
        { checkIn: addDays(today, 1), checkOut: addDays(today, 4), rooms: [{ type: 'Deluxe', beds: 0 }, { type: 'Deluxe', beds: 0 }], status: 'pending' },
        { checkIn: addDays(today, 3), checkOut: addDays(today, 6), rooms: [{ type: 'Deluxe', beds: 0 }, { type: 'Superior', beds: 0 }], status: 'pending' },
        { checkIn: addDays(today, 2), checkOut: addDays(today, 5), rooms: [{ type: 'Suite', beds: 0 }, { type: 'Deluxe', beds: 0 }], status: 'pending' },
        { checkIn: addDays(today, 5), checkOut: addDays(today, 8), rooms: [{ type: 'Deluxe', beds: 0 }], status: 'pending' },
      ];
      state.currentBooking.checkIn = addDays(today, 1);
      state.currentBooking.checkOut = addDays(today, 4);
      break;

    case 'weekend': {
      // Many bookings same weekend
      const fri = getNextFriday();
      const sun = addDays(fri, 2);
      state.currentBooking.checkIn = fri;
      state.currentBooking.checkOut = sun;
      const types = ['Deluxe', 'Superior', 'Suite'];
      for (let i = 0; i < 6; i++) {
        state.bookingQueue.push({
          checkIn: fri,
          checkOut: sun,
          rooms: [
            { type: types[i % 3], beds: 0 },
            { type: 'Deluxe', beds: 0 },
          ],
          status: 'pending',
        });
      }
      break;
    }
  }

  state.evalDates = { checkIn: state.currentBooking.checkIn, checkOut: state.currentBooking.checkOut };
  document.getElementById('br-checkin').value = state.currentBooking.checkIn;
  document.getElementById('br-checkout').value = state.currentBooking.checkOut;
  render();
}

// ============================================================
function loadTestCase(n) {
  resetAll();
  const today = todayISO();
  const tomorrow = addDays(today, 1);

  switch (n) {
    case 1:
      state.currentBooking.checkIn = tomorrow;
      state.currentBooking.checkOut = addDays(today, 3);
      state.currentBooking.rooms = [{ type: 'Deluxe', beds: 0 }];
      break;

    case 2:
      state.currentBooking.checkIn = tomorrow;
      state.currentBooking.checkOut = addDays(today, 3);
      state.currentBooking.rooms = [
        { type: 'Suite', beds: 0 },
        { type: 'Deluxe', beds: 1 },
      ];
      break;

    case 3:
      state.currentBooking.checkIn = tomorrow;
      state.currentBooking.checkOut = addDays(today, 3);
      state.currentBooking.rooms = Array(5).fill({ type: 'Deluxe', beds: 0 });
      break;

    case 4: {
      state.currentBooking.checkIn = tomorrow;
      state.currentBooking.checkOut = addDays(today, 4);
      state.currentBooking.rooms = [{ type: 'Deluxe', beds: 0 }, { type: 'Superior', beds: 0 }];
      const seedDeluxe = state.rooms.filter(r => r.type === 'Deluxe' && r.floor <= 7);
      seedDeluxe.slice(0, 8).forEach(r => {
        r.reservations.push({
          checkIn: addDays(today, 1),
          checkOut: addDays(today, 3),
          label: 'Existing Guest',
          type: 'seed',
        });
      });
      const seedSuperior = state.rooms.filter(r => r.type === 'Superior' && r.floor <= 6);
      seedSuperior.slice(0, 4).forEach(r => {
        r.reservations.push({
          checkIn: addDays(today, 2),
          checkOut: addDays(today, 4),
          label: 'Existing Guest',
          type: 'seed',
        });
      });
      break;
    }

    case 5: {
      const fri = getNextFriday();
      const sun = addDays(fri, 2);
      state.currentBooking.checkIn = fri;
      state.currentBooking.checkOut = sun;
      state.bookingQueue = [
        { checkIn: fri, checkOut: sun, rooms: [{ type: 'Suite', beds: 0 }, { type: 'Deluxe', beds: 0 }], status: 'pending' },
        { checkIn: fri, checkOut: sun, rooms: [{ type: 'Deluxe', beds: 0 }, { type: 'Deluxe', beds: 0 }, { type: 'Superior', beds: 0 }], status: 'pending' },
        { checkIn: fri, checkOut: sun, rooms: [{ type: 'Deluxe', beds: 1 }], status: 'pending' },
        { checkIn: fri, checkOut: sun, rooms: Array(4).fill({ type: 'Deluxe', beds: 0 }), status: 'pending' },
      ];
      break;
    }
  }

  state.evalDates = { checkIn: state.currentBooking.checkIn, checkOut: state.currentBooking.checkOut };
  document.getElementById('br-checkin').value = state.currentBooking.checkIn;
  document.getElementById('br-checkout').value = state.currentBooking.checkOut;
  render();

  if (n === 5) {
    setTimeout(() => processQueue(), 100);
  } else {
    document.querySelector('input[name="algo"][value="E"]').checked = true;
    setTimeout(() => runSelected(), 100);
  }
}

// 🧠 Algorithms (with step recording + overlap logging)
// ============================================================
// Each step: { type: 'info'|'consider'|'accept'|'reject'|'done', room: roomObj|null, thought: string, cost: number, assignedRooms: [roomNums] }
//
// [FIX Bug #1] seed จะถูกเก็บใน result.seed (string|null) ไม่ใช่ result.assignments.seed
// [FIX Bug #2] assignments จะเป็น deep copy ของ room objects เพื่อกัน reference ชี้ state.rooms
// [FIX Bug #3] costFunction จะรับ pairs [{room, br}] แทน index-based matching
// [FIX Bug #5] getAvailable จะถูกแคชใน Greedy/Hybrid

// Deep clone a room for result storage (ไม่ชี้ state.rooms)
function cloneRoomForResult(r) {
  if (!r) return null;
  return {
    num: r.num, type: r.type, side: r.side, pos: r.pos, beds: r.beds, floor: r.floor,
    bed_type: r.bed_type || 'double',
    reservations: r.reservations.map(res => ({ ...res })),
    assigned: false, isSeed: false, state: null
  };
}

// Clone assignment array (deep copy rooms)
function cloneAssignments(arr) {
  return arr.map(r => r ? cloneRoomForResult(r) : null);
}

function getAvailable(roomType, assignedNums, checkIn, checkOut) {
  const dates = checkIn && checkOut ? { checkIn, checkOut } : state.evalDates;
  const excluded = assignedNums || new Set();
  return state.rooms.filter(r =>
    !excluded.has(r.num) &&
    !r.assigned &&
    (roomType === null || r.type === roomType) &&
    isAvailable(r, dates.checkIn, dates.checkOut)
  );
}

// [FIX Bug #3] คำนวณ cost จาก pairs [{room, br}] แทน index-based
function costFunction(pairs) {
  const w = state.weights;
  if (pairs.length === 0) return Infinity;
  const validRooms = pairs.map(p => p.room).filter(r => r);
  if (validRooms.length === 0) return 0;

  const floors = validRooms.map(r => r.floor);
  // [FIX v2] floorSpread = distinct floor count bias (จำนวนชั้นที่กระจาย - 1)
  // แทน (max - min) เพื่อจับการกระจายหลายชั้นได้ตรงกว่า
  const distinctFloors = new Set(floors);
  const floorSpread = (distinctFloors.size - 1) * w.floor;
  // [FIX v2] sideMismatch: V2A/V2B รวมเป็นฝั่ง V2 เดียวกัน (กายภาพคือวิวเดียวกัน)
  // แต่ posSpread ด้านล่างยังคิดแยกตาม raw side (V2A, V2B แยก)
  const normSides = new Set(validRooms.map(r => r.side.startsWith('V2') ? 'V2' : r.side));
  const sideMismatch = (normSides.size - 1) * w.side;
  const sides = new Set(validRooms.map(r => r.side));

  let posSpread = 0;
  [...sides].forEach(side => {
    const sideRooms = validRooms.filter(r => r.side === side).map(r => r.pos);
    if (sideRooms.length > 1) {
      posSpread += (Math.max(...sideRooms) - Math.min(...sideRooms)) * w.pos;
    }
  });

  // [FIX Bug #3] bed waste คำนวณจาก pairs ตรงๆ ไม่ใช้ index
  let bedWaste = 0;
  pairs.forEach(p => {
    if (p.room && p.br) {
      const waste = Math.max(0, p.room.beds - 1 - p.br.beds);
      bedWaste += waste * w.bed;
    }
  });

  // [FIX v2] bed preference mismatch penalty — ใช้ soft penalty แทน hard filter
  // ถ้า BR ระบุ preference และ bed_type ของห้องไม่ match → +100 (หนักมาก เพื่อบังคับหลีกเลี่ยง)
  // ค่า 100 > floorSpread max ที่เป็นไปได้ (5 ชั้น = 4×50=200 ก็พอ ๆ กัน) แต่นุ่มพอที่ยอมถ้าไม่มีทางเลือก
  let bedPrefPenalty = 0;
  pairs.forEach(p => {
    if (p.room && p.br && p.br.bed_preference && p.br.bed_preference !== 'any') {
      if (p.room.bed_type !== p.br.bed_preference) {
        bedPrefPenalty += 100;
      }
    }
  });

  const total = floorSpread + sideMismatch + posSpread + bedWaste + bedPrefPenalty;
  return total;
}

// [FIX] Wrapper เก่าสำหรับ backward compat: แปลง assignedRooms + booking เป็น pairs
function costFromAssigned(assignedRooms, booking) {
  const pairs = [];
  for (let i = 0; i < assignedRooms.length && i < booking.length; i++) {
    if (assignedRooms[i]) {
      pairs.push({ room: assignedRooms[i], br: booking[i] });
    }
  }
  return costFunction(pairs);
}

// Log rooms filtered out due to overlap (for step explorer)
function logOverlapFiltered(result, booking) {
  const { checkIn, checkOut } = state.evalDates;
  const types = [...new Set(booking.map(b => b.type))];
  let logged = 0;
  const MAX_LOG = 8;
  for (const type of types) {
    const filtered = state.rooms.filter(r =>
      r.type === type && hasOverlap(r, checkIn, checkOut)
    );
    for (const r of filtered) {
      if (logged >= MAX_LOG) {
        result.steps.push({
          type: 'info', room: null,
          thought: `... และอีกหลายห้องถูกกรองเพราะ overlap ในช่วง ${fmtDate(checkIn)}-${fmtDate(checkOut)}`,
          cost: 0, assignedRooms: [],
        });
        return;
      }
      const ov = getOverlap(r, checkIn, checkOut);
      result.steps.push({
        type: 'reject', room: r,
        thought: `❌ ${r.num} has reservation ${fmtDate(ov.checkIn)}-${fmtDate(ov.checkOut)} overlapping ${fmtDate(checkIn)}-${fmtDate(checkOut)}`,
        cost: 0, assignedRooms: [],
      });
      logged++;
    }
  }
  if (logged === 0) {
    result.steps.push({
      type: 'info', room: null,
      thought: `✅ ไม่มีห้อง ${types.join('/')} ที่ overlap ในช่วง ${fmtDate(checkIn)}-${fmtDate(checkOut)} — ใช้ได้ทั้งหมด`,
      cost: 0, assignedRooms: [],
    });
  }
}

// [FIX Bug #7] X09 priority: ถ้า booking มี Deluxe+bed และ X09 ว่าง → เลือก X09 ก่อน
function getX09IfNeeded(booking, checkIn, checkOut) {
  if (!document.getElementById('policy-x09').checked) return null;
  const needsDeluxeWithBed = booking.some(br => br.type === 'Deluxe' && br.beds > 0);
  if (!needsDeluxeWithBed) return null;
  // X09 = ห้อง Deluxe 3 เตียง builtin ทุกชั้น (509, 609, 709, 809, 909)
  const x09Rooms = state.rooms.filter(r =>
    r.num.endsWith('09') && r.type === 'Deluxe' && r.beds === 3 &&
    isAvailable(r, checkIn, checkOut)
  );
  if (x09Rooms.length === 0) return null;
  // เลือกชั้นต่ำสุดก่อน
  return x09Rooms.sort((a, b) => a.floor - b.floor)[0];
}

// ============================================================
// Algorithm A: Block Contiguous
// ============================================================
function algoBlock(booking) {
  const result = { algo: 'Block Contiguous', steps: [], assignments: new Array(booking.length).fill(null), seed: null, ok: false, cost: Infinity };

  result.steps.push({ type: 'info', room: null, thought: `🅰️ Block Contiguous: หาห้องติดกันบนฝั่งเดียวกัน`, cost: 0, assignedRooms: [] });

  // Log overlap filtered rooms
  logOverlapFiltered(result, booking);

  const types = booking.map(b => b.type);
  const uniqueTypes = [...new Set(types)];
  if (uniqueTypes.length > 1) {
    result.steps.push({ type: 'info', room: null, thought: `⚠️ ต้องการหลาย type (${uniqueTypes.join(', ')}), จะหา block แยกตาม type`, cost: 0, assignedRooms: [] });
  }

  // [FIX Bug #7] X09 priority
  const x09 = getX09IfNeeded(booking, state.evalDates.checkIn, state.evalDates.checkOut);
  if (x09) {
    result.steps.push({ type: 'accept', room: x09, thought: `🎯 X09 Priority: เลือก ${x09.num} (Deluxe 3-bed builtin) สำหรับ Deluxe+bed`, cost: 0, assignedRooms: [x09.num] });
    // หา index ของ Deluxe+bed ตัวแรก
    const idx = booking.findIndex(b => b.type === 'Deluxe' && b.beds > 0);
    if (idx >= 0) {
      result.assignments[idx] = x09;
      result.seed = x09.num;
    }
  }

  // หา block สำหรับที่เหลือ
  const remaining = booking.map((br, i) => ({ br, i })).filter(({ i }) => !result.assignments[i]);

  // [FIX v2 — JOINT MIXED-TYPE] Block แบบ joint: หา block ติดกันของห้องทุก type
  // แล้ว assign แบบ type-aware (permutation เพื่อหา type-compatible assignment ที่ cost ต่ำสุด)
  if (remaining.length > 0) {
    const neededTypes = [...new Set(remaining.map(r => r.br.type))];
    const available = state.rooms.filter(r =>
      !result.assignments.some(a => a && a.num === r.num) &&
      neededTypes.includes(r.type) &&
      isAvailable(r, state.evalDates.checkIn, state.evalDates.checkOut)
    );

    const n = remaining.length;
    if (available.length < n) {
      result.steps.push({ type: 'reject', room: null, thought: `❌ ห้องว่างไม่พอ (${available.length} < ${n})`, cost: 0, assignedRooms: [] });
    } else {
      const brs = remaining.map(r => r.br);
      const idxs = remaining.map(r => r.i);

      // หา block ติดกันบนฝั่งเดียวกัน (รวมทุก type)
      const bySide = { V1: [], V2A: [], V2B: [] };
      available.forEach(r => bySide[r.side].push(r));
      Object.keys(bySide).forEach(s => bySide[s].sort((a, b) => a.floor - b.floor || a.pos - b.pos));

      let bestBlock = null;
      for (const side of ['V1', 'V2A', 'V2B']) {
        const rooms = bySide[side];
        for (let start = 0; start <= rooms.length - n; start++) {
          const block = rooms.slice(start, start + n);
          // ตรวจว่าติดกัน (floor เดียวกัน + pos ต่อเนื่อง)
          if (block.every(r => r.floor === block[0].floor)) {
            const positions = block.map(r => r.pos);
            let contiguous = true;
            for (let j = 1; j < positions.length; j++) {
              if (positions[j] !== positions[j-1] + 1) { contiguous = false; break; }
            }
            if (!contiguous) continue;

            // ลองทุก permutation เพื่อหา type-compatible assignment ที่ cost ต่ำสุด
            const perms = permutations(block);
            for (const perm of perms) {
              let typeOk = true;
              for (let j = 0; j < n; j++) {
                if (perm[j].type !== brs[j].type) { typeOk = false; break; }
              }
              if (!typeOk) continue;
              const pairs = perm.map((r, j) => ({ room: r, br: brs[j] }));
              const cost = costFunction(pairs);
              if (!bestBlock || cost < bestBlock.cost) {
                bestBlock = { block: perm, cost };
              }
            }
          }
        }
      }

      if (bestBlock) {
        bestBlock.block.forEach((r, j) => {
          result.assignments[idxs[j]] = r;
          result.steps.push({ type: 'accept', room: r, thought: `✅ เลือก ${r.num} (block ติดกัน joint ${side_label(r.side)})`, cost: bestBlock.cost, assignedRooms: bestBlock.block.map(x => x.num) });
        });
        if (!result.seed) result.seed = bestBlock.block[0].num;
      } else {
        // [FIX Bug #4] Best-effort fallback (joint version)
        const bestEffort = document.getElementById('policy-besteffort').checked;
        if (bestEffort) {
          result.steps.push({ type: 'info', room: null, thought: `⚠️ ไม่เจอ block ติดกัน — fallback best-effort`, cost: 0, assignedRooms: [] });
          const sorted = available.slice().sort((a, b) => a.floor - b.floor || a.pos - b.pos);
          const used = new Set();
          for (let j = 0; j < n; j++) {
            const pick = sorted.find(r => !used.has(r.num) && r.type === brs[j].type);
            if (pick) {
              result.assignments[idxs[j]] = pick;
              used.add(pick.num);
              result.steps.push({ type: 'accept', room: pick, thought: `✅ Best-effort: เลือก ${pick.num}`, cost: 0, assignedRooms: [pick.num] });
            }
          }
        } else {
          result.steps.push({ type: 'reject', room: null, thought: `❌ ไม่เจอ block ติดกัน และ best-effort ปิดอยู่`, cost: 0, assignedRooms: [] });
        }
      }
    }
  }

  // [FIX Bug #2] Deep copy assignments ก่อน return
  result.assignments = cloneAssignments(result.assignments);

  const allAssigned = result.assignments.every(a => a !== null);
  result.ok = allAssigned;
  const pairs = result.assignments.map((r, i) => ({ room: r, br: booking[i] })).filter(p => p.room);
  result.cost = allAssigned ? costFunction(pairs) : Infinity;

  result.steps.push({ type: 'done', room: null, thought: result.ok ? `🎉 สำเร็จ! cost=${result.cost.toFixed(1)}` : `❌ ล้มเหลว`, cost: result.cost, assignedRooms: result.assignments.filter(x => x).map(r => r.num) });
  return result;
}

function side_label(side) {
  return side === 'V1' ? 'V1' : 'V2';
}

// ============================================================
// Algorithm B: Graph (Connected Components)
// ============================================================
function algoGraph(booking) {
  const result = { algo: 'Graph Connected', steps: [], assignments: new Array(booking.length).fill(null), seed: null, ok: false, cost: Infinity };

  result.steps.push({ type: 'info', room: null, thought: `🅱️ Graph: หา connected component ที่ใหญ่ที่สุด`, cost: 0, assignedRooms: [] });
  logOverlapFiltered(result, booking);

  // [FIX Bug #7] X09 priority
  const x09 = getX09IfNeeded(booking, state.evalDates.checkIn, state.evalDates.checkOut);
  if (x09) {
    result.steps.push({ type: 'accept', room: x09, thought: `🎯 X09 Priority: เลือก ${x09.num}`, cost: 0, assignedRooms: [x09.num] });
    const idx = booking.findIndex(b => b.type === 'Deluxe' && b.beds > 0);
    if (idx >= 0) {
      result.assignments[idx] = x09;
      result.seed = x09.num;
    }
  }

  const remaining = booking.map((br, i) => ({ br, i })).filter(({ i }) => !result.assignments[i]);

  // [FIX v2 — JOINT MIXED-TYPE] Graph แบบ joint: build adjacency รวมทุก type
  if (remaining.length > 0) {
    const neededTypes = [...new Set(remaining.map(r => r.br.type))];
    const available = state.rooms.filter(r =>
      !result.assignments.some(a => a && a.num === r.num) &&
      neededTypes.includes(r.type) &&
      isAvailable(r, state.evalDates.checkIn, state.evalDates.checkOut)
    );

    const n = remaining.length;
    if (available.length < n) {
      result.steps.push({ type: 'reject', room: null, thought: `❌ ห้องว่างไม่พอ`, cost: 0, assignedRooms: [] });
    } else {
      const brs = remaining.map(r => r.br);
      const idxs = remaining.map(r => r.i);

      // Build adjacency: ห้องติดกัน = floor เดียวกัน + side เดียวกัน + pos ติดกัน (ข้าม type ได้)
      const adj = {};
      available.forEach(r => adj[r.num] = []);
      for (let i = 0; i < available.length; i++) {
        for (let j = i + 1; j < available.length; j++) {
          const a = available[i], b = available[j];
          if (a.floor === b.floor && a.side === b.side && Math.abs(a.pos - b.pos) === 1) {
            adj[a.num].push(b);
            adj[b.num].push(a);
          }
        }
      }

      // หา largest connected component
      const visited = new Set();
      let bestComp = [];
      for (const r of available) {
        if (visited.has(r.num)) continue;
        const comp = [];
        const queue = [r];
        while (queue.length) {
          const cur = queue.shift();
          if (visited.has(cur.num)) continue;
          visited.add(cur.num);
          comp.push(cur);
          for (const nb of adj[cur.num]) {
            if (!visited.has(nb.num)) queue.push(nb);
          }
        }
        if (comp.length > bestComp.length) bestComp = comp;
      }

      if (bestComp.length >= n) {
        // เลือก n ห้องจาก component แบบ type-aware ที่ cost ต่ำสุด
        // ลอง brute force combinations + permutations (ถ้า component เล็กพอ)
        bestComp.sort((a, b) => a.floor - b.floor || a.pos - b.pos);
        let bestChosen = null;
        let bestCost = Infinity;
        if (n <= 4 && bestComp.length <= 30) {
          const combos = combinations(bestComp, n);
          for (const combo of combos) {
            const perms = permutations(combo);
            for (const perm of perms) {
              let typeOk = true;
              for (let j = 0; j < n; j++) {
                if (perm[j].type !== brs[j].type) { typeOk = false; break; }
              }
              if (!typeOk) continue;
              const pairs = perm.map((r, j) => ({ room: r, br: brs[j] }));
              const c = costFunction(pairs);
              if (c < bestCost) { bestCost = c; bestChosen = perm; }
            }
          }
        } else {
          // Greedy type-aware: walk component เรียง แล้ว assign ตามลำดับ booking
          const used = new Set();
          bestChosen = [];
          for (const br of brs) {
            const pick = bestComp.find(r => !used.has(r.num) && r.type === br.type);
            if (pick) { bestChosen.push(pick); used.add(pick.num); }
          }
          if (bestChosen.length === n) {
            bestCost = costFunction(bestChosen.map((r, j) => ({ room: r, br: brs[j] })));
          } else {
            bestChosen = null;
          }
        }

        if (bestChosen) {
          bestChosen.forEach((r, j) => {
            result.assignments[idxs[j]] = r;
            result.steps.push({ type: 'accept', room: r, thought: `✅ เลือก ${r.num} จาก component (${bestComp.length} ห้อง, joint)`, cost: bestCost, assignedRooms: bestChosen.map(x => x.num) });
          });
          if (!result.seed) result.seed = bestChosen[0].num;
        }
      }

      if (!result.assignments.every((a, i) => a || remaining.findIndex(r => r.i === i) === -1)) {
        // ยัง assign ไม่ครบ → best-effort fallback
        const bestEffort = document.getElementById('policy-besteffort').checked;
        if (bestEffort) {
          result.steps.push({ type: 'info', room: null, thought: `⚠️ Component ไม่พอหรือ type ไม่ตรง — fallback best-effort`, cost: 0, assignedRooms: [] });
          const sorted = available.slice().sort((a, b) => a.floor - b.floor || a.pos - b.pos);
          const used = new Set(result.assignments.filter(a => a).map(a => a.num));
          for (let j = 0; j < n; j++) {
            if (result.assignments[idxs[j]]) continue;
            const pick = sorted.find(r => !used.has(r.num) && r.type === brs[j].type);
            if (pick) {
              result.assignments[idxs[j]] = pick;
              used.add(pick.num);
              result.steps.push({ type: 'accept', room: pick, thought: `✅ Best-effort: ${pick.num}`, cost: 0, assignedRooms: [pick.num] });
            }
          }
        }
      }
    }
  }

  // [FIX Bug #2] Deep copy
  result.assignments = cloneAssignments(result.assignments);
  const allAssigned = result.assignments.every(a => a !== null);
  result.ok = allAssigned;
  const pairs = result.assignments.map((r, i) => ({ room: r, br: booking[i] })).filter(p => p.room);
  result.cost = allAssigned ? costFunction(pairs) : Infinity;
  result.steps.push({ type: 'done', room: null, thought: result.ok ? `🎉 cost=${result.cost.toFixed(1)}` : `❌ ล้มเหลว`, cost: result.cost, assignedRooms: result.assignments.filter(x => x).map(r => r.num) });
  return result;
}

// ============================================================
// Algorithm C: Coordinate + Cost (Greedy ตามตำแหน่ง + cost)
// ============================================================
function algoCoordinate(booking) {
  const result = { algo: 'Coordinate + Cost', steps: [], assignments: new Array(booking.length).fill(null), seed: null, ok: false, cost: Infinity };

  result.steps.push({ type: 'info', room: null, thought: `🅲 Coordinate: คำนวณ cost ทุกชุดแล้วเลือกชุดที่ดีที่สุด`, cost: 0, assignedRooms: [] });
  logOverlapFiltered(result, booking);

  // [FIX Bug #7] X09 priority
  const x09 = getX09IfNeeded(booking, state.evalDates.checkIn, state.evalDates.checkOut);
  if (x09) {
    result.steps.push({ type: 'accept', room: x09, thought: `🎯 X09 Priority: ${x09.num}`, cost: 0, assignedRooms: [x09.num] });
    const idx = booking.findIndex(b => b.type === 'Deluxe' && b.beds > 0);
    if (idx >= 0) {
      result.assignments[idx] = x09;
      result.seed = x09.num;
    }
  }

  const remaining = booking.map((br, i) => ({ br, i })).filter(({ i }) => !result.assignments[i]);

  // [FIX v2 — JOINT MIXED-TYPE] ไม่แยกตาม type อีกต่อไป
  // รวม candidate pool ทุก type ที่ booking ต้องการ แล้ว brute-force/greedy แบบ joint
  // เพื่อให้ Suite+Deluxe พยายามอยู่ชั้นเดียวกันได้
  if (remaining.length === 0) {
    // ทุกห้องถูก X09 priority จัดการหมดแล้ว
  } else {
    const neededTypes = [...new Set(remaining.map(r => r.br.type))];
    const available = state.rooms.filter(r =>
      !result.assignments.some(a => a && a.num === r.num) &&
      neededTypes.includes(r.type) &&
      isAvailable(r, state.evalDates.checkIn, state.evalDates.checkOut)
    );

    const n = remaining.length;
    if (available.length < n) {
      result.steps.push({ type: 'reject', room: null, thought: `❌ ห้องว่างไม่พอ (${available.length} < ${n})`, cost: 0, assignedRooms: [] });
    } else {
      const brs = remaining.map(r => r.br);
      const idxs = remaining.map(r => r.i);
      let bestChosen = null;
      let bestCost = Infinity;

      // ประเมินขนาด combo: C(available, n) × n! (permutation เพราะ type-aware assignment)
      // ใช้ brute force เฉพาะ n ≤ 4 และ available ≤ 30 (เท่าเดิม)
      const bruteFeasible = n <= 4 && available.length <= 30;

      if (n === 1) {
        // ค้นหาห้องเดียวที่ type ตรง ที่ cost ต่ำสุด
        for (const r of available) {
          if (r.type !== brs[0].type) continue;
          const c = costFunction([{ room: r, br: brs[0] }]);
          if (c < bestCost) { bestCost = c; bestChosen = [r]; }
        }
      } else if (bruteFeasible) {
        // Brute force ทุก permutation ของ n ห้องจาก available ที่ type ตรงกับ brs ในตำแหน่งนั้น
        const combos = combinations(available, n);
        for (const combo of combos) {
          // ลองทุก permutation ของ combo เพื่อหา type-compatible assignment
          const perms = permutations(combo);
          for (const perm of perms) {
            let typeOk = true;
            for (let j = 0; j < n; j++) {
              if (perm[j].type !== brs[j].type) { typeOk = false; break; }
            }
            if (!typeOk) continue;
            const pairs = perm.map((r, j) => ({ room: r, br: brs[j] }));
            const c = costFunction(pairs);
            if (c < bestCost) { bestCost = c; bestChosen = perm; }
          }
        }
      } else {
        // Greedy joint fallback: เรียง available ตาม floor+pos
        // แล้ว walk แบบ type-aware (assign ห้องที่ type ตรง ในลำดับ booking)
        available.sort((a, b) => a.floor - b.floor || a.pos - b.pos);
        const used = new Set();
        const chosen = [];
        for (const br of brs) {
          const pick = available.find(r => !used.has(r.num) && r.type === br.type);
          if (pick) { chosen.push(pick); used.add(pick.num); }
        }
        if (chosen.length === n) {
          bestChosen = chosen;
          bestCost = costFunction(chosen.map((r, j) => ({ room: r, br: brs[j] })));
        }
      }

      if (bestChosen) {
        bestChosen.forEach((r, j) => {
          result.assignments[idxs[j]] = r;
          result.steps.push({ type: 'accept', room: r, thought: `✅ เลือก ${r.num} (joint cost ${bestCost.toFixed(1)})`, cost: bestCost, assignedRooms: bestChosen.map(x => x.num) });
        });
        if (!result.seed) result.seed = bestChosen[0].num;
      }
    }
  }

  // [FIX Bug #2] Deep copy
  result.assignments = cloneAssignments(result.assignments);
  const allAssigned = result.assignments.every(a => a !== null);
  result.ok = allAssigned;
  const pairs = result.assignments.map((r, i) => ({ room: r, br: booking[i] })).filter(p => p.room);
  result.cost = allAssigned ? costFunction(pairs) : Infinity;
  result.steps.push({ type: 'done', room: null, thought: result.ok ? `🎉 cost=${result.cost.toFixed(1)}` : `❌ ล้มเหลว`, cost: result.cost, assignedRooms: result.assignments.filter(x => x).map(r => r.num) });
  return result;
}

function combinations(arr, k) {
  if (k === 0) return [[]];
  if (arr.length < k) return [];
  const [first, ...rest] = arr;
  const withFirst = combinations(rest, k - 1).map(c => [first, ...c]);
  const withoutFirst = combinations(rest, k);
  return [...withFirst, ...withoutFirst];
}

// [FIX v2] permutations: สร้างทุก permutation ของ array (ใช้ใน joint Coordinate เพื่อลอง type-compatible assignment)
function permutations(arr) {
  if (arr.length <= 1) return [arr];
  const result = [];
  for (let i = 0; i < arr.length; i++) {
    const rest = [...arr.slice(0, i), ...arr.slice(i + 1)];
    for (const p of permutations(rest)) {
      result.push([arr[i], ...p]);
    }
  }
  return result;
}

// ============================================================
// Algorithm D: Greedy Seed + Expand
// ============================================================
function algoGreedy(booking) {
  const result = { algo: 'Greedy Seed + Expand', steps: [], assignments: new Array(booking.length).fill(null), seed: null, ok: false, cost: Infinity };

  result.steps.push({ type: 'info', room: null, thought: `🅳 Greedy: เลือก seed แล้วขยายไปห้องใกล้สุด`, cost: 0, assignedRooms: [] });
  logOverlapFiltered(result, booking);

  // [FIX Bug #7] X09 priority as seed
  const x09 = getX09IfNeeded(booking, state.evalDates.checkIn, state.evalDates.checkOut);
  if (x09) {
    result.steps.push({ type: 'accept', room: x09, thought: `🎯 X09 Priority seed: ${x09.num}`, cost: 0, assignedRooms: [x09.num] });
    const idx = booking.findIndex(b => b.type === 'Deluxe' && b.beds > 0);
    if (idx >= 0) {
      result.assignments[idx] = x09;
      result.seed = x09.num;
    }
  }

  const remaining = booking.map((br, i) => ({ br, i })).filter(({ i }) => !result.assignments[i]);

  // [FIX v2 — JOINT MIXED-TYPE] Greedy แบบ joint: seed แล้วขยายข้าม type ได้
  // เพื่อให้ Suite+Deluxe พยายามอยู่ชั้นเดียวกันได้
  if (remaining.length > 0) {
    const neededTypes = [...new Set(remaining.map(r => r.br.type))];
    const allAvailable = state.rooms.filter(r =>
      !result.assignments.some(a => a && a.num === r.num) &&
      neededTypes.includes(r.type) &&
      isAvailable(r, state.evalDates.checkIn, state.evalDates.checkOut)
    );

    const n = remaining.length;
    if (allAvailable.length < n) {
      result.steps.push({ type: 'reject', room: null, thought: `❌ ห้องว่างไม่พอ (${allAvailable.length} < ${n})`, cost: 0, assignedRooms: [] });
    } else {
      const brs = remaining.map(r => r.br);
      const idxs = remaining.map(r => r.i);

      // เลือก seed = ห้องกลางสุดของ type ตัวแรกที่ booking สั่ง (type ของ brs[0])
      // เพื่อให้ seed เป็นห้องที่ "ใช้ได้จริง" สำหรับตำแหน่งแรก
      const seedCandidates = allAvailable.filter(r => r.type === brs[0].type)
        .slice().sort((a, b) => a.floor - b.floor || a.pos - b.pos);
      const seedIdx = Math.floor(seedCandidates.length / 2);
      const seedRoom = seedCandidates[seedIdx];
      result.assignments[idxs[0]] = seedRoom;
      if (!result.seed) result.seed = seedRoom.num;
      result.steps.push({ type: 'accept', room: seedRoom, thought: `🌱 Seed: เลือก ${seedRoom.num}`, cost: 0, assignedRooms: [seedRoom.num] });

      // Expand: หาห้องใกล้ seed ที่สุดที่ type ตรงกับ brs[j]
      const usedNums = new Set([seedRoom.num]);
      for (let j = 1; j < n; j++) {
        const candidates = allAvailable.filter(r => !usedNums.has(r.num) && r.type === brs[j].type);
        if (candidates.length === 0) break;

        candidates.sort((a, b) => {
          const da = Math.abs(a.floor - seedRoom.floor) + Math.abs(a.pos - seedRoom.pos) * 0.5 + (a.side !== seedRoom.side ? 10 : 0);
          const db = Math.abs(b.floor - seedRoom.floor) + Math.abs(b.pos - seedRoom.pos) * 0.5 + (b.side !== seedRoom.side ? 10 : 0);
          return da - db;
        });

        const chosen = candidates[0];
        result.assignments[idxs[j]] = chosen;
        usedNums.add(chosen.num);
        result.steps.push({ type: 'accept', room: chosen, thought: `➕ Expand: เลือก ${chosen.num} (ใกล้ seed, type ${chosen.type})`, cost: 0, assignedRooms: [...usedNums] });
      }
    }
  }

  // [FIX Bug #1 & #2] seed แยก + deep copy
  result.assignments = cloneAssignments(result.assignments);
  const allAssigned = result.assignments.every(a => a !== null);
  result.ok = allAssigned;
  const pairs = result.assignments.map((r, i) => ({ room: r, br: booking[i] })).filter(p => p.room);
  result.cost = allAssigned ? costFunction(pairs) : Infinity;
  result.steps.push({ type: 'done', room: null, thought: result.ok ? `🎉 cost=${result.cost.toFixed(1)}` : `❌ ล้มเหลว`, cost: result.cost, assignedRooms: result.assignments.filter(x => x).map(r => r.num) });
  return result;
}

// ============================================================
// Algorithm E: Hybrid (Coordinate + Greedy)
// ============================================================
function algoHybrid(booking) {
  const result = { algo: 'Hybrid (C+D)', steps: [], assignments: new Array(booking.length).fill(null), seed: null, ok: false, cost: Infinity };

  result.steps.push({ type: 'info', room: null, thought: `⭐ Hybrid: รัน Coordinate + Greedy แล้วเลือก cost ต่ำสุด`, cost: 0, assignedRooms: [] });
  logOverlapFiltered(result, booking);

  // รัน Coordinate
  const coordResult = algoCoordinate(JSON.parse(JSON.stringify(booking)));
  // รัน Greedy
  const greedyResult = algoGreedy(JSON.parse(JSON.stringify(booking)));

  // เลือก result ที่ดีกว่า
  let best = null;
  if (coordResult.ok && greedyResult.ok) {
    best = coordResult.cost <= greedyResult.cost ? coordResult : greedyResult;
  } else if (coordResult.ok) {
    best = coordResult;
  } else if (greedyResult.ok) {
    best = greedyResult;
  } else {
    best = coordResult;
  }

  // Copy best result (deep)
  result.assignments = cloneAssignments(best.assignments);
  result.seed = best.seed; // [FIX Bug #1] seed เป็น string|null ไม่ใช่ array property
  result.ok = best.ok;
  result.cost = best.cost;
  result.steps.push({ type: 'info', room: null, thought: `📊 Coordinate cost=${coordResult.cost === Infinity ? '∞' : coordResult.cost.toFixed(1)}, Greedy cost=${greedyResult.cost === Infinity ? '∞' : greedyResult.cost.toFixed(1)} → เลือก ${best.algo}`, cost: 0, assignedRooms: [] });
  result.steps.push({ type: 'done', room: null, thought: result.ok ? `🎉 Hybrid เลือก ${best.algo} cost=${result.cost.toFixed(1)}` : `❌ ทั้งคู่ล้มเหลว`, cost: result.cost, assignedRooms: result.assignments.filter(x => x).map(r => r.num) });
  return result;
}

// ============================================================
// 📊 ALGOS registry
// ============================================================
const ALGOS = {
  A: { name: 'Block Contiguous', fn: algoBlock },
  B: { name: 'Graph Connected', fn: algoGraph },
  C: { name: 'Coordinate + Cost', fn: algoCoordinate },
  D: { name: 'Greedy Seed + Expand', fn: algoGreedy },
  E: { name: 'Hybrid (C+D)', fn: algoHybrid },
};

// ============================================================
// 🎮 Run functions
// ============================================================
function clearAssigned() {
  state.rooms.forEach(r => {
    r.assigned = false;
    r.isSeed = false;
    r.state = null;
  });
}

function applyResult(result) {
  clearAssigned();
  const seedNum = result.seed; // [FIX Bug #1] string|null
  result.assignments.forEach(r => {
    if (r) {
      const room = state.rooms.find(x => x.num === r.num);
      if (room) {
        room.assigned = true;
        if (seedNum && room.num === seedNum) room.isSeed = true;
      }
    }
  });
  render();
}

function runSelected() {
  const algo = document.querySelector('input[name="algo"]:checked').value;
  const booking = state.currentBooking.rooms;
  if (booking.length === 0) {
    alert('เพิ่มห้องใน booking ก่อนค่ะ!');
    return;
  }
  clearAssigned();
  const t0 = performance.now();
  const res = ALGOS[algo].fn(JSON.parse(JSON.stringify(booking)));
  res.time = performance.now() - t0;
  res.algoKey = algo;
  res.bookingDates = { checkIn: state.evalDates.checkIn, checkOut: state.evalDates.checkOut };
  state.lastResults = { [algo]: res };
  stepState.activeResult = res;
  renderResults(state.lastResults);
  renderPrompt(res, state.currentBooking);
  startStepMode(res);
  applyResult(res);
}

function runAll() {
  const booking = state.currentBooking.rooms;
  if (booking.length === 0) {
    alert('เพิ่มห้องใน booking ก่อนค่ะ!');
    return;
  }
  const results = {};
  let best = null;
  let bestAlgo = null;
  Object.keys(ALGOS).forEach(key => {
    clearAssigned();
    const t0 = performance.now();
    const res = ALGOS[key].fn(JSON.parse(JSON.stringify(booking)));
    res.time = performance.now() - t0;
    res.algoKey = key;
    res.bookingDates = { checkIn: state.evalDates.checkIn, checkOut: state.evalDates.checkOut };
    results[key] = res;
    if (res.ok && (!best || res.cost < best.cost)) {
      best = res;
      bestAlgo = key;
    }
  });
  state.lastResults = results;
  if (best) {
    stepState.activeResult = best;
    applyResult(best);
    startStepMode(best);
  }
  renderResults(results);
  if (best) renderPrompt(best, state.currentBooking);
}

// ============================================================
// 📊 Results rendering
// ============================================================
function renderResults(results) {
  const grid = document.getElementById('results-grid');
  grid.innerHTML = '';
  let bestKey = null;
  let bestCost = Infinity;
  Object.entries(results).forEach(([key, res]) => {
    if (res.ok && res.cost < bestCost) {
      bestCost = res.cost;
      bestKey = key;
    }
  });
  Object.entries(results).forEach(([key, res]) => {
    const card = document.createElement('div');
    card.className = 'result-card';
    if (key === bestKey) card.classList.add('best');
    if (!res.ok) card.classList.add('fail');
    if (res.steps && res.steps.length > 0) card.classList.add('has-steps');
    const seedDisplay = res.seed || '—'; // [FIX Bug #8] seed มี value เสมอ
    card.innerHTML = `
      <div class="name">${key}. ${res.algo}</div>
      <div class="metric"><span>Cost:</span> ${res.ok ? res.cost.toFixed(1) : '❌ FAIL'}</div>
      <div class="metric"><span>Time:</span> ${res.time.toFixed(1)}ms</div>
      <div class="metric"><span>Seed:</span> ${seedDisplay}</div>
      <div class="metric"><span>Rooms:</span> ${res.assignments.filter(x => x).length}/${res.assignments.length}</div>
    `;
    card.onclick = () => {
      if (res.steps && res.steps.length > 0) {
        stepState.activeResult = res;
        // [FIX Bug #6] sync evalDates กับ booking context ของ result ที่คลิก
        if (res.bookingDates) {
          state.evalDates = res.bookingDates;
        }
        startStepMode(res);
        applyResult(res);
      }
    };
    grid.appendChild(card);
  });
  const okCount = Object.values(results).filter(r => r.ok).length;
  const failCount = Object.values(results).filter(r => !r.ok).length;
  document.getElementById('result-summary').textContent =
    `${okCount}✅ / ${failCount}❌ / ${Object.keys(results).length} algorithms${bestKey ? ` · Best: ${bestKey}` : ''}`;
}

function renderPrompt(result, booking) {
  const box = document.getElementById('prompt-box');
  const nights = daysBetween(state.evalDates.checkIn, state.evalDates.checkOut);
  const lines = [];
  lines.push(`# Room Assignment Result`);
  lines.push(`Algorithm: ${result.algo}`);
  lines.push(`Dates: ${state.evalDates.checkIn} → ${state.evalDates.checkOut} (${nights}n)`);
  lines.push(`Cost: ${result.ok ? result.cost.toFixed(2) : 'FAILED'}`);
  lines.push(`Seed: ${result.seed || 'none'}`);
  lines.push('');
  lines.push(`## Assignments`);
  result.assignments.forEach((r, i) => {
    const br = booking.rooms ? booking.rooms[i] : booking[i];
    if (r && br) {
      lines.push(`- ${r.num} (${r.type}, ${r.beds}bed, ${r.side}/P${r.pos}) → ${br.type}+${br.beds}bed`);
    }
  });
  lines.push('');
  lines.push(`## Weights`);
  lines.push(`floor=${state.weights.floor} side=${state.weights.side} pos=${state.weights.pos} bed=${state.weights.bed}`);
  box.textContent = lines.join('\n');
}

function copyPrompt() {
  const text = document.getElementById('prompt-box').textContent;
  navigator.clipboard.writeText(text).then(() => {
    const btn = document.querySelector('#results .prompt-header button');
    const orig = btn.textContent;
    btn.textContent = '✅ Copied!';
    setTimeout(() => btn.textContent = orig, 1500);
  });
}

// ============================================================
// 🎬 Step Explorer
// ============================================================
function startStepMode(result) {
  stepStop();
  stepState.steps = result.steps || [];
  stepState.current = -1;
  renderStepLog();
  stepForward();
}

function renderStepLog() {
  const log = document.getElementById('step-log');
  log.innerHTML = stepState.steps.map((step, i) => {
    const cls = i === stepState.current ? 'current' : step.type;
    return `<div class="log-entry ${cls}">${step.thought}</div>`;
  }).join('');
  document.getElementById('step-counter').textContent = `${stepState.current + 1} / ${stepState.steps.length}`;
}

function applyStep(step) {
  clearAssigned();
  if (stepState.activeResult) {
    const seedNum = stepState.activeResult.seed;
    stepState.activeResult.assignments.forEach(r => {
      if (r) {
        const room = state.rooms.find(x => x.num === r.num);
        if (room) {
          room.assigned = true;
          if (seedNum && room.num === seedNum) room.isSeed = true;
        }
      }
    });
  }
  if (step && step.room) {
    const room = state.rooms.find(x => x.num === step.room.num);
    if (room) room.state = step.type === 'reject' ? 'rejected' : step.type === 'accept' ? 'accepted' : 'considering';
  }
  // [FIX Bug #6] sync evalDates กับ active result context
  if (stepState.activeResult && stepState.activeResult.bookingDates) {
    state.evalDates = stepState.activeResult.bookingDates;
  }
  render();
}

function stepForward() {
  if (stepState.current < stepState.steps.length - 1) {
    stepState.current++;
    const step = stepState.steps[stepState.current];
    applyStep(step);
    renderStepLog();
    document.getElementById('thought-bubble').innerHTML = `<span class="step-icon">${stepIcon(step.type)}</span><span class="step-action">${step.type.toUpperCase()}:</span> ${step.thought}`;
    document.getElementById('current-cost').textContent = step.cost === 0 ? '—' : step.cost.toFixed(1);
    const log = document.getElementById('step-log');
    const cur = log.querySelector('.current');
    if (cur) cur.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }
}

function stepBackward() {
  if (stepState.current > 0) {
    stepState.current--;
    const step = stepState.steps[stepState.current];
    applyStep(step);
    renderStepLog();
    document.getElementById('thought-bubble').innerHTML = `<span class="step-icon">${stepIcon(step.type)}</span><span class="step-action">${step.type.toUpperCase()}:</span> ${step.thought}`;
    document.getElementById('current-cost').textContent = step.cost === 0 ? '—' : step.cost.toFixed(1);
  }
}

function stepPlay() {
  if (stepState.playing) {
    stepStop();
  } else {
    if (stepState.current >= stepState.steps.length - 1) stepReset();
    stepState.playing = true;
    document.getElementById('btn-play').textContent = '⏸ Pause';
    stepState.interval = setInterval(() => {
      if (stepState.current < stepState.steps.length - 1) {
        stepForward();
      } else {
        stepStop();
      }
    }, stepState.speed);
  }
}

function stepStop() {
  stepState.playing = false;
  if (stepState.interval) clearInterval(stepState.interval);
  stepState.interval = null;
  const btn = document.getElementById('btn-play');
  if (btn) btn.textContent = '▶ Play';
}

function stepReset() {
  stepStop();
  stepState.current = -1;
  clearAssigned();
  renderStepLog();
  document.getElementById('thought-bubble').innerHTML = '<span style="color:var(--muted)">Reset แล้วค่ะ กด Step/Play เพื่อเริ่มใหม่</span>';
  document.getElementById('current-cost').textContent = '—';
  render();
}

function stepIcon(type) {
  return { info: '💡', consider: '🤔', accept: '✅', reject: '❌', done: '🎉' }[type] || '▸';
}

function updateSpeed() {
  stepState.speed = parseInt(document.getElementById('step-speed').value);
  document.getElementById('speed-val').textContent = `${stepState.speed}ms`;
  if (stepState.playing) {
    stepStop();
    stepPlay();
  }
}

// ============================================================
// 🚪 Room Reservation Modal
// ============================================================
function openRoomModal(room) {
  state.modalRoom = room;
  document.getElementById('modal-title').innerHTML = `${room.num} <span style="font-size:11px;color:var(--muted)">${room.type} · ${room.side}/P${room.pos} · ${room.beds}bed</span>`;
  renderModalBody(room);
  document.getElementById('room-modal').style.display = 'flex';
}

function closeRoomModal() {
  document.getElementById('room-modal').style.display = 'none';
  state.modalRoom = null;
}

function renderModalBody(room) {
  const body = document.getElementById('modal-body');
  const start = getTimelineStart();
  const timelineDays = [];
  for (let i = 0; i < TIMELINE_DAYS; i++) {
    const date = addDays(start, i);
    const occ = isRoomOccupiedOn(room, date);
    timelineDays.push(`<div class="mtl-day ${occ ? 'occ' : 'free'}">${date.substring(8)}</div>`);
  }
  const labels = [];
  for (let i = 0; i < TIMELINE_DAYS; i++) {
    labels.push(`<span>${addDays(start, i).substring(8)}</span>`);
  }

  const reservationsList = room.reservations.length === 0
    ? '<div style="color:var(--muted);font-size:11px;">ยังไม่มี reservation</div>'
    : room.reservations.map((res, i) => `
      <div class="res-item">
        <span>
          <span class="res-label">${res.label}</span>
          <span class="res-dates">${res.checkIn} → ${res.checkOut}</span>
          <span class="res-type">${res.type}</span>
        </span>
        <button class="small danger" onclick="removeReservation(${i})">✕</button>
      </div>
    `).join('');

  body.innerHTML = `
    <div class="modal-section">
      <h3>📅 Timeline (14 วัน)</h3>
      <div class="modal-timeline">${timelineDays.join('')}</div>
      <div class="modal-timeline-labels">${labels.join('')}</div>
    </div>
    <div class="modal-section">
      <h3>📋 Reservations (${room.reservations.length})</h3>
      ${reservationsList}
    </div>
    <div class="modal-section">
      <h3>➕ Add Reservation</h3>
      <div class="row">
        <label>Check-in: <input type="date" id="res-checkin" value="${state.currentBooking.checkIn}"></label>
        <label>Check-out: <input type="date" id="res-checkout" value="${state.currentBooking.checkOut}"></label>
      </div>
      <label>Label: <input type="text" id="res-label" value="Manual" placeholder="ชื่อ reservation"></label>
      <button class="primary small" onclick="addReservation()" style="width:100%;margin-top:4px;">+ เพิ่ม Reservation</button>
    </div>
  `;
}

function addReservation() {
  const room = state.modalRoom;
  if (!room) return;
  const checkIn = document.getElementById('res-checkin').value;
  const checkOut = document.getElementById('res-checkout').value;
  const label = document.getElementById('res-label').value || 'Manual';
  if (checkIn >= checkOut) {
    alert('Check-out ต้องหลัง check-in ค่ะ!');
    return;
  }
  room.reservations.push({ checkIn, checkOut, label, type: 'manual' });
  renderModalBody(room);
  render();
}

function removeReservation(i) {
  const room = state.modalRoom;
  if (!room) return;
  room.reservations.splice(i, 1);
  renderModalBody(room);
  render();
}

// Close modal on backdrop click / ESC
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeRoomModal();
});
document.getElementById('room-modal').addEventListener('click', (e) => {
  if (e.target.id === 'room-modal') closeRoomModal();
});

// ============================================================
// 🔧 Resizable Panels — drag handles to resize zones
// ============================================================
function makeResizer(el, axis, varName, min, max) {
  if (!el) return;
  let startPos = 0, startSize = 0;

  const beginDrag = (clientPos) => {
    el.classList.add('active');
    startPos = clientPos;
    const cur = parseFloat(getComputedStyle(document.documentElement).getPropertyValue(varName));
    startSize = isNaN(cur) ? (axis === 'x' ? 300 : 200) : cur;
    document.body.style.cursor = axis === 'x' ? 'col-resize' : 'row-resize';
    document.body.style.userSelect = 'none';
module.exports = { algoBlock, algoGraph, algoCoordinate, algoGreedy, algoHybrid, state, buildRooms };
