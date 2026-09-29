<?php

namespace MediaWiki\Extension\Footprints\Hook;

use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;

interface FootprintsViewCountedHook {
	/**
	 * A page view has just been counted: it added to fp_views and wrote a
	 * footprint_log row. Views inside the cooldown do not reach this hook.
	 *
	 * Runs in a POSTSEND deferred update, after the response has gone out.
	 *
	 * @param Title $title The page that was read
	 * @param UserIdentity $reader Who read it
	 * @param bool $isNewReader True the first time this reader opens this page
	 */
	public function onFootprintsViewCounted( Title $title, UserIdentity $reader, bool $isNewReader ): void;
}
