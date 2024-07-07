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

class PageOwnershipHooks {

	/** @var admins */
	public static $admins = [ 'sysop', 'bureaucrat', 'interface-admin' ];

	/**
	 * @param DatabaseUpdater|null $updater
	 * @return void
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater = null ) {
		$base = __DIR__;
		$dbType = $updater->getDB()->getType();
		$array = [
			[
				'table' => 'pageownership_permissions',
				'filename' => '../' . $dbType . '/pageownership_permissions.sql'
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
	 * @param MediaWiki|MediaWiki\Actions\ActionEntryPoint $mediaWiki $mediaWiki
	 * @return void
	 */
	public static function onBeforeInitialize( \Title &$title, $unused, \OutputPage $output, \User $user, \WebRequest $request, $mediaWiki ) {
		\PageOwnership::initialize( $user );
	}

	/**
	 * @param string &$siteNotice
	 * @param Skin $skin
	 * @return bool
	 */
	public static function onSiteNoticeBefore( &$siteNotice, $skin ) {
		$user = \PageOwnership::getUser();
		$userGroups = \PageOwnership::getUserGroups( $user, true );

		if ( count( array_intersect( self::$admins, $userGroups ) ) ) {
			$dbr = \PageOwnership::getDB( DB_REPLICA );

			if ( !$dbr->tableExists( 'pageownership_permissions' ) ) {
				$siteNotice = '<div class="pageownership-sitenotice">' . wfMessage( 'pageownership-sitenotice-missing-table' )->plain() . '</div>';
				return false;
			}
		}
		return true;
	}

	/**
	 * @param Title $title
	 * @param User $user
	 * @param string $action
	 * @param array &$errors
	 * @param bool $doExpensiveQueries
	 * @param bool $short
	 * @return bool|void
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/getUserPermissionsErrors
	 */
	public static function onTitleQuickPermissions( $title, $user, $action, &$errors, $doExpensiveQueries, $short ) {
		// disable MediaWiki\Permissions\PermissionManager -> checkQuickPermissions
		return false;
	}

	/**
	 * @param Title $title
	 * @param User $user
	 * @param string $action
	 * @param array|string|MessageSpecifier &$result User
	 * @return bool
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/getUserPermissionsErrors
	 */
	public static function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
		global $wgWhitelistRead;

		if ( $action === 'read' && is_array( $wgWhitelistRead )
			&& in_array( $title->getFullText(), $wgWhitelistRead ) ) {
			return true;
		}

		if ( \PageOwnership::isAuthorized( $user ) ) {
			return true;
		}
		$ret = \PageOwnership::getPermissions( $title, $user, $action );

		if ( $ret !== null ) {
			// @TODO whitelist only if they aren't explicitly
			// forbidden
			// *** whitelist other pages ?
			$whitelistSpecials = [ 'UserLogin', 'CreateAccount' ];

			foreach ( $whitelistSpecials as $value ) {
				$special = SpecialPage::getTitleFor( $value );
				if ( $title->getFullText() === $special->getFullText() ) {
					return true;
				}
			}
		}

		if ( $ret !== false ) {
			return true;
		}

		$result = [ 'badaccess-group0' ];
		return false;
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

		if ( $user->isRegistered() ) {
			$confstr .= '!registered_user';
		}
	}

	/**
	 * *** ignore the cache if a page contains a transcluded page with stored permissions
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
				if ( \PageOwnership::permissionsExist( $title ) ) {
					return false;
				}
			}
		}
	}

	/**
	 * @param array &$defaults
	 * @param array &$inCacheKey
	 * @param array &$lazyLoad
	 * @return bool|void True or no return value to continue or false to abort
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

		$isAuthorized = \PageOwnership::isAuthorized( $user );

		if ( $isAuthorized ) {
			return true;
		}

		if ( \PageOwnership::getPermissions( $title, $user ) !== false ) {
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

		$isAuthorized = \PageOwnership::isAuthorized( $user );

		if ( $isAuthorized ) {
			return true;
		}

		if ( \PageOwnership::getPermissions( $title, $user ) !== false ) {
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

		// this is used only if DOMDocument is not available
		$outputPage->addHeadItem( 'pageownership_groupsusersmultiselectwidget_input',
			'<style>#pageownership-form .mw-widgets-tagMultiselectWidget-multilineTextInputWidget{ display: none; } </style>' );
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

		$isAuthorized = \PageOwnership::isAuthorized( $user );

		if ( $isAuthorized ) {
			return;
		}

		foreach ( $queryResult->getResults() as $result ) {
			$title = $result->getTitle();
			if ( !$title instanceof Title ) {
				// T296559
				continue;
			}

			if ( \PageOwnership::getPermissions( $title, $user ) !== false ) {
				$filtered[] = $result;
				continue;
			}

			$changed = true;
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
		// @TODO update to new version
		// \PageOwnership::deleteOwnershipData( [ 'page_id' => $id ] );
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

		if ( !$title->isKnown() || $title->isSpecialPage() ) {
			return;
		}

		$isAuthorized = \PageOwnership::isAuthorized( $user );

		if ( !$isAuthorized ) {
			$isAuthorized = $user->isAllowed( 'pageownership-canmanagepermissions' );
		}

		if ( !$isAuthorized ) {
			$isAuthorized = \PageOwnership::getPermissions( $title, $user, "pageownership-caneditpermissions" );
		}

		if ( $isAuthorized ) {
			$url = SpecialPage::getTitleFor( 'PageOwnershipPermissions', $title )->getLocalURL();
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
		if ( isset( $GLOBALS['wgPageOwnershipDisableSidebarPages'] ) ) {
			return;
		}

		$user = $skin->getUser();

		if ( !$user || !$user->isRegistered() ) {
			return;
		}

		$pages = \PageOwnership::getUserPagesDB( $user );

		foreach ( $pages as $page ) {
			$title = Title::newFromID( $page );

			if ( !$title ) {
				continue;
			}

			$bar[ wfMessage( 'pageownership-sidebar-section' )->text() ][] = [
				// @TODO add "reader"
				// . ( $page['role'] === 'reader' ? ' (' . wfMessage( 'pageownership-sidebar-role-reader' )->text() . ')' : '' ),
				'text'   => $title->getText(),
				'href'   => $title->getLocalURL()
			];
		}
	}
}
