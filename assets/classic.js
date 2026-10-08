/* Linkyar – classic editor metabox (e.g. WooCommerce products). */
( function () {
	'use strict';

	var cfg = window.LINKYAR || {};
	var t = cfg.i18n || {};
	var box = document.querySelector( '.linkyar-classic' );
	if ( ! box || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}
	var apiFetch = window.wp.apiFetch;
	var list = box.querySelector( '[data-linkyar-list]' );
	var stat = box.querySelector( '[data-linkyar-stat]' );
	var findBtn = box.querySelector( '[data-linkyar-find]' );
	var saveBtn = box.querySelector( '[data-linkyar-save]' );
	var kw = box.querySelector( '[data-linkyar-kw]' );

	function content() {
		var ed = window.tinymce && window.tinymce.get( 'content' );
		if ( ed && ! ed.isHidden() ) {
			return ed.getContent();
		}
		var ta = document.getElementById( 'content' );
		return ta ? ta.value : '';
	}

	function node( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( text !== undefined ) {
			n.textContent = text;
		}
		return n;
	}

	function copy( text, btn ) {
		var done = function () {
			btn.textContent = '✓ ' + t.copied;
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done );
			return;
		}
		var ta = node( 'textarea' );
		ta.value = text;
		document.body.appendChild( ta );
		ta.select();
		document.execCommand( 'copy' );
		ta.remove();
		done();
	}

	function render( d ) {
		list.innerHTML = '';
		stat.textContent = d.inbound ? String( t.thisInbound ).replace( '%d', d.inbound ) : t.thisOrphan;
		stat.className = 'linkyar-stat' + ( d.inbound ? '' : ' is-orphan' );
		if ( ! d.built ) {
			list.appendChild( node( 'p', 'linkyar-tip', t.notBuilt ) );
		}
		if ( ! d.suggestions.length ) {
			list.appendChild( node( 'p', 'linkyar-empty', t.none ) );
			return;
		}
		d.suggestions.forEach( function ( s ) {
			var card = node( 'div', 'linkyar-card' );
			var head = node( 'div', 'linkyar-card-head' );
			var a = node( 'a', 'linkyar-target', s.target.title );
			a.href = s.target.url;
			a.target = '_blank';
			a.rel = 'noopener noreferrer';
			head.appendChild( a );
			head.appendChild( node( 'span', 'linkyar-badge' + ( s.target.inbound ? '' : ' is-orphan' ), s.target.inbound ? String( t.inboundN ).replace( '%d', s.target.inbound ) : t.orphan ) );
			card.appendChild( head );

			var ctx = node( 'p', 'linkyar-context' );
			ctx.dir = 'auto';
			ctx.appendChild( document.createTextNode( s.context.before + ' ' ) );
			ctx.appendChild( node( 'mark', '', s.context.match ) );
			ctx.appendChild( document.createTextNode( ' ' + s.context.after ) );
			card.appendChild( ctx );

			var foot = node( 'div', 'linkyar-card-foot' );
			foot.appendChild( node( 'span', 'linkyar-source', ( t.source && t.source[ s.source ] ) || s.source ) );
			var btn = node( 'button', 'button button-small', t.copy );
			btn.type = 'button';
			btn.addEventListener( 'click', function () {
				copy( s.target.url, btn );
			} );
			foot.appendChild( btn );
			card.appendChild( foot );
			list.appendChild( card );
		} );
	}

	function run() {
		findBtn.disabled = true;
		findBtn.textContent = t.searching;
		apiFetch( { path: '/linkyar/v1/suggest', method: 'POST', data: { post_id: cfg.postId, html: content() || ' ' } } )
			.then( render )
			.catch( function () {
				list.innerHTML = '';
				list.appendChild( node( 'p', 'linkyar-warn', t.error ) );
			} )
			.then( function () {
				findBtn.disabled = false;
				findBtn.textContent = t.refresh;
			} );
	}

	findBtn.addEventListener( 'click', run );
	saveBtn.addEventListener( 'click', function () {
		saveBtn.disabled = true;
		apiFetch( { path: '/linkyar/v1/keywords', method: 'POST', data: { post_id: cfg.postId, keywords: kw.value } } )
			.then( function () {
				saveBtn.textContent = '✓ ' + t.saved;
			} )
			.catch( function () {
				saveBtn.textContent = t.error;
			} )
			.then( function () {
				saveBtn.disabled = false;
			} );
	} );
	kw.addEventListener( 'input', function () {
		saveBtn.textContent = t.save;
	} );
} )();
