/* Linkyar – rebuild the link map in batches. */
( function () {
	'use strict';
	var btn = document.getElementById( 'linkyar-rebuild' );
	if ( ! btn || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}
	var t = ( window.LINKYAR_ADMIN && window.LINKYAR_ADMIN.i18n ) || {};
	var bar = document.getElementById( 'linkyar-progress' );
	var status = document.getElementById( 'linkyar-status' );

	function step( offset ) {
		return window.wp.apiFetch( { path: '/linkyar/v1/rebuild', method: 'POST', data: { offset: offset } } ).then( function ( r ) {
			bar.value = r.total ? Math.round( ( r.processed / r.total ) * 100 ) : 100;
			status.textContent = String( t.working ).replace( '%1$d', r.processed ).replace( '%2$d', r.total );
			return r.done ? r : step( r.processed );
		} );
	}

	btn.addEventListener( 'click', function () {
		btn.disabled = true;
		bar.hidden = false;
		bar.value = 0;
		step( 0 )
			.then( function () {
				window.location.reload();
			} )
			.catch( function () {
				status.textContent = t.error;
				btn.disabled = false;
			} );
	} );
} )();
