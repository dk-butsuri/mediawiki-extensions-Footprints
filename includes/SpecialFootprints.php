<?php

namespace MediaWiki\Extension\Footprints;

use MediaWiki\Config\Config;
use MediaWiki\Html\Html;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\UserLinkRenderer;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserIdentityValue;

/**
 * Special:Footprints[/page name]
 *
 * With a page name: who has read that page.
 * Without one: which pages have been read by the most different people.
 *
 * Anyone who can read the wiki can open this: the extension is meant for
 * closed wikis where reading already requires an account.
 */
class SpecialFootprints extends SpecialPage {

	private FootprintStore $store;
	private TitleFactory $titleFactory;
	private UserLinkRenderer $userLinkRenderer;
	private LinkRenderer $linkRenderer;
	private Config $config;

	public function __construct(
		FootprintStore $store,
		TitleFactory $titleFactory,
		UserLinkRenderer $userLinkRenderer,
		LinkRenderer $linkRenderer,
		Config $config
	) {
		parent::__construct( 'Footprints' );
		$this->store = $store;
		$this->titleFactory = $titleFactory;
		$this->userLinkRenderer = $userLinkRenderer;
		$this->linkRenderer = $linkRenderer;
		$this->config = $config;
	}

	/** @inheritDoc */
	public function getGroupName() {
		return 'wiki';
	}

	/** @inheritDoc */
	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();

		$par = trim( (string)( $par ?? $this->getRequest()->getText( 'page' ) ) );
		if ( $par === '' ) {
			$this->showTopPages();
			return;
		}

		$title = $this->titleFactory->newFromText( $par );
		if ( !$title || !$title->exists() ) {
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'footprints-nosuchpage', $par )->parse()
			) );
			return;
		}

		$this->showViewers( $title );
	}

	private function showViewers( $title ): void {
		$out = $this->getOutput();
		$lang = $this->getLanguage();

		$out->setPageTitleMsg( $this->msg( 'footprints-page-title', $title->getPrefixedText() ) );
		$out->addSubtitle( $this->msg( 'footprints-backtopage' )->rawParams(
			$this->linkRenderer->makeLink( $title )
		)->escaped() );

		$rows = $this->store->getViewers(
			$title->getArticleID(),
			(int)$this->config->get( 'FootprintsListLimit' )
		);

		if ( !$rows->numRows() ) {
			$out->addWikiMsg( 'footprints-empty' );
			return;
		}

		$html = Html::openElement( 'table', [ 'class' => 'wikitable sortable ext-footprints-table' ] );
		$html .= Html::rawElement( 'tr', [],
			Html::element( 'th', [], $this->msg( 'footprints-header-user' )->text() ) .
			Html::element( 'th', [], $this->msg( 'footprints-header-last' )->text() ) .
			Html::element( 'th', [], $this->msg( 'footprints-header-first' )->text() ) .
			Html::element( 'th', [], $this->msg( 'footprints-header-views' )->text() )
		);

		foreach ( $rows as $row ) {
			$user = UserIdentityValue::newRegistered( (int)$row->fp_user, $row->user_name );
			$html .= Html::rawElement( 'tr', [],
				Html::rawElement( 'td', [],
					$this->userLinkRenderer->userLink( $user, $this->getContext() ) ) .
				Html::element( 'td', [], $lang->userTimeAndDate( $row->fp_last, $this->getUser() ) ) .
				Html::element( 'td', [], $lang->userTimeAndDate( $row->fp_first, $this->getUser() ) ) .
				Html::element( 'td', [], $lang->formatNum( (int)$row->fp_views ) )
			);
		}

		$html .= Html::closeElement( 'table' );

		$out->addHTML( Html::rawElement( 'p', [],
			$this->msg( 'footprints-readercount' )->numParams( $rows->numRows() )->escaped()
		) );
		$out->addHTML( $html );
	}

	private function showTopPages(): void {
		$out = $this->getOutput();
		$lang = $this->getLanguage();

		$out->addWikiMsg( 'footprints-top-intro' );

		$rows = $this->store->getTopPages( 50 );
		if ( !$rows->numRows() ) {
			$out->addWikiMsg( 'footprints-empty' );
			return;
		}

		$html = Html::openElement( 'table', [ 'class' => 'wikitable sortable ext-footprints-table' ] );
		$html .= Html::rawElement( 'tr', [],
			Html::element( 'th', [], $this->msg( 'footprints-header-page' )->text() ) .
			Html::element( 'th', [], $this->msg( 'footprints-header-viewers' )->text() ) .
			Html::element( 'th', [], $this->msg( 'footprints-header-views' )->text() ) .
			Html::element( 'th', [], $this->msg( 'footprints-header-last' )->text() )
		);

		foreach ( $rows as $row ) {
			$title = $this->titleFactory->makeTitle( $row->page_namespace, $row->page_title );
			$html .= Html::rawElement( 'tr', [],
				Html::rawElement( 'td', [], $this->linkRenderer->makeLink( $title ) ) .
				Html::rawElement( 'td', [], $this->linkRenderer->makeLink(
					$this->getPageTitle( $title->getPrefixedDBkey() ),
					$lang->formatNum( (int)$row->viewers )
				) ) .
				Html::element( 'td', [], $lang->formatNum( (int)$row->views ) ) .
				Html::element( 'td', [], $lang->userTimeAndDate( $row->last, $this->getUser() ) )
			);
		}

		$html .= Html::closeElement( 'table' );
		$out->addHTML( $html );
	}
}
