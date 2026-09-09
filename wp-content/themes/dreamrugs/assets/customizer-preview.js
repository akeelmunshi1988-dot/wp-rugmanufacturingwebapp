/**
 * Live-preview updates for the two postMessage-transport color controls
 * registered in inc/customizer.php. Only handles what actually needs no
 * reload — font changes use 'refresh' transport and are handled by core.
 */
( function ( wp ) {
	if ( ! wp || ! wp.customize ) return;

	function setVar( name, value ) {
		var style = document.getElementById( 'dreamrugs-brand-vars' );
		if ( style && style.sheet && style.sheet.cssRules && style.sheet.cssRules[0] ) {
			style.sheet.cssRules[0].style.setProperty( name, value );
		}
	}

	wp.customize( 'drc_color_primary', function ( value ) {
		value.bind( function ( newValue ) { setVar( '--drc-primary', newValue ); } );
	} );
	wp.customize( 'drc_color_accent', function ( value ) {
		value.bind( function ( newValue ) { setVar( '--drc-accent', newValue ); } );
	} );
} )( window.wp );
