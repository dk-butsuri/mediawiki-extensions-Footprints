<?php

namespace MediaWiki\Extension\Footprints;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHooks implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$type = $updater->getDB()->getType();
		$updater->addExtensionTable(
			FootprintStore::TABLE,
			__DIR__ . "/../sql/$type/tables-generated.sql"
		);
		// footprint_log was added after the initial release, so a fresh install
		// gets it from tables-generated.sql above but an existing one needs the
		// patch applied separately.
		$updater->addExtensionTable(
			FootprintStore::TABLE_LOG,
			__DIR__ . "/../sql/$type/patch-footprint_log.sql"
		);
	}
}
