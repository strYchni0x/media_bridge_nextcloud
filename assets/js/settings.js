/* global ncmbSettings */
( function () {
	'use strict';

	var cfg = window.ncmbSettings || {};
	var i18n = cfg.i18n || {};
	var btn = document.getElementById( 'ncmb-test-connection' );
	if ( ! btn ) {
		return;
	}

	function escapeHtml( value ) {
		return String( value ).replace( /[&<>"']/g, function ( c ) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#39;'
			}[ c ];
		} );
	}

	btn.addEventListener( 'click', function () {
		var out = document.getElementById( 'ncmb-test-result' );
		if ( ! out ) {
			return;
		}
		out.textContent = i18n.checking || '';

		fetch( cfg.restUrl + '?path=%2F', {
			headers: { 'X-WP-Nonce': cfg.nonce }
		} ).then( function ( r ) {
			return r.json().then( function ( b ) {
				return { ok: r.ok, body: b };
			} );
		} ).then( function ( res ) {
			if ( res.ok ) {
				out.innerHTML = '<span style="color:#1a7f37">' + escapeHtml( i18n.ok ) + '</span>';
			} else {
				var msg = ( res.body && res.body.message ) ? res.body.message : i18n.error;
				out.innerHTML = '<span style="color:#d63638">' + escapeHtml( msg ) + '</span>';
			}
		} ).catch( function ( e ) {
			out.innerHTML = '<span style="color:#d63638">' + escapeHtml( e.message ) + '</span>';
		} );
	} );
} )();
