<?php

namespace MediaWiki\Extension\Footprints;

/**
 * Registration of the footprint notification with Echo.
 *
 * ★ This class deliberately does not implement Echo's BeforeCreateEchoEventHook
 *   interface. Echo is a soft dependency — Footprints works without it, minus
 *   the notification — and `implements` on an interface from an extension that
 *   may not be installed is a fatal error the moment the class is autoloaded.
 *   Keeping it in a class of its own means it is only ever loaded by Echo
 *   itself, when Echo runs the hook.
 */
class EchoHooks {

	/**
	 * @param array &$notifications
	 * @param array &$notificationCategories
	 * @param array &$notificationIcons
	 */
	public function onBeforeCreateEchoEvent(
		array &$notifications,
		array &$notificationCategories,
		array &$notificationIcons
	) {
		$notificationCategories['footprints'] = [
			// Below talk page messages and mentions, above the "someone edited a
			// page you watch" traffic: being read is good news, never urgent.
			'priority' => 6,
			'tooltip' => 'echo-pref-tooltip-footprints',
		];

		$notifications['footprints-read'] = [
			'category' => 'footprints',
			'group' => 'positive',
			// 'message', not 'alert': this belongs in the blue tray with the
			// thanks and milestones, not next to reverts and login warnings.
			'section' => 'message',
			'presentation-model' => EchoFootprintsPresentationModel::class,
			'user-locators' => [
				[
					'MediaWiki\\Extension\\Notifications\\UserLocator::locateFromEventExtra',
					[ 'recipients' ],
				],
			],
		];

		$notificationIcons['footprints'] = [
			'path' => 'Footprints/resources/footprints-progressive.svg',
		];
	}
}
