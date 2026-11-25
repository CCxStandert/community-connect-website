/* Built from cc-site/template.html by build.py. Edit there. */
{
const CFG = window.CCLanding || {};
const FALLBACK = CFG.bikes || [];
const EVENTS = CFG.events || [];
const HORIZON = CFG.horizon || '2027-12-31';
const ICON = CFG.icon || '';
const IMG = CFG.img || 'img';
const STATS = CFG.stats || null;
const API = CFG.api || null;
const LIVE = !!CFG.live;
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const nice = (d) => `${Number(d.slice(8))} ${MON[Number(d.slice(5, 7)) - 1]} ${d.slice(0, 4)}`;
const setText = (id, v) => { const e = $(id); if (e) e.textContent = v; };
setText('horizon', nice(HORIZON));
setText('yr', new Date().getFullYear());
const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ---- menu ---- */
const menu = $('menu'), mOpen = $('menuOpen');
if (menu && mOpen) {
const setMenu = (on) => { menu.hidden = !on; mOpen.setAttribute('aria-expanded', on); document.body.style.overflow = on ? 'hidden' : ''; if (on) $('menuClose').focus(); else mOpen.focus(); };
mOpen.addEventListener('click', () => setMenu(true));
$('menuClose').addEventListener('click', () => setMenu(false));
menu.addEventListener('click', (e) => { if (e.target.closest('a')) setMenu(false); });
menu.addEventListener('keydown', (e) => { if (e.key === 'Escape') setMenu(false); });
}

/* ---- hero slides (any element with id="slides"; dots are added if the page has none) ---- */
const slides = $('slides');
if (slides) {
const slideEls = [...slides.children];
if (!$('dots')) { const d = document.createElement('div'); d.className = 'dots'; d.id = 'dots'; d.setAttribute('role', 'group'); d.setAttribute('aria-label', 'Choose a highlight'); slides.after(d); }
$('dots').innerHTML = slideEls.map((_, i) => `<button aria-label="Highlight ${i + 1} of ${slideEls.length}"></button>`).join('');
const dotEls = [...$('dots').children];
const curSlide = () => Math.round(slides.scrollLeft / slides.clientWidth);
const mark = () => dotEls.forEach((d, i) => d.setAttribute('aria-current', i === curSlide()));
slides.addEventListener('scroll', () => requestAnimationFrame(mark), { passive: true });
dotEls.forEach((d, i) => d.addEventListener('click', () => slides.scrollTo({ left: i * slides.clientWidth, behavior: reduce ? 'auto' : 'smooth' })));
mark();
let heroPaused = reduce;
slides.addEventListener('pointerdown', () => { heroPaused = true; });
if (!reduce && slideEls.length > 1) setInterval(() => { if (!heroPaused && !document.hidden) { const n = (curSlide() + 1) % slideEls.length; slides.scrollTo({ left: n * slides.clientWidth, behavior: 'smooth' }); } }, 6000);
}

/* ---- the fleet: availability first (Airbnb-style) ----
   Every card shows when its bike is free; "Check availability" opens a date picker, and the grid
   then shows which bikes are free for all of the picked days. */
if ($('list')) {
const COLORS = { 'CC-03': ['#CFC8BB', 0], 'CC-04': ['#BDB8AE', 0], 'CC-05': ['#4E6F3A', 0], 'CC-06': ['#E8C33A', 0], 'CC-07': ['#EF6AA9', 0], 'CC-08': ['#2A2830', 1], 'CC-09': ['#2F5C45', 0], 'CC-11': ['#B9783A', 0], 'CC-12': ['#D9CBD3', 0], 'CC-13': ['#D9CBD3', 0] };
const BRAND = CFG.brand || '<span class="mono">Standert</span>'; // Our own SVG, from the server. Bike IDs stay internal.
const PHOTOS = { 'CC-12': 'cc-13.webp', 'CC-13': 'cc-12.webp' };
const DOW = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
let bikes = FALLBACK.map((b) => ({ ...b })), filter = 'all';
let cal = null;            // { today, earliest, latest, max_days, bikes: { CC-xx: { bookable, taken:Set } } }
let sel = { a: null, b: null };
let ctx = null;            // set when "Find a bike" on an event picked the dates
const fits = (b, h) => b.range && h >= b.range[0] - 2 && h <= b.range[1] + 2;
const soon = (b) => b.state === 'soon';
const label = { available: 'Available', out: 'Out riding', workshop: 'Workshop', booked: 'Booked', reserved: 'Raffle bike', soon: 'Coming soon' };
const cats = () => [...new Set(bikes.map((b) => b.cat).filter(Boolean))];
const chipsHtml = () => [['all', 'Show all'], ['fits', 'Fits me'], ...cats().map((c) => [c, c])].map(([k, l]) => `<button class="chip" data-f="${esc(k)}" aria-pressed="${k === filter}">${esc(l)}</button>`).join('');
const pad = (n) => String(n).padStart(2, '0');
const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const addDays = (d, n) => { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() + n); return iso(x); };
const dayMon = (d) => `${Number(d.slice(8))} ${MON[Number(d.slice(5, 7)) - 1]}`;
const short = (a, b) => (a === b ? dayMon(a) : (a.slice(5, 7) === b.slice(5, 7) ? `${Number(a.slice(8))}–${dayMon(b)}` : `${dayMon(a)} – ${dayMon(b)}`));
const height = () => +$('h').value;
const shown = (b) => filter === 'all' || (filter === 'fits' ? fits(b, height()) : b.cat === filter);
const info = (b) => cal && cal.bikes[b.cc];
const freeOn = (b, d) => { const i = info(b); return !!i && i.bookable && d >= cal.earliest && d <= cal.latest && !i.taken.has(d); };
const freeRange = (b, a, z) => { for (let d = a; d <= z; d = addDays(d, 1)) { if (!freeOn(b, d)) return false; } return true; };
const nextFree = (b) => { if (!cal) return null; for (let d = cal.earliest; d <= cal.latest; d = addDays(d, 1)) { if (freeOn(b, d)) return d; } return null; };
const hasRange = () => sel.a && sel.b;

const pic = (b) => {
  const img = b.image || (PHOTOS[b.cc] ? `${IMG}/${PHOTOS[b.cc]}` : '');
  const c = COLORS[b.cc] || ['#D3C8B4', 0];
  return img ? `<span class="pic"><img src="${esc(img)}" alt="${esc(b.model)} ${esc(b.size)}, ${esc(b.name)}" loading="lazy"></span>`
    : `<span class="pic${c[1] ? ' dark' : ''}" style="background:${c[0]}"><b>${esc(b.size)}</b><span class="hand">${soon(b) ? 'coming 2027' : 'photo to come'}</span></span>`;
};
// The card's availability: a line ("Free now", "Free from 3 Dec") and two weeks of days.
const availHtml = (b) => {
  if (soon(b)) return '<span class="av"><span class="av__t">Joins the fleet in 2027 · not bookable yet</span></span>';
  if (!cal || !info(b)) return '';
  const i = info(b);
  if (!i.bookable) return '<span class="av"><span class="av__t">Back on the calendar soon</span></span>';
  const from = hasRange() ? sel.a : cal.earliest;
  let t;
  if (hasRange()) t = freeRange(b, sel.a, sel.b) ? `<b>Free ${esc(short(sel.a, sel.b))}</b>` : 'Taken on those dates';
  else { const n = nextFree(b); t = !n ? 'Fully booked' : (n === cal.earliest ? '<b>Free from ' + (cal.earliest === addDays(cal.today, 1) ? 'tomorrow' : esc(dayMon(n))) + '</b>' : `Free from ${esc(dayMon(n))}`); }
  let strip = '';
  for (let k = 0; k < 14; k++) {
    const d = addDays(from, k);
    const inSel = hasRange() && d >= sel.a && d <= sel.b;
    strip += `<i class="${freeOn(b, d) ? 'f' : 't'}${inSel ? ' s' : ''}"></i>`;
  }
  return `<span class="av"><span class="av__t">${t}</span><span class="av__s" aria-hidden="true">${strip}</span></span>`;
};
const render = () => {
  const h = height();
  $('hv').textContent = `${h} cm`;
  const real = bikes.filter((b) => !soon(b));
  const sizes = [...new Set(real.filter((b) => fits(b, h)).map((b) => b.size))].sort();
  const nFit = real.filter((b) => fits(b, h)).length;
  $('fitMsg').textContent = nFit ? `Probably size ${sizes.join(' or ')}: ${nFit} bike${nFit === 1 ? '' : 's'} fit${nFit === 1 ? 's' : ''}.` : 'Nothing in the fleet fits that height yet. Message us anyway.';
  $('chips').innerHTML = chipsHtml();
  // Bookable bikes first (by number), the ones still on their way at the end.
  // With dates picked, the bikes free for all of them come first (then taken, then coming soon).
  const rank = (b) => (soon(b) ? 2 : (hasRange() && cal && !freeRange(b, sel.a, sel.b) ? 1 : 0));
  const list = [...bikes].sort((a, b) => (rank(a) - rank(b)) || a.cc.localeCompare(b.cc));
  $('list').innerHTML = list.map((b) => {
    const ok = fits(b, h);
    const free = hasRange() && cal ? freeRange(b, sel.a, sel.b) : null;
    const st = soon(b) ? 'soon' : (free === null ? b.state : (free ? 'available' : 'booked'));
    const chip = soon(b) ? label.soon : (free === null ? (label[st] || st) : (free ? 'Free those dates' : 'Booked'));
    const href = free ? `${b.url}${b.url.includes('?') ? '&' : '?'}start=${sel.a}&end=${sel.b}${ctx && ctx.id ? `&event=${ctx.id}` : ''}#request` : b.url;
    const inner = `<span class="hd"><span class="brand">${BRAND}</span><span class="st ${esc(st)}">${esc(chip)}</span></span>${pic(b)}<strong>${esc(b.model)} ${esc(b.size)}</strong><small>${esc(b.name)}</small><span class="fit">${b.range ? `${b.range[0]}–${b.range[1]} cm${ok && !soon(b) ? ' · <b>fits you</b>' : ''}` : ''}</span>${availHtml(b)}`;
    const dim = free === false || soon(b);
    return `<li${shown(b) ? '' : ' hidden'}>${soon(b) ? `<div class="card soon dim">${inner}</div>` : `<a class="card${dim ? ' dim' : ''}" href="${esc(href)}">${inner}</a>`}</li>`;
  }).join('');
  const nShown = list.filter((b) => shown(b) && !soon(b)).length;
  const nSoon = list.filter((b) => shown(b) && soon(b)).length;
  if (hasRange() && cal) {
    const n = list.filter((b) => shown(b) && freeRange(b, sel.a, sel.b)).length;
    $('msg').textContent = n
      ? `${n} bike${n === 1 ? '' : 's'} free ${short(sel.a, sel.b)}${ctx ? ` for ${ctx.name}` : ''}. Tap one to request it.`
      : (ctx ? `Every bike is taken around ${ctx.name}. Message us, we may still sort something out.` : 'Nothing free for all of those days. Try shifting by a day or two.');
  } else if (sel.a && cal) {
    $('msg').textContent = `From ${dayMon(sel.a)}. Now tap your last day.`;
  } else {
    $('msg').textContent = `${nShown} bike${nShown === 1 ? '' : 's'}${nSoon ? ` · ${nSoon} coming soon` : ''}` + (LIVE ? '' : ' · preview');
  }
  setText('nBikes', real.length);
  days();
};

/* "Check availability": Pick-up | Return fields and a date picker (Airbnb-style). Days with no bike
   free are struck through; once a pick-up day is set, return days that no single bike can cover are too. */
const WD = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
// The picker is built here, not in the page HTML (WordPress would wrap its lines in paragraphs).
const dp = $('avail') ? Object.assign(document.createElement('div'), { className: 'dp', id: 'dp', hidden: true }) : null;
if (dp) { dp.innerHTML = '<div class="dp__in" role="dialog" aria-modal="true" aria-labelledby="dpT"><div class="dp__hd"><div class="dp__tt"><h3 id="dpT">Select dates</h3><p id="dpHint">Pick the day you collect the bike.</p></div><div class="avail__box dp__sel"><span class="avail__f" id="dpA"><span class="mono">Pick-up</span><b>Add date</b></span><span class="avail__f" id="dpB"><span class="mono">Return</span><b>Add date</b></span></div><button type="button" class="dp__x" id="dpX" aria-label="Close">×</button></div><div class="dp__cal"><button type="button" class="dp__nav" id="dpPrev" aria-label="Earlier months">‹</button><div class="dp__months" id="dpMonths"></div><button type="button" class="dp__nav" id="dpNext" aria-label="Later months">›</button></div><div class="dp__ft"><p class="dp__key"><span class="k off"></span>No bike free &nbsp; <span class="k sel"></span>Your dates</p><button type="button" class="avail__clear" id="dpClear">Clear dates</button><button type="button" class="pillbtn ink" id="dpDone">Close</button></div></div>'; document.body.appendChild(dp); }
const wide = matchMedia('(min-width: 760px)');
let m0 = null, lastFocus = null;
const pool = () => bikes.filter((b) => shown(b) && !soon(b));
const nFree = (d) => pool().filter((b) => freeOn(b, d)).length;
const nRange = (a, z) => pool().filter((b) => freeRange(b, a, z)).length;
const dlen = (a, z) => Math.round((new Date(z + 'T12:00:00') - new Date(a + 'T12:00:00')) / 864e5) + 1;
const nice = (d) => { const x = new Date(d + 'T12:00:00'); return `${['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][x.getDay()]} ${x.getDate()} ${MON[x.getMonth()]}`; };
const fields = () => {
  [['availA', 'dpA', sel.a], ['availB', 'dpB', sel.b]].forEach(([f1, f2, v]) => {
    [f1, f2].forEach((id) => { const el = $(id); if (!el) return; el.querySelector('b').textContent = v ? nice(v) : 'Add date'; el.classList.toggle('has', !!v); });
  });
  if ($('dpA')) { $('dpA').classList.toggle('is-on', !sel.a || !!sel.b); $('dpB').classList.toggle('is-on', !!sel.a && !sel.b); }
  if ($('availGo')) $('availGo').textContent = hasRange() ? 'Change dates' : 'Check availability';
  if ($('availClear')) $('availClear').hidden = !sel.a;
};
const days = () => { fields(); if (dp && !dp.hidden) months(); };
const dayState = (d) => {
  if (!cal || d < cal.earliest || d > cal.latest) return { off: true, out: true };
  if (sel.a && !sel.b && d >= sel.a) {
    const ok = (!cal.max_days || dlen(sel.a, d) <= cal.max_days) && nRange(sel.a, d) > 0;
    return { off: !ok, n: ok ? nRange(sel.a, d) : 0 };
  }
  const n = nFree(d);
  return { off: n === 0, n };
};
const months = () => {
  if (!cal) return;
  const first = cal.today.slice(0, 7), last = cal.latest.slice(0, 7);
  if (!m0) m0 = (sel.a || cal.earliest).slice(0, 7);
  const count = wide.matches ? 2 : 1;
  const addM = (ym, k) => { const [y, m] = ym.split('-').map(Number); const t = new Date(y, m - 1 + k, 1); return `${t.getFullYear()}-${pad(t.getMonth() + 1)}`; };
  if (m0 < first) m0 = first;
  if (addM(m0, count - 1) > last) m0 = addM(last, -(count - 1)) < first ? first : addM(last, -(count - 1));
  let html = '';
  for (let k = 0; k < count; k++) {
    const ym = addM(m0, k);
    const [y, m] = ym.split('-').map(Number);
    const lead = (new Date(y, m - 1, 1).getDay() + 6) % 7;
    const dim = new Date(y, m, 0).getDate();
    let cells = '<span></span>'.repeat(lead);
    for (let n = 1; n <= dim; n++) {
      const d = `${ym}-${pad(n)}`;
      const st = dayState(d);
      const z = sel.b || sel.a;
      const cls = ['dp__d', st.out ? 'is-out' : (st.off ? 'is-off' : ''), d === sel.a ? 'is-a' : '', sel.b && d === sel.b ? 'is-b' : '', sel.a && sel.b && d > sel.a && d < sel.b ? 'is-in' : '', d === cal.today ? 'is-today' : ''].filter(Boolean).join(' ');
      const lab = `${nice(d)}${st.off ? ', not available' : `, ${st.n} bike${st.n === 1 ? '' : 's'} free`}`;
      cells += `<button type="button" class="${cls}" data-d="${d}"${st.off ? ' disabled' : ''} aria-label="${lab}"${d === sel.a || d === z ? ' aria-pressed="true"' : ''}>${n}</button>`;
    }
    html += `<div class="dp__m"><h4>${MONTHS[m - 1]} ${y}</h4><div class="dp__g">${WD.map((w) => `<span class="dp__w">${w}</span>`).join('')}${cells}</div></div>`;
  }
  $('dpMonths').innerHTML = html;
  $('dpPrev').disabled = m0 <= first;
  $('dpNext').disabled = addM(m0, count - 1) >= last;
  $('dpHint').textContent = !sel.a ? 'Pick the day you collect the bike.' : (!sel.b ? `Now your return day. Loans go up to ${cal.max_days || 7} days.` : `${nRange(sel.a, sel.b)} bike${nRange(sel.a, sel.b) === 1 ? '' : 's'} free ${short(sel.a, sel.b)}.`);
  const n = hasRange() ? nRange(sel.a, sel.b) : 0;
  $('dpDone').textContent = hasRange() ? (n ? `Show ${n} bike${n === 1 ? '' : 's'}` : 'Close') : 'Close';
};
const openDp = (e) => {
  if (!dp || !cal) return;
  lastFocus = e && e.currentTarget || document.activeElement;
  m0 = null; dp.hidden = false; document.documentElement.classList.add('dp-open');
  months(); fields();
  setTimeout(() => { const f = dp.querySelector('.dp__d.is-a, .dp__d:not([disabled])'); if (f) f.focus(); }, 30);
};
const closeDp = () => {
  if (!dp || dp.hidden) return;
  dp.hidden = true; document.documentElement.classList.remove('dp-open');
  if (lastFocus && lastFocus.focus) lastFocus.focus();
};
const pick = (d) => {
  ctx = null;
  if (!sel.a || sel.b || d < sel.a) sel = { a: d, b: null };
  else sel.b = d;
  render(); months();
};
if (dp) {
  document.querySelectorAll('[data-dp]').forEach((b) => b.addEventListener('click', openDp));
  $('dpMonths').addEventListener('click', (e) => { const el = e.target.closest('.dp__d'); if (el && !el.disabled) pick(el.dataset.d); });
  const shift = (k) => { const [y, m] = m0.split('-').map(Number); const t = new Date(y, m - 1 + k, 1); m0 = `${t.getFullYear()}-${pad(t.getMonth() + 1)}`; months(); };
  $('dpPrev').addEventListener('click', () => shift(-1));
  $('dpNext').addEventListener('click', () => shift(1));
  $('dpX').addEventListener('click', closeDp);
  $('dpDone').addEventListener('click', () => { closeDp(); if (hasRange()) { const l = $('list'); if (l) l.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' }); } });
  const clear = () => { sel = { a: null, b: null }; ctx = null; render(); months(); };
  $('dpClear').addEventListener('click', clear);
  $('availClear').addEventListener('click', clear);
  dp.addEventListener('click', (e) => { if (e.target === dp) closeDp(); });
  document.addEventListener('keydown', (e) => {
    if (dp.hidden) return;
    if (e.key === 'Escape') closeDp();
    if (e.key === 'Tab') { // keep focus inside the picker
      const f = [...dp.querySelectorAll('button:not([disabled])')]; if (!f.length) return;
      if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    }
  });
  (wide.addEventListener ? wide.addEventListener('change', () => { if (!dp.hidden) months(); }) : null);
}

$('chips').addEventListener('click', (e) => { const b = e.target.closest('[data-f]'); if (!b) return; filter = b.dataset.f; render(); });
// Moving the size slider shows the bikes that fit; "Show all" brings back the whole fleet.
$('h').addEventListener('input', () => { filter = 'fits'; render(); });
try { const saved = localStorage.getItem('cc-height'); if (saved) $('h').value = saved; } catch (e) {}
$('h').addEventListener('change', () => { try { localStorage.setItem('cc-height', $('h').value); } catch (e) {} });

// From the events list: pick up two days before, back the day after.
window.ccFindBike = (e) => {
  const today = cal ? cal.earliest : addDays(iso(new Date()), 1);
  let a = addDays(e.start, -2), b = addDays(e.end || e.start, 1);
  if (a < today) a = today;
  if (b > HORIZON) b = HORIZON;
  sel = { a, b }; ctx = { id: e.id, name: e.name }; filter = 'all';
  render();
  const box = $('avail').closest('section') || $('avail');
  box.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
};

render();
if (API) {
  fetch(`${API}/fleet-calendar`, { headers: { Accept: 'application/json' } }).then((r) => (r.ok ? r.json() : null)).then((j) => {
    if (!j || !j.bikes) { $('avail').hidden = true; return; }
    const out = {};
    Object.keys(j.bikes).forEach((k) => {
      const taken = new Set();
      (j.bikes[k].booked || []).forEach((r) => { for (let d = r.start; d <= r.end; d = addDays(d, 1)) taken.add(d); });
      out[k] = { bookable: !!j.bikes[k].bookable, taken };
    });
    cal = { today: j.today, earliest: j.earliest, latest: j.latest, max_days: j.max_days, bikes: out };
    render();
  }).catch(() => { $('avail').hidden = true; });
} else if ($('avail')) { $('avail').hidden = true; }
if (API && !LIVE) {
  fetch(`${API}/bikes`, { headers: { Accept: 'application/json' } }).then((r) => r.ok ? r.json() : null).then((j) => {
    if (!j || !j.length) return;
    bikes = j.map((b) => ({ cc: b.cc_id, name: b.name, model: b.model, size: b.size, cat: b.category, range: b.rider_range, state: b.state, url: b.url, image: b.image }));
    render();
  }).catch(() => {});
}

}

/* ---- how it works: the loop + step cards ---- */
const STEPS = [
  ['Pick a bike', 'Find one in your size that’s free on your dates.', 'Open a bike, tap your first and last day and send the request. Racing? Pick the event.'],
  ['We say yes', 'Usually within a couple of days, by email.', 'We check who has the bike before you. You get a yes with the handoff, or other dates or bikes.'],
  ['Meet the last rider', 'We connect you on WhatsApp.', 'Collect from the rider before you, or from the crew. Check the bike together and flag anything off.'],
  ['Ride', 'Race, explore, commute, go bikepacking.', 'It’s yours for those dates. Something feels off? Message us early.'],
  ['Bring it back clean', 'Washed, chain lubed, on time.', 'Same condition you got it in. A dirty bike means you cover a wash & lube.'],
  ['Pass it on', 'The next rider is waiting.', 'Hand it over, share what the bike let you do, and come back for another one.']
];
const NAMES = ['Pick', 'Yes', 'Handoff', 'Ride', 'Return', 'Pass on'];
const stepsBox = $('how-steps');
// Step cards written on the page (e.g. in the theme's editor) win: the loop takes its words from them.
const cards = stepsBox ? [...stepsBox.children].filter((c) => c.querySelector('h3')) : [];
if (cards.length >= 2) {
  STEPS.length = 0;
  cards.forEach((c) => { const t = c.querySelector('h3').textContent.trim(); const body = (c.querySelector('p:not(.mono)') || {}).textContent || ''; STEPS.push([t, (body.match(/^[^.!?]+[.!?]/) || [body])[0].trim(), body]); });
} else if (stepsBox) {
  stepsBox.innerHTML = STEPS.map((s, i) => `<div role="listitem" data-i="${i}"><span class="mono">0${i + 1} / ${esc(NAMES[i])}</span><h3>${esc(s[0])}</h3><p>${esc(s[2])}</p></div>`).join('');
}
const svg = $('track');
if (svg) {
let cur = 0, playing = !reduce, t0 = performance.now(), len = 0, pathEl = null, rider = null, stopEls = [];
const PER = 2600;
const draw = () => {
  const W = svg.clientWidth, H = svg.clientHeight;
  if (!W || !H) return;
  const m = Math.max(30, Math.min(W, H) * 0.1);
  const x1 = m, x2 = W - m, y1 = m, y2 = H - m - 34;
  const ry = Math.min((x2 - x1) / 2, (y2 - y1) / 2, 170);
  const d = `M ${x1 + ry} ${y1} H ${x2 - ry} A ${ry} ${ry} 0 0 1 ${x2 - ry} ${y2} H ${x1 + ry} A ${ry} ${ry} 0 0 1 ${x1 + ry} ${y1} Z`;
  svg.innerHTML = `<path id="p" d="${d}" fill="none" stroke="#D3C8B4" stroke-width="12" stroke-linecap="round"/><path d="${d}" fill="none" stroke="#141217" stroke-width="1.5" stroke-dasharray="2 9"/>` +
    STEPS.map((_, i) => `<g class="stop"><circle r="11" fill="#F4EEE4" stroke="#141217" stroke-width="1.5"/><text text-anchor="middle" dy="4" font-family="IBM Plex Mono, monospace" font-size="11" fill="#141217">${i + 1}</text></g>`).join('') +
    `<g id="rider"><circle r="26" fill="#FFB1EC" stroke="#141217" stroke-width="1.5"/><g transform="translate(-18 -18) scale(0.09)" fill="#141217">${ICON}</g></g>`;
  pathEl = svg.querySelector('#p'); len = pathEl.getTotalLength(); rider = svg.querySelector('#rider'); stopEls = [...svg.querySelectorAll('.stop')];
  stopEls.forEach((g, i) => { const p = pathEl.getPointAtLength(len * i / STEPS.length); g.setAttribute('transform', `translate(${p.x} ${p.y})`); });
  place(cur / STEPS.length); paint();
};
const place = (f) => {
  if (!pathEl) return;
  const at = (((f % 1) + 1) % 1) * len;
  const a = pathEl.getPointAtLength(at), b = pathEl.getPointAtLength((at + 2) % len);
  const lean = Math.max(-14, Math.min(14, (b.y - a.y) * 7)) * (b.x >= a.x ? 1 : -1);
  const bob = reduce ? 0 : Math.sin(at / 9) * 1.5;
  rider.setAttribute('transform', `translate(${a.x} ${a.y - 2 + bob}) rotate(${lean})`);
};
const paint = () => stopEls.forEach((g, k) => g.querySelector('circle').setAttribute('fill', k === cur ? '#FFB1EC' : k < cur ? '#D3C8B4' : '#F4EEE4'));
const show = (i) => {
  cur = (i + STEPS.length) % STEPS.length;
  const say = $('say');
  say.classList.add('swap');
  setTimeout(() => { $('sayN').textContent = `Step ${cur + 1} of ${STEPS.length}`; $('sayB').textContent = STEPS[cur][0]; $('sayS').textContent = STEPS[cur][1]; say.classList.remove('swap'); }, reduce ? 0 : 220);
  paint();
  if (stepsBox) [...stepsBox.children].forEach((s, k) => s.classList.toggle('on', k === cur));
};
const tick = (now) => {
  if (playing) {
    const f = (now - t0) / (PER * STEPS.length);
    place(f);
    const i = Math.floor((f * STEPS.length) % STEPS.length);
    if (i !== cur) show(i);
  }
  requestAnimationFrame(tick);
};
new ResizeObserver(draw).observe(svg);
const setPlay = (on) => { playing = on; $('play').textContent = on ? 'Pause' : 'Play'; $('play').setAttribute('aria-label', on ? 'Pause animation' : 'Play animation'); };
$('play').addEventListener('click', () => { setPlay(!playing); if (playing) t0 = performance.now() - cur * PER; });
const jump = (k) => { setPlay(false); show(cur + k); place(cur / STEPS.length); };
$('prev').addEventListener('click', () => jump(-1));
$('next').addEventListener('click', () => jump(1));
if (reduce) setPlay(false);
show(0); requestAnimationFrame(tick);
}

/* ---- events ---- */
const span = (e) => {
  const y = e.start.slice(0, 4);
  if (!e.end || e.end === e.start) return `${Number(e.start.slice(8))} ${MON[+e.start.slice(5, 7) - 1]} ${y}`;
  const sm = MON[+e.start.slice(5, 7) - 1], em = MON[+e.end.slice(5, 7) - 1];
  return sm === em ? `${Number(e.start.slice(8))}–${Number(e.end.slice(8))} ${sm} ${y}` : `${Number(e.start.slice(8))} ${sm}–${Number(e.end.slice(8))} ${em} ${y}`;
};
// Categories: an event can be a race, a social ride, an overnight bikepacking trip… (several at once).
const MAIN = ['race', 'group', 'social', 'overnight', 'bikepacking', 'getaway'];
const labelsOf = (e) => e.labels || (e.type ? [/ride|getaway/i.test(e.type) ? 'Ride' : 'Race'] : []);
const catsOf = (e) => e.cats || [];
// Road / gravel / MTB: its own pill first, so every row says what bike it's for.
const SURF = { road: 'Road', gravel: 'Gravel', mtb: 'MTB' };
const surfPill = (e) => { const k = catsOf(e).find((x) => SURF[x]); return k ? `<span class="st surf surf--${k}">${SURF[k]}</span>` : ''; };
const kindPills = (e) => {
  const l = labelsOf(e), c = catsOf(e);
  let idx = c.map((k, i) => (MAIN.includes(k) ? i : -1)).filter((i) => i >= 0);
  if (!idx.length) idx = l.length ? [0] : [];
  return idx.slice(0, 2).map((i) => `<span class="st${c[i] === 'race' ? ' race' : ''}">${esc(l[i])}</span>`).join('');
};
const evLink = (e) => (e.link ? `<a href="${esc(e.link)}" rel="noopener" target="_blank">${/instagram\.com/.test(e.link) ? 'On Instagram' : (e.when ? 'More' : 'Event page')} <svg class="arw" viewBox="0 0 12 12" aria-hidden="true"><path d="M3 9 9 3M4.5 3H9v4.5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg></a>` : '');
// The event's description folds out under "About this event"; paragraphs keep their line breaks.
const evDesc = (d) => d ? d.split(/\n\s*\n|\n/).map((p) => p.trim()).filter(Boolean).map((p) => `<p>${esc(p)}</p>`).join('') : '';
// "Find a bike": only for events with real dates that are still ahead.
const bikeBtn = (e) => (!e.when && e.start && (e.end || e.start) >= new Date().toISOString().slice(0, 10) && typeof window.ccFindBike === 'function')
  ? `<button type="button" class="ev__bike" data-ev="${esc(JSON.stringify({ id: e.id, name: e.name, start: e.start, end: e.end || e.start }))}">Find a bike</button>` : '';
// Row: [date + Road/Gravel] [name, place] [ride type] [Find a bike]
const whenCol = (dateHtml, e) => `<div class="ev__when">${dateHtml}${surfPill(e)}</div>`;
// The fold-out (about / all dates) opens under the row; its toggle sits next to "Find a bike".
const evTog = (label) => `<button type="button" class="ev__tog" aria-expanded="false">${label}</button>`;
const evRow = (e) => `<li class="ev" data-cats="${esc(catsOf(e).join(' '))}">${whenCol(e.when ? `<span class="mono when">[${esc(e.when)}]</span>` : `<time class="mono" datetime="${esc(e.start)}">[${esc(span(e))}]</time>`, e)}<div class="ev__what"><b>${esc(e.name)}</b><small>${esc(e.where)}</small></div><span class="sts">${kindPills(e)}</span><div class="ev__acts">${bikeBtn(e)}${e.desc ? evTog('About') : evLink(e)}</div>${e.desc ? `<div class="ev__body" hidden><div class="ev__desc">${evDesc(e.desc)}</div>${evLink(e)}</div>` : ''}</li>`;
// A series ("FAR Inverted #1", "#2"…) becomes one row with its dates in a fold-out.
const ARW = '<svg class="arw" viewBox="0 0 12 12" aria-hidden="true"><path d="M3 9 9 3M4.5 3H9v4.5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>';
const dayMon = (d) => `${Number(d.slice(8))} ${MON[+d.slice(5, 7) - 1]}`;
const groupSeries = (list) => {
  const out = [], at = {};
  list.forEach((e) => {
    const m = !e.when && (e.name || '').match(/^(.*?)\s*#\s*\d+/);
    if (!m) { out.push(e); return; }
    const k = m[1].trim();
    if (at[k] === undefined) { at[k] = out.length; out.push({ series: k, items: [e] }); } else { out[at[k]].items.push(e); }
  });
  return out.map((x) => (x.series && x.items.length < 2 ? x.items[0] : x));
};
const seriesRow = (g) => {
  const a = g.items[0], z = g.items[g.items.length - 1], cats = [...new Set(g.items.flatMap(catsOf))];
  const range = `${dayMon(a.start)} → ${dayMon(z.end || z.start)} ${(z.end || z.start).slice(0, 4)}`;
  const dates = g.items.map((e) => `<li><time class="mono" datetime="${esc(e.start)}">${esc(span(e))}</time><span>${esc(e.name.replace(g.series, '').replace(/^[\s·–-]+/, ''))}</span>${bikeBtn(e).replace('class="ev__bike"', 'class="ev__bike ev__bike--sm"')}</li>`).join('');
  const next = g.items.find((e) => bikeBtn(e));
  return `<li class="ev ev--series" data-cats="${esc(cats.join(' '))}">${whenCol(`<time class="mono" datetime="${esc(a.start)}">[${esc(range)}]</time>`, a)}<div class="ev__what"><b>${esc(g.series)}</b><small>${g.items.length} dates · ${esc(a.where)}</small></div><span class="sts">${kindPills(a)}</span><div class="ev__acts">${next ? bikeBtn(next).replace('>Find a bike<', `>Find a bike · ${esc(dayMon(next.start))}<`) : ''}${evTog(`All ${g.items.length} dates`)}</div><div class="ev__body" hidden>${a.desc ? `<div class="ev__desc">${evDesc(a.desc)}</div>` : ''}<ol>${dates}</ol>${a.link ? `<a href="${esc(a.link)}" rel="noopener" target="_blank">${/instagram\.com/.test(a.link) ? 'On Instagram' : 'Event page'} ${ARW}</a>` : ''}</div></li>`;
};
if ($('evList')) {
  const list = $('evList');
  // Soonest first (a series by its first date); events without a fixed date go last.
  const startOf = (x) => (x.series ? x.items[0].start : x.start) || '9999';
  const rows = groupSeries(EVENTS).sort((p, q) => startOf(p).localeCompare(startOf(q)));
  list.innerHTML = EVENTS.length ? rows.map((x) => (x.series ? seriesRow(x) : evRow(x))).join('') : '<li class="ev"><div><b>Nothing on the calendar right now.</b><small>Follow along on Instagram.</small></div></li>';
  // Filter buttons for the categories that actually occur.
  // Two filters that combine: the bike (Road / Gravel) and the kind of event.
  const TIERS = [
    { key: 'surf', label: 'Bike', opts: [['road', 'Road', ['road']], ['gravel', 'Gravel', ['gravel', 'mtb']]] },
    { key: 'type', label: 'Type', opts: [['race', 'Races', ['race']], ['group', 'Group rides', ['group', 'social'], 'Group'], ['overnight', 'Overnight bikepacking', ['overnight', 'bikepacking', 'getaway'], 'Overnight']] },
  ].map((t) => ({ ...t, opts: t.opts.filter(([, , ks]) => EVENTS.some((e) => catsOf(e).some((c) => ks.includes(c)))) }))
   .filter((t) => t.opts.length > 1);
  const pick = { surf: '', type: '' };
  list.addEventListener('click', (ev) => {
    const tog = ev.target.closest('.ev__tog');
    if (tog) {
      const body = tog.closest('.ev').querySelector('.ev__body');
      const show = body.hidden; body.hidden = !show;
      tog.setAttribute('aria-expanded', show ? 'true' : 'false');
      return;
    }
    const btn = ev.target.closest('.ev__bike'); if (!btn) return;
    try { window.ccFindBike(JSON.parse(btn.dataset.ev)); } catch (e) {}
  });
  // Only the next 5 show; the rest fold out under one button.
  const SHOW = 5;
  let open = false;
  const matches = (li) => {
    const cats = (li.dataset.cats || '').split(' ');
    return TIERS.every((t) => { const o = t.opts.find(([k]) => k === pick[t.key]); return !o || cats.some((c) => o[2].includes(c)); });
  };
  const more = document.createElement('button');
  more.type = 'button'; more.className = 'evs__more max'; more.setAttribute('aria-expanded', 'false');
  list.after(more);
  const empty = document.createElement('p');
  empty.className = 'evs__none max'; empty.hidden = true; empty.textContent = 'Nothing on the calendar for that combination yet.';
  list.after(empty);
  const refresh = () => {
    let n = 0;
    list.querySelectorAll('.ev').forEach((li) => {
      const match = matches(li);
      if (match) n++;
      li.hidden = !match || (!open && n > SHOW);
    });
    more.hidden = n <= SHOW;
    if (empty) empty.hidden = n > 0;
    more.textContent = open ? 'Show fewer' : `Show all ${n} events`;
    more.setAttribute('aria-expanded', open ? 'true' : 'false');
  };
  more.addEventListener('click', () => {
    open = !open; refresh();
    if (!open) list.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  });
  refresh();
  if (TIERS.length) {
    const bar = document.createElement('div');
    bar.className = 'evfilter max'; bar.setAttribute('role', 'group'); bar.setAttribute('aria-label', 'Filter events');
    bar.innerHTML = TIERS.map((t) => `<div class="evfilter__row"><span class="evfilter__lbl">${esc(t.label)}</span><div class="chips evchips" data-tier="${t.key}"><button type="button" class="chip on" data-k="" aria-pressed="true">All</button>${t.opts.map(([k, l, , sh]) => `<button type="button" class="chip" data-k="${k}" aria-pressed="false"${sh ? ` aria-label="${esc(l)}"` : ''}>${sh ? `<span class="l-long">${esc(l)}</span><span class="l-short" aria-hidden="true">${esc(sh)}</span>` : esc(l)}</button>`).join('')}</div></div>`).join('');
    list.before(bar);
    bar.addEventListener('click', (ev) => {
      const b = ev.target.closest('button'); if (!b) return;
      const row = b.closest('[data-tier]');
      row.querySelectorAll('button').forEach((x) => { const on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      pick[row.dataset.tier] = b.dataset.k; open = false; refresh();
    });
  }
}

/* ---- numbers ---- */
if (STATS) { setText('sLoans', STATS.loans); setText('sYear', STATS.year); setText('sRiders', STATS.riders); setText('sEvents', STATS.events); }
else { setText('sEvents', EVENTS.length); }

/* ---- who pays? ---- */
const Q = [
  ['The chain is worn out after 600 happy kilometres.', 'us', 'Normal wear. That’s what bikes do, and that’s on us.'],
  ['The rear derailleur snaps when you jump a kerb for a video.', 'you', 'Reckless riding. We get it fixed at Woodstock Cycleworks and share the invoice, no markup.'],
  ['You crash in a sprint at The Gallows and bend the bars.', 'you', 'Damage while racing is yours to cover. That’s the extra line you tick for events.'],
  ['A puncture halfway through the Tankwa.', 'us', 'A puncture on a long day out is normal wear.'],
  ['The bike comes back caked in mud with a dry chain.', 'you', 'You cover a wash & lube, so the next rider gets a bike that’s ready to go.'],
  ['At pickup you spot a cracked bottle cage and post a photo in the chat.', 'us', 'Flagged at the handover, so it isn’t on you. We sort it out.'],
  ['You noticed a scratch at pickup but didn’t mention it.', 'you', 'Whatever isn’t flagged at the handover counts as received in good condition.']
];
if ($('gq')) {
let qi = 0, score = 0, answered = false;
const pips = () => { $('pips').innerHTML = Q.map((_, i) => `<i class="${i < qi + (answered ? 1 : 0) ? 'done' : ''}"></i>`).join(''); };
const ask = () => { answered = false; $('gq').textContent = Q[qi][0]; $('gw').textContent = ''; $('gw').className = 'why'; $('gs').textContent = `${qi + 1} of ${Q.length}`; $('gn').disabled = true; pips(); };
const pick = (who) => {
  if (answered) return;
  answered = true;
  const right = who === Q[qi][1];
  if (right) score++;
  $('gw').className = 'why' + (right ? ' ok' : '');
  $('gw').innerHTML = `<b>${right ? 'Right.' : 'Not quite.'}</b> ${esc(Q[qi][2])}`;
  $('gn').disabled = false;
  $('gn').textContent = qi === Q.length - 1 ? 'Play again' : 'Next';
  if (qi === Q.length - 1) $('gs').textContent = `${score} of ${Q.length} right`;
  pips();
};
$('aUs').addEventListener('click', () => pick('us'));
$('aYou').addEventListener('click', () => pick('you'));
$('gn').addEventListener('click', () => { if (qi === Q.length - 1) { qi = 0; score = 0; } else qi++; ask(); });
ask();
}

/* ---- agreement: open the section a link points to ---- */
const openHash = () => { const t = location.hash && document.querySelector(location.hash); if (t && t.tagName === 'DETAILS') t.open = true; };
addEventListener('hashchange', openHash); openHash();
}
