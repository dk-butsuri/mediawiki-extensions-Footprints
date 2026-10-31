/*!
 * Shared by both dialog styles: keeps the indicator's count current without a
 * reload, when something outside this extension says the page's footprints
 * changed, e.g. mw.hook( 'ext.footprints.refresh' ).fire( mw.config.get( 'wgPageName' ) ).
 */

// The API caps the viewer list it counts, so on a page read by more people
// than that, its count is lower than the indicator's; never go down.
function updateIndicator( data ) {
	var $indicator = $( '.ext-footprints-indicator' );
	var shown = Number( $indicator.attr( 'data-count' ) ) || 0;
	if ( data.count <= shown ) {
		return;
	}
	$indicator.attr( {
		'data-count': data.count,
		title: mw.msg( 'footprints-indicator-tooltip', data.count )
	} );
	$indicator.find( '.ext-footprints-count' ).text( mw.language.convertNumber( data.count ) );
}

// Several signals in a row cost one fetch, and a background tab waits until
// it is looked at again.
function onRefresh( refresh ) {
	var DELAY_MS = 1000;
	var timer = null;
	var pendingWhileHidden = false;

	function schedule() {
		if ( document.visibilityState === 'hidden' ) {
			pendingWhileHidden = true;
			return;
		}
		clearTimeout( timer );
		timer = setTimeout( refresh, DELAY_MS );
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'visible' && pendingWhileHidden ) {
			pendingWhileHidden = false;
			schedule();
		}
	} );

	mw.hook( 'ext.footprints.refresh' ).add( function ( page ) {
		if ( !page || page === mw.config.get( 'wgPageName' ) ) {
			schedule();
		}
	} );
}

module.exports = {
	updateIndicator: updateIndicator,
	onRefresh: onRefresh
};
