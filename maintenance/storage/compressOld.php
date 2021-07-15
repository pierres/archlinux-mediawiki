<?php
/**
 * Compress the text of a wiki.
 *
 * Usage:
 *
 * Non-wikimedia
 * php compressOld.php [options...]
 *
 * Wikimedia
 * php compressOld.php <database> [options...]
 *
 * Options are:
 *  -t <type>           set compression type to either:
 *                          gzip: compress revisions independently
 *                          concat: concatenate revisions and compress in chunks (default)
 *  -c <chunk-size>     maximum number of revisions in a concat chunk
 *  -b <begin-date>     earliest date to check for uncompressed revisions
 *  -e <end-date>       latest revision date to compress
 *  -s <startid>        the id to start from (referring to the text table for
 *                      type gzip, and to the page table for type concat)
 *  -n <endid>          the page_id to stop at (only when using concat compression type)
 *  --extdb <cluster>   store specified revisions in an external cluster (untested)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance ExternalStorage
 */
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;

require_once __DIR__ . '/../Maintenance.php';

/**
 * Maintenance script that compress the text of a wiki.
 *
 * @ingroup Maintenance ExternalStorage
 */
class CompressOld extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Compress the text of a wiki' );
		$this->addOption( 'type', 'Set compression type to either: gzip|concat', false, true, 't' );
		$this->addOption(
			'chunksize',
			'Maximum number of revisions in a concat chunk',
			false,
			true,
			'c'
		);
		$this->addOption(
			'begin-date',
			'Earliest date to check for uncompressed revisions',
			false,
			true,
			'b'
		);
		$this->addOption( 'end-date', 'Latest revision date to compress', false, true, 'e' );
		$this->addOption(
			'startid',
			'The id to start from (gzip -> text table, concat -> page table)',
			false,
			true,
			's'
		);
		$this->addOption(
			'extdb',
			'Store specified revisions in an external cluster (untested)',
			false,
			true
		);
		$this->addOption(
			'endid',
			'The page_id to stop at (only when using concat compression type)',
			false,
			true,
			'n'
		);
	}

	public function execute() {
		global $wgDBname;
		if ( !function_exists( "gzdeflate" ) ) {
			$this->fatalError( "You must enable zlib support in PHP to compress old revisions!\n" .
				"Please see https://www.php.net/manual/en/ref.zlib.php\n" );
		}

		$type = $this->getOption( 'type', 'concat' );
		$chunkSize = $this->getOption( 'chunksize', 20 );
		$startId = $this->getOption( 'startid', 0 );
		$beginDate = $this->getOption( 'begin-date', '' );
		$endDate = $this->getOption( 'end-date', '' );
		$extDB = $this->getOption( 'extdb', '' );
		$endId = $this->getOption( 'endid', false );

		if ( $type != 'concat' && $type != 'gzip' ) {
			$this->error( "Type \"{$type}\" not supported" );
		}

		if ( $extDB != '' ) {
			$this->output( "Compressing database {$wgDBname} to external cluster {$extDB}\n"
				. str_repeat( '-', 76 ) . "\n\n" );
		} else {
			$this->output( "Compressing database {$wgDBname}\n"
				. str_repeat( '-', 76 ) . "\n\n" );
		}

		$success = true;
		if ( $type == 'concat' ) {
			$success = $this->compressWithConcat( $startId, $chunkSize, $beginDate,
				$endDate, $extDB, $endId );
		} else {
			$this->compressOldPages( $startId, $extDB );
		}

		if ( $success ) {
			$this->output( "Done.\n" );
		}
	}

	/**
	 * Fetch the text row-by-row to 'compressPage' function for compression.
	 *
	 * @param int $start
	 * @param string $extdb
	 */
	private function compressOldPages( $start = 0, $extdb = '' ) {
		$chunksize = 50;
		$this->output( "Starting from old_id $start...\n" );
		$dbw = $this->getDB( DB_MASTER );
		do {
			$res = $dbw->select(
				'text',
				[ 'old_id', 'old_flags', 'old_text' ],
				"old_id>=$start",
				__METHOD__,
				[ 'ORDER BY' => 'old_id', 'LIMIT' => $chunksize, 'FOR UPDATE' ]
			);

			if ( $res->numRows() == 0 ) {
				break;
			}

			$last = $start;

			foreach ( $res as $row ) {
				# print "  {$row->old_id} - {$row->old_namespace}:{$row->old_title}\n";
				$this->compressPage( $row, $extdb );
				$last = $row->old_id;
			}

			$start = $last + 1; # Deletion may leave long empty stretches
			$this->output( "$start...\n" );
		} while ( true );
	}

	/**
	 * Compress the text in gzip format.
	 *
	 * @param stdClass $row
	 * @param string $extdb
	 * @return bool
	 */
	private function compressPage( $row, $extdb ) {
		if ( strpos( $row->old_flags, 'gzip' ) !== false
			|| strpos( $row->old_flags, 'object' ) !== false
		) {
			# print "Already compressed row {$row->old_id}\n";
			return false;
		}
		$dbw = $this->getDB( DB_MASTER );
		$flags = $row->old_flags ? "{$row->old_flags},gzip" : "gzip";
		$compress = gzdeflate( $row->old_text );

		# Store in external storage if required
		if ( $extdb !== '' ) {
			$esFactory = MediaWikiServices::getInstance()->getExternalStoreFactory();
			/** @var ExternalStoreDB $storeObj */
			$storeObj = $esFactory->getStore( 'DB' );
			$compress = $storeObj->store( $extdb, $compress );
			if ( $compress === false ) {
				$this->error( "Unable to store object" );

				return false;
			}
		}

		# Update text row
		$dbw->update( 'text',
			[ /* SET */
				'old_flags' => $flags,
				'old_text' => $compress
			], [ /* WHERE */
				'old_id' => $row->old_id
			], __METHOD__,
			[ 'LIMIT' => 1 ]
		);

		return true;
	}

	/**
	 * Compress the text in chunks after concatenating the revisions.
	 *
	 * @param int $startId
	 * @param int $maxChunkSize
	 * @param string $beginDate
	 * @param string $endDate
	 * @param string $extdb
	 * @param bool|int $maxPageId
	 * @return bool
	 */
	private function compressWithConcat( $startId, $maxChunkSize, $beginDate,
		$endDate, $extdb = "", $maxPageId = false
	) {
		$dbr = $this->getDB( DB_REPLICA );
		$dbw = $this->getDB( DB_MASTER );

		# Set up external storage
		if ( $extdb != '' ) {
			$esFactory = MediaWikiServices::getInstance()->getExternalStoreFactory();
			/** @var ExternalStoreDB $storeObj */
			$storeObj = $esFactory->getStore( 'DB' );
		}

		$blobStore = MediaWikiServices::getInstance()
			->getBlobStoreFactory()
			->newSqlBlobStore();

		# Get all articles by page_id
		if ( !$maxPageId ) {
			$maxPageId = $dbr->selectField( 'page', 'max(page_id)', '', __METHOD__ );
		}
		$this->output( "Starting from $startId of $maxPageId\n" );
		$pageConds = [];

		/*
		if ( $exclude_ns0 ) {
			print "Excluding main namespace\n";
			$pageConds[] = 'page_namespace<>0';
		}
		if ( $queryExtra ) {
					$pageConds[] = $queryExtra;
		}
		 */

		# For each article, get a list of revisions which fit the criteria

		# No recompression, use a condition on old_flags
		# Don't compress object type entities, because that might produce data loss when
		# overwriting bulk storage concat rows. Don't compress external references, because
		# the script doesn't yet delete rows from external storage.
		$conds = [
			'old_flags NOT ' . $dbr->buildLike( $dbr->anyString(), 'object', $dbr->anyString() )
			. ' AND old_flags NOT '
			. $dbr->buildLike( $dbr->anyString(), 'external', $dbr->anyString() )
		];

		if ( $beginDate ) {
			if ( !preg_match( '/^\d{14}$/', $beginDate ) ) {
				$this->error( "Invalid begin date \"$beginDate\"\n" );

				return false;
			}
			$conds[] = "rev_timestamp>'" . $beginDate . "'";
		}
		if ( $endDate ) {
			if ( !preg_match( '/^\d{14}$/', $endDate ) ) {
				$this->error( "Invalid end date \"$endDate\"\n" );

				return false;
			}
			$conds[] = "rev_timestamp<'" . $endDate . "'";
		}

		$slotRoleStore = MediaWikiServices::getInstance()->getSlotRoleStore();
		$tables = [ 'revision', 'slots', 'content', 'text' ];
		$conds = array_merge( [
			'rev_id=slot_revision_id',
			'slot_role_id=' . $slotRoleStore->getId( SlotRecord::MAIN ),
			'content_id=slot_content_id',
			'SUBSTRING(content_address, 1, 3)=' . $dbr->addQuotes( 'tt:' ),
			'SUBSTRING(content_address, 4)=old_id',
		], $conds );

		$fields = [ 'rev_id', 'old_id', 'old_flags', 'old_text' ];
		$revLoadOptions = 'FOR UPDATE';

		# Don't work with current revisions
		# Don't lock the page table for update either -- TS 2006-04-04
		# $tables[] = 'page';
		# $conds[] = 'page_id=rev_page AND rev_id != page_latest';

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		for ( $pageId = $startId; $pageId <= $maxPageId; $pageId++ ) {
			$lbFactory->waitForReplication();

			# Wake up
			$dbr->ping();

			# Get the page row
			$pageRes = $dbr->select( 'page',
				[ 'page_id', 'page_namespace', 'page_title', 'page_latest' ],
				$pageConds + [ 'page_id' => $pageId ], __METHOD__ );
			if ( $pageRes->numRows() == 0 ) {
				continue;
			}
			$pageRow = $dbr->fetchObject( $pageRes );

			# Display progress
			$titleObj = Title::makeTitle( $pageRow->page_namespace, $pageRow->page_title );
			$this->output( "$pageId\t" . $titleObj->getPrefixedDBkey() . " " );

			# Load revisions
			$revRes = $dbw->select( $tables, $fields,
				array_merge( [
					'rev_page' => $pageRow->page_id,
					# Don't operate on the current revision
					# Use < instead of <> in case the current revision has changed
					# since the page select, which wasn't locking
					'rev_id < ' . (int)$pageRow->page_latest
				], $conds ),
				__METHOD__,
				$revLoadOptions
			);
			$revs = [];
			foreach ( $revRes as $revRow ) {
				$revs[] = $revRow;
			}

			if ( count( $revs ) < 2 ) {
				# No revisions matching, no further processing
				$this->output( "\n" );
				continue;
			}

			# For each chunk
			$i = 0;
			while ( $i < count( $revs ) ) {
				if ( $i < count( $revs ) - $maxChunkSize ) {
					$thisChunkSize = $maxChunkSize;
				} else {
					$thisChunkSize = count( $revs ) - $i;
				}

				$chunk = new ConcatenatedGzipHistoryBlob();
				$stubs = [];
				$this->beginTransaction( $dbw, __METHOD__ );
				$usedChunk = false;
				$primaryOldid = $revs[$i]->old_id;

				# Get the text of each revision and add it to the object
				for ( $j = 0; $j < $thisChunkSize && $chunk->isHappy(); $j++ ) {
					$oldid = $revs[$i + $j]->old_id;

					# Get text. We do not need the full `extractBlob` since the query is built
					# to fetch non-externalstore blobs.
					$text = $blobStore->decompressData(
						$revs[$i + $j]->old_text,
						explode( ',', $revs[$i + $j]->old_flags )
					);

					if ( $text === false ) {
						$this->error( "\nError, unable to get text in old_id $oldid" );
						# $dbw->delete( 'old', [ 'old_id' => $oldid ] );
					}

					if ( $extdb == "" && $j == 0 ) {
						$chunk->setText( $text );
						$this->output( '.' );
					} else {
						# Don't make a stub if it's going to be longer than the article
						# Stubs are typically about 100 bytes
						if ( strlen( $text ) < 120 ) {
							$stub = false;
							$this->output( 'x' );
						} else {
							$stub = new HistoryBlobStub( $chunk->addItem( $text ) );
							$stub->setLocation( $primaryOldid );
							$stub->setReferrer( $oldid );
							$this->output( '.' );
							$usedChunk = true;
						}
						$stubs[$j] = $stub;
					}
				}
				$thisChunkSize = $j;

				# If we couldn't actually use any stubs because the pages were too small, do nothing
				if ( $usedChunk ) {
					if ( $extdb != "" ) {
						# Move blob objects to External Storage
						$stored = $storeObj->store( $extdb, serialize( $chunk ) );
						if ( $stored === false ) {
							$this->error( "Unable to store object" );

							return false;
						}
						# Store External Storage URLs instead of Stub placeholders
						foreach ( $stubs as $stub ) {
							if ( $stub === false ) {
								continue;
							}
							# $stored should provide base path to a BLOB
							$url = $stored . "/" . $stub->getHash();
							$dbw->update( 'text',
								[ /* SET */
									'old_text' => $url,
									'old_flags' => 'external,utf-8',
								], [ /* WHERE */
									'old_id' => $stub->getReferrer(),
								],
								__METHOD__
							);
						}
					} else {
						# Store the main object locally
						$dbw->update( 'text',
							[ /* SET */
								'old_text' => serialize( $chunk ),
								'old_flags' => 'object,utf-8',
							], [ /* WHERE */
								'old_id' => $primaryOldid
							],
							__METHOD__
						);

						# Store the stub objects
						for ( $j = 1; $j < $thisChunkSize; $j++ ) {
							# Skip if not compressing and don't overwrite the first revision
							if ( $stubs[$j] !== false && $revs[$i + $j]->old_id != $primaryOldid ) {
								$dbw->update( 'text',
									[ /* SET */
										'old_text' => serialize( $stubs[$j] ),
										'old_flags' => 'object,utf-8',
									], [ /* WHERE */
										'old_id' => $revs[$i + $j]->old_id
									],
									__METHOD__
								);
							}
						}
					}
				}
				# Done, next
				$this->output( "/" );
				$this->commitTransaction( $dbw, __METHOD__ );
				$i += $thisChunkSize;
			}
			$this->output( "\n" );
		}

		return true;
	}
}

$maintClass = CompressOld::class;
require_once RUN_MAINTENANCE_IF_MAIN;
