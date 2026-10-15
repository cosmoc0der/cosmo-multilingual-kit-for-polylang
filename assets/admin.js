document.addEventListener( 'click', function ( event ) {
	var link = event.target.closest( '[data-cosmo-mlk-confirm]' );

	if ( link && ! window.confirm( link.dataset.cosmoMlkConfirm ) ) {
		event.preventDefault();
	}
} );
