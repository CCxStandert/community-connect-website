/* CC Fleet front end: live availability calendar, date picking, request form. No dependencies. */
(function () {
	'use strict';
	var C = window.CCFleet || {};
	(function () { // Fallback: read config from the markup.
		var n = document.querySelector('[data-cc-api]');
		if (n) { C.api = C.api || n.getAttribute('data-cc-api'); C.today = C.today || n.getAttribute('data-cc-today'); C.latest = C.latest || n.getAttribute('data-cc-latest'); }
	})();
	var MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
	var DAYS = ['Mo','Tu','We','Th','Fr','Sa','Su'];

	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
	function parse(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
	function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }

	function api(path, opts) {
		return fetch(C.api.replace(/\/$/, '') + path, Object.assign({ headers: { 'Accept': 'application/json' }, credentials: 'omit' }, opts || {}))
			.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; }); });
	}

	/* ---------- Calendar ---------- */
	function Calendar(root) {
		this.root = root;
		this.bike = root.getAttribute('data-bike');
		this.form = root.closest('form');
		this.booked = {};
		this.view = parse(C.today); this.view.setDate(1);
		this.start = null; this.end = null;
		this.load();
	}
	Calendar.prototype.load = function () {
		var self = this;
		// One call for everything bookable: today to the end of the booking window (C.latest, 31 Dec next year).
		var to = C.latest ? parse(C.latest) : new Date(this.view.getFullYear(), this.view.getMonth() + 14, 0);
		api('/bikes/' + encodeURIComponent(this.bike) + '/availability?from=' + C.today + '&to=' + iso(to)).then(function (res) {
			if (!res.ok) { self.root.textContent = 'Availability could not be loaded.'; return; }
			self.data = res.body; self.booked = {}; self.cover = {}; self.ends = {}; self.starts = {};
			res.body.booked.forEach(function (b) {
				for (var d = parse(b.start); iso(d) <= b.end; d.setDate(d.getDate() + 1)) {
					var k = iso(d); self.booked[k] = b.kind; self.cover[k] = (self.cover[k] || 0) + 1;
				}
				self.ends[b.end] = (self.ends[b.end] || 0) + 1;
				self.starts[b.start] = (self.starts[b.start] || 0) + 1;
			});
			self.render();
		}).catch(function () { self.root.textContent = 'Availability could not be loaded.'; });
	};
	Calendar.prototype.isFree = function (d) { return !this.booked[d] && d >= this.data.earliest && d <= this.data.latest; };
	Calendar.prototype.rangeFree = function (a, b) {
		for (var d = parse(a); iso(d) <= b; d.setDate(d.getDate() + 1)) {
			var k = iso(d);
			if (this.booked[k]) {
				// Same-day handoff: you may collect on the day every other loan ends,
				// or return on the day every other loan starts.
				var h = this.data.same_day_handoff && a !== b;
				if (h && k === a && this.ends[k] === this.cover[k] && !this.starts[k]) continue;
				if (h && k === b && this.starts[k] === this.cover[k] && !this.ends[k]) continue;
				return false;
			}
		}
		return true;
	};
	Calendar.prototype.render = function () {
		var self = this, root = this.root;
		root.textContent = '';
		var head = el('div', 'cc-cal__head');
		var prev = el('button', 'cc-cal__nav', '‹'); prev.type = 'button'; prev.setAttribute('aria-label', 'Previous month');
		var next = el('button', 'cc-cal__nav', '›'); next.type = 'button'; next.setAttribute('aria-label', 'Next month');
		var title = el('p', 'cc-cal__title', MONTHS[this.view.getMonth()] + ' ' + this.view.getFullYear());
		var first = parse(C.today); first.setDate(1);
		prev.disabled = this.view <= first;
		var last = parse(this.data.latest || C.today); last.setDate(1);
		next.disabled = this.view >= last;
		prev.onclick = function () { self.view.setMonth(self.view.getMonth() - 1); self.render(); };
		next.onclick = function () { self.view.setMonth(self.view.getMonth() + 1); self.render(); };
		head.append(prev, title, next);
		var grid = el('div', 'cc-cal__grid'); grid.setAttribute('role', 'grid');
		DAYS.forEach(function (d) { grid.append(el('span', 'cc-cal__dow', d)); });
		var d = new Date(this.view), offset = (d.getDay() + 6) % 7;
		for (var i = 0; i < offset; i++) grid.append(el('span', 'cc-cal__pad'));
		while (d.getMonth() === this.view.getMonth()) {
			var k = iso(d), b = el('button', 'cc-cal__day', String(d.getDate()));
			b.type = 'button'; b.dataset.date = k;
			var taken = !!this.booked[k], past = k < this.data.earliest || (this.data.latest && k > this.data.latest);
			if (taken) b.classList.add(this.booked[k] === 'reserved' ? 'is-reserved' : 'is-booked');
			if (past) b.classList.add('is-past');
			if (this.start && k === this.start) b.classList.add('is-start');
			if (this.end && k === this.end) b.classList.add('is-end');
			if (this.start && this.end && k > this.start && k < this.end) b.classList.add('is-range');
			b.disabled = past || (taken && !this.data.same_day_handoff);
			b.setAttribute('aria-label', k + (taken ? (this.booked[k] === 'reserved' ? ' reserved for an event raffle' : ' booked') : past ? ' unavailable' : ' free'));
			b.onclick = (function (key) { return function () { self.pick(key); }; })(k);
			grid.append(b);
			d.setDate(d.getDate() + 1);
		}
		var legend = el('p', 'cc-cal__legend');
		legend.innerHTML = '<span class="cc-cal__key is-booked"></span> Booked <span class="cc-cal__key is-reserved"></span> Raffle bike <span class="cc-cal__key is-start"></span> Your dates';
		var hint = el('p', 'cc-cal__hint', this.start && !this.end ? 'Now tap your last day.' : 'Tap your first day, then your last day.');
		root.append(head, grid, legend, hint);
	};
	Calendar.prototype.pick = function (k) {
		if (!this.start || this.end || k < this.start) { this.start = k; this.end = null; }
		else {
			var days = (parse(k) - parse(this.start)) / 864e5 + 1;
			if (!this.rangeFree(this.start, k)) { this.flash('Those dates cross a booking. Pick another range.'); this.start = k; this.end = null; }
			else if (days > this.data.max_days) { this.flash('Loans are limited to ' + this.data.max_days + ' days.'); }
			else this.end = k;
		}
		if (this.form) {
			var s = this.form.querySelector('[data-cc-start]'), e = this.form.querySelector('[data-cc-end]');
			if (s) s.value = this.start || ''; if (e) e.value = this.end || '';
			this.form.dispatchEvent(new CustomEvent('cc:dates'));
		}
		this.render();
	};
	Calendar.prototype.flash = function (msg) {
		var err = this.form && this.form.querySelector('[data-cc-error="dates"]');
		if (err) { err.textContent = msg; err.hidden = false; setTimeout(function () { err.hidden = true; }, 4000); }
	};

	/* ---------- Request form ---------- */
	function RequestForm(form) {
		var t = form.querySelector('[data-cc-t]'); if (t) t.value = Math.floor(Date.now() / 1000);
		// Longer than the usual loan: allowed, but ask for a reason in the plan.
		var usual = parseInt(form.getAttribute('data-cc-usual') || '7', 10), long = form.querySelector('[data-cc-long]');
		var checkLong = function () {
			var s = form.querySelector('[data-cc-start]'), e = form.querySelector('[data-cc-end]');
			if (!long || !s || !e || !s.value || !e.value) { if (long) long.hidden = true; return; }
			var n = Math.round((parse(e.value) - parse(s.value)) / 864e5) + 1;
			long.hidden = !(n > usual);
			if (n > usual) long.textContent = 'That’s ' + n + ' days, longer than our usual ' + usual + '. That can work: tell us why in your plan below, so we can keep the bike moving for others too.';
		};
		['[data-cc-start]', '[data-cc-end]'].forEach(function (q) { var i = form.querySelector(q); if (i) { i.addEventListener('change', checkLong); i.addEventListener('input', checkLong); } });
		form.addEventListener('cc:dates', checkLong);
		var min = C.today; ['[data-cc-start]', '[data-cc-end]'].forEach(function (s) { var i = form.querySelector(s); if (i) { i.min = min; if (C.latest) i.max = C.latest; } });
		// Riding an event: show the racing terms, and suggest dates around the event if none are picked yet.
		var ev = form.querySelector('[data-cc-event]'), race = form.querySelector('[data-cc-race]');
		if (ev && race) {
			var sync = function () {
				var sel = ev.options[ev.selectedIndex];
				var on = !!ev.value && !!sel && sel.getAttribute('data-race') !== '0'; race.hidden = !on;
				var box = race.querySelector('input[name=race_agree]'); if (box) box.required = on;
				var o = ev.options[ev.selectedIndex];
				var s = form.querySelector('[data-cc-start]'), e = form.querySelector('[data-cc-end]');
				if (ev.value && o && o.dataset.start && s && e && !s.value && !e.value) {
					var a = parse(o.dataset.start); a.setDate(a.getDate() - 2);
					var b = parse(o.dataset.end); b.setDate(b.getDate() + 1);
					s.value = iso(a) < (s.min || '') ? s.min : iso(a); e.value = iso(b);
				}
			};
			ev.addEventListener('change', sync); sync();
		}
		form.addEventListener('change', function (e) {
			var hid = form.querySelector('input[name=waitlist]');
			if (hid && e.target && e.target !== hid && /start|end|bike|size/.test(e.target.name || '')) hid.value = '';
		});
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			form.querySelectorAll('[data-cc-error]').forEach(function (e) { e.hidden = true; });
			var data = {};
			new FormData(form).forEach(function (v, k) { data[k] = v; });
			var btn = form.querySelector('button[type=submit]');
			btn.disabled = true; btn.dataset.label = btn.dataset.label || btn.textContent; btn.textContent = 'Sending…';
			api('/requests', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(data) })
				.then(function (res) {
					if (res.ok) {
						var ok = form.querySelector('[data-cc-success]');
						ok.textContent = res.body.message || 'Request sent.'; ok.hidden = false;
						if (res.body.whatsapp && /^https:\/\/wa\.me\//.test(res.body.whatsapp)) {
							var wa = document.createElement('a');
							wa.className = 'cc-wa'; wa.href = res.body.whatsapp; wa.target = '_blank'; wa.rel = 'noopener';
							wa.textContent = 'Say hi on WhatsApp';
							ok.appendChild(document.createElement('br')); ok.appendChild(wa);
						}
						Array.prototype.forEach.call(form.elements, function (e) { if (e !== ok) e.disabled = true; });
						btn.textContent = 'Sent ✓';
						ok.scrollIntoView({ behavior: 'smooth', block: 'center' });
						return;
					}
					var fields = (res.body.data && res.body.data.fields) || {};
					var shown = false;
					Object.keys(fields).forEach(function (k) {
						var e = form.querySelector('[data-cc-error="' + k + '"]') || form.querySelector('[data-cc-error="form"]');
						e.textContent = fields[k]; e.hidden = false; shown = true;
					});
					if (!shown) {
						var f = form.querySelector('[data-cc-error="form"]');
						var alt = res.body.data && res.body.data.alternatives;
						f.textContent = res.body.message + (alt && alt.length ? ' Free in the same size: ' + alt.join(', ') + '.' : '');
						f.hidden = false;
						// Booked: offer the waiting list for the same dates (0.14).
						if (res.body.data && res.body.data.waitlist) {
							var w = document.createElement('button');
							w.type = 'button'; w.className = 'cc-waitlist-btn';
							w.textContent = 'Join the waiting list for these dates';
							w.addEventListener('click', function () {
								var hid = form.querySelector('input[name=waitlist]');
								if (!hid) { hid = document.createElement('input'); hid.type = 'hidden'; hid.name = 'waitlist'; form.appendChild(hid); }
								hid.value = '1';
								if (form.requestSubmit) form.requestSubmit(); else btn.click();
							});
							var p = document.createElement('span'); p.className = 'cc-waitlist-note';
							p.textContent = ' If someone backs out, we go down the list and email you.';
							f.appendChild(document.createElement('br')); f.appendChild(w); f.appendChild(p);
						}
					}
					btn.disabled = false; btn.textContent = btn.dataset.label;
				})
				.catch(function () {
					var f = form.querySelector('[data-cc-error="form"]');
					f.textContent = 'Network problem — please try again.'; f.hidden = false;
					btn.disabled = false; btn.textContent = btn.dataset.label;
				});
		});
	}

	/* ---------- Live status on fleet grids (pages may be cached) ---------- */
	function refreshStates(root) {
		api('/bikes').then(function (res) {
			if (!res.ok) return;
			var labels = { available: 'Available', out: 'Out riding', workshop: 'Workshop', retired: 'Retired' };
			res.body.forEach(function (b) {
				var card = root.querySelector('[data-bike="' + b.id + '"] [data-cc-state]');
				if (card) { card.className = 'cc-state cc-state--' + b.state; card.textContent = labels[b.state] || b.state; }
			});
		});
	}

	function boot() {
		if (!C.api) return;
		document.querySelectorAll('[data-cc-calendar]').forEach(function (r) { new Calendar(r); });
		document.querySelectorAll('[data-cc-request]').forEach(function (f) { RequestForm(f); });
		document.querySelectorAll('[data-cc-fleet]').forEach(refreshStates);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();

/* Bike page photo slider + sticky request bar (0.8.1) */
(function () {
	document.querySelectorAll('[data-cc-gal]').forEach(function (g) {
		var track = g.querySelector('.cc-gal__track'), dots = g.querySelectorAll('.cc-gal__dots button');
		if (!track || !dots.length) return;
		dots.forEach(function (d, i) { d.addEventListener('click', function () { track.scrollTo({ left: i * track.clientWidth, behavior: 'smooth' }); }); });
		var t; track.addEventListener('scroll', function () { clearTimeout(t); t = setTimeout(function () {
			var i = Math.round(track.scrollLeft / track.clientWidth);
			dots.forEach(function (d, j) { d.setAttribute('aria-current', i === j ? 'true' : 'false'); });
		}, 60); }, { passive: true });
	});
	var bar = document.querySelector('[data-cc-sticky]'), form = document.getElementById('request');
	if (bar && form && 'IntersectionObserver' in window) {
		var area = form.nextElementSibling || form;
		new IntersectionObserver(function (es) { es.forEach(function (e) { bar.classList.toggle('is-away', e.isIntersecting); }); }, { rootMargin: '0px 0px -30% 0px' }).observe(area);
	}
})();
