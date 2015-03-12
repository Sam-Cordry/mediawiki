<?php

/**
 * API for MediaWiki 1.14+
 *
 * Created on Jun 18, 2012
 *
 * Copyright © 2012 Brad Jorsch
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
 */

/**
 * API interface for setting the wl_notificationtimestamp field
 * @ingroup API
 */
class ApiSetNotificationTimestamp extends ApiBase {

	private $mPageSet;

	public function execute() {
		$user = $this->getUser();

		if ( $user->isAnon() ) {
			$this->dieUsage( 'Anonymous users cannot use watchlist change notifications', 'notloggedin' );
		}
		if ( !$user->isAllowed( 'editmywatchlist' ) ) {
			$this->dieUsage( 'You don\'t have permission to edit your watchlist', 'permissiondenied' );
		}

		$params = $this->extractRequestParams();
		$this->requireMaxOneParameter( $params, 'timestamp', 'torevid', 'newerthanrevid' );

		$this->getResult()->beginContinuation( $params['continue'], array(), array() );

		$pageSet = $this->getPageSet();
		if ( $params['entirewatchlist'] && $pageSet->getDataSource() !== null ) {
			$this->dieUsage(
				"Cannot use 'entirewatchlist' at the same time as '{$pageSet->getDataSource()}'",
				'multisource'
			);
		}

		$dbw = wfGetDB( DB_MASTER, 'api' );

		$timestamp = null;
		if ( isset( $params['timestamp'] ) ) {
			$timestamp = $dbw->timestamp( $params['timestamp'] );
		}

		if ( !$params['entirewatchlist'] ) {
			$pageSet->execute();
		}

		if ( isset( $params['torevid'] ) ) {
			if ( $params['entirewatchlist'] || $pageSet->getGoodTitleCount() > 1 ) {
				$this->dieUsage( 'torevid may only be used with a single page', 'multpages' );
			}
			$title = reset( $pageSet->getGoodTitles() );
			if ( $title ) {
				$timestamp = Revision::getTimestampFromId(
					$title, $params['torevid'], Revision::READ_LATEST );
				if ( $timestamp ) {
					$timestamp = $dbw->timestamp( $timestamp );
				} else {
					$timestamp = null;
				}
			}
		} elseif ( isset( $params['newerthanrevid'] ) ) {
			if ( $params['entirewatchlist'] || $pageSet->getGoodTitleCount() > 1 ) {
				$this->dieUsage( 'newerthanrevid may only be used with a single page', 'multpages' );
			}
			$title = reset( $pageSet->getGoodTitles() );
			if ( $title ) {
				$revid = $title->getNextRevisionID(
					$params['newerthanrevid'], Title::GAID_FOR_UPDATE );
				if ( $revid ) {
					$timestamp = $dbw->timestamp( Revision::getTimestampFromId( $title, $revid ) );
				} else {
					$timestamp = null;
				}
			}
		}

		$apiResult = $this->getResult();
		$result = array();
		if ( $params['entirewatchlist'] ) {
			// Entire watchlist mode: Just update the thing and return a success indicator
			$dbw->update( 'watchlist', array( 'wl_notificationtimestamp' => $timestamp ),
				array( 'wl_user' => $user->getID() ),
				__METHOD__
			);

			$result['notificationtimestamp'] = is_null( $timestamp )
				? ''
				: wfTimestamp( TS_ISO_8601, $timestamp );
		} else {
			// First, log the invalid titles
			foreach ( $pageSet->getInvalidTitles() as $title ) {
				$r = array();
				$r['title'] = $title;
				$r['invalid'] = '';
				$result[] = $r;
			}
			foreach ( $pageSet->getMissingPageIDs() as $p ) {
				$page = array();
				$page['pageid'] = $p;
				$page['missing'] = '';
				$page['notwatched'] = '';
				$result[] = $page;
			}
			foreach ( $pageSet->getMissingRevisionIDs() as $r ) {
				$rev = array();
				$rev['revid'] = $r;
				$rev['missing'] = '';
				$rev['notwatched'] = '';
				$result[] = $rev;
			}

			if ( $pageSet->getTitles() ) {
				// Now process the valid titles
				$lb = new LinkBatch( $pageSet->getTitles() );
				$dbw->update( 'watchlist', array( 'wl_notificationtimestamp' => $timestamp ),
					array( 'wl_user' => $user->getID(), $lb->constructSet( 'wl', $dbw ) ),
					__METHOD__
				);

				// Query the results of our update
				$timestamps = array();
				$res = $dbw->select(
					'watchlist',
					array( 'wl_namespace', 'wl_title', 'wl_notificationtimestamp' ),
					array( 'wl_user' => $user->getID(), $lb->constructSet( 'wl', $dbw ) ),
					__METHOD__
				);
				foreach ( $res as $row ) {
					$timestamps[$row->wl_namespace][$row->wl_title] = $row->wl_notificationtimestamp;
				}

				// Now, put the valid titles into the result
				/** @var $title Title */
				foreach ( $pageSet->getTitles() as $title ) {
					$ns = $title->getNamespace();
					$dbkey = $title->getDBkey();
					$r = array(
						'ns' => intval( $ns ),
						'title' => $title->getPrefixedText(),
					);
					if ( !$title->exists() ) {
						$r['missing'] = '';
					}
					if ( isset( $timestamps[$ns] ) && array_key_exists( $dbkey, $timestamps[$ns] ) ) {
						$r['notificationtimestamp'] = '';
						if ( $timestamps[$ns][$dbkey] !== null ) {
							$r['notificationtimestamp'] = wfTimestamp( TS_ISO_8601, $timestamps[$ns][$dbkey] );
						}
					} else {
						$r['notwatched'] = '';
					}
					$result[] = $r;
				}
			}

			$apiResult->setIndexedTagName( $result, 'page' );
		}
		$apiResult->addValue( null, $this->getModuleName(), $result );

		$apiResult->endContinuation();
	}

	/**
	 * Get a cached instance of an ApiPageSet object
	 * @return ApiPageSet
	 */
	private function getPageSet() {
		if ( !isset( $this->mPageSet ) ) {
			$this->mPageSet = new ApiPageSet( $this );
		}

		return $this->mPageSet;
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	public function needsToken() {
		return 'csrf';
	}

	public function getAllowedParams( $flags = 0 ) {
		$result = array(
			'entirewatchlist' => array(
				ApiBase::PARAM_TYPE => 'boolean'
			),
			'timestamp' => array(
				ApiBase::PARAM_TYPE => 'timestamp'
			),
			'torevid' => array(
				ApiBase::PARAM_TYPE => 'integer'
			),
			'newerthanrevid' => array(
				ApiBase::PARAM_TYPE => 'integer'
			),
			'continue' => array(
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			),
		);
		if ( $flags ) {
			$result += $this->getPageSet()->getFinalParams( $flags );
		}

		return $result;
	}

	protected function getExamplesMessages() {
		return array(
			'action=setnotificationtimestamp&entirewatchlist=&token=123ABC'
				=> 'apihelp-setnotificationtimestamp-example-all',
			'action=setnotificationtimestamp&titles=Main_page&token=123ABC'
				=> 'apihelp-setnotificationtimestamp-example-page',
			'action=setnotificationtimestamp&titles=Main_page&' .
				'timestamp=2012-01-01T00:00:00Z&token=123ABC'
				=> 'apihelp-setnotificationtimestamp-example-pagetimestamp',
			'action=setnotificationtimestamp&generator=allpages&gapnamespace=2&token=123ABC'
				=> 'apihelp-setnotificationtimestamp-example-allpages',
		);
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/API:SetNotificationTimestamp';
	}
}
