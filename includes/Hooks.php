<?php

namespace MediaWiki\Extension\Footprints;

use MediaWiki\Config\Config;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Html\Html;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Skin\Skin;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\ReadOnlyMode;

class Hooks implements BeforePageDisplayHook, PageDeleteCompleteHook {

	private Config $config;
	private FootprintStore $store;
	private UserGroupManager $userGroupManager;
	private ReadOnlyMode $readOnlyMode;
	private RevisionStore $revisionStore;
	private HookRunner $hookRunner;

	public function __construct(
		Config $config,
		FootprintStore $store,
		UserGroupManager $userGroupManager,
		ReadOnlyMode $readOnlyMode,
		RevisionStore $revisionStore,
		HookContainer $hookContainer
	) {
		$this->config = $config;
		$this->store = $store;
		$this->userGroupManager = $userGroupManager;
		$this->readOnlyMode = $readOnlyMode;
		$this->revisionStore = $revisionStore;
		$this->hookRunner = new HookRunner( $hookContainer );
	}

	/**
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		// isArticle() is only true when the page content itself is being shown.
		// History, diffs, edit previews, action=raw and special pages all fail it,
		// which is what keeps "editing a page" from leaving a footprint on it.
		if ( !$out->isArticle() ) {
			return;
		}

		$request = $out->getRequest();
		if ( ( $request->getRawVal( 'action' ) ?? 'view' ) !== 'view' || $request->getCheck( 'diff' ) ) {
			return;
		}

		$title = $out->getTitle();
		if ( !$title || !$title->exists() ) {
			return;
		}

		$pageId = $title->getArticleID();
		if ( $pageId <= 0 ) {
			return;
		}

		$namespaces = $this->config->get( 'FootprintsNamespaces' );
		if ( !in_array( $title->getNamespace(), $namespaces, true ) ) {
			return;
		}

		$user = $out->getUser();
		// Anonymous and temporary accounts have no stable identity to show.
		if ( !$user->isNamed() ) {
			return;
		}

		$excluded = $this->config->get( 'FootprintsExcludeGroups' );
		if ( $excluded && array_intersect(
			$excluded, $this->userGroupManager->getUserEffectiveGroups( $user )
		) ) {
			return;
		}

		$userId = $user->getId();

		$this->addIndicator( $out, $title->getPrefixedDBkey(), $pageId, $userId );

		if ( $this->readOnlyMode->isReadOnly() ) {
			return;
		}

		$store = $this->store;
		$cooldown = (int)$this->config->get( 'FootprintsCooldown' );
		// Writing on a GET request is only acceptable after the response has been
		// flushed, so the reader never waits on it. The milestone notification is
		// deliberately inside the same update: it needs to know whether this very
		// call added a new unique reader, which is something only record() knows.
		DeferredUpdates::addCallableUpdate(
			function () use ( $store, $pageId, $userId, $cooldown, $title, $user ) {
				$isNewReader = $store->record( $pageId, $userId, $cooldown, $counted );
				if ( $isNewReader ) {
					$this->notifyMilestone( $title, $user, $pageId, $userId );
				}
				if ( $counted ) {
					$this->hookRunner->onFootprintsViewCounted( $title, $user, $isNewReader );
				}
			},
			DeferredUpdates::POSTSEND
		);
	}

	/**
	 * Tell the person who started a page that it is being read.
	 *
	 * Called only when a reader who had never opened the page before has just
	 * been recorded, so "how many people have read this" has gone up by exactly
	 * one and we are the ones who moved it.
	 *
	 * ★ The count that decides a milestone leaves the page creator out.
	 *   Saving an edit redirects to the page view, so the creator is recorded as
	 *   reader number one on essentially every page they write. Measured against
	 *   the raw total, the "someone read it for the first time" notification —
	 *   the whole point of the feature — would never fire on any page, and every
	 *   later milestone would be reached one reader early. Counting other people
	 *   only also makes the number in the notification the true answer to the
	 *   question the recipient is actually asking.
	 *
	 * ★ Milestones, not every reader. On a small wiki a new page collects its
	 *   whole audience within a week or two, and a notification per reader would
	 *   turn into a stream nobody reads. See README for the full reasoning.
	 */
	private function notifyMilestone(
		Title $title, UserIdentity $reader, int $pageId, int $readerId
	): void {
		if ( !$this->config->get( 'FootprintsNotifyEnabled' )
			|| !ExtensionRegistry::getInstance()->isLoaded( 'Echo' )
		) {
			return;
		}

		$firstRev = $this->revisionStore->getFirstRevision( $title );
		if ( !$firstRev ) {
			return;
		}
		// RAW: a creator whose username is revision-deleted still gets told that
		// their page is being read. Echo will not show their name to anyone.
		$creator = $firstRev->getUser( RevisionRecord::RAW );
		if ( !$creator || !$creator->isRegistered() ) {
			return;
		}
		$creatorId = $creator->getId();

		// Reading your own page is not news. Echo drops the agent from the
		// recipients anyway; returning here also saves the two queries below.
		if ( $creatorId === $readerId ) {
			return;
		}

		$count = $this->store->countReaders( $pageId, $creatorId );
		if ( !in_array( $count, $this->config->get( 'FootprintsNotifyMilestones' ), true ) ) {
			return;
		}

		Event::create( [
			'type' => 'footprints-read',
			'title' => $title,
			'agent' => $reader,
			'extra' => [
				'count' => $count,
				// Read back by UserLocator::locateFromEventExtra. Echo has a
				// locateArticleCreator that would find the same person, but the
				// creator is already resolved here to work out the count, and
				// letting Echo look it up again would repeat the query.
				'recipients' => [ $creatorId ],
			],
		] );
	}

	/**
	 * Footprint icon and count, shown as a page status indicator (top right of
	 * the title in Vector 2022). The link works without JavaScript.
	 */
	private function addIndicator( OutputPage $out, string $dbKey, int $pageId, int $userId ): void {
		$count = $this->store->getDisplayCount( $pageId, $userId );
		$target = SpecialPage::getTitleFor( 'Footprints', $dbKey );

		$link = Html::rawElement( 'a', [
			'href' => $target->getLocalURL(),
			'class' => 'ext-footprints-indicator',
			'title' => $out->msg( 'footprints-indicator-tooltip' )->numParams( $count )->text(),
		],
			Html::element( 'span', [ 'class' => 'ext-footprints-icon' ] ) .
			Html::element(
				'span',
				[ 'class' => 'ext-footprints-count' ],
				$out->getLanguage()->formatNum( $count )
			)
		);

		$before = [];
		$this->hookRunner->onFootprintsIndicator( $out, $before );

		$out->setIndicators( [
			'footprints' => Html::rawElement(
				'span', [ 'class' => 'ext-footprints-group' ], implode( '', $before ) . $link
			),
		] );
		$out->addModuleStyles( [ 'ext.footprints.styles' ] );
		// Turns the link into a dialog. Without it the link still works.
		$out->addModules( [
			$this->config->get( 'FootprintsDialogStyle' ) === 'rich'
				? 'ext.footprints.rich'
				: 'ext.footprints.simple'
		] );
	}

	/**
	 * @param ProperPageIdentity $page
	 * @param Authority $deleter
	 * @param string $reason
	 * @param int $pageID
	 * @param RevisionRecord $deletedRev
	 * @param ManualLogEntry $logEntry
	 * @param int $archivedRevisionCount
	 */
	public function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	) {
		if ( $pageID <= 0 ) {
			return;
		}

		$store = $this->store;
		DeferredUpdates::addCallableUpdate(
			static function () use ( $store, $pageID ) {
				$store->deleteForPage( $pageID );
			}
		);
	}
}
