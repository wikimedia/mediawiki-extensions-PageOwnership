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
 * @copyright Copyright ©2021-2025, https://wikisphere.org
 */

use MediaWiki\Extension\PageOwnership\Aliases\Title as TitleClass;
use MediaWiki\MediaWikiServices;

class PageOwnership {
	/** @var array */
	public static $UserPagesCache = [];

	/** @var array */
	public static $permissionsCache = [];

	/** @var array */
	public static $hasPermissionsCache = [];

	/** @var array */
	public static $UserGroupsCache = [];

	/** @var User */
	public static $User;

	/** @var UserGroupManager */
	public static $userGroupManager;

	/** @var Parser */
	public static $Parser;

	/** @var int */
	public static $queryLimit = 500;

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:User_rights
	 * @var PermissionsByType
	 */
	public static $PermissionsByType = [
		'reading' => [
			'read'
		],
		'editing' => [
			'applychangetags',
			'autocreateaccount',
			'createaccount',
			'createpage',
			'createtalk',
			'delete-redirect',
			'edit',
			'editsemiprotected',
			'editprotected',
			'minoredit',
			'move',
			'move-categorypages',
			'move-rootuserpages',
			'move-subpages',
			'movefile',
			'reupload',
			'reupload-own',
			'reupload-shared',
			'sendemail',
			'upload',
			'upload_by_url'
		],
		'management' => [
			'bigdelete',
			'block',
			'blockemail',
			'browsearchive',
			'changetags',
			'delete',
			'deletedhistory',
			'deletedtext',
			'deletelogentry',
			'deleterevision',
			'editcontentmodel',
			'editinterface',
			'editmyoptions',
			'editmyprivateinfo',
			'editmyusercss',
			'editmyuserjs',
			'editmyuserjsredirect',
			'editmyuserjson',
			'editmywatchlist',
			'editsitecss',
			'editsitejs',
			'editsitejson',
			'editusercss',
			'edituserjs',
			'edituserjson',
			'hideuser',
			'markbotedits',
			'mergehistory',
			'pagelang',
			'patrol',
			'patrolmarks',
			'protect',
			'rollback',
			'suppressionlog',
			'suppressrevision',
			'unblockself',
			'undelete',
			'userrights',
			'userrights-interwiki',
			'viewmyprivateinfo',
			'viewmywatchlist',
			'viewsuppressed'
		],
		'administration' => [
			'autopatrol',
			'deletechangetags',
			'import',
			'importupload',
			'managechangetags',
			'siteadmin',
			'unwatchedpages',
		],
		'technical' => [
			'apihighlimits',
			'autoconfirmed',
			'bot',
			'ipblock-exempt',
			'nominornewtalk',
			'noratelimit',
			'override-export-depth',
			'purge',
			'suppressredirect',
			'writeapi'
		]
	];

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
	 * @param Title|Mediawiki\Title\Title $title
	 * @param array $options
	 * @param string $table
	 * @param string $prefix
	 * @return void
	 */
	public static function getLinksTo( $title, $options = [], $table = 'pagelinks', $prefix = 'pl' ) {
		$db = self::getDB( count( $options ) > 0 ? DB_PRIMARY : DB_REPLICA );

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
				$titleObj = TitleClass::newFromID( $row->page_id );
				if ( $titleObj ) {
					// $linkCache->addGoodLinkObjFromRow( $titleObj, $row );
					$retVal[] = $titleObj;
				}
			}
		}
		return $retVal;
	}

	/**
	 * @param array|string $usernames
	 * @param array|int $pageIDs
	 * @param array|int $namespaces
	 * @param array|int $created_by
	 * @param int|null $id
	 * @param array|null &$errors []
	 * @return array
	 */
	public static function getPermissions(
		$usernames,
		$pageIDs,
		$namespaces,
		$created_by,
		$id = null,
		&$errors = []
	) {
		$row = [
			'usernames' => $usernames,
			'pages' => $pageIDs,
			'namespaces' => $namespaces,
			'created_by' => $created_by
		];

		$tables = [ 'pageownership_permissions' ];
		$fields = [ '*' ];
		$joinConds = [];
		$conds = [];
		$options = [];

		if ( !empty( $row['id'] ) ) {
			$conds['id'] = $row['id'];
		}

		$db = self::getDB( DB_REPLICA );
		foreach ( $row as $key => $value ) {
			if ( !empty( $value ) ) {
				$sql = [];
				array_map( static function ( $val ) use ( $db, &$sql, $key ) {
					$sql[] = 'FIND_IN_SET(' . $db->addQuotes( $val ) . ', ' . $key . ')';
				}, $value );

				$conds[] = $db->makeList( $sql, LIST_OR );
			}
		}

		$res = $db->select(
			$tables,
			$fields,
			$conds,
			__METHOD__,
			$options,
			$joinConds
		);

		$ret = [];
		foreach ( $res as $row ) {
			$ret[] = (array)$row;
		}

		if ( !empty( $params['id'] ) && count( $output ) ) {
			$ret = current( $output );
		}

		return $ret;
	}

	/**
	 * @param \User $user
	 * @param array|string $usernames
	 * @param array|string $permissionsByType
	 * @param array|string $additionalRights
	 * @param array|string $addPermissions
	 * @param array|string $removePermissions
	 * @param array|int $pageIDs
	 * @param array|int $namespaces
	 * @param int|null $id
	 * @param array|null &$errors []
	 * @return array
	 */
	public static function setPermissions(
		$user,
		$usernames,
		$permissionsByType,
		// $permissionsByGroup,
		$additionalRights,
		$addPermissions,
		$removePermissions,
		$pageIDs,
		$namespaces,
		$id = null,
		&$errors = []
	) {
		$row = [
			'usernames' => $usernames,
			'permissions_by_type' => $permissionsByType,
			// 'permissions_by_group' => $permissionsByGroup,
			'additional_rights' => $additionalRights,
			'add_permissions' => $addPermissions,
			'remove_permissions' => $removePermissions,
			'pages' => $pageIDs,
			'namespaces' => $namespaces
		];

		foreach ( $row as $key => $value ) {
			$row[$key] = $value;
			if ( !is_array( $row[$key] ) ) {
				$row[$key] = preg_split( "/\s*,\s*/", $row[$key], -1, PREG_SPLIT_NO_EMPTY );
			}
		}

		if ( !count( $row['usernames'] ) ) {
			return false;
		}

		// filter permissions_by_type
		$row['permissions_by_type'] = array_intersect( $row['permissions_by_type'],
			array_keys( self::$PermissionsByType ) );

		foreach ( $row as $key => $value ) {
			$row[$key] = implode( ',', $value );
		}

		$row['id'] = $id;
		$row['created_by'] = $user->getName();

		$dbw = self::getDB( DB_PRIMARY );

		if ( !$id ) {
			$date = date( 'Y-m-d H:i:s' );
			$res = $dbw->insert( 'pageownership_permissions', $row + [ 'updated_at' => $date, 'created_at' => $date ] );

		} else {
			$res = $dbw->update( 'pageownership_permissions', $row, [ 'id' => $id ], __METHOD__ );
		}

		if ( !is_array( $pageIDs ) ) {
			$pageIDs = preg_split( "/\s*,\s*/", $pageids, -1, PREG_SPLIT_NO_EMPTY );
		}

		foreach ( $pageIDs as $value ) {
			$title_ = TitleClass::newFromID( $value );
			if ( $title_ && $title_->isKnown() ) {
				self::invalidateCacheOfPagesWithAskQueriesRelatedToTitle( $title_ );
				self::invalidateCacheOfPagesWithTemplateLinksTo( $title_ );
			}
		}

		return $res;
	}

	/**
	 * *** invalidate cache of all pages in which this page has been transcluded
	 * @param Title|Mediawiki\Title\Title $title
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
	 * @param Title|Mediawiki\Title\Title $title
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
	 * @param Title|Mediawiki\Title\Title $title
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
	 * @param array $conds
	 * @return void
	 */
	public static function deletePermissions( $conds ) {
		$dbw = self::getDB( DB_PRIMARY );

		$dbw->delete(
			'pageownership_permissions', $conds,
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

		$dbr = self::getDB( DB_REPLICA );

		if ( !$dbr->tableExists( 'pageownership_permissions' ) ) {
			return [];
		}

		// $user_groups = self::getUserGroups( $user, true );
		$user_groups = [];
		$user_groups[] = $user->getName();
		$conds = array_merge( self::groupsCond( $dbr, $user_groups, true ), $conds );

		$res = $dbr->select( 'pageownership_permissions', '*', $conds );

		$output = [];
		foreach ( $res as $row ) {
			$row = (array)$row;

			$usernames = explode( ',', $row[ 'usernames' ] );
			if ( in_array( 'pageownership-article-author', $usernames )
				// only 'pageownership-article-author' matched
				&& !count( array_intersect( $user_groups, $usernames ) ) ) {
					continue;
			}

			if ( !empty( $row['permissions_by_type'] ) ) {
				$output = array_merge( $output, ( !empty( $row[ 'pages' ] ) ? explode( ',', $row[ 'pages' ] ) : [] ) );
			}
		}

		// retrieve all pages assigned to users as authors
		$conds = [ 'FIND_IN_SET(' . $dbr->addQuotes( 'pageownership-article-author' ) . ', usernames)' ];
		$res = $dbr->select( 'pageownership_permissions', '*', $conds );

		foreach ( $res as $row ) {
			$row = (array)$row;

			// $titleIdentifier = self::titleIdentifier( $title_ );
			$pages = explode( ',', $row['pages'] );
			foreach ( $pages as $pageID ) {
				$title_ = TitleClass::newFromID( $pageID );
				// legacy identifier, remove in future versions
				if ( !$title_ ) {
					$title_ = TitleClass::newFromText( $pageID );
				}
				if ( self::isAuthor( $title_, $user ) ) {
					$output[] = $title_->getArticleID();
				}
				$additional_rights = explode( ',', $row['additional_rights'] );
				if ( in_array( "pageownership-include-subpages", $additional_rights ) ) {
					$subpages = self::getPagesWithPrefix( $title_->getText(), $title_->getNamespace() );

					foreach ( $subpages as $title_ ) {
						if ( self::isAuthor( $title_, $user ) ) {
							$output[] = $title_->getArticleID();
						}
					}
				}
			}
		}

		$output = array_unique( $output );

		self::$UserPagesCache[ $cache_key ] = $output;
		return self::$UserPagesCache[ $cache_key ];
	}

	/**
	 * @param string $prefix
	 * @param int|null $namespace
	 * @return array
	 */
	public static function getPagesWithPrefix( $prefix, $namespace = null ) {
		$dbr = self::getDB( DB_REPLICA );
		$conds = [ 'page_is_redirect' => 0 ];

		if ( is_int( $namespace ) ) {
			$conds['page_namespace'] = $namespace;
		}

		if ( !empty( $prefix ) ) {
			$conds[] = 'page_title' . $dbr->buildLike( $prefix, $dbr->anyString() );
		}

		$res = $dbr->select(
			'page',
			[ 'page_namespace', 'page_title', 'page_id' ],
			$conds,
			__METHOD__,
			[
				'LIMIT' => self::$queryLimit,
				'ORDER BY' => 'page_title',
				// @see here https://doc.wikimedia.org/mediawiki-core/
				'USE INDEX' => ( version_compare( MW_VERSION, '1.36', '<' ) ? 'name_title' : 'page_name_title' ),
			]
		);

		if ( !$res->numRows() ) {
			return [];
		}

		$ret = [];
		foreach ( $res as $row ) {
			$title = TitleClass::newFromRow( $row );

			if ( $title->isKnown() ) {
				$ret[] = $title;
			}
		}

		return $ret;
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
				$title = TitleClass::newFromID( $value );
				if ( $title ) {
					return $title->getFullText();
				}
			// special pages
			} else {
				$title = TitleClass::newFromText( $value );
				if ( $title ) {
					return $title->getFullText();
				}
			}
		}, $arr ), static function ( $value ) {
			return !empty( $value );
		} );
	}

	/**
	 * @param Title|Mediawiki\Title\Title $title
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
					$title = TitleClass::newFromText( $value );
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
	 * @see PageOwnershipHooks -> onRejectParserCacheValue
	 * @param Title|Mediawiki\Title\Title $title
	 * @return bool
	 */
	public static function permissionsExist( $title ) {
		$cacheKey = $title->getFullText();
		$cacheVar = 'hasPermissionsCache';

		if ( array_key_exists( $cacheKey, self::$$cacheVar ) ) {
			return self::$$cacheVar[ $cacheKey ];
		}

		$dbr = self::getDB( DB_REPLICA );
		if ( !$dbr->tableExists( 'pageownership_permissions' ) ) {
			self::$$cacheVar[ $cacheKey ] = false;
			return self::$$cacheVar[ $cacheKey ];
		}
		$conds = [];
		$conds[] = $dbr->makeList( [
			'namespaces = ""',
			'FIND_IN_SET(' . $title->getNamespace() . ', namespaces)',
		], LIST_OR );

		$page_ancestors = self::page_ancestors( $title, false );
		$page_ancestors = array_reverse( $page_ancestors );

		$ret = false;
		// closest (deepest) first
		foreach ( $page_ancestors as $key => $title_ ) {
			$conds_ = $conds;
			$titleIdentifier = self::titleIdentifier( $title_ );

			$conds_[] = $dbr->makeList( [
				'pages = ""',
				'FIND_IN_SET(' . $dbr->addQuotes( $titleIdentifier ) . ', pages)',

				// back-compatibility
				'FIND_IN_SET(' . ( $title_->isContentPage() ? $title_->getArticleID()
					: $dbr->addQuotes( $title_->getFullText() ) ) . ', pages)',
			], LIST_OR );

			$res = $dbr->select(
				'pageownership_permissions',
				'*',
				$conds_,
				__METHOD__
			);

			if ( $res->numRows() ) {
				$ret = true;
				break;
			}
		}

		self::$$cacheVar[ $cacheKey ] = $ret;
		return self::$$cacheVar[ $cacheKey ];
	}

	/**
	 * @param Title|Mediawiki\Title\Title &$title
	 * @param User $user
	 * @param string|null $action
	 * @return bool|null
	 */
	public static function checkPermissions( &$title, $user, $action = 'read' ) {
		// remove title parameter for special pages
		if ( $title->isSpecialPage() ) {
			$prefixedText = $title->getPrefixedText();
			$pos = strpos( $prefixedText, '/' );
			if ( $pos !== false ) {
				$title = TitleClass::newFromText( substr( $prefixedText, 0, $pos ) );
			}
		}

		$cacheKey = $title->getFullText() . $user->getName() . $action;
		// $cacheVar = ( $user ? 'permissionsCache' : 'hasPermissionsCache' );
		$cacheVar = 'permissionsCache';

		if ( array_key_exists( $cacheKey, self::$$cacheVar ) ) {
			return self::$$cacheVar[ $cacheKey ];
		}

		$retSuccess = static function () use ( $cacheVar, $cacheKey ) {
			self::$$cacheVar[ $cacheKey ] = true;
			return true;
		};

		$right = $action;
		$nsInfo = MediaWikiServices::getInstance()->getNamespaceInfo();

		// @see PermissionManager
		if ( $action === 'create' ) {
			$right = ( !$nsInfo->isTalk( $title->getNamespace() ) ?
				'createpage' : 'createtalk' );
		}

		// or add in the obj PermissionsByType
		if ( $action === 'move-target' ) {
			$right = 'move';
		}

		$dbr = self::getDB( DB_REPLICA );
		if ( !$dbr->tableExists( 'pageownership_permissions' ) ) {
			self::$$cacheVar[ $cacheKey ] = null;
			return self::$$cacheVar[ $cacheKey ];
		}

		$page_ancestors = self::page_ancestors( $title, false );
		$page_ancestors = array_reverse( $page_ancestors );

		$user_groups = self::getUserGroups( $user, true );
		$user_groups[] = $user->getName();

		$conds = self::groupsCond( $dbr, $user_groups );

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
				'FIND_IN_SET(' . ( $title_->isContentPage() ? $title_->getArticleID()
					: $dbr->addQuotes( $title_->getFullText() ) ) . ', pages)',
			], LIST_OR );

			$res_ = $dbr->select(
				'pageownership_permissions',
				'*',
				$conds_,
				__METHOD__
			);

			$rows_ = [];
			if ( $res_->numRows() ) {
				$fields = [
					'pages',
					'usernames',
					'additional_rights',
					'permissions_by_type',
					'remove_permissions',
					'add_permissions'
				];

				$found = false;
				foreach ( $res_ as $k => $v ) {
					$row = (array)$v;
					foreach ( $fields as $field ) {
						$row[$field] = ( !empty( $row[$field] )
							? explode( ',', $row[$field] )
							: [] );
					}
					$rows_[] = $row;

					if ( in_array( $titleIdentifier, $row['pages'] )
						&& count( array_intersect( $user_groups, $row['usernames'] ) ) ) {
						$found = true;
					}
				}

				// remove generic entry if one specific
				// has been found
				if ( $found ) {
					foreach ( $rows_ as $k => $v ) {
						if ( !in_array( $titleIdentifier, $v['pages'] ) ) {
							unset( $rows_[$k] );
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

			foreach ( $rows_ as $row ) {
				if ( $subpage && !in_array( "pageownership-include-subpages", $row['additional_rights'] ) ) {
					continue;
				}

				if ( in_array( 'pageownership-article-author', $row['usernames'] )
					// only 'pageownership-article-author' matched
					&& !count( array_intersect( $user_groups, $row['usernames'] ) ) ) {

					if ( $subpage ) {
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
						return $retSuccess();
					}
				}

				if ( in_array( $right, $row['add_permissions'] ) ) {
					return $retSuccess();
				}

				if ( in_array( $right, $row['additional_rights'] ) ) {
					return $retSuccess();
				}
			}
		}
		self::$$cacheVar[ $cacheKey ] = $ret;
		return self::$$cacheVar[ $cacheKey ];
	}

	/**
	 * *** credits https://gerrit.wikimedia.org/r/plugins/gitiles/mediawiki/extensions/LockAuthor/+/refs/heads/master/includes/LockAuthor.php
	 * @param Title|Mediawiki\Title\Title $title
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
		$cacheKey = $user->getName() . (int)$replace_asterisk;
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

			[ $type, $url ] = $val;

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
	 * @param Title|Mediawiki\Title\Title $title
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
				$title_ = TitleClass::newFromText( $title_text );
				if ( $title_ && $title_->isKnown() ) {
					$output[] = $title_;
				}
			}
		}

		return $output;
	}

	/**
	 * @param Title|Mediawiki\Title\Title $title
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

	/**
	 * @param int $db
	 * @return \Wikimedia\Rdbms\DBConnRef
	 */
	public static function getDB( $db ) {
		if ( !method_exists( MediaWikiServices::class, 'getConnectionProvider' ) ) {
			// @see https://gerrit.wikimedia.org/r/c/mediawiki/extensions/PageEncryption/+/1038754/comment/4ccfc553_58a41db8/
			return MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( $db );
		}
		$connectionProvider = MediaWikiServices::getInstance()->getConnectionProvider();
		switch ( $db ) {
			case DB_PRIMARY:
				return $connectionProvider->getPrimaryDatabase();
			case DB_REPLICA:
			default:
				return $connectionProvider->getReplicaDatabase();
		}
	}

}
