<?php

/**
 * This file is part of the MediaWiki extension PageOwnership.
 *
 * PageOwnership is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * PageOwnership is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PageOwnership.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @file
 * @ingroup extensions
 * @author thomas-topway-it <thomas.topway.it@mail.com>
 * @copyright Copyright ©2021-2022, https://wikisphere.org
 */

use MediaWiki\MediaWikiServices;

class PageOwnership {
	/** @var UserPagesCache */
	public static $UserPagesCache = [];
	/** @var permissionsCache */
	public static $permissionsCache = [];
	/** @var OwnedPagesCache */
	public static $OwnedPagesCache = [];
	/** @var User */
	public static $User;
	/** @var userGroupManager */
	public static $userGroupManager;
	/** @var Parser */
	public static $Parser;

	/**
	 * @param User|null $user
	 */
	public static function initialize( $user ) {
		self::$User = $user;
		self::$userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();
		self::$Parser = MediaWikiServices::getInstance()->getParser();
	}

	/**
	 * @return User|null
	 */
	public static function getUser() {
		if ( self::$User instanceof MediaWiki\User ) {
			return self::$User;
		}
		return RequestContext::getMain()->getUser();
	}

	/**
	 * @return MediaWiki\User\UserGroupManager|null
	 */
	public static function getUserGroupManager() {
		if ( self::$userGroupManager instanceof MediaWiki\User\UserGroupManager ) {
			return self::$userGroupManager;
		}
		return MediaWikiServices::getInstance()->getUserGroupManager();
	}

	/**
	 * @see includes/Title.php
	 * *** the standard method fails when $row->page_title is null
	 * @param Title $title
	 * @param array $options
	 * @param string $table
	 * @param string $prefix
	 * @return void
	 */
	public static function getLinksTo( $title, $options = [], $table = 'pagelinks', $prefix = 'pl' ) {
		if ( count( $options ) > 0 ) {
			$db = wfGetDB( DB_PRIMARY );
		} else {
			$db = wfGetDB( DB_REPLICA );
		}

		$res = $db->select(
			[ 'page', $table ],
			LinkCache::getSelectFields(),
			[
				"{$prefix}_from=page_id",
				// ***edited
				"{$prefix}_namespace" => $title->getNamespace(),
				"{$prefix}_title" => $title->getDBkey() ],
			__METHOD__,
			$options
		);

		$retVal = [];
		if ( $res->numRows() ) {
			$linkCache = MediaWikiServices::getInstance()->getLinkCache();
			foreach ( $res as $row ) {
				// ***edited
				// $titleObj = self::makeTitle( $row->page_namespace, $row->page_title );
				$titleObj = Title::newFromID( $row->page_id );
				if ( $titleObj ) {
					// $linkCache->addGoodLinkObjFromRow( $titleObj, $row );
					$retVal[] = $titleObj;
				}
			}
		}
		return $retVal;
	}

	/**
	 * *** invalidate cache of all pages in which this page has been transcluded
	 * @param Title $title
	 * @return void
	 */
	public static function invalidateCacheOfPagesWithTemplateLinksTo( $title ) {
		$context = RequestContext::getMain();
		$config = $context->getConfig();
		$options = [ 'LIMIT' => $config->get( 'PageInfoTransclusionLimit' ) ];

		// $transcludedTargets = $title->getTemplateLinksTo( $options );

		$transcludedTargets = self::getLinksTo( $title, $options, 'templatelinks', 'tl' );

		foreach ( $transcludedTargets as $title_ ) {
			$title_->invalidateCache();
		}
	}

	/**
	 * @param Title $title
	 * @return void
	 */
	public static function invalidateCacheOfPagesWithAskQueriesRelatedToTitle( $title ) {
		if ( !defined( 'SMW_VERSION' ) ) {
			return;
		}

		// @see extensions/SemanticMediaWiki/src/MediaWiki/Hooks.php
		$queryDependencyLinksStoreFactory = SMW\ApplicationFactory::getInstance()
			->singleton( 'QueryDependencyLinksStoreFactory' );

		$store = \SMW\StoreFactory::getStore();

		$queryDependencyLinksStore = $queryDependencyLinksStoreFactory->newQueryDependencyLinksStore(
			$store
		);

		$subject = new SMW\DIWikiPage( $title, NS_MAIN );

		$requestOptions = new \SMWRequestOptions();
		$requestOptions->limit = 500;

		// @see extensions/SemanticMediaWiki/src/SQLStore/QueryDependency/QueryReferenceBacklinks.php

		// Don't display a reference where the requesting page is
		// part of the list that contains queries (suppress self-embedded queries)
		foreach ( $queryDependencyLinksStore->findEmbeddedQueryIdListBySubject( $subject ) as $key => $qid ) {
			$requestOptions->addExtraCondition( 's_id!=' . $qid );
		}

		$queryReferenceBacklinks = $queryDependencyLinksStoreFactory->newQueryReferenceBacklinks(
			$store
		);

		$referenceLinks = $queryReferenceBacklinks->findReferenceLinks( $subject, $requestOptions );

		$property = new SMW\DIProperty(
			'_ASK'
		);

		foreach ( $referenceLinks as $subject ) {
			$title_ = $subject->getTitle();
			$title_->invalidateCache();
		}
	}

	/**
	 * @see extension lockdown/src/Hooks
	 * @param array $groups
	 * @return array
	 */
	public static function getGroupLinks( $groups ) {
		$links = [];
		foreach ( $groups as $group ) {
			$links[] = UserGroupMembership::getLink(
				$group, RequestContext::getMain(), 'wiki'
			);
		}
		return $links;
	}

	/**
	 * @param string $creatorUsername
	 * @param Title $title
	 * @param array $usernames
	 * @param array $permissions
	 * @param string $role
	 * @param int|null $id
	 * @return bool
	 */
	public static function setPageOwnership( $creatorUsername, $title, $usernames, $permissions, $role, $id = null ) {
		$pageId = $title->getArticleID();
		$dbr = wfGetDB( DB_MASTER );

		if ( !count( $usernames ) ) {
			return false;
		}

		$row = [
			'created_by' => $creatorUsername,
			'usernames' => implode( ',', $usernames ),
			'page_id' => $pageId,
			'permissions' => implode( ',', $permissions ),
			'role' => $role,
		];

		if ( !$id ) {
			$date = date( 'Y-m-d H:i:s' );
			$res = $dbr->insert( 'page_ownership', $row + [ 'updated_at' => $date, 'created_at' => $date ] );

		} else {
			$res = $dbr->update( 'page_ownership', $row, [ 'id' => $id ], __METHOD__ );
		}

		return $res;
	}

	/**
	 * @param array $conds
	 * @return void
	 */
	public static function deleteOwnershipData( $conds ) {
		$dbw = wfGetDB( DB_PRIMARY );

		$dbw->delete(
			'page_ownership',  $conds,
			__METHOD__
		);
	}

	/**
	 * @param Title $title
	 * @return bool
	 */
	public static function isOwned( $title ) {
		$cache_key = $title->getText();

		if ( array_key_exists( $cache_key, self::$OwnedPagesCache ) ) {
			return self::$OwnedPagesCache[ $cache_key ];
		}

		$dbr = wfGetDB( DB_REPLICA );

		$page_ancestors = self::page_ancestors( $title, false );

		$page_ancestors = array_reverse( $page_ancestors );

		// closest (deepest) first
		foreach ( $page_ancestors as $key => $title_ ) {
			$conds = [ 'page_id' => $title_->getArticleID() ];

			if ( $key > 0 ) {
				$conds[] = 'FIND_IN_SET(' . $dbr->addQuotes( 'subpages' ) . ', permissions)';
			}

			$row = $dbr->selectRow(
				'page_ownership',
				'*',
				$conds,
				__METHOD__
			);

			if ( $row && $row !== [ false ] ) {
				self::$OwnedPagesCache[ $cache_key ] = true;
				return true;
			}
		}

		self::$OwnedPagesCache[ $cache_key ] = false;
		return false;
	}

	/**
	 * @param User $user
	 * @return array
	 */
	public static function getUserPagesDB( $user ) {
		$cache_key = $user->getName();

		if ( array_key_exists( $cache_key, self::$UserPagesCache ) ) {
			return self::$UserPagesCache[ $cache_key ];
		}

		$dbr = wfGetDB( DB_REPLICA );

		$conds = self::groupsCond( $dbr, $user );

		$rows = $dbr->select( 'page_ownership', '*', $conds );

		$output = [];

		foreach ( $rows as $row ) {
			$row = (array)$row;

			if ( !array_key_exists( $row[ 'page_id' ], $output ) ) {
				$output[ $row[ 'page_id' ] ] = $row;
			}
		}

		self::$UserPagesCache[ $cache_key ] = $output;

		return self::$UserPagesCache[ $cache_key ];
	}

	/**
	 * @param Parser $parser
	 * @param string $param1
	 * @return string
	 */
	public static function pageownership_userpages( Parser $parser, $param1 = '' ) {
		$user = $parser->getUserIdentity() ?? self::getUser();

		$pages = self::userpages( $user );

		return implode( ',', $pages );
	}

	/**
	 * @param User $user
	 * @return array
	 */
	public static function userpages( $user ) {
		if ( !$user->isRegistered() ) {
			return [];
		}

		$pages = self::getUserPagesDB( $user );

		$pages = array_filter( array_map( static function ( $value ) {
					$title = Title::newFromID( $value['page_id'] );
					if ( $title ) {
						return $title->getText();
					}
		}, $pages ), static function ( $value ) {
			return !empty( $value );
		} );

		return $pages;
	}

	/**
	 * @param \Wikimedia\Rdbms\DBConnRef $dbr
	 * @param User $user
	 * @return array
	 */
	public static function groupsCond( $dbr, $user ) {
		$userGroupManager = self::getUserGroupManager();
		$user_groups = self::getUserGroups( $userGroupManager, $user, true );
		$user_groups[] = $user->getName();

		$conds = [];
		array_map( static function ( $value ) use ( &$conds, $dbr ) {
			$conds[] = 'FIND_IN_SET(' . $dbr->addQuotes( $value ) . ', usernames)';
		}, $user_groups );

		return [ $dbr->makeList( $conds, LIST_OR ) ];
	}

	/**
	 * @param Title $title
	 * @param User $user
	 * @return array
	 */
	public static function permissionsOfPage( $title, $user ) {
		// $hash = md5();

		$cache_key = $title->getText() . $user->getName();

		if ( array_key_exists( $cache_key, self::$permissionsCache ) ) {
			return self::$permissionsCache[ $cache_key ];
		}

		$conds = [];

		$dbr = wfGetDB( DB_REPLICA );

		$page_ancestors = self::page_ancestors( $title, false );

		$page_ancestors = array_reverse( $page_ancestors );

		$conds = self::groupsCond( $dbr, $user );

		$role = null;
		$permissions = [];

		$roles_hierarchy = [ 'admin', 'editor', 'reader' ];

		// closest (deepest) first
		foreach ( $page_ancestors as $key => $title_ ) {
			$conds['page_id'] = $title_->getArticleID();

			if ( $key > 0 ) {
				$conds[] = 'FIND_IN_SET(' . $dbr->addQuotes( 'subpages' ) . ', permissions)';
			}

			$rows = $dbr->select(
				'page_ownership',
				'*',
				$conds,
				__METHOD__
			);

			foreach ( $rows as $row ) {
				$row = (array)$row;

				// get all permissions and role
				if ( $row && $row !== [ false ] ) {
					if ( !empty( $row['permissions'] ) ) {
						array_map( static function ( $value ) use ( &$permissions ) {
							if ( !in_array( $value, $permissions ) ) {
								$permissions[] = $value;
							}
						}, explode( ",", $row['permissions'] ) );
					}

					if ( $role == null || ( array_search( $row[ 'role' ], $roles_hierarchy ) < array_search( $role, $roles_hierarchy ) ) ) {
						$role = $row[ 'role' ];
					}
				}
			}
		}

		self::$permissionsCache[ $cache_key ] = [ $role, $permissions ];

		return self::$permissionsCache[ $cache_key ];
	}

	/**
	 * @param MediaWiki\User\UserGroupManager $userGroupManager
	 * @param User $user
	 * @param bool $replace_asterisk
	 * @return array
	 */
	public static function getUserGroups( $userGroupManager, $user, $replace_asterisk = false ) {
		$user_groups = $userGroupManager->getUserEffectiveGroups( $user );
		// $user_groups[] = $user->getName();

		if ( array_search( '*', $user_groups ) === false ) {
			$user_groups[] = '*';
		}

		if ( $replace_asterisk ) {
			$key = array_search( '*', $user_groups );
			$user_groups[ $key ] = 'all';
		}

		return $user_groups;
	}

	/**
	 * @param OutputPage $outputPage
	 * @param array $items
	 * @return array
	 */
	public static function addHeaditem( $outputPage, $items ) {
		foreach ( $items as $key => $val ) {

			list( $type, $url ) = $val;

			switch ( $type ) {
				case 'stylesheet':
					$item = '<link rel="stylesheet" href="' . $url . '" />';
					break;

				case 'script':
					$item = '<script src="' . $url . '"></script>';
					break;

			}

			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$outputPage->addHeadItem( 'PageOwnership_head_item' . $key, $item );
		}
	}

	/**
	 * @param string $varName
	 * @return array
	 */
	public static function getGlobalParameterAsArray( $varName ) {
		$ret = ( array_key_exists( $varName, $GLOBALS ) ? $GLOBALS[ $varName ] : null );
		if ( empty( $ret ) ) {
			$ret = [];
		}
		if ( !is_array( $ret ) ) {
			$ret = preg_split( "/\s*,\s*/", $ret, -1, PREG_SPLIT_NO_EMPTY );
		}
		return $ret;
	}

	/**
	 * @param User $user
	 * @param Title $title
	 * @return bool
	 */
	public static function isAuthorized( $user, $title ) {
		$authorizedEditors = self::getGlobalParameterAsArray( 'wgPageOwnershipAuthorizedEditors' );
		$authorizedEditors = array_unique( array_merge( $authorizedEditors, [ 'sysop' ] ) );

		$userGroupManager = self::getUserGroupManager();

		// ***the following avoids that an user
		// impersonates a group through the username
		$all_groups = array_merge( $userGroupManager->listAllGroups(), $userGroupManager->listAllImplicitGroups() );

		$authorized_users = array_diff( $authorizedEditors, $all_groups );

		$authorized_groups = array_intersect( $authorizedEditors, $all_groups );

		$user_groups = self::getUserGroups( $userGroupManager, $user );

		$isAuthorized = count( array_intersect( $authorized_groups, $user_groups ) );

		if ( !$isAuthorized ) {
			$isAuthorized = in_array( $user->getName(), $authorized_users );
		}

		return $isAuthorized;
	}

	/**
	 * @param Title $title
	 * @param bool $exclude_current
	 * @return array
	 */
	public static function page_ancestors( $title, $exclude_current = true ) {
		$output = [];

		$title_parts = explode( '/', $title->getText() );

		if ( $exclude_current ) {
			array_pop( $title_parts );
		}

		$path = [];

		foreach ( $title_parts as $value ) {
			$path[] = $value;
			$title_text = implode( '/', $path );

			if ( $title->getText() == $title_text ) {
				$output[] = $title;

			} else {
				$title_ = Title::newFromText( $title_text );
				if ( $title_->isKnown() ) {
					$output[] = $title_;
				}
			}
		}

		return $output;
	}

	/**
	 * *** attention! this avoids storing the parser output in the cache,
	 * *** not retrieving an uncached version of a page !!
	 * @param Parser|null $parser
	 * @return void
	 */
	public static function disableCaching( $parser = null ) {
		if ( !$parser ) {
			$parser = self::$Parser;
		}

		if ( $parser->getOutput() ) {
			$parser->getOutput()->updateCacheExpiry( 0 );
		}

		// @credits Zabe
		$output = RequestContext::getMain()->getOutput();
		if ( method_exists( $output, 'disableClientCache' ) ) {
			// MW 1.38+
			$output->disableClientCache();
		} else {
			$output->enableClientCache( false );
		}
	}
}
