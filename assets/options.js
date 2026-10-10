document.addEventListener( 'click', function ( event ) {
	var link = event.target.closest( '.cosmo-mlk-lang-bar__copy' );

	if ( link && ! window.confirm( link.dataset.confirm ) ) {
		event.preventDefault();
	}
} );
