/* Community Connect shop: size/colour pills, sponsorship amounts, the info sheet, the gallery and the cart drawer.
   Everything works without it (normal WooCommerce forms); this makes it instant. */
(function () {
	'use strict';
	var C = window.CCShop || {};
	var url = function (ep) { return (C.ajax || '/?wc-ajax=%%endpoint%%').replace('%%endpoint%%', ep); };
	var post = function (ep, data) {
		var body = data instanceof FormData ? data : (function () { var f = new FormData(); Object.keys(data || {}).forEach(function (k) { f.append(k, data[k]); }); return f; })();
		return fetch(url(ep), { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); });
	};
	var $ = function (s, r) { return (r || document).querySelector(s); };
	var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

	/* ---------------- Cart drawer ---------------- */
	var cart = $('[data-cc-cart]');
	var lastFocus = null;
	function setCount(n) {
		$$('[data-cc-cart-count]').forEach(function (el) { el.textContent = n; el.hidden = !n; });
		$$('[data-cc-cart-open]').forEach(function (b) { b.setAttribute('aria-label', 'Cart, ' + n + ' item' + (n === 1 ? '' : 's')); });
	}
	function render(state) {
		if (!state) return;
		setCount(state.count || 0);
		var body = $('[data-cc-cart-body]');
		if (body && typeof state.html === 'string') body.innerHTML = state.html;
	}
	function openCart() {
		if (!cart) { location.href = C.checkout ? C.checkout.replace(/checkout\/?$/, 'cart/') : '/cart/'; return; }
		lastFocus = document.activeElement;
		cart.hidden = false;
		document.documentElement.classList.add('cc-locked');
		requestAnimationFrame(function () { cart.classList.add('is-open'); var x = $('.cc-x', cart); if (x) x.focus(); });
	}
	function closeCart() {
		if (!cart || cart.hidden) return;
		cart.classList.remove('is-open');
		document.documentElement.classList.remove('cc-locked');
		setTimeout(function () { cart.hidden = true; }, 280);
		if (lastFocus && lastFocus.focus) lastFocus.focus();
	}
	document.addEventListener('click', function (e) {
		if (e.target.closest('[data-cc-cart-open]')) { e.preventDefault(); openCart(); return; }
		if (e.target.closest('[data-cc-cart-close]')) { e.preventDefault(); closeCart(); return; }
		var q = e.target.closest('[data-cc-qty]');
		if (q && cart && cart.contains(q)) {
			var item = q.closest('[data-key]'); var body = $('[data-cc-cart-body]');
			body.classList.add('is-busy');
			post('cc_qty', { key: item.getAttribute('data-key'), qty: q.getAttribute('data-cc-qty') })
				.then(render).finally(function () { body.classList.remove('is-busy'); });
		}
	});
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') return;
		closeCart();
		closeSheet();
	});

	/* ---------------- Product gallery ---------------- */
	$$('[data-cc-gallery]').forEach(function (g) {
		var track = $('.cc-pdp__track', g), dots = $$('.cc-pdp__dots button', g);
		if (!track || !dots.length) return;
		dots.forEach(function (d, i) { d.addEventListener('click', function () { track.scrollTo({ left: i * track.clientWidth, behavior: 'smooth' }); }); });
		var t; track.addEventListener('scroll', function () { clearTimeout(t); t = setTimeout(function () {
			var i = Math.round(track.scrollLeft / track.clientWidth);
			dots.forEach(function (d, j) { d.setAttribute('aria-current', i === j ? 'true' : 'false'); });
		}, 60); }, { passive: true });
	});

	/* ---------------- Info sheet (shipping / size / details) ---------------- */
	var sheet = $('[data-cc-sheet-panel]');
	function showTab(name) {
		if (!sheet) return;
		var tabs = $$('[data-tab]', sheet);
		if (!tabs.some(function (t) { return t.getAttribute('data-tab') === name; })) name = tabs.length ? tabs[0].getAttribute('data-tab') : '';
		tabs.forEach(function (t) { t.setAttribute('aria-selected', t.getAttribute('data-tab') === name ? 'true' : 'false'); });
		$$('[data-panel]', sheet).forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
	}
	function openSheet(name) {
		if (!sheet) return;
		lastFocus = document.activeElement;
		showTab(name);
		sheet.hidden = false;
		document.documentElement.classList.add('cc-locked');
		var x = $('[data-cc-sheet-close]', sheet); if (x) x.focus();
	}
	function closeSheet() {
		if (!sheet || sheet.hidden) return;
		sheet.hidden = true;
		document.documentElement.classList.remove('cc-locked');
		if (lastFocus && lastFocus.focus) lastFocus.focus();
	}
	document.addEventListener('click', function (e) {
		var o = e.target.closest('[data-cc-sheet]');
		if (o) { e.preventDefault(); openSheet(o.getAttribute('data-cc-sheet')); return; }
		if (e.target.closest('[data-cc-sheet-close]') || e.target === sheet) { closeSheet(); return; }
		var t = e.target.closest('[data-tab]');
		if (t && sheet && sheet.contains(t)) showTab(t.getAttribute('data-tab'));
	});

	/* ---------------- Buy form: variations, amounts, add to cart ---------------- */
	$$('[data-cc-buy]').forEach(function (form) {
		var vars = []; try { vars = JSON.parse(form.getAttribute('data-variations') || '[]'); } catch (e) { vars = []; }
		var groups = $$('[data-cc-attr]', form);
		var btn = $('[data-cc-buy-btn]', form), msg = $('[data-cc-msg]', form), vid = $('[data-cc-variation]', form);
		var price = $('[data-cc-price]'), mainImg = $('[data-cc-main]');
		var baseImg = mainImg ? { src: mainImg.getAttribute('src'), srcset: mainImg.getAttribute('srcset') || '' } : null;
		var label = btn ? btn.getAttribute('data-label') : 'Add to cart';
		var sponsor = form.hasAttribute('data-sponsor');

		function chosen() {
			var c = {};
			groups.forEach(function (g) { var i = $('input:checked', g); c[g.getAttribute('data-cc-attr')] = i ? i.value : ''; });
			return c;
		}
		function matches(v, c, skip) {
			return Object.keys(c).every(function (k) { return k === skip || !c[k] || !v.attrs[k] || v.attrs[k] === c[k]; });
		}
		function update() {
			if (!vars.length) return;
			var c = chosen();
			// Grey out options with nothing in stock, given the other choices.
			groups.forEach(function (g) {
				var key = g.getAttribute('data-cc-attr');
				$$('input', g).forEach(function (i) {
					var test = Object.assign({}, c); test[key] = i.value;
					var ok = vars.some(function (v) { return v.stock && v.buy && matches(v, test); });
					i.disabled = !ok; i.closest('.cc-opt').classList.toggle('is-gone', !ok);
				});
				var sel = $('input:checked', g), out = $('[data-cc-chosen]', g);
				if (out) out.textContent = sel ? '· ' + (sel.getAttribute('data-name') || sel.value) : '';
			});
			var complete = groups.every(function (g) { return !!$('input:checked', g); });
			var match = complete ? vars.filter(function (v) { return matches(v, c); })[0] : null;
			if (vid) vid.value = match && match.stock ? match.id : '';
			if (btn) {
				btn.disabled = !(match && match.stock && match.buy);
				btn.textContent = !complete ? 'Choose a ' + (groups[0] ? ($('legend', groups[0]).firstChild.textContent.trim().toLowerCase() || 'size') : 'size') : (match && match.stock ? label : 'Sold out');
			}
			if (price && match && match.price) price.textContent = match.price;
			if (mainImg) {
				var img = match && match.img && match.img.src ? match.img : baseImg;
				if (img && mainImg.getAttribute('src') !== img.src) { mainImg.setAttribute('src', img.src); if (img.srcset) mainImg.setAttribute('srcset', img.srcset); else mainImg.removeAttribute('srcset'); }
			}
		}
		groups.forEach(function (g) { g.addEventListener('change', update); });
		update();

		// Sponsorship amounts: the pills fill the field; typing clears the pills unless it matches one.
		var amount = $('[data-cc-amount]', form);
		if (amount) {
			form.addEventListener('change', function (e) {
				if (e.target.name === 'cc_preset') { amount.value = e.target.value; }
			});
			amount.addEventListener('input', function () {
				$$('input[name=cc_preset]', form).forEach(function (r) { r.checked = r.value === String(parseInt(amount.value, 10)); });
				if (msg) { msg.textContent = ''; msg.classList.remove('is-error'); }
			});
		}

		form.addEventListener('submit', function (e) {
			if (!window.fetch || !window.FormData) return; // Plain form post.
			e.preventDefault();
			if (amount) {
				var n = parseInt(amount.value, 10), min = C.minAmount || 50;
				if (!n || n < min) { msg.textContent = 'The smallest amount is ' + (C.currency || 'R') + min + '.'; msg.classList.add('is-error'); amount.focus(); return; }
			}
			var data = new FormData(form);
			data.delete('add-to-cart'); // Our endpoint adds it; WooCommerce must not add it a second time.
			btn.classList.add('is-busy'); btn.disabled = true;
			msg.textContent = ''; msg.classList.remove('is-error');
			post('cc_add', data).then(function (res) {
				render(res);
				if (res.ok) {
					msg.textContent = sponsor ? 'Thank you! It’s in your cart.' : 'Added to your cart.';
					openCart();
					document.dispatchEvent(new CustomEvent('cc:added', { detail: { form: form, sponsor: sponsor } }));
				} else {
					msg.textContent = res.message || 'Sorry, that didn’t work. Please try again.';
					msg.classList.add('is-error');
				}
			}).catch(function () {
				msg.textContent = 'Network problem: please try again.'; msg.classList.add('is-error');
			}).finally(function () {
				btn.classList.remove('is-busy'); btn.disabled = false; update();
			});
		});
	});
})();
