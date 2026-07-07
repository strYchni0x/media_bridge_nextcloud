/* global ncmbSettings */
( function () {
	'use strict';

	var cfg = window.ncmbSettings || {};
	var i18n = cfg.i18n || {};

	var wrap = document.querySelector( '.ncmb-settings-wrap' );
	var container = document.getElementById( 'ncmb-accounts' );
	var template = document.getElementById( 'ncmb-account-template' );
	var addBtn = document.getElementById( 'ncmb-add-account' );

	if ( ! wrap || ! container ) {
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

	function accountRows() {
		return container.querySelectorAll( '.ncmb-account' );
	}

	// Monotonic index for new rows so field names never collide (PHP reindexes
	// on save, removal never frees an index).
	var nextIndex = accountRows().length;

	function updateSingleClass() {
		if ( accountRows().length <= 1 ) {
			wrap.classList.add( 'ncmb-single' );
		} else {
			wrap.classList.remove( 'ncmb-single' );
		}
	}

	// Adds a fresh, empty account row from the template.
	function addAccount() {
		if ( ! template || ! template.content ) {
			return;
		}
		var frag = template.content.cloneNode( true );
		var fieldset = frag.querySelector( '.ncmb-account' );
		if ( ! fieldset ) {
			return;
		}
		fieldset.removeAttribute( 'data-template' );

		var idx = nextIndex++;
		// Replace the "__INDEX__" placeholder in every field name.
		fieldset.querySelectorAll( '[name]' ).forEach( function ( el ) {
			el.setAttribute( 'name', el.getAttribute( 'name' ).replace( '__INDEX__', String( idx ) ) );
		} );

		container.appendChild( fieldset );
		updateSingleClass();

		var firstInput = fieldset.querySelector( '.ncmb-f-label' );
		if ( firstInput ) {
			firstInput.focus();
		}
	}

	// Live-updates the row title from the "Name" field.
	function onLabelInput( e ) {
		if ( ! e.target.classList.contains( 'ncmb-f-label' ) ) {
			return;
		}
		var fieldset = e.target.closest( '.ncmb-account' );
		var title = fieldset && fieldset.querySelector( '.ncmb-account-title' );
		if ( title ) {
			title.textContent = e.target.value || title.textContent;
		}
	}

	// Removes an account row.
	function onRemoveClick( e ) {
		var btn = e.target.closest( '.ncmb-remove-account' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		if ( ! window.confirm( i18n.confirmDel || 'Remove?' ) ) {
			return;
		}
		var fieldset = btn.closest( '.ncmb-account' );
		if ( fieldset ) {
			fieldset.parentNode.removeChild( fieldset );
			updateSingleClass();
		}
	}

	// Tests the connection of a single (saved) account.
	function onTestClick( e ) {
		var btn = e.target.closest( '.ncmb-test-connection' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();

		var fieldset = btn.closest( '.ncmb-account' );
		var out = fieldset && fieldset.querySelector( '.ncmb-test-result' );
		if ( ! out ) {
			return;
		}

		var accountId = btn.getAttribute( 'data-account' ) || '';
		if ( '' === accountId ) {
			out.innerHTML = '<span style="color:#d63638">' + escapeHtml( i18n.saveFirst ) + '</span>';
			return;
		}

		out.textContent = i18n.checking || '';

		var url = cfg.restUrl + '?account=' + encodeURIComponent( accountId ) + '&path=%2F';
		fetch( url, {
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
		} ).catch( function ( err ) {
			out.innerHTML = '<span style="color:#d63638">' + escapeHtml( err.message ) + '</span>';
		} );
	}

	if ( addBtn ) {
		addBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			addAccount();
		} );
	}

	container.addEventListener( 'click', function ( e ) {
		onRemoveClick( e );
		onTestClick( e );
	} );
	container.addEventListener( 'input', onLabelInput );

	updateSingleClass();
} )();
