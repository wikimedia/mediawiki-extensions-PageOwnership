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

class PageOwnershipHooks {

	/**
	 * @param DatabaseUpdater|null $updater
	 * @return void
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
		\PageOwnership::initialize( $user );
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param MediaWiki\User\UserIdentity $user
	 * @param string $summary
	 * @param int $flags
	 * @param MediaWiki\Revision\RevisionRecord $revisionRecord
	 * @param MediaWiki\Storage\EditResult $editResult
	 * @return bool|void
	 */
	public static function onPageSaveComplete( WikiPage $wikiPage, MediaWiki\User\UserIdentity $user, string $summary, int $flags, MediaWiki\Revision\RevisionRecord $revisionRecord, MediaWiki\Storage\EditResult $editResult ) {
		// see onGetUserPermissionsErrors
		// *** this is a way to enforce *implicit moderation*
		if ( $editResult->isNew() ) {
			$is_allowed = $user->isAllowed( 'pageownership-caneditunassignedpages' );
			if ( !$is_allowed ) {
				$onCreateUnassignedPageAssignTo = \PageOwnership::getGlobalParameterAsArray( 'wgPageOwnershipOnCreateUnassignedPageAssignTo' );
				$title = $wikiPage->getTitle();

				if ( count( $onCreateUnassignedPageAssignTo ) && !\PageOwnership::isOwned( $title ) ) {
					// add the current user as editor
					\PageOwnership::setPageOwnership( 'onCreateUnassignedPageAssignTo', $title, [ $user->getName() ], [ 'edit', 'create', 'subpages' ], 'editor' );

					// add the content of $wgPageOwnershipOnCreateUnassignedPagesAssignTo as admin
					\PageOwnership::setPageOwnership( 'onCreateUnassignedPageAssignTo', $title, $onCreateUnassignedPageAssignTo, [], 'admin' );
				}
			}
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

		$isAuthorized = \PageOwnership::isAuthorized( $user, $title );

		if ( $isAuthorized ) {
			return true;
		}

		// not an owned page
		if ( !\PageOwnership::isOwned( $title ) ) {

			if ( $action == 'edit' || $new_page ) {
				$is_allowed = $user->isAllowed( 'pageownership-caneditunassignedpages' );
			}

			if ( $new_page ) {
				// *** this is a way to enforce *implicit moderation*
				$onCreateUnassignedPageAssignTo = \PageOwnership::getGlobalParameterAsArray( 'wgPageOwnershipOnCreateUnassignedPageAssignTo' );
				if ( !$is_allowed && count( $onCreateUnassignedPageAssignTo ) ) {
					return true;
				}
			}

			if ( $action == 'edit' ) {
				if ( !$is_allowed ) {
					if ( !$logged_in ) {
						$result = [ 'badaccess-group0' ];

					} else {
						// *** this is correct but ignored on page edit
						$groupLinks = \PageOwnership::getGroupLinks( [ 'pageownership-admin', 'pageownership-editorofunassignedpages' ] );

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

		// owned page
		list( $role, $permissions ) = \PageOwnership::permissionsOfPage( $title, $user );

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

		// \PageOwnership::disableCaching();

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
		$user = \PageOwnership::getUser();
		if ( $user->isRegistered() ) {
			$title = $wikiPage->getTitle();
			$transcludedTemplates = $title->getTemplateLinksFrom();

			foreach ( $transcludedTemplates as $title_ ) {
				if ( \PageOwnership::isOwned( $title_ ) ) {
					return false;
				}
			}
		}
	}

	/**
	 * @param array &$defaults Options and their defaults
	 * @param array &$inCacheKey Whether each option splits the parser cache
	 * @param array &$lazyLoad Initializers for lazy-loaded options
	 */
	public static function onParserOptionsRegister( &$defaults, &$inCacheKey, &$lazyLoad ) {
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

		$user = \PageOwnership::getUser();

		$isAuthorized = \PageOwnership::isAuthorized( $user, $title );

		if ( $isAuthorized ) {
			return true;
		}

		if ( !\PageOwnership::isOwned( $title ) ) {
			return true;
		}

		list( $role, $permissions ) = \PageOwnership::permissionsOfPage( $title, $user );

		if ( !empty( $role ) ) {
			return true;
		}

		// \PageOwnership::disableCaching();

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
	 * @return bool|void
	 */
	public static function onParserFetchTemplate( $parser, $title, $rev, &$text, &$deps ) {
		$user = $parser->getUserIdentity() ?? \PageOwnership::getUser();

		$isAuthorized = \PageOwnership::isAuthorized( $user, $title );

		if ( $isAuthorized ) {
			return true;
		}

		if ( !\PageOwnership::isOwned( $title ) ) {
			return true;
		}

		list( $role, $permissions ) = \PageOwnership::permissionsOfPage( $title, $user );

		if ( !empty( $role ) ) {
			return true;
		}

		// \PageOwnership::disableCaching( $parser );

		// Display error text instead of template.
		$msgKey = 'pageownership-denied-transclusion-' . ( $user->isAnon() ? 'anonymous' : 'registered' );

		$text = wfMessage( $msgKey )->plain();

		return false;
	}

	/**
	 * @param OutputPage $outputPage
	 * @param Skin $skin
	 * @return void
	 */
	public static function onBeforePageDisplay( OutputPage $outputPage, Skin $skin ) {
		global $wgResourceBasePath;

		\PageOwnership::addHeaditem( $outputPage, [
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

		$user = \PageOwnership::getUser();

		$isAuthorized = \PageOwnership::isAuthorized( $user, null );

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
			if ( !\PageOwnership::isOwned( $title ) ) {
				$filtered[] = $result;
				continue;
			}

			list( $role, $permissions ) = \PageOwnership::permissionsOfPage( $title, $user );

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

		// \PageOwnership::disableCaching();

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
	 * @return bool|void
	 */
	public static function onArticleDeleteComplete(
		WikiPage $wikiPage, User $user, string $reason, int $id, Content $content,
		LogEntry $logEntry, int $archivedRevisionCount
	) {
		\PageOwnership::deleteOwnershipData( [ 'page_id' => $id ] );
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
		$user = $parser->getUserIdentity() ?? \PageOwnership::getUser();

		switch ( $magicWordId ) {
			case 'pageownership_userpages':
				$userpages = \PageOwnership::userpages( $user );
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
	 * @return void
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

		$isAuthorized = \PageOwnership::isAuthorized( $user, $title );

		if ( !$isAuthorized ) {
			list( $role, $permissions ) = \PageOwnership::permissionsOfPage( $title, $user );

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
	 * *** Register any render callbacks with the parser
	 * @param Parser $parser
	 * @return bool|void
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook( 'pageownership_userpages', [ \PageOwnership::class, 'pageownership_userpages' ] );
	}

	/**
	 * @param Skin $skin
	 * @param array &$bar
	 * @return bool|void
	 */
	public static function onSkinBuildSidebar( $skin, &$bar ) {
		$user = $skin->getUser();

		if ( !$user || !$user->isRegistered() ) {
			return;
		}

		$pages = \PageOwnership::getUserPagesDB( $user );

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
