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
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright ©2021-2023, https://wikisphere.org
 */

use MediaWiki\MediaWikiServices;

class PageOwnership {
	/** @var UserPagesCache */
	public static $UserPagesCache = [];

	/** @var permissionsCache */
	public static $permissionsCache = [];

	/** @var hasPermissionsCache */
	public static $hasPermissionsCache = [];

	/** @var UserGroupsCache */
	public static $UserGroupsCache = [];

	/** @var User */
	public static $User;

	/** @var userGroupManager */
	public static $userGroupManager;

	/** @var Parser */
	public static $Parser;

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:User_rights
	 * @var PermissionsByType
	 */
	public static $PermissionsByType = [
		"reading" => [
			"read"
		],
		"editing" => [
			"applychangetags",
			"autocreateaccount",
			"createaccount",
			"createpage",
			"createtalk",
			"delete-redirect",
			"edit",
			"editsemiprotected",
			"editprotected",
			"minoredit",
			"move",
			"move-categorypages",
			"move-rootuserpages",
			"move-subpages",
			"movefile",
			"reupload",
			"reupload-own",
			"reupload-shared",
			"sendemail",
			"upload",
			"upload_by_url"
		],
		"management" => [
			"bigdelete",
			"block",
			"blockemail",
			"browsearchive",
			"changetags",
			"delete",
			"deletedhistory",
			"deletedtext",
			"deletelogentry",
			"deleterevision",
			"editcontentmodel",
			"editinterface",
			"editmyoptions",
			"editmyprivateinfo",
			"editmyusercss",
			"editmyuserjs",
			"editmyuserjsredirect",
			"editmyuserjson",
			"editmywatchlist",
			"editsitecss",
			"editsitejs",
			"editsitejson",
			"editusercss",
			"edituserjs",
			"edituserjson",
			"hideuser",
			"markbotedits",
			"mergehistory",
			"pagelang",
			"patrol",
			"patrolmarks",
			"protect",
			"rollback",
			"suppressionlog",
			"suppressrevision",
			"unblockself",
			"undelete",
			"userrights",
			"userrights-interwiki",
			"viewmyprivateinfo",
			"viewmywatchlist",
			"viewsuppressed"
		],
		"administration" => [
			"autopatrol",
			"deletechangetags",
			"import",
			"importupload",
			"managechangetags",
			"siteadmin",
			"unwatchedpages",
		],
		"technical" => [
			"apihighlimits",
			"autoconfirmed",
			"bot",
			"ipblock-exempt",
			"nominornewtalk",
			"noratelimit",
			"override-export-depth",
			"purge",
			"suppressredirect",
			"writeapi"
		]
	];

	/**
	 * @param User|null $user
	 */
	public static function initialize( $user ) {
		self::$User = $user;
		self::$userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();
		self::$Parser = MediaWikiServices::getInstance()->getParser();

/*
global $wgGroupPermissions;
print_r($wgGroupPermissions);

global $wgAvailableRights;
print_r($wgAvailableRights);
*/
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
			// $linkCache = MediaWikiServices::getInstance()->getLinkCache();
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

		if ( version_compare( MW_VERSION, '1.39', '<' ) ) {
			$transcludedTargets = self::getLinksTo( $title, $options, 'templatelinks', 'tl' );
		} else {
			$transcludedTargets = $title->getTemplateLinksTo( $options );
		}

		foreach ( $transcludedTargets as $title_ ) {
			// $title_->invalidateCache();
			self::doPurge( $title_ );
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

		if ( !$title ) {
			return;
		}

		// @see extensions/SemanticMediaWiki/src/MediaWiki/Hooks.php
		$queryDependencyLinksStoreFactory = SMW\ApplicationFactory::getInstance()
			->singleton( 'QueryDependencyLinksStoreFactory' );

		$store = \SMW\StoreFactory::getStore();

		$queryDependencyLinksStore = $queryDependencyLinksStoreFactory->newQueryDependencyLinksStore(
			$store
		);

		$subject = SMW\DIWikiPage::newFromTitle( $title );

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
			// $title_->invalidateCache();
			self::doPurge( $title_ );
		}
	}

	/**
	 * @param Title $title
	 * @return null|bool
	 */
	 public static function doPurge( $title ) {
		if ( !$title ) {
			return false;
		}
		$wikiPage = self::getWikiPage( $title );
		if ( $wikiPage ) {
			$wikiPage->doPurge();
			return true;
		}
		$title->invalidateCache();
		return null;
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
	 * @param array $row
	 * @param int|null $id
	 * @return bool
	 */
	public static function setPermissions( $creatorUsername, $row, $id = null ) {
		$dbr = wfGetDB( DB_MASTER );

		if ( !count( $row['usernames'] ) ) {
			return false;
		}

		$row['pages'] = self::titleTextsToIDs( $row['pages'] );

		foreach ( $row as $key => $value ) {
			$row[$key] = implode( ',', $value );
		}

		$row['created_by'] = $creatorUsername;

		if ( !$id ) {
			$date = date( 'Y-m-d H:i:s' );
			$res = $dbr->insert( 'pageownership_permissions', $row + [ 'updated_at' => $date, 'created_at' => $date ] );

		} else {
			$res = $dbr->update( 'pageownership_permissions', $row, [ 'id' => $id ], __METHOD__ );
		}

		return $res;
	}

	/**
	 * @param array $conds
	 * @return void
	 */
	public static function deletePermissions( $conds ) {
		$dbw = wfGetDB( DB_PRIMARY );

		$dbw->delete(
			'pageownership_permissions',  $conds,
			__METHOD__
		);
	}

	/**
	 * @param User $user
	 * @param array|null $conds
	 * @return array
	 */
	public static function getUserPagesDB( $user, $conds = [] ) {
		$cache_key = $user->getName();

		if ( array_key_exists( $cache_key, self::$UserPagesCache ) ) {
			return self::$UserPagesCache[ $cache_key ];
		}

		$dbr = wfGetDB( DB_REPLICA );

		if ( !$dbr->tableExists( 'pageownership_permissions' ) ) {
			return [];
		}

		$user_groups = self::getUserGroups( $user, true );
		$user_groups[] = $user->getName();
		$conds = array_merge( self::groupsCond( $dbr, $user_groups, true ), $conds );

		$rows = $dbr->select( 'pageownership_permissions', '*', $conds );

		$output = [];
		foreach ( $rows as $row ) {
			$row = (array)$row;

			if ( !empty( $row['permissions_by_type'] ) ) {
				$output = array_merge( $output, ( !empty( $row[ 'pages' ] ) ? explode( ',', $row[ 'pages' ] ) : [] ) );
			}
		}

		$output = array_unique( $output );

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

		return self::pageIDsToText( $pages );
	}

	/**
	 * @param array $arr
	 * @return array
	 */
	public static function pageIDsToText( $arr ) {
		return array_filter( array_map( static function ( $value ) {
				// article
				if ( is_numeric( $value ) ) {
					$title = Title::newFromID( $value );
					if ( $title ) {
						return $title->getFullText();
					}
				// special pages
				} else {
					$title = Title::newFromText( $value );
					if ( $title ) {
						return $title->getFullText();
					}
				}
		}, $arr ), static function ( $value ) {
			return !empty( $value );
		} );
	}

	/**
	 * @param Title $title
	 * @return int|string
	 */
	public static function titleIdentifier( $title ) {
		// *** unfortunately we cannot always rely on $title->isContentPage()
		// @see https://github.com/debtcompliance/EmailPage/pull/4#discussion_r1191646022
		// or use $title->exists()
		$isArticle = ( $title && $title->canExist() && $title->getArticleID() > 0
			&& $title->isKnown() );

		return ( $isArticle ? $title->getArticleID() : $title->getFullText() );
	}

	/**
	 * @param array $arr
	 * @return array
	 */
	public static function titleTextsToIDs( $arr ) {
		return array_filter( array_map( static function ( $value ) {
					$title = Title::newFromText( $value );
					return self::titleIdentifier( $title );
		}, $arr ), static function ( $value ) {
			return !empty( $value );
		} );
	}

	/**
	 * @param \Wikimedia\Rdbms\DBConnRef $dbr
	 * @param array $userGroups
	 * @param bool $authorship false
	 * @param string|null $field
	 * @return array
	 */
	public static function groupsCond( $dbr, $userGroups, $authorship = false, $field = 'usernames' ) {
		$conds = [];
		array_map( static function ( $value ) use ( &$conds, $dbr, $field ) {
			$conds[] = 'FIND_IN_SET(' . $dbr->addQuotes( $value ) . ', ' . $field . ')';
		}, $userGroups );

		$conds[] = 'FIND_IN_SET(' . $dbr->addQuotes( 'pageownership-article-author' ) . ', ' . $field . ')';

		return [ $dbr->makeList( $conds, LIST_OR ) ];
	}

	/**
	 * @param Title $title
	 * @param User|null $user
	 * @param string|null $action
	 * @return bool
	 */
	public static function getPermissions( $title, $user = null, $action = "read" ) {
		$cacheKey = $title->getFullText() . ( $user ? $user->getName() : '' ) . $action;
		$cacheVar = ( $user ? 'permissionsCache' : 'hasPermissionsCache' );

		if ( array_key_exists( $cacheKey, self::$$cacheVar ) ) {
			return self::$$cacheVar[ $cacheKey ];
		}

		$right = $action;
		$nsInfo = MediaWikiServices::getInstance()->getNamespaceInfo();

		// @see PermissionManager
		if ( $action === 'create' ) {
			$right = ( !$nsInfo->isTalk( $title->getNamespace() ) ?
				'createpage' : 'createtalk' );
		}

		$conds = [];

		$dbr = wfGetDB( DB_REPLICA );
		if ( !$dbr->tableExists( 'pageownership_permissions' ) ) {
			return null;
		}

		$page_ancestors = self::page_ancestors( $title, false );
		$page_ancestors = array_reverse( $page_ancestors );

		$user_groups = self::getUserGroups( $user, true );
		$user_groups[] = $user->getName();

		$conds = ( $user ? self::groupsCond( $dbr, $user_groups ) : [] );

		$conds[] = $dbr->makeList( [
			'namespaces = ""',
			'FIND_IN_SET(' . $title->getNamespace() . ', namespaces)',
		], LIST_OR );

		$ret = null;
		$subpage = null;
		// closest (deepest) first
		foreach ( $page_ancestors as $key => $title_ ) {
			if ( $key > 0 ) {
				$subpage = $page_ancestors[$key - 1];
			}

			$conds_ = $conds;
			$titleIdentifier = self::titleIdentifier( $title_ );

			$conds_[] = $dbr->makeList( [
				'pages = ""',
				'FIND_IN_SET(' . $dbr->addQuotes( $titleIdentifier ) . ', pages)',

				// back-compatibility
				'FIND_IN_SET(' . ( $title_->isContentPage() ? $title_->getArticleID() : $dbr->addQuotes( $title_->getFullText() ) ) . ', pages)',
			], LIST_OR );

			$rows_ = $dbr->select(
				'pageownership_permissions',
				'*',
				$conds_,
				__METHOD__
			);

			$rows = [];
			if ( $rows_->numRows() ) {
				$fields = [
					'pages',
					'usernames',
					'additional_rights',
					'permissions_by_type',
					'remove_permissions',
					'add_permissions'
				];

				$found = false;
				foreach ( $rows_ as $k => $v ) {
					$row = (array)$v;
					foreach ( $fields as $field ) {
						$row[$field] = ( !empty( $row[$field] )
							? explode( ',', $row[$field] )
							: [] );
					}
					$rows[] = $row;
					if ( in_array( $titleIdentifier, $row['pages'] ) ) {
						$found = true;
					}
				}

				// remove generic entry if one specific
				// has been found
				if ( $found ) {
					foreach ( $rows as $k => $v ) {
						if ( !in_array( $titleIdentifier, $v['pages'] ) ) {
							unset( $rows[$k] );
						}
					}
				}

				// initialize to false if a row matched the current
				// title or user/group
				$ret = false;
			}

			// we only need to know if a condition matched
			// all the "continue" statements indicate
			// that we keep the output to false, without
			// evaluating further the set of permissions
			if ( !$user ) {
				continue;
			}

			foreach ( $rows as $row ) {

				if ( in_array( 'pageownership-article-author', $row['usernames'] )
					// only 'pageownership-article-author' matched
					&& !count( array_intersect( $user_groups, $row['usernames'] ) ) ) {

					if ( $subpage ) {
						if ( !in_array( "pageownership-include-subpages", $row['additional_rights'] ) ) {
							continue;
						}

						if ( $subpage->isKnown() && !self::isAuthor( $subpage, $user ) ) {
							continue;
						}

					} else {
						if ( $title_->isKnown() && !self::isAuthor( $title_, $user ) ) {
							continue;
						}
					}
				}

				if ( in_array( $right, $row['remove_permissions'] ) ) {
					continue;
				}

				foreach ( $row['permissions_by_type'] as $type ) {
					if ( in_array( $right, self::$PermissionsByType[$type] ) ) {
						$ret = true;
						break 2;
					}
				}

				if ( in_array( $right, $row['add_permissions'] ) ) {
					$ret = true;
					break;
				}

				if ( in_array( $right, $row['additional_rights'] ) ) {
					$ret = true;
					break;
				}
			}
		}
		self::$$cacheVar[ $cacheKey ] = $ret;
		return self::$$cacheVar[ $cacheKey ];
	}

	/**
	 * *** credits https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/extensions/LockAuthor/+/refs/heads/master/includes/LockAuthor.php
	 * @param Title $title
	 * @param User $user
	 * @return bool
	 */
	public static function isAuthor( $title, $user ) {
		$rev = MediaWikiServices::getInstance()->getRevisionLookup()->getFirstRevision( $title );
		if ( !$rev ) {
			return true;
		}
		if ( $user->getName() == $rev->getUser()->getName() ) {
			return true;
		}
		return false;
	}

	/**
	 * @param User $user
	 * @param bool $replace_asterisk
	 * @return array
	 */
	public static function getUserGroups( $user, $replace_asterisk = false ) {
		$cacheKey = $user->getName();
		if ( array_key_exists( $cacheKey, self::$UserGroupsCache ) ) {
			return self::$UserGroupsCache[ $cacheKey ];
		}

		$userGroupManager = self::getUserGroupManager();
		$userGroups = $userGroupManager->getUserEffectiveGroups( $user );
		// $userGroups[] = $user->getName();

		if ( array_search( '*', $userGroups ) === false ) {
			$userGroups[] = '*';
		}

		if ( $replace_asterisk ) {
			$key = array_search( '*', $userGroups );
			$userGroups[ $key ] = 'all';
		}

		self::$UserGroupsCache[ $cacheKey ] = $userGroups;
		return self::$UserGroupsCache[ $cacheKey ];
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
	 * @return bool
	 */
	public static function isAuthorized( $user ) {
		$admins = self::getGlobalParameterAsArray( 'wgPageOwnershipAdmins' );
		$admins = array_unique( array_merge( $admins, [ 'sysop' ] ) );

		return self::matchUsernameOrGroup( $user, $admins );
	}

	/**
	 * @param User $user
	 * @param array $groups
	 * @return bool
	 */
	public static function matchUsernameOrGroup( $user, $groups ) {
		$userGroupManager = self::getUserGroupManager();

		// ***the following prevents that an user
		// impersonates a group through the username
		$all_groups = array_merge( $userGroupManager->listAllGroups(), $userGroupManager->listAllImplicitGroups() );

		$authorized_users = array_diff( $groups, $all_groups );

		$authorized_groups = array_intersect( $groups, $all_groups );

		$user_groups = self::getUserGroups( $user );

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

		$title_parts = explode( '/', $title->getFullText() );

		if ( $exclude_current ) {
			array_pop( $title_parts );
		}

		$path = [];
		foreach ( $title_parts as $value ) {
			$path[] = $value;
			$title_text = implode( '/', $path );

			if ( $title->getFullText() === $title_text ) {
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
	 * @param Title $title
	 * @return void
	 */
	public static function getWikiPage( $title ) {
		// MW 1.36+
		if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
			return MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		}
		return WikiPage::factory( $title );
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
