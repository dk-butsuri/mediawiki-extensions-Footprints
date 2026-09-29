<?php

namespace MediaWiki\Extension\Footprints;

use MediaWiki\Extension\Footprints\Hook\FootprintsIndicatorHook;
use MediaWiki\Extension\Footprints\Hook\FootprintsViewCountedHook;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Output\OutputPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;

class HookRunner implements FootprintsIndicatorHook, FootprintsViewCountedHook {

	private HookContainer $hookContainer;

	public function __construct( HookContainer $hookContainer ) {
		$this->hookContainer = $hookContainer;
	}

	/** @inheritDoc */
	public function onFootprintsIndicator( OutputPage $out, array &$before ): void {
		$this->hookContainer->run(
			'FootprintsIndicator',
			[ $out, &$before ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onFootprintsViewCounted( Title $title, UserIdentity $reader, bool $isNewReader ): void {
		$this->hookContainer->run(
			'FootprintsViewCounted',
			[ $title, $reader, $isNewReader ],
			[ 'abortable' => false ]
		);
	}
}
