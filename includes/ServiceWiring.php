<?php

use MediaWiki\Extension\Footprints\FootprintStore;
use MediaWiki\MediaWikiServices;

return [
	'Footprints.FootprintStore' => static function ( MediaWikiServices $services ): FootprintStore {
		return new FootprintStore( $services->getConnectionProvider() );
	},
];
