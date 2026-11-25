/* My rides: change dates, cancel, email-me-my-link. */
(function () {
	'use strict';
	var C = window.CCMe || {};
	if (!C.api) return;
	function post(path, body) {
		return fetch(C.api.replace(/\/$/, '') + path, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
			body: JSON.stringify(body || {})
		}).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); });
	}
	function say(el, text, bad) { el.textContent = text; el.hidden = false; el.classList.toggle('is-bad', !!bad); }

	// Booking cards: open a form, send it.
	document.querySelectorAll('.cc-me__card').forEach(function (card) {
		var id = card.getAttribute('data-loan'), msg = card.querySelector('.cc-me__msg');
		card.querySelectorAll('[data-act]').forEach(function (b) {
			b.addEventListener('click', function () {
				var f = card.querySelector('[data-form="' + b.getAttribute('data-act') + '"]');
				card.querySelectorAll('.cc-me__form').forEach(function (x) { if (x !== f) x.hidden = true; });
				f.hidden = !f.hidden;
				if (!f.hidden) { var first = f.querySelector('input,textarea'); if (first) first.focus(); }
			});
		});
		card.querySelectorAll('.cc-me__form').forEach(function (f) {
			var dates = f.querySelectorAll('input[type=date]');
			dates.forEach(function (i) { i.min = C.today; if (C.horizon) i.max = C.horizon; });
			f.addEventListener('submit', function (ev) {
				ev.preventDefault();
				var kind = f.getAttribute('data-form'), data = {};
				new FormData(f).forEach(function (v, k) { data[k] = v; });
				if (kind === 'change' && data.end < data.start) { say(msg, 'The end date is before the start.', true); return; }
				var btn = f.querySelector('button[type=submit]'); btn.disabled = true;
				post('/me/loans/' + id + '/' + kind, data).then(function (res) {
					btn.disabled = false;
					say(msg, (res.body && res.body.message) || 'Something went wrong. Please WhatsApp the crew.', !res.ok);
					if (res.ok) {
						f.hidden = true;
						if (res.body.status === 'cancelled') { card.classList.add('is-cancelled'); card.querySelectorAll('.cc-me__acts').forEach(function (a) { a.hidden = true; }); }
					}
				}).catch(function () { btn.disabled = false; say(msg, 'No connection. Try again in a moment.', true); });
			});
		});
	});

	// Signed out: email me my link.
	var link = document.querySelector('[data-cc-link]');
	if (link) {
		var m = link.querySelector('.cc-me__msg');
		link.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var btn = link.querySelector('button'); btn.disabled = true;
			post('/me/link', { email: link.querySelector('input[name=email]').value }).then(function (res) {
				btn.disabled = false; say(m, res.body && res.body.message || 'Please try again.', !res.ok);
			}).catch(function () { btn.disabled = false; say(m, 'No connection. Try again in a moment.', true); });
		});
	}

	// Signing out also forgets the details remembered for the request form.
	var out = document.querySelector('[data-cc-forget]');
	if (out) out.addEventListener('click', function () { try { localStorage.removeItem('cc_rider'); } catch (e) {} });
})();
