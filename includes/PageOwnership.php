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
	private static $UserPagesCache = [];
	/** @var permissionsCache */
	private static $permissionsCache = [];
	/** @var OwnedPagesCache */
	private static $OwnedPagesCache = [];
	/** @var User */
	public static $User;
	/** @var userGroupManager */
	public static $userGroupManager;
	/** @var Parser */
	public static $Parser;

	/**
	 * @param DatabaseUpdater|null $updater
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater = null ) {
		$base = __DIR__;
		$dbType = $updater->getDB()->getType();
		$array = [
			[
				'table' => 'page_ownership',
				'filename' => '../' . $dbType . '/page_ownership.sql'
			]
		];
		foreach ( $array as $value ) {
			if ( file_exists( $base . '/' . $value['filename'] ) ) {
				$updater->addExtensionUpdate(
					[
						'addTable', $value['table'],
						$base . '/' . $value['filename'], true
					]
				);
			}
		}
	}

	/**
	 * @param Title &$title
	 * @param null $unused
	 * @param OutputPage $output
	 * @param User $user
	 * @param WebRequest $request
	 * @param MediaWiki $mediaWiki
	 * @return void
	 */
	public static function onBeforeInitialize( \Title &$title, $unused, \OutputPage $output, \User $user, \WebRequest $request, \MediaWiki $mediaWiki ) {
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

	public static function onPageSaveComplete( WikiPage $wikiPage, MediaWiki\User\UserIdentity $user, string $summary, int $flags, MediaWiki\Revision\RevisionRecord $revisionRecord, MediaWiki\Storage\EditResult $editResult ) {
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
	private static function getLinksTo( $title, $options = [], $table = 'pagelinks', $prefix = 'pl' ) {
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
			$linkCache = MediaWiki\MediaWikiServices::getInstance()->getLinkCache();
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
	 * Fetch an appropriate permission error (or none!)
	 *
	 * @param Title $title being checked
	 * @param User $user whose access is being checked
	 * @param string $action being checked
	 * @param array|string|MessageSpecifier &$result User
	 *   permissions error to add. If none, return true. $result can be
	 *   returned as a single error message key (string), or an array of
	 *   error message keys when multiple messages are needed
	 * @return bool
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/getUserPermissionsErrors
	 */
	public static function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
		global $wgLang;

		$logged_in = $user->isRegistered();

		$username = $user->getName();

		$read = ( $action == 'read' );
		$edit = ( $action == 'edit' || $action == 'delete' || $action == 'move' || $action == 'create' );

		$new_page = ( !$title->isKnown() || $action == 'create' );

		// page is being created through a pageform form
		if ( strpos( $title->getFullText(), 'Special:FormEdit/' ) !== false ) {
			return true;
		}

		if ( $title->getFullText() == 'Page Forms permissions test' ) {
			return true;
		}

		if ( $title->getNamespace() != NS_MAIN ) {
			return true;
		}

		// shows a more adequate message
		if ( $read && $new_page ) {
			return true;
		}

		$isAuthorized = \PageOwnershipFunctions::isAuthorized( $user, $title );

		if ( $isAuthorized ) {
			return true;
		}

		// not an owned page
		if ( !self::isOwned( $title ) ) {

			if ( $action == 'edit' ) {
				$is_allowed = $user->isAllowed( 'pageownership-caneditunassignedpages' );

				if ( !$is_allowed ) {
					if ( !$logged_in ) {
						$result = [ 'badaccess-group0' ];

					} else {
						// *** this is correct but ignored on page edit
						$groupLinks = self::getGroupLinks( [ 'pageownership-admin', 'pageownership-editorofunassignedpages' ] );

						$result = [
							'badaccess-groups',
							$wgLang->commaList( $groupLinks ),
							count( $groupLinks )
						];

					}

					return false;
				}
			}

			return true;
		}

		list( $role, $permissions ) = self::permissionsOfPage( $title, $user );

		if ( $read ) {
			if ( $role != null ) {
				return true;
			}
		}

		if ( $edit ) {
			if ( $role == 'admin' ) {
				return true;
			}

			if ( $new_page && in_array( 'create', $permissions ) ) {
				return true;
			}

			if ( in_array( 'edit', $permissions ) ) {
				return true;
			}

		}

		// the user is the page's creator
		// *** it could also be an anonymous user with same ip ?
		if ( $logged_in ) {
			// @credits Umherirrender
			if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
				// MW 1.36+
				$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
			} else {
				$wikiPage = WikiPage::factory( $title );
			}

			$creator = $wikiPage->getCreator();

			if ( $creator == $username ) {
				return true;
			}
		}

		// \PageOwnershipFunctions::disableCaching();

		$result = [ 'badaccess-group0' ];

		return false;
	}

	/**
	 * *** use a specific cache hash key for registered users
	 * *** so the cache of a page is always related to anonymous/registered_users
	 * @param string &$confstr
	 * @param User $user
	 * @param array &$forOptions
	 * @return void
	 */
	public static function onPageRenderingHash( &$confstr, User $user, &$forOptions ) {
		// *** see also parserOptions->addExtraKey

		// *** for some reason we cannot rely on $user->isRegistered()
		if ( $user->isRegistered() ) {
			$confstr .= '!registered_user';
		}
	}

	/**
	 * *** ignore the cache if a page contains a transcluded owned page
	 * *** only for cache related to registered users
	 * @param ParserOutput $parserOutput
	 * @param WikiPage $wikiPage
	 * @param ParserOptions $parserOptions
	 * @return void
	 */
	public static function onRejectParserCacheValue( $parserOutput, $wikiPage, $parserOptions ) {
		$user = self::getUser();
		if ( $user->isRegistered() ) {
			$title = $wikiPage->getTitle();
			$transcludedTemplates = $title->getTemplateLinksFrom();

			foreach ( $transcludedTemplates as $title_ ) {
				if ( self::isOwned( $title_ ) ) {
					return false;
				}
			}
		}
	}

	public static function onParserOptionsRegister( &$defaults, &$inCacheKey, &$lazyLoad ) {
	}

	/**
	 * @see extension lockdown/src/Hooks
	 * @param array $groups
	 * @return array
	 */
	private static function getGroupLinks( $groups ) {
		$links = [];
		foreach ( $groups as $group ) {
			$links[] = UserGroupMembership::getLink(
				$group, RequestContext::getMain(), 'wiki'
			);
		}
		return $links;
	}

	/**
	 * *** Mediawiki >= 1.37
	 * @param Title $contextTitle
	 * @param Title $title
	 * @param bool &$skip
	 * @param RevisionRecord &$revRecord
	 * @return bool
	 */
	public static function onBeforeParserFetchTemplateRevisionRecord( $contextTitle, $title, &$skip, &$revRecord ) {
		// if ( interface_exists('\\MediaWiki\\Hook\\ParserFetchTemplateHook') ) {
		// 	return;
		// }

		// *** prevents the following error
		// http://127.0.0.1/mediawiki/load.php?lang=en&modules=startup&only=scripts&raw=1&skin=vector
		// "Sessions are disabled for load entry point"
		if ( defined( 'MW_NO_SESSION' ) ) {
			// *** this is a "conservative" block, but is it necessary ?
			$skip = true;
			return false;
		}

		$user = self::getUser();

		$isAuthorized = \PageOwnershipFunctions::isAuthorized( $user, $title );

		if ( $isAuthorized ) {
			return true;
		}

		if ( !self::isOwned( $title ) ) {
			return true;
		}

		list( $role, $permissions ) = self::permissionsOfPage( $title, $user );

		if ( !empty( $role ) ) {
			return true;
		}

		// \PageOwnershipFunctions::disableCaching();

		$skip = true;
		return false;
	}

	/**
	 * *** Mediawiki < 1.37
	 * @see SemanticACL/SemanticACL.class.php
	 * @param Parser|bool $parser
	 * @param Title $title
	 * @param Revision $rev
	 * @param string|bool|null &$text
	 * @param array &$deps
	 * @return array bool
	 */
	public static function onParserFetchTemplate( $parser, $title, $rev, &$text, &$deps ) {
		$user = $parser->getUserIdentity() ?? self::getUser();

		$isAuthorized = \PageOwnershipFunctions::isAuthorized( $user, $title );

		if ( $isAuthorized ) {
			return true;
		}

		if ( !self::isOwned( $title ) ) {
			return true;
		}

		list( $role, $permissions ) = self::permissionsOfPage( $title, $user );

		if ( !empty( $role ) ) {
			return true;
		}

		// \PageOwnershipFunctions::disableCaching( $parser );

		// Display error text instead of template.
		$msgKey = 'pageownership-denied-transclusion-' . ( $user->isAnon() ? 'anonymous' : 'registered' );

		$text = wfMessage( $msgKey )->plain();

		return false;
	}

	/**
	 * @param OutputPage $outputPage
	 * @param Skin $skin
	 */
	public static function onBeforePageDisplay( OutputPage $outputPage, Skin $skin ) {
		global $wgResourceBasePath;

		\PageOwnershipFunctions::addHeaditem( $outputPage, [
			[ 'stylesheet', $wgResourceBasePath . '/extensions/PageOwnership/resources/style.css' ],
		] );
	}

	/**
	 * @see SemanticACL/SemanticACL.class.php
	 * @param SMW\Store $store
	 * @param SMWQueryResult &$queryResult
	 * @return bool
	 */
	public static function onSMWStoreAfterQueryResultLookupComplete( SMW\Store $store, SMWQueryResult &$queryResult ) {
		/* NOTE: this filtering does not work with count queries. To do filtering on count queries, we would
		 * have to use SMW::Store::BeforeQueryResultLookupComplete to add conditions on ACL properties.
		 * However, doing that would make it extremely difficult to tweak caching on results.
		 */
		$filtered = [];
		// If the result list was changed.
		$changed = false;

		$user = self::getUser();

		$isAuthorized = \PageOwnershipFunctions::isAuthorized( $user, null );

		if ( $isAuthorized ) {
			return;
		}

		foreach ( $queryResult->getResults() as $result ) {
			$title = $result->getTitle();
			if ( !$title instanceof Title ) {
				// T296559
				continue;
			}

			// not an owned page
			if ( !self::isOwned( $title ) ) {
				$filtered[] = $result;
				continue;
			}

			list( $role, $permissions ) = self::permissionsOfPage( $title, $user );

			if ( !empty( $role ) ) {
				$filtered[] = $result;

			} else {
				$changed = true;
			}
		}

		if ( !$changed ) {
			// No changes to the query results.
			return;
		}

		// \PageOwnershipFunctions::disableCaching();

		// Build a new query result object
		$queryResult = new SMWQueryResult(
			$queryResult->getPrintRequests(),
			$queryResult->getQuery(),
			$filtered,
			$store,
			$queryResult->hasFurtherResults()
		);
	}

	/**
	 * @see SemanticACL/SemanticACL.class.php
	 * @param WikiPage $wikiPage
	 * @param User $user
	 * @param string $reason
	 * @param int $id
	 * @param Content $content
	 * @param LogEntry $logEntry
	 * @param int $archivedRevisionCount
	 */
	public static function onArticleDeleteComplete(
		WikiPage $wikiPage, User $user, string $reason, int $id, Content $content,
		LogEntry $logEntry, int $archivedRevisionCount
	) {
		self::deleteOwnershipData( [ 'page_id' => $id ] );
	}

	/**
	 * @param array $conds
	 */
	public static function deleteOwnershipData( $conds ) {
		$dbw = wfGetDB( DB_PRIMARY );

		$dbw->delete(
			'page_ownership',  $conds,
			__METHOD__
		);
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Variable
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/GetMagicVariableIDs
	 * @param string[] &$aCustomVariableIds
	 * @return bool
	 */
	public static function onMagicWordwgVariableIDs( &$aCustomVariableIds ) {
		$variables = [
			'pageownership_userpages',
		];

		foreach ( $variables as $var ) {
			$aCustomVariableIds[] = $var;
		}

		// Permit future callbacks to run for this hook.
		// never return false since this will prevent further callbacks AND indicate we found no value!
		return true;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ParserGetVariableValueSwitch
	 * @param Parser $parser
	 * @param array &$variableCache
	 * @param string $magicWordId
	 * @param string &$ret
	 * @param PPFrame $frame
	 * @return bool|void
	 */
	public static function onParserGetVariableValueSwitch( $parser, &$variableCache, $magicWordId, &$ret, $frame ) {
		$user = $parser->getUserIdentity() ?? self::getUser();

		switch ( $magicWordId ) {
			case 'pageownership_userpages':
				$userpages = self::userpages( $user );
				$ret = implode( ',', $userpages );
				break;

			default:
		}

		$variableCache[$magicWordId] = $ret;

		// Permit future callbacks to run for this hook.
		// never return false since this will prevent further callbacks AND indicate we found no value!
		return true;
	}

	/**
	 * @param SkinTemplate $skinTemplate
	 * @param array &$links
	 */
	public static function onSkinTemplateNavigation( SkinTemplate $skinTemplate, array &$links ) {
		$user = $skinTemplate->getUser();

		if ( !$user || !$user->isRegistered() ) {
			return;
		}

		$title = $skinTemplate->getTitle();

		if ( $title->getNamespace() != NS_MAIN ) {
			return;
		}

		// @contributor Umherirrender
		if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
			// MW 1.36+
			$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		} else {
			$wikiPage = WikiPage::factory( $title );
		}
		$creator = $wikiPage->getCreator();

		$isAuthorized = \PageOwnershipFunctions::isAuthorized( $user, $title );

		if ( !$isAuthorized ) {
			list( $role, $permissions ) = self::permissionsOfPage( $title, $user );

			if ( $role == 'admin' ) {
				$isAuthorized = true;
			}
		}

		if ( $isAuthorized ) {
			$url = SpecialPage::getTitleFor( 'PageOwnership', $title )->getLocalURL();
			$links[ 'actions' ][] = [ 'text' => wfMessage( 'pageownership-navigation' )->text(), 'href' => $url ];
		}
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

		$page_ancestors = \PageOwnershipFunctions::page_ancestors( $title, false );

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
	 * @param \Wikimedia\Rdbms\DBConnRef $dbr
	 * @param User $user
	 * @return array
	 */
	public static function groupsCond( $dbr, $user ) {
		$userGroupManager = self::getUserGroupManager();
		$user_groups = \PageOwnershipFunctions::getUserGroups( $userGroupManager, $user, true );
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

		$page_ancestors = \PageOwnershipFunctions::page_ancestors( $title, false );

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
	 * *** Register any render callbacks with the parser
	 * @param Parser $parser
	 */
	public static function OnParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook( 'pageownership_userpages', [ self::class, 'pageownership_userpages' ] );
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
	 * @param Skin $skin
	 * @param array &$bar
	 */
	public static function OnSkinBuildSidebar( $skin, &$bar ) {
		$user = $skin->getUser();

		if ( !$user || !$user->isRegistered() ) {
			return;
		}

		$pages = self::getUserPagesDB( $user );

		foreach ( $pages as $page ) {
			$title = Title::newFromID( $page['page_id'] );

			if ( !$title ) {
				continue;
			}

			$bar[ wfMessage( 'pageownership-sidebar-section' )->text() ][] = [
				'text'   => $title->getText() . ( $page['role'] == 'reader' ? ' (' . wfMessage( 'pageownership-sidebar-role-reader' )->text() . ')' : '' ),
				'href'   => $title->getLocalURL()
			];
		}
	}
}
