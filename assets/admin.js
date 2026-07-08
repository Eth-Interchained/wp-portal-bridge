/* WP Portal Bridge — admin niceties: copy-to-clipboard + destructive confirms. */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-wpb-copy]' );
		if ( ! button ) {
			return;
		}
		var target = document.querySelector( button.getAttribute( 'data-wpb-copy' ) );
		if ( ! target ) {
			return;
		}
		var text = target.textContent;
		var done = function () {
			var original = button.textContent;
			button.textContent = 'Copied ✓';
			window.setTimeout( function () {
				button.textContent = original;
			}, 1600 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done );
		} else {
			var range = document.createRange();
			range.selectNodeContents( target );
			var selection = window.getSelection();
			selection.removeAllRanges();
			selection.addRange( range );
			document.execCommand( 'copy' );
			selection.removeAllRanges();
			done();
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target.closest( 'form[data-wpb-confirm]' );
		if ( form && ! window.confirm( form.getAttribute( 'data-wpb-confirm' ) ) ) {
			event.preventDefault();
		}
	} );
}() );
