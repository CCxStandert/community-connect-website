/* Cookie notice + Google Analytics 4 in Consent Mode v2 ("basic"): Google's script loads only after "Accept".
   The choice is kept in localStorage and a first-party cookie (cc_consent) for a year; "Cookie preferences" reopens it. */
(function () {
	'use strict';
	var CFG = window.CCConsent || {};
	var KEY = 'cc_consent', YEAR = 365 * 24 * 3600 * 1000;
	var bar = document.querySelector('[data-cc-consent]');
	var loaded = false, queue = [];

	function read() {
		try {
			var s = JSON.parse(localStorage.getItem(KEY) || 'null');
			if (s && s.v && Date.now() - (s.t || 0) < YEAR) return s.v;
		} catch (e) { /* storage blocked */ }
		var m = document.cookie.match(/(?:^|; )cc_consent=(all|essential)/);
		return m ? m[1] : '';
	}
	function save(v) {
		try { localStorage.setItem(KEY, JSON.stringify({ v: v, t: Date.now() })); } catch (e) { /* ignore */ }
		document.cookie = 'cc_consent=' + v + ';max-age=' + (365 * 24 * 3600) + ';path=/;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
	}
	function show() { if (bar) { bar.hidden = false; var b = bar.querySelector('.cc-consent__yes'); if (b) b.focus({ preventScroll: true }); } }
	function hide() { if (bar) bar.hidden = true; }

	function send(name, params) { window.gtag('event', name, params || {}); }
	function track(name, params) { if (loaded) send(name, params); else queue.push([name, params]); }
	window.ccTrack = track;

	function loadGA() {
		if (loaded || !CFG.id) return;
		loaded = true;
		window.gtag('consent', 'update', { analytics_storage: 'granted' });
		var s = document.createElement('script');
		s.async = true;
		s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(CFG.id);
		document.head.appendChild(s);
		window.gtag('js', new Date());
		window.gtag('config', CFG.id, { allow_google_signals: false, allow_ad_personalization_signals: false });
		(window.CCEvents || []).forEach(function (e) { send(e[0], e[1]); });
		queue.splice(0).forEach(function (e) { send(e[0], e[1]); });
	}
	function forgetGA() {
		window.gtag('consent', 'update', { analytics_storage: 'denied' });
		// Remove Google Analytics cookies we may have set before.
		document.cookie.split('; ').forEach(function (c) {
			var n = c.split('=')[0];
			if (/^_ga/.test(n)) {
				var host = location.hostname.replace(/^www\./, '');
				['', '.' + host, host].forEach(function (d) { document.cookie = n + '=;max-age=0;path=/' + (d ? ';domain=' + d : ''); });
			}
		});
	}

	function choose(v) {
		save(v);
		hide();
		if (v === 'all') loadGA();
		else if (loaded) { forgetGA(); }
	}

	document.addEventListener('click', function (e) {
		var c = e.target.closest('[data-cc-consent-choice]');
		if (c) { choose(c.getAttribute('data-cc-consent-choice')); return; }
		if (e.target.closest('[data-cc-consent-open]')) { e.preventDefault(); show(); return; }
		// outbound_click: links that leave community-connect.cc (Instagram, Strava, Standert…).
		var a = e.target.closest('a[href]');
		if (a && /^https?:/i.test(a.href) && a.hostname && a.hostname !== location.hostname) {
			track('outbound_click', { link_url: a.href, link_domain: a.hostname.replace(/^www\./, ''), link_text: (a.textContent || '').trim().slice(0, 80) });
		}
	});

	// Shop: something went into the cart (shop.js).
	document.addEventListener('cc:added', function (e) {
		var f = e.detail && e.detail.form;
		if (!f) return;
		var name = (document.querySelector('.cc-pdp__name b') || {}).textContent || '';
		var amount = f.querySelector('[data-cc-amount]');
		var view = (window.CCEvents || []).filter(function (x) { return x[0] === 'view_item'; })[0];
		var item = view ? Object.assign({}, view[1].items[0]) : { item_name: name };
		var value = amount ? parseInt(amount.value, 10) || 0 : (item.price || 0);
		item.price = value;
		var size = f.querySelector('input[name^="attribute_"]:checked');
		if (size) item.item_variant = size.getAttribute('data-name') || size.value;
		track('add_to_cart', { currency: 'ZAR', value: value, items: [item] });
		if (e.detail.sponsor) track('sponsorship_selected', { fund: name.trim(), value: value, currency: 'ZAR' });
	});

	// Bike requests and the waiting list (CC Fleet plugin's request form).
	document.addEventListener('cc:newsletter', function () {
		track('sign_up', { method: 'newsletter' });
	});
	document.addEventListener('cc:bike_request', function (e) {
		var d = e.detail || {};
		track(d.waitlist ? 'waitlist_join' : 'bike_request', { bike: d.bike || 'any', event_related: !!d.event });
	});

	var v = read();
	if (v === 'all') loadGA();
	else if (!v) show();
})();
