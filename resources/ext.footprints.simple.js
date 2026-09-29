/*!
 * Opens the list of readers in a dialog instead of navigating away.
 *
 * The indicator is a plain link to Special:Footprints, so everything here is
 * an enhancement: if the API call fails we follow the link as normal.
 */
( function () {
	'use strict';

	var userNsPrefix = mw.config.get( 'wgFormattedNamespaces' )[ 2 ] + ':';

	function formatDate( iso ) {
		var date = new Date( iso );
		if ( isNaN( date.getTime() ) ) {
			return iso;
		}
		return date.toLocaleString( mw.config.get( 'wgUserLanguage' ), {
			year: 'numeric', month: 'short', day: 'numeric',
			hour: '2-digit', minute: '2-digit'
		} );
	}

	function buildTable( viewers ) {
		var $table = $( '<table>' ).addClass( 'wikitable ext-footprints-table' ),
			$head = $( '<tr>' );

		[ 'footprints-header-user', 'footprints-header-last', 'footprints-header-views' ]
			.forEach( function ( key ) {
				$head.append( $( '<th>' ).text( mw.msg( key ) ) );
			} );
		$table.append( $( '<thead>' ).append( $head ) );

		var $body = $( '<tbody>' );
		viewers.forEach( function ( viewer ) {
			$body.append( $( '<tr>' ).append(
				$( '<td>' ).append(
					$( '<a>' )
						.attr( 'href', mw.util.getUrl( userNsPrefix + viewer.user ) )
						.text( viewer.user )
				),
				$( '<td>' ).text( formatDate( viewer.last ) ),
				$( '<td>' ).text( mw.language.convertNumber( viewer.views ) )
			) );
		} );
		$table.append( $body );

		return $table;
	}

	function buildContent( data, href ) {
		var $content = $( '<div>' ).addClass( 'ext-footprints-dialog' );

		if ( !data.viewers.length ) {
			$content.append( $( '<p>' ).text( mw.msg( 'footprints-empty' ) ) );
			return $content;
		}

		$content.append(
			$( '<p>' ).text( mw.msg( 'footprints-readercount', data.count ) ),
			buildTable( data.viewers ),
			$( '<p>' ).append(
				$( '<a>' ).attr( 'href', href ).text( mw.msg( 'footprints-viewall' ) )
			)
		);

		return $content;
	}

	$( function () {
		var $indicator = $( '.ext-footprints-indicator' );
		if ( !$indicator.length ) {
			return;
		}

		$indicator.on( 'click', function ( e ) {
			// Leave modified clicks (new tab, etc.) and non-primary buttons alone.
			if ( e.which !== 1 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
				return;
			}
			e.preventDefault();

			var $link = $( this ),
				href = $link.attr( 'href' );

			if ( $link.data( 'footprintsBusy' ) ) {
				return;
			}
			$link.data( 'footprintsBusy', true );

			mw.loader.using( [ 'oojs-ui-windows', 'mediawiki.api' ] ).then( function () {
				return new mw.Api().get( {
					action: 'query',
					list: 'footprints',
					fppage: mw.config.get( 'wgPageName' ),
					formatversion: 2
				} );
			} ).then( function ( response ) {
				try {
					var data = response.query.footprints;
					OO.ui.alert( buildContent( data, href ), {
						title: mw.msg( 'footprints-page-title', data.title ),
						size: 'medium'
					} );
				} catch ( err ) {
					window.location.href = href;
				}
			}, function () {
				// Anything went wrong: behave like the plain link it started as.
				window.location.href = href;
			} ).always( function () {
				$link.removeData( 'footprintsBusy' );
			} );
		} );
	} );
}() );
