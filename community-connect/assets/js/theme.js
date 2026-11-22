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
})();
