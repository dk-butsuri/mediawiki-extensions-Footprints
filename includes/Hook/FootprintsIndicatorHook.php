<?php

namespace MediaWiki\Extension\Footprints\Hook;

use MediaWiki\Output\OutputPage;

interface FootprintsIndicatorHook {
	/**
	 * The footprint indicator is about to be added to a page.
	 *
	 * HTML appended to $before goes inside the same page indicator, to the
	 * left of the footprint link. It has to be the same indicator: MediaWiki
	 * sorts indicators by key, so a separate one would not stay beside it.
	 *
	 * @param OutputPage $out
	 * @param string[] &$before Raw HTML fragments; the caller escapes nothing
	 */
	public function onFootprintsIndicator( OutputPage $out, array &$before ): void;
}
