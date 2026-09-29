<?php

namespace MediaWiki\Extension\Footprints;

use DateTimeImmutable;
use DateTimeZone;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IResultWrapper;
use Wikimedia\Rdbms\RawSQLValue;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * All database access for the footprint and footprint_log tables lives here.
 *
 * footprint has one row per (page, user) pair, so the number of unique
 * viewers of a page is simply COUNT(*) over fp_page. footprint_log is
 * append-only, one row per counted view, for callers that need to know when
 * views happened rather than just the latest one per person.
 */
class FootprintStore {

	public const TABLE = 'footprint';
	public const TABLE_LOG = 'footprint_log';

	private IConnectionProvider $dbProvider;

	public function __construct( IConnectionProvider $dbProvider ) {
		$this->dbProvider = $dbProvider;
	}

	/**
	 * Record one view. Called from a POSTSEND deferred update, never inline.
	 *
	 * A single upsert rather than SELECT-then-UPDATE, so concurrent views on the
	 * same page cannot lose an increment.
	 *
	 * Views within $cooldown seconds of the last counted one don't add to
	 * fp_views, so reloading a page does not inflate the count. fp_last still
	 * moves to now regardless — it means "last seen", not "last counted view" —
	 * which makes the cooldown a sliding idle window: a page kept open and
	 * refreshed continuously never counts again as long as the gaps stay under
	 * $cooldown, and only counts once more after a real gap that long.
	 *
	 * @return bool True when this call added a reader who had never opened the
	 *   page before, i.e. the unique viewer count just went up by one. The
	 *   milestone notification hangs off this and nothing else — a reload or a
	 *   second visit must never be able to trigger it.
	 *
	 *   The question is asked with an explicit SELECT rather than by reading
	 *   affectedRows() after the upsert. INSERT ... ON DUPLICATE KEY UPDATE does
	 *   report 1-for-insert / 2-for-update on MariaDB, but every other backend
	 *   the Rdbms layer can emit that statement for reports something different,
	 *   and a wrong answer here means notifications sent for page reloads. The
	 *   extra query is a primary key lookup and runs POSTSEND.
	 * @param-out bool $counted Whether this view went into fp_views and
	 *   footprint_log, i.e. whether the FootprintsViewCounted hook runs for it.
	 */
	public function record( int $pageId, int $userId, int $cooldown = 0, ?bool &$counted = null ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$now = $dbw->timestamp();

		$existingLast = $dbw->newSelectQueryBuilder()
			->select( 'fp_last' )
			->from( self::TABLE )
			->where( [ 'fp_page' => $pageId, 'fp_user' => $userId ] )
			->caller( __METHOD__ )
			->fetchField();

		$isNewReader = ( $existingLast === false );
		// Same rule the UPSERT below applies to fp_views/fp_last, decided here
		// in PHP instead so record() also knows whether to log this view. A
		// concurrent request landing between this SELECT and the UPSERT could
		// in principle disagree with the CASE WHEN below, but at the traffic
		// this extension is meant for that race is not worth a second round trip.
		$isCounted = $isNewReader
			|| $cooldown <= 0
			|| $existingLast < $dbw->timestamp( (int)ConvertibleTimestamp::time() - $cooldown );
		$counted = $isCounted;

		if ( $cooldown > 0 ) {
			$cutoff = $dbw->addQuotes( $dbw->timestamp( (int)ConvertibleTimestamp::time() - $cooldown ) );
			// fp_views must be listed before fp_last: MySQL evaluates a SET list
			// left to right, so this CASE WHEN still reads the pre-update fp_last.
			// If fp_last were reassigned first, this would always see $now (never
			// < $cutoff) and stop counting anything, ever. fp_last itself is
			// unconditional -- see the "sliding idle window" note above.
			$update = [
				'fp_views' => new RawSQLValue( "fp_views + CASE WHEN fp_last < $cutoff THEN 1 ELSE 0 END" ),
				'fp_last' => $now,
			];
		} else {
			$update = [
				'fp_views' => new RawSQLValue( 'fp_views + 1' ),
				'fp_last' => $now,
			];
		}

		// footprint and footprint_log are two views of the same event: the
		// current per-(page,user) state and the append-only history behind it.
		// Wrapped together so a mid-request failure can never commit one write
		// without the other and leave the two disagreeing.
		$dbw->startAtomic( __METHOD__ );
		try {
			$dbw->newInsertQueryBuilder()
				->insertInto( self::TABLE )
				->row( [
					'fp_page' => $pageId,
					'fp_user' => $userId,
					'fp_views' => 1,
					'fp_first' => $now,
					'fp_last' => $now,
				] )
				->onDuplicateKeyUpdate()
				->uniqueIndexFields( [ 'fp_page', 'fp_user' ] )
				->set( $update )
				->caller( __METHOD__ )
				->execute();

			if ( $isCounted ) {
				$dbw->newInsertQueryBuilder()
					->insertInto( self::TABLE_LOG )
					->row( [
						'fl_page' => $pageId,
						'fl_user' => $userId,
						'fl_timestamp' => $now,
					] )
					->caller( __METHOD__ )
					->execute();
			}
			$dbw->endAtomic( __METHOD__ );
		} catch ( \Throwable $e ) {
			$dbw->cancelAtomic( __METHOD__ );
			throw $e;
		}

		return $isNewReader;
	}

	/**
	 * Number of distinct people who have read a page.
	 *
	 * Unlike getDisplayCount() this is the plain truth in the table: it does not
	 * add the current viewer in, because the caller (the milestone notification)
	 * runs after record() has already written that row.
	 *
	 * Reads the primary, not a replica: the only caller runs immediately after
	 * record() has inserted the row it needs to see, and on a replica that row
	 * may not have arrived yet. A milestone counted one short is a notification
	 * that never fires, because the count never lands on that number again.
	 *
	 * @param int $pageId
	 * @param int $excludeUserId Leave this user out of the count. 0 counts everyone.
	 * @return int
	 */
	public function countReaders( int $pageId, int $excludeUserId = 0 ): int {
		$dbr = $this->dbProvider->getPrimaryDatabase();

		$conds = [ 'fp_page' => $pageId ];
		if ( $excludeUserId > 0 ) {
			$conds[] = $dbr->expr( 'fp_user', '!=', $excludeUserId );
		}

		return (int)$dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( self::TABLE )
			->where( $conds )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * Number of footprints on a page, as it should be displayed to $userId.
	 *
	 * The viewer's own visit is written after the response is sent, so on a first
	 * visit the row does not exist yet. Counting it here keeps the number from
	 * looking stale to the person who just arrived.
	 *
	 * @return int
	 */
	public function getDisplayCount( int $pageId, int $userId ): int {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$row = $dbr->newSelectQueryBuilder()
			->select( [
				'total' => 'COUNT(*)',
				'mine' => 'SUM( CASE WHEN fp_user = ' . (int)$userId . ' THEN 1 ELSE 0 END )',
			] )
			->from( self::TABLE )
			->where( [ 'fp_page' => $pageId ] )
			->caller( __METHOD__ )
			->fetchRow();

		if ( !$row ) {
			return 1;
		}

		return (int)$row->total + ( (int)$row->mine > 0 ? 0 : 1 );
	}

	/**
	 * Everyone who has visited one page, most recent visit first.
	 */
	public function getViewers( int $pageId, int $limit ): IResultWrapper {
		$dbr = $this->dbProvider->getReplicaDatabase();

		return $dbr->newSelectQueryBuilder()
			->select( [ 'fp_user', 'fp_views', 'fp_first', 'fp_last', 'user_name' ] )
			->from( self::TABLE )
			->join( 'user', null, 'user_id = fp_user' )
			->where( [ 'fp_page' => $pageId ] )
			->orderBy( 'fp_last', SelectQueryBuilder::SORT_DESC )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();
	}

	/**
	 * Pages ordered by how many different people have read them.
	 */
	public function getTopPages( int $limit ): IResultWrapper {
		$dbr = $this->dbProvider->getReplicaDatabase();

		return $dbr->newSelectQueryBuilder()
			->select( [
				'page_namespace',
				'page_title',
				'viewers' => 'COUNT(*)',
				'views' => 'SUM( fp_views )',
				'last' => 'MAX( fp_last )',
			] )
			->from( self::TABLE )
			->join( 'page', null, 'page_id = fp_page' )
			->groupBy( [ 'fp_page', 'page_namespace', 'page_title' ] )
			->orderBy( 'viewers', SelectQueryBuilder::SORT_DESC )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();
	}

	/**
	 * View counts for one page, one bucket per day, oldest first, zero-filled.
	 *
	 * Bucketed in $timezone rather than the UTC fl_timestamp is stored in, so
	 * "today" lines up with the wiki's own midnight. Done in PHP on the raw
	 * rows rather than with SQL date functions, because mwtimestamp columns
	 * are plain YYYYMMDDHHMMSS strings, not a real temporal type, and
	 * CONVERT_TZ() would depend on the server's time zone tables being loaded.
	 *
	 * @param int $pageId
	 * @param int $days Window size including today
	 * @param string $timezone A PHP time zone name, normally $wgLocaltimezone
	 * @return array<int, array{date: string, views: int}> $days entries, date as Ymd
	 */
	public function getDailyActivity( int $pageId, int $days, string $timezone ): array {
		$dbr = $this->dbProvider->getReplicaDatabase();
		try {
			$tz = new DateTimeZone( $timezone );
		} catch ( \Exception $e ) {
			$tz = new DateTimeZone( 'UTC' );
		}
		$now = (int)ConvertibleTimestamp::time();
		// One extra day of rows, so the oldest bucket is complete however far
		// the zone is from UTC.
		$since = $dbr->timestamp( $now - ( $days + 1 ) * 86400 );

		$timestamps = $dbr->newSelectQueryBuilder()
			->select( 'fl_timestamp' )
			->from( self::TABLE_LOG )
			->where( [ 'fl_page' => $pageId ] )
			->andWhere( $dbr->expr( 'fl_timestamp', '>=', $since ) )
			->caller( __METHOD__ )
			->fetchFieldValues();

		$byDay = [];
		foreach ( $timestamps as $ts ) {
			$day = ( new DateTimeImmutable( '@' . ConvertibleTimestamp::convert( TS_UNIX, $ts ) ) )
				->setTimezone( $tz )->format( 'Ymd' );
			$byDay[$day] = ( $byDay[$day] ?? 0 ) + 1;
		}

		$today = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
		$out = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day = $today->modify( "-$i days" )->format( 'Ymd' );
			$out[] = [ 'date' => $day, 'views' => $byDay[$day] ?? 0 ];
		}

		return $out;
	}

	/**
	 * Drop every footprint on a page. Called when the page is deleted, otherwise
	 * the rows would stay behind forever keyed to a page_id nothing points at.
	 */
	public function deleteForPage( int $pageId ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->startAtomic( __METHOD__ );
		try {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( self::TABLE )
				->where( [ 'fp_page' => $pageId ] )
				->caller( __METHOD__ )
				->execute();

			$dbw->newDeleteQueryBuilder()
				->deleteFrom( self::TABLE_LOG )
				->where( [ 'fl_page' => $pageId ] )
				->caller( __METHOD__ )
				->execute();
			$dbw->endAtomic( __METHOD__ );
		} catch ( \Throwable $e ) {
			$dbw->cancelAtomic( __METHOD__ );
			throw $e;
		}
	}
}
