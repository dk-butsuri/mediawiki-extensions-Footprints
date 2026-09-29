<?php

namespace MediaWiki\Extension\Footprints;

use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Config\Config;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Title\TitleFactory;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

/**
 * action=query&list=footprints&fppage=...
 *
 * Feeds the dialog that opens when the footprint indicator is clicked.
 * Same viewer rows as Special:Footprints, so the two never disagree; the
 * comparison against the latest revision and the daily chart exist only here.
 */
class ApiQueryFootprints extends ApiQueryBase {

	private FootprintStore $store;
	private TitleFactory $titleFactory;
	private Config $config;
	private RevisionStore $revisionStore;

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		FootprintStore $store,
		TitleFactory $titleFactory,
		Config $config,
		RevisionStore $revisionStore
	) {
		parent::__construct( $query, $moduleName, 'fp' );
		$this->store = $store;
		$this->titleFactory = $titleFactory;
		$this->config = $config;
		$this->revisionStore = $revisionStore;
	}

	public function execute() {
		$params = $this->extractRequestParams();

		$title = $this->titleFactory->newFromText( $params['page'] );
		if ( !$title || !$title->exists() ) {
			$this->dieWithError( [ 'apierror-missingtitle' ] );
		}

		$this->checkTitleUserPermissions( $title, 'read' );
		$pageId = $title->getArticleID();

		$limit = min( (int)$params['limit'], (int)$this->config->get( 'FootprintsListLimit' ) );
		$rows = $this->store->getViewers( $pageId, $limit );

		// RAW: a revision-deleted editor's name still drives "read since the
		// last edit" and the last-editor badge. Nothing here shows the name to
		// anyone it shouldn't -- it is compared against viewer ids, not output,
		// except updatedBy below which mirrors what page history already shows.
		$latestRev = $this->revisionStore->getRevisionByTitle( $title );
		$updatedAt = $latestRev ? $latestRev->getTimestamp() : null;
		$latestUser = $latestRev ? $latestRev->getUser( RevisionRecord::RAW ) : null;
		$lastEditorId = $latestUser ? $latestUser->getId() : 0;

		$firstRev = $this->revisionStore->getFirstRevision( $title );
		$firstUser = $firstRev ? $firstRev->getUser( RevisionRecord::RAW ) : null;
		$ownerId = $firstUser ? $firstUser->getId() : 0;

		$currentUserId = $this->getUser()->getId();
		$regularViews = (int)$this->config->get( 'FootprintsRegularViews' );

		$result = [];
		$readAfterUpdate = 0;
		foreach ( $rows as $row ) {
			$last = wfTimestamp( TS_ISO_8601, $row->fp_last );
			// The last editor has seen the version they wrote, even when no view
			// after it was recorded: VisualEditor saves in place without
			// reloading the page, so their fp_last stays just before the save.
			$isRead = $updatedAt === null || $row->fp_last >= $updatedAt
				|| ( $lastEditorId > 0 && (int)$row->fp_user === $lastEditorId );
			if ( $isRead ) {
				$readAfterUpdate++;
			}

			$result[] = [
				'userid' => (int)$row->fp_user,
				'user' => $row->user_name,
				'views' => (int)$row->fp_views,
				'first' => wfTimestamp( TS_ISO_8601, $row->fp_first ),
				'last' => $last,
				'isYou' => ( (int)$row->fp_user === $currentUserId ),
				'isOwner' => ( $ownerId > 0 && (int)$row->fp_user === $ownerId ),
				'isLastEditor' => ( $lastEditorId > 0 && (int)$row->fp_user === $lastEditorId ),
				'isRegular' => ( (int)$row->fp_views >= $regularViews ),
				'readAfterUpdate' => $isRead,
			];
		}

		$this->getResult()->addValue(
			[ 'query' ],
			$this->getModuleName(),
			[
				'title' => $title->getPrefixedText(),
				'count' => count( $result ),
				'totalViews' => array_sum( array_column( $result, 'views' ) ),
				'updatedAt' => $updatedAt ? wfTimestamp( TS_ISO_8601, $updatedAt ) : null,
				'updatedBy' => $latestUser ? $latestUser->getName() : null,
				'readAfterUpdate' => $readAfterUpdate,
				'chart' => $this->store->getDailyActivity(
					$pageId, 14, (string)$this->config->get( 'Localtimezone' )
				),
				'viewers' => $result,
			]
		);
		$this->getResult()->addIndexedTagName(
			[ 'query', $this->getModuleName(), 'viewers' ], 'viewer'
		);
		$this->getResult()->addIndexedTagName(
			[ 'query', $this->getModuleName(), 'chart' ], 'day'
		);
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'page' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'limit' => [
				ParamValidator::PARAM_TYPE => 'limit',
				ParamValidator::PARAM_DEFAULT => 200,
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => 500,
				IntegerDef::PARAM_MAX2 => 500,
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=query&list=footprints&fppage=' . wfUrlencode( wfMessage( 'mainpage' )->inContentLanguage()->text() )
				=> 'apihelp-query+footprints-example-simple',
		];
	}

	/** @inheritDoc */
	public function isInternal() {
		return false;
	}

	/** @inheritDoc */
	public function getCacheMode( $params ) {
		return 'private';
	}
}
