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

/* ---- the fleet ---- */
if ($('list')) {
const COLORS = { 'CC-03': ['#CFC8BB', 0], 'CC-04': ['#BDB8AE', 0], 'CC-05': ['#4E6F3A', 0], 'CC-06': ['#E8C33A', 0], 'CC-07': ['#EF6AA9', 0], 'CC-08': ['#2A2830', 1], 'CC-09': ['#2F5C45', 0], 'CC-11': ['#B9783A', 0], 'CC-12': ['#D9CBD3', 0], 'CC-13': ['#D9CBD3', 0] };
const BRAND = CFG.brand || '<span class="mono">Standert</span>'; // Our own SVG, from the server. Bike IDs stay internal.
const PHOTOS = { 'CC-12': 'cc-12.webp', 'CC-13': 'cc-13.webp' };
let bikes = FALLBACK.map((b) => ({ ...b })), free = null, filter = 'all';
const fits = (b, h) => b.range && h >= b.range[0] - 2 && h <= b.range[1] + 2;
const label = { available: 'Available', out: 'Out riding', workshop: 'Workshop', booked: 'Booked', reserved: 'Raffle bike' };
const cats = () => [...new Set(bikes.map((b) => b.cat).filter(Boolean))];
const chipsHtml = () => [['all', 'All'], ['fits', 'Fits me'], ...cats().map((c) => [c, c])].map(([k, l]) => `<button class="chip" data-f="${esc(k)}" aria-pressed="${k === filter}">${esc(l)}</button>`).join('');
const pic = (b) => {
  const img = b.image || (PHOTOS[b.cc] ? `${IMG}/${PHOTOS[b.cc]}` : '');
  const c = COLORS[b.cc] || ['#D3C8B4', 0];
  return img ? `<span class="pic"><img src="${esc(img)}" alt="${esc(b.model)} ${esc(b.size)}, ${esc(b.name)}" loading="lazy"></span>`
    : `<span class="pic${c[1] ? ' dark' : ''}" style="background:${c[0]}"><b>${esc(b.size)}</b><span class="hand">photo to come</span></span>`;
};
const render = () => {
  const h = +$('h').value;
  $('hv').textContent = `${h} cm`;
  const sizes = [...new Set(bikes.filter((b) => fits(b, h)).map((b) => b.size))].sort();
  const nFit = bikes.filter((b) => fits(b, h)).length;
  $('fitMsg').textContent = nFit ? `Probably size ${sizes.join(' or ')}: ${nFit} bike${nFit === 1 ? '' : 's'} fit${nFit === 1 ? 's' : ''}.` : 'Nothing in the fleet fits that height yet. Message us anyway.';
  $('chips').innerHTML = chipsHtml();
  const show = (b) => filter === 'all' || (filter === 'fits' ? fits(b, h) : b.cat === filter);
  const list = [...bikes].sort((a, b) => a.cc.localeCompare(b.cc));
  $('list').innerHTML = list.map((b) => {
    const st = free ? (free.has(b.cc) ? 'available' : 'booked') : b.state;
    const chip = free ? (free.has(b.cc) ? 'Free those dates' : 'Booked') : (label[st] || st);
    const ok = fits(b, h);
    return `<li${show(b) ? '' : ' hidden'}><a class="card${free && !free.has(b.cc) ? ' dim' : ''}" href="${esc(b.url)}"><span class="hd"><span class="brand">${BRAND}</span><span class="st ${esc(st)}">${esc(chip)}</span></span>${pic(b)}<strong>${esc(b.model)} ${esc(b.size)}</strong><small>${esc(b.name)}</small><span class="fit">${b.range ? `${b.range[0]}–${b.range[1]} cm${ok ? ' · <b>fits you</b>' : ''}` : ''}</span></a></li>`;
  }).join('');
  const shown = list.filter(show).length;
  if (!free) $('msg').textContent = `${shown} bike${shown === 1 ? '' : 's'}` + (LIVE ? '' : ' · preview, fleet as of 21 Nov 2026');
  setText('nBikes', bikes.length);
};
$('chips').addEventListener('click', (e) => { const b = e.target.closest('[data-f]'); if (!b) return; filter = b.dataset.f; render(); });
$('h').addEventListener('input', render);
try { const saved = localStorage.getItem('cc-height'); if (saved) $('h').value = saved; } catch (e) {}
$('h').addEventListener('change', () => { try { localStorage.setItem('cc-height', $('h').value); } catch (e) {} });
const pad = (n) => String(n).padStart(2, '0');
const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1);
$('d1').min = $('d2').min = iso(tomorrow);
$('d1').max = $('d2').max = HORIZON;
$('dateForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const a = $('d1').value, b = $('d2').value;
  if (!a || !b || b < a) { $('msg').textContent = 'Pick a first and a last day, in that order.'; return; }
  if (b > HORIZON) { $('msg').textContent = `We take requests up to ${nice(HORIZON)}.`; return; }
  if (!API) { $('msg').textContent = 'The live date check works on community-connect.cc. Open a bike to see its calendar.'; return; }
  $('msg').textContent = 'Checking…';
  try {
    const r = await fetch(`${API}/available?start=${a}&end=${b}`, { headers: { Accept: 'application/json' } });
    const j = await r.json();
    if (!r.ok) throw new Error(j.message || 'Those dates don’t work.');
    free = new Set(j.map((x) => x.cc_id));
    render();
    $('msg').textContent = free.size ? `${free.size} bike${free.size === 1 ? '' : 's'} free ${nice(a)} – ${nice(b)}.` : 'Nothing free on those dates. Try shifting by a few days.';
  } catch (e) { $('msg').textContent = e.message; }
});
render();
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
const MAIN = ['race', 'social', 'overnight', 'bikepacking', 'getaway'];
const labelsOf = (e) => e.labels || (e.type ? [/ride|getaway/i.test(e.type) ? 'Ride' : 'Race'] : []);
const catsOf = (e) => e.cats || [];
const pills = (e) => {
  const l = labelsOf(e), c = catsOf(e);
  let idx = c.map((k, i) => (MAIN.includes(k) ? i : -1)).filter((i) => i >= 0);
  if (!idx.length) idx = l.length ? [0] : [];
  return idx.slice(0, 2).map((i) => `<span class="st${c[i] === 'race' ? ' race' : ''}">${esc(l[i])}</span>`).join('');
};
const evRow = (e) => `<li class="ev" data-cats="${esc(catsOf(e).join(' '))}"><time class="mono" datetime="${esc(e.start)}">[${esc(span(e))}]</time><div><b>${esc(e.name)}</b><small>${esc(e.where)}</small></div><span class="sts">${pills(e)}</span>${e.link ? `<a href="${esc(e.link)}" rel="noopener" target="_blank">Event page <svg class="arw" viewBox="0 0 12 12" aria-hidden="true"><path d="M3 9 9 3M4.5 3H9v4.5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg></a>` : ''}</li>`;
if ($('evList')) {
  const list = $('evList');
  list.innerHTML = EVENTS.length ? EVENTS.map(evRow).join('') : '<li class="ev"><div><b>Nothing on the calendar right now.</b><small>Follow along on Instagram.</small></div></li>';
  // Filter buttons for the categories that actually occur.
  const FILTERS = [['all', 'All'], ['race', 'Races'], ['social', 'Social rides'], ['overnight', 'Overnight bikepacking'], ['bikepacking', 'Bikepacking trips'], ['getaway', 'Getaways']]
    .filter(([k]) => k === 'all' || EVENTS.some((e) => catsOf(e).includes(k)));
  if (FILTERS.length > 2) {
    const bar = document.createElement('div');
    bar.className = 'chips evchips max'; bar.setAttribute('role', 'group'); bar.setAttribute('aria-label', 'Filter events');
    bar.innerHTML = FILTERS.map(([k, l], i) => `<button type="button" class="chip${i ? '' : ' on'}" data-k="${k}" aria-pressed="${i ? 'false' : 'true'}">${esc(l)}</button>`).join('');
    list.before(bar);
    bar.addEventListener('click', (ev) => {
      const b = ev.target.closest('button'); if (!b) return;
      bar.querySelectorAll('button').forEach((x) => { const on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      list.querySelectorAll('.ev').forEach((li) => { li.hidden = b.dataset.k !== 'all' && !(li.dataset.cats || '').split(' ').includes(b.dataset.k); });
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
