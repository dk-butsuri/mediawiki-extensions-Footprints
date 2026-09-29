<?php

namespace MediaWiki\Extension\Footprints;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * How a footprint milestone reads in the notification tray.
 *
 * Two shapes, chosen by the number: the first person to read a page is an event
 * with a name attached ("X read your page"), every later milestone is a count
 * ("your page has been read by 10 people"). Naming the reader on the tenth
 * milestone would be misleading — they are simply whoever happened to arrive
 * when the counter crossed.
 */
class EchoFootprintsPresentationModel extends EchoEventPresentationModel {

	public function getIconType() {
		return 'footprints';
	}

	protected function getHeaderMessageKey() {
		// The following messages are used here:
		// * footprints-notification-header-first
		// * footprints-notification-header-milestone
		return (int)$this->event->getExtraParam( 'count' ) === 1
			? 'footprints-notification-header-first'
			: 'footprints-notification-header-milestone';
	}

	public function getHeaderMessage() {
		// $1/$2 are the agent's name and its GENDER form, added by the parent.
		$msg = $this->getMessageWithAgent( $this->getHeaderMessageKey() );
		$title = $this->event->getTitle();
		$msg->params( $title ? $this->getTruncatedTitleText( $title, true ) : '' );
		$msg->numParams( (int)$this->event->getExtraParam( 'count' ) );
		return $msg;
	}

	/**
	 * Echo would otherwise look for notification-subject-footprints-read, which
	 * does not exist, and put the raw message key in the email.
	 */
	public function getSubjectMessage() {
		return $this->getHeaderMessage();
	}

	public function getPrimaryLink() {
		$title = $this->event->getTitle();
		if ( !$title ) {
			return false;
		}

		return [
			'url' => $title->getLocalURL(),
			'label' => $this->msg( 'footprints-notification-link' )->text(),
		];
	}

	public function getSecondaryLinks() {
		$title = $this->event->getTitle();
		if ( !$title ) {
			return [];
		}

		// The list of who has read it — the question this notification puts in
		// the recipient's head, answered in one click.
		return [ [
			'url' => SpecialPage::getTitleFor(
				'Footprints', $title->getPrefixedDBkey()
			)->getLocalURL(),
			'label' => $this->msg( 'footprints-notification-link-list' )->text(),
			'icon' => 'userGroup',
			'prioritized' => true,
		] ];
	}
}
