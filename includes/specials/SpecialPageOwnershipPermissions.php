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

require_once __DIR__ . '/PageOwnershipPermissionsPager.php';
require_once __DIR__ . '/SpecialPageOwnershipForm.php';

include_once __DIR__ . '/Widgets/HTMLGroupsUsersMultiselectField.php';
include_once __DIR__ . '/Widgets/HTMLMenuTagMultiselectField.php';
include_once __DIR__ . '/Widgets/HTMLMultiToggleButtonField.php';

use MediaWiki\MediaWikiServices;
use Wikimedia\IPUtils;

/**
 * A special page that lists protected pages
 *
 * @ingroup SpecialPage
 */
class SpecialPageOwnershipPermissions extends SpecialPage {

	/** @var Title */
	public $title;

	/** @var Title */
	public $localTitle;

	/** @var User */
	private $user;

	/** @var Request */
	private $request;

	/** @var int */
	private $latest_id;

	/** @var array */
	private $groups;

	/** @var array */
	public $rightsMessages;

	/** @var array */
	private $allRights = [
		"read",
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
		"upload_by_url",
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
		"viewsuppressed",
		"autopatrol",
		"deletechangetags",
		"import",
		"importupload",
		"managechangetags",
		"siteadmin",
		"unwatchedpages",
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
	];

	/**
	 * @inheritDoc
	 */
	public function __construct() {
		$listed = true;
		parent::__construct( 'PageOwnershipPermissions', '', $listed );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		$this->requireLogin();

		$this->setHeaders();
		$this->outputHeader();

		$title = Title::newFromText( $par );

		if ( $title ) {
			if ( !$title->canExist() || !$title->isKnown()
				|| $title->getArticleID() === 0 ) {
				$title = null;
			}
		}

		$user = $this->getUser();
		$isAuthorized = \PageOwnership::isAuthorized( $user );

		if ( !$isAuthorized ) {
			if ( !$title ) {
				if ( !$user->isAllowed( 'pageownership-canmanagepermissions' ) ) {
					$this->displayRestrictionError();
					return;
				}

			} elseif ( !\PageOwnership::getPermissions( $title, $user, "pageownership-caneditpermissions" ) ) {
				$this->displayRestrictionError();
				return;
			}
		}

		$this->localTitle = SpecialPage::getTitleFor( 'PageOwnershipPermissions', $title );

		$this->title = $title;

		$out = $this->getOutput();

		$out->addModuleStyles( 'mediawiki.special' );

		$out->addModules( [ 'ext.PageOwnershipPermissions' ] );

		$out->setPageTitle( $this->msg( "pageownershippermissions" ) . ( $title ? ' (' . $title->getFullText() . ')' : '' ) );

		$this->addHelpLink( 'Extension:PageOwnership' );

		$request = $this->getRequest();

		$this->request = $request;

		$this->user = $user;

		$this->groups = $this->groupsList();

		$this->addJsConfigVars( $out );

		$id = $request->getVal( 'edit' );

		if ( $id ) {
			$this->editPermission( $request, $out );
			return;
		}

		if ( $title ) {
			$out->addWikiMsg(
				'pageownership-managepermissions-return',
				$title->getFullText(),
				$title->getFullText()
			);
		}

		$pager = new PageOwnershipPermissionsPager(
			$this,
			$request,
			$this->getLinkRenderer()
		);

		$out->enableOOUI();

		$out->addWikiMsg( 'pageownership-managepermissions-description-' . ( $this->title ? 'specific' : 'generic' ),
			!$this->title || !$user->isAllowed( 'pageownership-canmanagepermissions' ) ? '' : $this->msg( 'pageownership-managepermissions-description-manage-all-permissions' )->text() );

		$out->addHTML( '<br />' );

		$layout = new OOUI\PanelLayout( [ 'id' => 'pageownership-panel-layout', 'expanded' => false, 'padded' => false, 'framed' => false ] );

		$layout->appendContent(
			new OOUI\FieldsetLayout(
				[
					'label' => $this->msg( 'pageownership-managepermissions-form-button-addpermission-legend' )->text(), 'items' => [
						new OOUI\ButtonWidget(
							[
								'href' => wfAppendQuery( $this->localTitle->getLocalURL(), 'edit=new' ),
								'label' => $this->msg( 'pageownership-managepermissions-form-button-addpermission' )->text(),
								'infusable' => true,
								'flags' => [ 'progressive', 'primary' ],
							]
						)
					],
				]
			)
		);

		$out->addHTML( $layout );

		$out->addHTML( '<br />' );

		if ( empty( $par ) ) {
			$out->addHTML( $this->showOptions( $request ) );
			$out->addHTML( '<br />' );
		}

		if ( $pager->getNumRows() ) {
			$out->addParserOutputContent( $pager->getFullOutput() );

		} else {
			$out->addWikiMsg( 'pageownership-managepermissions-table-empty' );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function doesWrites() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function editPermission( $request, $out ) {
		$id = $request->getVal( 'edit' );
		$action = $request->getVal( 'action' );
		$new = ( $id && $id === 'new' );

		$dbr = \PageOwnership::wfGetDB( DB_MASTER );

		if ( !empty( $action ) ) {

			switch ( $action ) {
				case 'delete':
					\PageOwnership::deletePermissions( [ 'id' => $id ] );
					\PageOwnership::invalidateCacheOfPagesWithAskQueriesRelatedToTitle( $this->title );

					header( 'Location: ' . $this->localTitle->getLocalURL() );
					return;

				case 'cancel':
					header( 'Location: ' . $this->localTitle->getLocalURL() );
					return;
			}
		}

		$row = [];

		if ( !$new ) {
			$dbr = \PageOwnership::wfGetDB( DB_REPLICA );
			$row = $dbr->selectRow( 'pageownership_permissions', '*', [ 'id' => $id ], __METHOD__ );
			$row = (array)$row;
		}

		if ( !$row || $row == [ false ] ) {
			if ( !$new ) {
				$out->addWikiMsg( 'pageownership-managepermissions-form-missing-item' );
				$out->addHTML( '<br />' );
				return;
			}

			$row = [
				'usernames' => '',
				'permissions_by_type' => '',
				'additional_rights' => '',
				'add_permissions' => '',
				'remove_permissions' => '',
				'pages' => '',
				'namespaces' => '',
			];

		} else {

			foreach ( [ 'usernames', 'permissions_by_type', 'additional_rights', 'add_permissions', 'remove_permissions', 'namespaces' ] as $value ) {
				if ( !empty( $row[$value] ) ) {
					$row[$value] = str_replace( ",", "\n", $row[$value] );
				}
			}

			// if ( !empty( $row['usernames'] ) ) {
			// 	$row['usernames'] = $this->normalizeUsernames( $row['usernames'] );
			// }

			if ( !empty( $row['pages'] ) ) {
				$arr = explode( ',', $row['pages'] );
				$arr = \PageOwnership::pageIDsToText( $arr );
				$row['pages'] = implode( "\n", $arr );
			}
		}

		$formDescriptor = $this->getFormDescriptor( $row, $out );

		$messagePrefix = 'pageownership-managepermissions';
		$htmlForm = new SpecialPageOwnershipForm( $formDescriptor, $this->getContext(), $messagePrefix );

		$htmlForm->setId( 'pageownership-form-permissions' );

		$htmlForm->setMethod( 'post' );

		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );

		$htmlForm->showCancel();

		$htmlForm->setCancelTarget( $this->localTitle->getLocalURL() );

		$htmlForm->setSubmitTextMsg( 'pageownership-managepermissions-form-button-submit' );

		$out->addWikiMsg(
			'pageownership-managepermissions-form-returnlink',
			$this->localTitle->getFullText()
		);

		$out->addWikiMsg( 'pageownership-managepermissions-form-preamble-' . ( $this->title ? 'specific' : 'generic' ) );

		$htmlForm->prepareForm();

		$result = $htmlForm->tryAuthorizedSubmit();

		$htmlForm->setAction(
			wfAppendQuery( $this->localTitle->getLocalURL(),
				'edit=' . ( !empty( $this->latest_id ) ? $this->latest_id : $id ) )
		);

		if ( !$new || $this->latest_id ) {
			$htmlForm->addButton(
				[
				'type' => 'button',
				'name' => 'action',
				'value' => 'delete',
				'href' => $this->localTitle->getLocalURL(),
				'label-message' => 'pageownership-managepermissions-form-button-delete',
				'flags' => [ 'destructive' ]
				]
			);
		}

		$htmlForm->displayForm( $result );
	}

	/**
	 * @see HTMLUsersMultiselectField
	 * @param string $value
	 * @param string $sep
	 * @return string
	 */
	public function normalizeUsernames( $value, $sep = "\n" ) {
		$usersArray = explode( $sep, $value );

		// Remove empty lines
		$usersArray = array_values( array_filter( $usersArray, static function ( $username ) {
			return trim( $username ) !== '';
		} ) );

		// Normalize usernames
		$normalizedUsers = [];
		$userNameUtils = MediaWikiServices::getInstance()->getUserNameUtils();
		$listOfIps = [];
		foreach ( $usersArray as $user ) {

			// ***edited
			if ( in_array( $user, $this->groups ) ) {
				$normalizedUsers[] = array_search( $user, $this->groups );
				continue;
			}

			$canonicalUser = false;
			if ( IPUtils::isIPAddress( $user ) ) {
				$parsedIPRange = IPUtils::parseRange( $user );
				if ( !in_array( $parsedIPRange, $listOfIps ) ) {
					$canonicalUser = IPUtils::sanitizeRange( $user );
					$listOfIps[] = $parsedIPRange;
				}
			} else {
				$canonicalUser = $userNameUtils->getCanonical(
					$user, version_compare( MW_VERSION, '1.36', '>=' ) ? \MediaWiki\User\UserRigorOptions::RIGOR_NONE
					: \MediaWiki\User\UserNameUtils::RIGOR_NONE );
			}
			if ( $canonicalUser !== false ) {
				$normalizedUsers[] = $canonicalUser;
			}
		}
		// Remove any duplicate usernames
		$uniqueUsers = array_unique( $normalizedUsers );

		// This function is expected to return a string
		return implode( $sep, $uniqueUsers );
	}

	/**
	 * @return array
	 */
	public function namespacesOptions() {
		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		$options = $contLang->getFormattedNamespaces();
		$options[0] = $this->msg( 'blanknamespace' )->parse();
		asort( $options, SORT_STRING );

		return $options;
	}

	/**
	 * @param array $row
	 * @param Output $out
	 * @return array
	 */
	protected function getFormDescriptor( $row, $out ) {
		$formDescriptor = [];

		$section_prefix = '';

		HTMLForm::$typeMappings['groupsusersmultiselect'] = \HTMLGroupsUsersMultiselectField::class;
		HTMLForm::$typeMappings['multitogglebutton'] = \HTMLMultiToggleButtonField::class;
		HTMLForm::$typeMappings['menutagmultiselect'] = \HTMLMenuTagMultiselectField::class;

		$formDescriptor['usernames'] = [
			'label-message' => 'pageownership-managepermissions-form-username-label',
			'type' => 'groupsusersmultiselect',
			'name' => 'usernames',
			'required' => true,
			'exists' => true,
			'section' => $section_prefix . 'form-fieldset-permissions-main',
			'help-message' => 'pageownership-managepermissions-form-username-help',
			'default' => $row['usernames'],
			'options' => array_flip( $this->groups ),
		];

		$options = [];
		foreach ( [ 'reading', 'editing', 'management', 'administration', 'technical' ] as $value ) {
			$options[$value] = $this->msg( 'pageownership-permissions-actions-label-' . $value )->parse();
		}

		$formDescriptor['permissions_by_type'] = [
			'label-message' => 'pageownership-managepermissions-form-permissions-bytype-label',
			'type' => 'multitogglebutton',
			'name' => 'permissions_by_type',
			'required' => false,
			'id' => 'pageownership-permissions-field-permissions-bytype',
			'section' => $section_prefix . 'form-fieldset-permissions-main',
			'help-message' => 'pageownership-managepermissions-form-permissions-bytype-help',
			'default' => $row['permissions_by_type'],
			'options' => $options,
		];
		$context = $this->getContext();
		$availableRights = $context->getConfig()->get( 'AvailableRights' );
		$options = [];
		foreach ( $availableRights as $value ) {
			$options[$value] = strip_tags( $this->msg( 'right-' . $value )->parse() );
		}

		$formDescriptor['additional_rights'] = [
			'label-message' => 'pageownership-managepermissions-form-additional-rights-label',
			'type' => 'menutagmultiselect',
			'name' => 'additional_rights',
			'required' => false,
			'section' => $section_prefix . 'form-fieldset-permissions-main',
			'help-message' => 'pageownership-managepermissions-form-additional-rights-help',
			'default' => $row['additional_rights'],
			'options' => $options,
		];

		$options = $this->getPermissionsOptions();

		$formDescriptor['add_permissions'] = [
			'label-message' => 'pageownership-managepermissions-form-permissions-addpermissions-label',
			'type' => 'menutagmultiselect',
			'name' => 'add_permissions',
			'required' => false,
			'id' => 'pageownership-permissions-field-add-permissions',
			'section' => $section_prefix . 'form-fieldset-permissions-main',
			'help-message' => 'pageownership-managepermissions-form-permissions-addpermissions-help',
			'default' => $row['add_permissions'],

			// create options only for selected tags
			'options' => array_intersect_key( $this->rightsMessages, array_flip( explode( "\n", (string)$row['add_permissions'] ) ) )
		];

		$formDescriptor['remove_permissions'] = [
			'label-message' => 'pageownership-managepermissions-form-permissions-removepermissions-label',
			'type' => 'menutagmultiselect',
			'name' => 'remove_permissions',
			'required' => false,
			'id' => 'pageownership-permissions-field-remove-permissions',
			'section' => $section_prefix . 'form-fieldset-permissions-main',
			'help-message' => 'pageownership-managepermissions-form-permissions-removepermissions-help',
			'default' => $row['remove_permissions'],

			// create options only for selected tags
			'options' => array_intersect_key( $this->rightsMessages, array_flip( explode( "\n", (string)$row['remove_permissions'] ) ) )
		];

		if ( !$this->title ) {
			$formDescriptor['pages'] = [
				'label-message' => 'pageownership-managepermissions-form-permissions-pages-label',
				'type' => 'titlesmultiselect',
				'name' => 'pages',
				'section' => $section_prefix . 'form-fieldset-permissions-main',
				'help-message' => 'pageownership-managepermissions-form-permissions-pages-help',
				'default' => $row['pages'],
				'options' => array_flip( $this->groups ),
			];

			$formDescriptor['namespaces'] = [
				'label-message' => 'pageownership-managepermissions-form-permissions-namespaces-label',
				// 'type' => 'namespacesmultiselect',
				'type' => 'menutagmultiselect',
				'name' => 'namespaces',
				'section' => $section_prefix . 'form-fieldset-permissions-main',
				'help-message' => 'pageownership-managepermissions-form-permissions-namespaces-help',
				'default' => $row['namespaces'],
				'options' => $this->namespacesOptions(),
			];
		}

		$submitted = $this->request->getCheck( 'usernames' );

		return $formDescriptor;
	}

	/**
	 * @return void
	 */
	private function getPermissionsOptions() {
	}

	/**
	 * @param Output $out
	 */
	private function addJsConfigVars( $out ) {
		$context = $this->getContext();
		$groupPermissions = $context->getConfig()->get( 'GroupPermissions' );
		$availableRights = $context->getConfig()->get( 'AvailableRights' );

		$messages = [];
		foreach ( $groupPermissions as $key => $values ) {
			$msg = $this->msg( $key !== '*' ? 'group-' . $key . '-member' : "group-all" );
			$messages[$key] = ( $msg->exists() ? strip_tags( $msg->parse() ) : $key );

			foreach ( $values as $right => $value ) {
				if ( $value ) {
					$msg = $this->msg( 'right-' . $right );
					if ( !$msg->exists() ) {
						$msg = $this->msg( $right );
					}
					$messages[$right] = ( $msg->exists() ? strip_tags( $msg->parse() ) : $right );
				}
			}
		}

		foreach ( array_merge( $this->allRights, $availableRights ) as $value ) {
			if ( !array_key_exists( $value, $messages ) ) {
				$msg = $this->msg( 'right-' . $value );
				if ( !$msg->exists() ) {
					$msg = $this->msg( $value );
				}
				$messages[$value] = ( $msg->exists() ? strip_tags( $msg->parse() ) : $value );
			}
		}

		// @TODO is there a better way to add dynamically modules' messages ?
		$out->addJsConfigVars( [
			'pageownership-disableVersionCheck' => isset( $GLOBALS['wgPageOwnershipDisableVersionCheck'] ),
			'pageownership-canmanagepermissions' => $this->user->isAllowed( 'pageownership-canmanagepermissions' ),
			'pageownership-permissions-groupPermissions-messages' => json_encode( $messages )
		] );

		$this->rightsMessages = $messages;
	}

	/**
	 * @see includes/specials/SpecialListGroupRights.php
	 * @return bool
	 */
	private function groupsList() {
		$ret = [];

		$config = $this->getConfig();
		$groupPermissions = $config->get( 'GroupPermissions' );
		$revokePermissions = $config->get( 'RevokePermissions' );
		$addGroups = $config->get( 'AddGroups' );
		$removeGroups = $config->get( 'RemoveGroups' );
		$groupsAddToSelf = $config->get( 'GroupsAddToSelf' );
		$groupsRemoveFromSelf = $config->get( 'GroupsRemoveFromSelf' );
		$allGroups = array_unique(
			array_merge(
				array_keys( $groupPermissions ),
				array_keys( $revokePermissions ),
				array_keys( $addGroups ),
				array_keys( $removeGroups ),
				array_keys( $groupsAddToSelf ),
				array_keys( $groupsRemoveFromSelf )
			)
		);
		asort( $allGroups );

		$linkRenderer = $this->getLinkRenderer();
		if ( method_exists( Language::class, 'getGroupName' ) ) {
			// MW 1.38+
			$lang = $this->getLanguage();
		} else {
			$lang = null;
		}

		foreach ( $allGroups as $group ) {
			$permissions = $groupPermissions[ $group ] ?? [];

			// Replace * with a more descriptive groupname
			$groupname = ( $group == '*' )
			? 'all' : $group;

			if ( $lang !== null ) {
				// MW 1.38+
				$groupnameLocalized = $lang->getGroupName( $groupname );
			} else {
				$groupnameLocalized = UserGroupMembership::getGroupName( $groupname );
			}

			$ret[$groupnameLocalized] = $groupname;
		}

		return $ret;
	}

	/**
	 * @param array $data
	 * @param HTMLForm $htmlForm
	 * @return bool
	 */
	public function onSubmit( $data, $htmlForm ) {
		$request = $this->getRequest();

		$id = $request->getVal( 'edit' );

		$new = ( $id && $id === 'new' );

		if ( $this->title ) {
			$data['pages'] = $this->title->getFullText();
			$data['namespaces'] = '';
		}

		if ( empty( $data['usernames'] ) ) {
			return false;
		}

		$row = [];
		foreach ( [ 'usernames', 'permissions_by_type', 'additional_rights', 'add_permissions', 'remove_permissions', 'pages', 'namespaces' ] as $value ) {
			// extract( [ $value => preg_split( "/[\r\n]+/", $data[$value], -1, PREG_SPLIT_NO_EMPTY ) ] );
			$row[$value] = preg_split( "/[\r\n]+/", $data[$value], -1, PREG_SPLIT_NO_EMPTY );
		}

		\PageOwnership::setPermissions( $this->user->getName(), $row, ( !$new ? $id : null ) );

		if ( $new ) {
			$dbr = \PageOwnership::wfGetDB( DB_MASTER );
			$this->latest_id = $dbr->selectField(
				'pageownership_permissions',
				'id',
				[],
				__METHOD__,
				[ 'ORDER BY' => 'id DESC' ]
			);
		}

		foreach ( $row['pages'] as $value ) {
			$title_ = Title::newFromText( $value );
			if ( $title_ && $title_->isKnown() ) {
				\PageOwnership::invalidateCacheOfPagesWithAskQueriesRelatedToTitle( $title_ );
				\PageOwnership::invalidateCacheOfPagesWithTemplateLinksTo( $title_ );
			}
		}

		header( 'Location: ' . $this->localTitle->getLocalURL() );

		// return true;
	}

	public function onSuccess() {
	}

	/**
	 * @param Request $request
	 * @return string
	 */
	protected function showOptions( $request ) {
		$formDescriptor = [];

		$created_by = $request->getVal( 'created_by' );

		$formDescriptor['created_by'] = [
			'label-message' => 'pageownership-managepermissions-form-search-created_by-label',
			'type' => 'user',
			'name' => 'created_by',
			'required' => false,
			'help-message' => 'pageownership-managepermissions-form-search-created_by-help',
			'default' => ( !empty( $created_by ) ? $created_by : null ),
		];

		HTMLForm::$typeMappings['groupsusersmultiselect'] = \HTMLGroupsUsersMultiselectField::class;

		$usernames = $request->getVal( 'usernames' );

		$formDescriptor['usernames'] = [
			'label-message' => 'pageownership-managepermissions-form-username-label',
			'type' => 'groupsusersmultiselect',
			'name' => 'usernames',
			// 'help-message' => 'pageownership-managepermissions-form-username-help',
			'options' => array_flip( $this->groups ),
			'default' => ( !empty( $usernames ) ? $usernames : '' ),
		];

		// @TODO, add other fields ...

		$pages = $request->getVal( 'pages' );

		$formDescriptor['pages'] = [
			'label-message' => 'pageownership-managepermissions-form-pages-label',
			'type' => 'titlesmultiselect',
			'name' => 'pages',
			'default' => ( !empty( $pages ) ? $pages : null ),
		];

		HTMLForm::$typeMappings['menutagmultiselect'] = \HTMLMenuTagMultiselectField::class;

		$namespaces = $request->getVal( 'namespaces' );

		// @see SpecialBlock
		$formDescriptor['namespaces'] = [
			'label-message' => 'pageownership-managepermissions-form-namespaces-label',
			// 'type' => 'namespacesmultiselect',
			'type' => 'menutagmultiselect',
			'exists' => true,
			'name' => 'namespaces',
			'default' => ( !empty( $namespaces ) ? $namespaces : '' ),
			'options' => $this->namespacesOptions(),
		];

		$htmlForm = new SpecialPageOwnershipForm( $formDescriptor, $this->getContext() );

		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'pageownership-managepermissions-form-search-legend' )
			->setSubmitText( $this->msg( 'pageownership-managepermissions-form-search-submit' )->text() );

		return $htmlForm->prepareForm()->getHTML( false );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'pageownership';
	}
}
