/**
 * Index Pages admin: asks for confirmation before destructive form submits.
 */
( function () {
	'use strict';

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.getAttribute ) {
			return;
		}
		var question = form.getAttribute( 'data-indexpages-confirm' );
		if ( question && ! window.confirm( question ) ) {
			event.preventDefault();
		}
	} );
}() );
