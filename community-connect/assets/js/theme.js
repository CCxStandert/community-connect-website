/* Community Connect theme: small helpers. The plugin's script runs the live parts and the photo slides. */
(function () {
	'use strict';
	// Open the agreement section a link points to (#ag-…), and the "details" around any #anchor.
	var openHash = function () {
		if (!location.hash) return;
		var t = document.getElementById(decodeURIComponent(location.hash.slice(1)));
		if (!t) return;
		var d = t.tagName === 'DETAILS' ? t : t.closest('details');
		if (d) d.open = true;
	};
	addEventListener('hashchange', openHash);
	openHash();

	// Ride types: on phones each one folds to its number and name; tap to open it.
	var mq = matchMedia('(max-width: 759px)');
	document.querySelectorAll('.cc-rides').forEach(function (list) {
		var heads = [];
		list.querySelectorAll('.cc-ride').forEach(function (ride) {
			var name = ride.querySelector('.cc-ride__name');
			if (!name) return;
			var head = document.createElement('div');
			head.className = 'cc-ride__head';
			ride.insertBefore(head, ride.firstElementChild);
			// Everything up to and including the name goes in the head (number, FAR logo, name).
			while (head.nextElementSibling) {
				var el = head.nextElementSibling;
				head.appendChild(el);
				if (el === name) break;
			}
			var toggle = function () {
				if (!mq.matches) return;
				var open = ride.classList.toggle('is-open');
				head.setAttribute('aria-expanded', open ? 'true' : 'false');
			};
			head.addEventListener('click', toggle);
			head.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
			});
			heads.push(head);
		});
		var sync = function () {
			list.classList.toggle('is-acc', mq.matches);
			heads.forEach(function (h) {
				if (mq.matches) {
					h.setAttribute('role', 'button');
					h.setAttribute('tabindex', '0');
					h.setAttribute('aria-expanded', h.parentNode.classList.contains('is-open') ? 'true' : 'false');
				} else {
					h.removeAttribute('role');
					h.removeAttribute('tabindex');
					h.removeAttribute('aria-expanded');
				}
			});
		};
		(mq.addEventListener ? mq.addEventListener('change', sync) : mq.addListener(sync));
		sync();
	});
})();
