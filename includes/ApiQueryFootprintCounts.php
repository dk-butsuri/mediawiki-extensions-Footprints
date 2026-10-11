<?php

namespace MediaWiki\Extension\Footprints;

use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Title\TitleFactory;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

class ApiQueryFootprintCounts extends ApiQueryBase {

	private FootprintStore $store;
	private TitleFactory $titleFactory;

	public function __construct( ApiQuery $query, string $moduleName, FootprintStore $store,
		TitleFactory $titleFactory
	) {
		parent::__construct( $query, $moduleName, 'fpc' );
		$this->store = $store;
		$this->titleFactory = $titleFactory;
	}

	public function execute() {
		$params = $this->extractRequestParams();
		$title = null;
		if ( $params['page'] !== null ) {
			$title = $this->titleFactory->newFromText( $params['page'] );
			if ( !$title || !$title->exists() ) {
				$this->dieWithError( 'apierror-missingtitle' );
			}
			$this->checkTitleUserPermissions( $title, 'read' );
		}
		$limit = (int)$params['limit'];
		$rows = $this->store->getAggregateCounts(
			$title ? $title->getArticleID() : null, (int)$params['namespace'],
			$params['excludegroups'], (int)$params['continue'], $limit + 1
		);
		$result = [];
		$seen = 0;
		foreach ( $rows as $row ) {
			if ( $seen++ === $limit ) {
				$this->setContinueEnumParameter( 'continue', $lastId );
				break;
			}
			$lastId = (int)$row->page_id;
			$page = $this->titleFactory->makeTitle( (int)$row->page_namespace, $row->page_title );
			if ( !$this->getPermissionManager()->userCan( 'read', $this->getUser(), $page ) ) {
				continue;
			}
			$result[] = [ 'pageid' => $lastId, 'ns' => (int)$row->page_namespace,
				'title' => $page->getPrefixedText(), 'readers' => (int)$row->readers,
				'views' => (int)$row->views ];
		}
		if ( $title && !$result ) {
			$result[] = [ 'pageid' => $title->getArticleID(), 'ns' => $title->getNamespace(),
				'title' => $title->getPrefixedText(), 'readers' => 0, 'views' => 0 ];
		}
		$this->getResult()->addValue( [ 'query' ], $this->getModuleName(), $result );
		$this->getResult()->addIndexedTagName( [ 'query', $this->getModuleName() ], 'page' );
	}

	public function getAllowedParams() {
		return [
			'page' => [ ParamValidator::PARAM_TYPE => 'string' ],
			'namespace' => [ ParamValidator::PARAM_TYPE => 'namespace', ParamValidator::PARAM_DEFAULT => 0 ],
			'excludegroups' => [ ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_ISMULTI => true, ParamValidator::PARAM_DEFAULT => [] ],
			'continue' => [ ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_DEFAULT => 0, IntegerDef::PARAM_MIN => 0 ],
			'limit' => [ ParamValidator::PARAM_TYPE => 'limit', ParamValidator::PARAM_DEFAULT => 50,
				IntegerDef::PARAM_MIN => 1, IntegerDef::PARAM_MAX => 500, IntegerDef::PARAM_MAX2 => 500 ],
		];
	}

	protected function getExamplesMessages() {
		return [ 'action=query&list=footprintcounts&fpcnamespace=0' => 'apihelp-query+footprintcounts-example-simple' ];
	}

	public function getCacheMode( $params ) {
		return 'private';
	}
}
