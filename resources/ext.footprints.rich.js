/*!
 * Opens the list of readers in a dialog instead of navigating away.
 *
 * The indicator is a plain link to Special:Footprints, so everything here is
 * an enhancement: if the API call fails we follow the link as normal. The
 * dialog is a plain DOM overlay rather than an OOUI window, because nothing
 * here needs OOUI beyond a modal box, and the sort tabs / chart / badges are
 * easier to keep in sync with the skin's own colours (light and dark) as
 * hand-written markup than as OOUI widgets fighting their own styling.
 */
( function () {
	'use strict';

	var config = require( './config.json' );
	var userNsPrefix = mw.config.get( 'wgFormattedNamespaces' )[ 2 ] + ':';

	// How many rows the dialog itself shows before handing off to
	// Special:Footprints. Keeps the dialog a glance, not a second Special page.
	var VISIBLE_LIMIT = 8;

	var MINUTE = 60,
		HOUR = 3600,
		DAY = 86400;

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

	// Coarser the further back it is, same convention as everywhere else that
	// shows "how long ago": exact minutes only while it is still fresh enough
	// for the minute to mean something, days out to a week, then an actual date.
	function formatRelative( iso ) {
		var then = new Date( iso ).getTime();
		if ( isNaN( then ) ) {
			return iso;
		}
		var seconds = Math.max( 0, ( Date.now() - then ) / 1000 );
		if ( seconds < MINUTE ) {
			return mw.msg( 'footprints-relative-now' );
		}
		if ( seconds < HOUR ) {
			return mw.msg( 'footprints-relative-minutes', Math.floor( seconds / MINUTE ) );
		}
		if ( seconds < DAY ) {
			return mw.msg( 'footprints-relative-hours', Math.floor( seconds / HOUR ) );
		}
		if ( seconds < 7 * DAY ) {
			return mw.msg( 'footprints-relative-days', Math.floor( seconds / DAY ) );
		}
		return formatDate( iso );
	}

	// Wraps runs of digits in a monospace span, so counts stand out from the
	// surrounding prose the way the row list's own numbers already do. Safe
	// against the message text itself, which never carries raw HTML -- mw.msg
	// only substitutes the plain numbers this file itself passed in.
	function markNumbers( text ) {
		return text.replace( /[0-9]+(?:[.,][0-9]+)*/g, function ( m ) {
			return '<span class="ext-footprints-num">' + m + '</span>';
		} );
	}

	function escapeHtml( s ) {
		var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return map[ c ];
		} );
	}

	// Wraps the rightmost exact match of `needle` in `html` (both escaped
	// first, so this operates on the same text markNumbers() would see) with
	// a coloured span. Best-effort, not a template engine: fine for a short,
	// distinctive token like a name or a small count, where the odd stray
	// collision elsewhere in the sentence is a cosmetic non-issue. Rightmost
	// match because in every phrasing this file writes, the count comes
	// after the date it might otherwise be confused with.
	function markLast( html, needle, cls ) {
		var escNeedle = escapeHtml( needle );
		var idx = html.lastIndexOf( escNeedle );
		if ( idx === -1 ) {
			return html;
		}
		return html.slice( 0, idx ) +
			'<span class="' + cls + '">' + escNeedle + '</span>' +
			html.slice( idx + escNeedle.length );
	}

	function initial( name ) {
		// Array.from splits on code points, not UTF-16 units, so a name
		// starting with an astral character still yields one whole glyph.
		// Leading digits are skipped: on wikis whose accounts are named
		// "<member number><name>", a digit says nothing about who it is.
		var chars = Array.from( String( name ).replace( /^[0-9\s_-]+(?=.)/, '' ) );
		return chars.length ? chars[ 0 ] : '?';
	}

	// Decorative only: a stable colour per user id, not meant to be unique or
	// to encode anything. Picked from a small fixed hue set so avatars stay
	// legible (fixed saturation/lightness) in both light and dark mode.
	var AVATAR_HUES = [ 205, 265, 25, 165, 320, 45, 285, 5 ];
	function avatarColor( userId ) {
		var hue = AVATAR_HUES[ Math.abs( userId ) % AVATAR_HUES.length ];
		return 'hsl(' + hue + ', 32%, 38%)';
	}

	// "YYYYMMDD" -> "M/D".
	function shortDate( ymd ) {
		return ( +ymd.slice( 4, 6 ) ) + '/' + ( +ymd.slice( 6, 8 ) );
	}

	function buildChart( chart ) {
		var max = Math.max.apply( null, chart.map( function ( d ) {
			return d.views;
		} ).concat( [ 1 ] ) );

		var $bars = $( '<div>' ).addClass( 'ext-footprints-chart' );
		chart.forEach( function ( d, i ) {
			var ratio = d.views / max;
			$bars.append(
				$( '<div>' )
					.addClass( 'ext-footprints-chart-bar' )
					// A day well above the others earns a lighter shade, the same
					// way the reference design picks out its busiest days rather
					// than leaving every non-today bar a single flat colour.
					.toggleClass( 'ext-footprints-chart-bar-peak', !( i === chart.length - 1 ) && ratio >= 0.85 )
					.toggleClass( 'ext-footprints-chart-bar-high', !( i === chart.length - 1 ) && ratio >= 0.55 && ratio < 0.85 )
					.toggleClass( 'ext-footprints-chart-bar-today', i === chart.length - 1 )
					// A day with zero views still gets a sliver, so the axis
					// reads as continuous rather than looking like missing data.
					.css( 'height', ( d.views ? Math.max( 6, ratio * 100 ) : 3 ) + '%' )
					.attr( 'title', shortDate( d.date ) + ': ' + d.views )
			);
		} );

		var mid = chart[ Math.floor( chart.length / 2 ) ];
		var $axis = $( '<div>' ).addClass( 'ext-footprints-chart-axis' ).append(
			$( '<span>' ).text( shortDate( chart[ 0 ].date ) ),
			$( '<span>' ).text( shortDate( mid.date ) ),
			$( '<span>' ).text( mw.msg( 'footprints-dialog-chart-today' ) )
		);

		return $( '<div>' ).append( $bars, $axis );
	}

	// One <path> pair, reused rather than fetched, since it is the only icon
	// the dialog needs and pulling in an icon module for it would cost more
	// than it saves.
	var PENCIL_ICON = '<svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
		'stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
		'<path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"></path></svg>';

	var CHEVRON_ICON = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
		'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
		'<path d="m6 9 6 6 6-6"></path></svg>';

	function buildBadges( viewer ) {
		var $badges = $( '<span>' ).addClass( 'ext-footprints-badges' );

		function add( key, cls, icon ) {
			var $badge = $( '<span>' ).addClass( 'ext-footprints-badge ' + cls );
			if ( icon ) {
				$badge.append( icon );
			}
			$badge.append( document.createTextNode( mw.msg( key ) ) );
			$badges.append( $badge );
		}

		// "You" first: it is the one badge about the viewer rather than the
		// row's subject, so it reads before anything describing the person.
		if ( viewer.isYou ) {
			add( 'footprints-dialog-badge-you', 'ext-footprints-badge-you' );
		}
		if ( viewer.isOwner ) {
			add( 'footprints-dialog-badge-owner', 'ext-footprints-badge-owner' );
		}
		if ( viewer.isLastEditor ) {
			add( 'footprints-dialog-badge-last-editor', 'ext-footprints-badge-editor', PENCIL_ICON );
		}
		if ( viewer.isRegular ) {
			add( 'footprints-dialog-badge-regular', 'ext-footprints-badge-regular' );
		}

		return $badges;
	}

	function buildRow( viewer, maxViews ) {
		var barWidth = maxViews ? Math.max( 4, viewer.views / maxViews * 100 ) : 0;

		var $avatar = $( '<span>' )
			.addClass( 'ext-footprints-avatar' )
			.attr( 'aria-hidden', 'true' )
			.css( 'background-color', avatarColor( viewer.userid ) )
			.text( initial( viewer.user ) );

		var $name = $( '<a>' )
			.addClass( 'ext-footprints-name' )
			.attr( 'href', mw.util.getUrl( userNsPrefix + viewer.user ) )
			.text( viewer.user );

		var $meta = $( '<div>' ).addClass( 'ext-footprints-row-meta' ).append(
			$( '<span>' ).addClass( 'ext-footprints-row-bar' ).append(
				$( '<span>' ).css( 'width', barWidth + '%' )
			),
			$( '<span>' ).addClass( 'ext-footprints-row-views' ).text( mw.language.convertNumber( viewer.views ) )
		);

		var $body = $( '<div>' ).addClass( 'ext-footprints-row-body' ).append(
			$( '<div>' ).addClass( 'ext-footprints-row-top' ).append( $name, buildBadges( viewer ) ),
			$meta
		);

		var $time = $( '<span>' ).addClass( 'ext-footprints-row-time' ).text( formatRelative( viewer.last ) );

		return $( '<li>' )
			.addClass( 'ext-footprints-row' )
			.toggleClass( 'ext-footprints-row-you', viewer.isYou )
			.append( $avatar, $body, $time );
	}

	var SORTERS = {
		recent: function ( a, b ) {
			return new Date( b.last ) - new Date( a.last );
		},
		frequent: function ( a, b ) {
			return b.views - a.views;
		}
	};

	// "unread" reuses the recency order -- the tab's job is picking the
	// subset, not a different order within it.
	function sortedViewers( viewers, sort ) {
		var list = sort === 'unread'
			? viewers.filter( function ( v ) {
				return !v.readAfterUpdate;
			} )
			: viewers.slice();
		list.sort( SORTERS[ sort === 'unread' ? 'recent' : sort ] );
		return list;
	}

	function buildList( viewers, sort ) {
		var visible = sortedViewers( viewers, sort ).slice( 0, VISIBLE_LIMIT );
		var maxViews = Math.max.apply( null, viewers.map( function ( v ) {
			return v.views;
		} ).concat( [ 1 ] ) );

		var $list = $( '<ul>' ).addClass( 'ext-footprints-list' );
		visible.forEach( function ( viewer ) {
			$list.append( buildRow( viewer, maxViews ) );
		} );
		return $list;
	}

	function buildUpdatedSection( data ) {
		var unread = data.count - data.readAfterUpdate;
		var pct = data.count ? Math.round( data.readAfterUpdate / data.count * 100 ) : 0;
		var when = formatDate( data.updatedAt );

		var line = data.updatedBy
			? mw.msg( 'footprints-dialog-updated-line', when, data.updatedBy, unread )
			: mw.msg( 'footprints-dialog-updated-line-unknown-editor', when, unread );

		// Highlight the two facts in this sentence worth a second look: who
		// edited, and how many people have not caught up. The date and the
		// rest of the phrasing stay at the paragraph's own muted colour.
		var noteHtml = escapeHtml( line );
		if ( data.updatedBy ) {
			noteHtml = markLast( noteHtml, data.updatedBy, 'ext-footprints-emphasis' );
		}
		if ( unread > 0 ) {
			noteHtml = markLast( noteHtml, mw.language.convertNumber( unread ), 'ext-footprints-warn' );
		}

		return $( '<section>' ).addClass( 'ext-footprints-section' ).append(
			$( '<div>' ).addClass( 'ext-footprints-section-head' ).append(
				$( '<h3>' ).text( mw.msg( 'footprints-dialog-updated-section' ) ),
				$( '<span>' ).addClass( 'ext-footprints-section-fraction' ).append(
					$( '<span>' ).addClass( 'ext-footprints-fraction-num' )
						.text( mw.language.convertNumber( data.readAfterUpdate ) ),
					document.createTextNode( ' / ' + mw.language.convertNumber( data.count ) )
				)
			),
			$( '<div>' ).addClass( 'ext-footprints-progress' ).append(
				$( '<div>' ).addClass( 'ext-footprints-progress-fill' ).css( 'width', pct + '%' )
			),
			$( '<p>' ).addClass( 'ext-footprints-section-note' ).html( noteHtml )
		);
	}

	function buildChartSection( data ) {
		var chart = data.chart;
		var today = chart.length ? chart[ chart.length - 1 ].views : 0;

		return $( '<section>' ).addClass( 'ext-footprints-section' ).append(
			$( '<div>' ).addClass( 'ext-footprints-section-head' ).append(
				$( '<h3>' ).text( mw.msg( 'footprints-dialog-chart-title' ) ),
				$( '<span>' ).addClass( 'ext-footprints-section-fraction' ).append(
					document.createTextNode( mw.msg( 'footprints-dialog-chart-days', chart.length ) + ' · ' +
						mw.msg( 'footprints-dialog-chart-today' ) + ' ' ),
					$( '<span>' ).addClass( 'ext-footprints-chart-today-num' )
						.text( mw.language.convertNumber( today ) )
				)
			),
			buildChart( chart )
		);
	}

	function buildTabs( data, activeSort, onChange ) {
		var unread = data.count - data.readAfterUpdate;
		var tabs = [
			{ key: 'recent', label: mw.msg( 'footprints-dialog-sort-recent' ) },
			{ key: 'frequent', label: mw.msg( 'footprints-dialog-sort-frequent' ) },
			{ key: 'unread', label: mw.msg( 'footprints-dialog-sort-unread', unread ) }
		];

		var $tabs = $( '<div>' ).addClass( 'ext-footprints-tabs' ).attr( 'role', 'tablist' );
		tabs.forEach( function ( tab ) {
			$( '<button>' )
				.attr( { type: 'button', role: 'tab', 'aria-pressed': tab.key === activeSort } )
				.addClass( 'ext-footprints-tab' )
				.toggleClass( 'ext-footprints-tab-active', tab.key === activeSort )
				.text( tab.label )
				.on( 'click', function () {
					onChange( tab.key );
				} )
				.appendTo( $tabs );
		} );
		return $tabs;
	}

	function buildContent( data, href ) {
		var $content = $( '<div>' ).addClass( 'ext-footprints-body' );

		if ( !data.count ) {
			$content.append( $( '<p>' ).addClass( 'ext-footprints-section-note' ).text( mw.msg( 'footprints-empty' ) ) );
			return $content;
		}

		var sort = 'recent';
		var $listHolder = $( '<div>' );

		function render() {
			$listHolder.empty().append( buildList( data.viewers, sort ) );
		}

		var $tabsHolder = $( '<div>' );
		function renderTabs() {
			$tabsHolder.empty().append( buildTabs( data, sort, function ( next ) {
				sort = next;
				render();
				renderTabs();
			} ) );
		}
		renderTabs();
		render();

		$content.append(
			data.updatedAt ? buildUpdatedSection( data ) : null,
			buildChartSection( data ),
			$tabsHolder,
			$listHolder
		);

		if ( data.count > VISIBLE_LIMIT ) {
			$content.append(
				$( '<a>' )
					.addClass( 'ext-footprints-viewall' )
					.attr( 'href', href )
					.append(
						document.createTextNode( mw.msg( 'footprints-dialog-viewall', data.count - VISIBLE_LIMIT ) ),
						$( CHEVRON_ICON )
					)
			);
		}

		return $content;
	}

	var fontsRequested = false;

	// Off by default: it sends every reader who opens the dialog to Google.
	// Loaded lazily, only once the dialog is actually opened -- nothing else
	// on the page needs these two faces.
	//
	// ★ One family per <link>. Cloudflare's "Rewrite to Cloudflare Fonts"
	//   inlines Google Fonts <link> tags as @font-face, and when a single
	//   URL bundles more than one family, only the first survives that
	//   rewrite; the rest disappear with no error.
	function ensureFonts() {
		if ( !config.FootprintsDialogWebFonts ) {
			return false;
		}
		if ( fontsRequested ) {
			return true;
		}
		fontsRequested = true;

		var head = document.head;
		[ 'https://fonts.googleapis.com', 'https://fonts.gstatic.com' ].forEach( function ( origin ) {
			var $preconnect = $( '<link>' ).attr( { rel: 'preconnect', href: origin } );
			if ( origin.indexOf( 'gstatic' ) !== -1 ) {
				$preconnect[ 0 ].crossOrigin = 'anonymous';
			}
			head.appendChild( $preconnect[ 0 ] );
		} );
		[
			'Zen+Kaku+Gothic+New:wght@500;700',
			'M+PLUS+1+Code:wght@400;500'
		].forEach( function ( family ) {
			head.appendChild( $( '<link>' ).attr( {
				rel: 'stylesheet',
				href: 'https://fonts.googleapis.com/css2?family=' + family + '&display=swap'
			} )[ 0 ] );
		} );
		return true;
	}

	// Cached by page name so a hover/focus that already warmed the request
	// isn't repeated when the click follows it, and concurrent callers share
	// one in-flight request instead of firing one each.
	var prefetchCache = {};

	function fetchFootprints( page ) {
		if ( !prefetchCache[ page ] ) {
			prefetchCache[ page ] = mw.loader.using( 'mediawiki.api' ).then( function () {
				return new mw.Api().get( {
					action: 'query',
					list: 'footprints',
					fppage: page,
					formatversion: 2
				} );
			} ).then( function ( response ) {
				return response.query.footprints;
			} );
			// A failed fetch shouldn't poison later attempts on the same page.
			prefetchCache[ page ].fail( function () {
				delete prefetchCache[ page ];
			} );
		}
		return prefetchCache[ page ];
	}

	// Opens immediately with a loading skeleton -- the fetch may already be
	// in flight (warmed on hover/focus) or may only start now, but either way
	// the wait is shown rather than left as a silent delay before the click.
	function openDialog( href, promise ) {
		var closed = false;
		var $overlay = $( '<div>' )
			.addClass( 'ext-footprints-overlay' )
			.toggleClass( 'ext-footprints-webfonts', ensureFonts() );
		var $dialog = $( '<div>' )
			.addClass( 'ext-footprints-dialog' )
			.attr( { role: 'dialog', 'aria-modal': 'true', 'aria-label': mw.msg( 'footprints-page-title', mw.config.get( 'wgTitle' ) ) } );

		var $closeBtn = $( '<button>' )
			.attr( 'type', 'button' )
			.addClass( 'ext-footprints-close' )
			.text( mw.msg( 'footprints-dialog-close' ) );

		function close() {
			closed = true;
			$overlay.remove();
			$( document ).off( 'keydown.extFootprints' );
			$returnFocus.trigger( 'focus' );
		}

		var $returnFocus = $( document.activeElement );

		$overlay.on( 'mousedown', function ( e ) {
			if ( e.target === $overlay[ 0 ] ) {
				close();
			}
		} );
		$closeBtn.on( 'click', close );
		$( document ).on( 'keydown.extFootprints', function ( e ) {
			if ( e.key === 'Escape' ) {
				close();
			}
		} );

		var $title = $( '<h2>' ).text( mw.msg( 'footprints-page-title', mw.config.get( 'wgTitle' ) ) );
		var $subtitle = $( '<p>' ).addClass( 'ext-footprints-subtitle' );
		var $body = $( '<div>' ).addClass( 'ext-footprints-body ext-footprints-body-loading' ).append(
			$( '<div>' ).addClass( 'ext-footprints-spinner' ),
			$( '<p>' ).addClass( 'ext-footprints-loading-text' ).text( mw.msg( 'footprints-dialog-loading' ) )
		);

		var $footer = $( '<footer>' ).addClass( 'ext-footprints-footer' ).append( $closeBtn );

		$dialog.append(
			$( '<header>' ).addClass( 'ext-footprints-header' ).append( $title, $subtitle ),
			$body,
			$footer
		);

		// Lets another extension or a gadget put a link at the footer's left,
		// e.g. $footer.prepend( $( '<a>' ).addClass( 'ext-footprints-footer-link' ) ... ).
		mw.hook( 'ext.footprints.dialog.footer' ).fire( $footer );

		$overlay.append( $dialog );
		$( document.body ).append( $overlay );
		$closeBtn.trigger( 'focus' );

		promise.then( function ( data ) {
			if ( closed ) {
				return;
			}
			$title.text( mw.msg( 'footprints-page-title', data.title ) );
			$dialog.attr( 'aria-label', mw.msg( 'footprints-page-title', data.title ) );
			$subtitle.html( markNumbers( mw.msg( 'footprints-dialog-subtitle', data.count, data.totalViews ) ) );
			try {
				$body.replaceWith( buildContent( data, href ) );
			} catch ( err ) {
				close();
				window.location.href = href;
			}
		}, function () {
			// Anything went wrong: behave like the plain link this started as.
			if ( !closed ) {
				close();
			}
			window.location.href = href;
		} );
	}

	$( function () {
		var $indicator = $( '.ext-footprints-indicator' );
		if ( !$indicator.length ) {
			return;
		}

		function warm() {
			fetchFootprints( mw.config.get( 'wgPageName' ) );
		}
		// Hover/focus/touch typically precede a click by long enough to have
		// the response back before the dialog even needs to show a spinner.
		$indicator.on( 'mouseenter focus touchstart', warm );

		$indicator.on( 'click', function ( e ) {
			// Leave modified clicks (new tab, etc.) and non-primary buttons alone.
			if ( e.which !== 1 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) {
				return;
			}
			e.preventDefault();

			openDialog( $( this ).attr( 'href' ), fetchFootprints( mw.config.get( 'wgPageName' ) ) );
		} );
	} );
}() );
