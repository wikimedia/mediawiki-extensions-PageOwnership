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
 * @author thomas-topway-it <business@topway.it>
 * @copyright Copyright ©2021-2023, https://wikisphere.org
 */

require_once __DIR__ . '/PageOwnershipPager.php';
include_once __DIR__ . '/HTMLGroupsUsersMultiselectField.php';

/**
 * A special page that lists protected pages
 *
 * @ingroup SpecialPage
 */
class SpecialPageOwnership extends SpecialPage {

	/** @var title */
	public $title;

	/** @var localTitle */
	public $localTitle;

	/** @var user */
	private $user;

	/** @var request */
	private $request;

	/** @var latest_id */
	private $latest_id;

	/**
	 * @inheritDoc
	 */
	public function __construct() {
		$listed = true;
		parent::__construct( 'PageOwnership', '', $listed );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		$this->requireLogin();

		$this->setHeaders();
		$this->outputHeader();

		$out = $this->getOutput();

		$out->addModuleStyles( 'mediawiki.special' );

		$out->addModules( 'ext.PageOwnership' );

		$title = Title::newFromText( $par );

		$user = $this->getUser();

		$isAuthorized = \PageOwnership::isAuthorized( $user, $title );

		if ( !$isAuthorized ) {
			if ( !$title ) {
				$this->displayRestrictionError();
				return;
			}

			list( $role, $permissions ) = \PageOwnership::permissionsOfPage( $title, $user );

			if ( $role != 'admin' ) {
				$this->displayRestrictionError();
				return;
			}
		}

		if ( $title && ( !$title->isKnown() || $title->isSpecialPage() ) ) {
			$title = null;

		} else {
			$this->localTitle = SpecialPage::getTitleFor( 'PageOwnership', $title );
		}

		$this->title = $title;

		$this->addHelpLink( 'Extension:PageOwnership' );

		$request = $this->getRequest();

		$this->request = $request;

		$this->user = $user;

		$id = $request->getVal( 'edit' );

		if ( $id ) {
			$this->editPermission( $request, $out );
			return;
		}

		if ( $title ) {
			$out->addWikiMsg(
				'pageownership-manageownership-return',
				$title->getFullText(),
				$title->getFullText()
			);
		}

		$created_by = $request->getVal( 'created_by' );
		$usernames = $request->getVal( 'usernames' );
		$role = $request->getVal( 'role' );

		$pager = new PageOwnershipPager(
			$this,
			$created_by,
			$usernames,
			$role,
			$this->getLinkRenderer()
		);

		$out->enableOOUI();

		$out->addWikiMsg( 'pageownership-manageownership-description',
			$this->msg( 'pageownership-manageownership-description-' . ( $this->title ? 'specific' : 'generic' ) )->text() );

		$layout = new OOUI\PanelLayout( [ 'expanded' => false, 'padded' => false, 'framed' => false ] );

		$layout->appendContent(
			new OOUI\FieldsetLayout(
				[
					'label' => $this->msg( 'pageownership-manageownership-form-newrole-legend' )->text(), 'items' => [
						new OOUI\ButtonWidget(
							[
								'href' => $this->localTitle->getLocalURL() . '?edit=new',
								'label' => $this->msg( 'pageownership-manageownership-form-button-new_user' )->text(),
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
			$out->addHTML( $this->showOptions( $created_by, $usernames, $role ) );
			$out->addHTML( '<br />' );
		}

		if ( $pager->getNumRows() ) {
			$out->addParserOutputContent( $pager->getFullOutput() );

		} else {
			$out->addWikiMsg( 'pageownership-manageownership-table-empty' );
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

		$dbr = wfGetDB( DB_MASTER );

		if ( !empty( $action ) ) {

			switch ( $action ) {
				case 'delete':
					\PageOwnership::deleteOwnershipData( [ 'id' => $id ] );
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
			$dbr = wfGetDB( DB_REPLICA );
			$row = $dbr->selectRow( 'page_ownership', '*', [ 'id' => $id ], __METHOD__ );
			$row = (array)$row;
		}

		if ( !$row || $row == [ false ] ) {
			if ( !$new ) {
				$out->addWikiMsg( 'pageownership-manageownership-form-missing-item' );
				$out->addHTML( '<br />' );
				return;
			}

			$row = [
				'usernames' => null,
				'page_id' => null,
				'role' => 'editor',
				'permissions' => null
			];

		} else {
			if ( !empty( $row['usernames'] ) ) {
				$row['usernames'] = str_replace( ",", "\n", $row['usernames'] );
			}
		}

		$formDescriptor = $this->getFormDescriptor( $row, $out );

		$htmlForm = new OOUIHTMLForm( $formDescriptor, $this->getContext(), 'pageownership-manageownership' );

		$htmlForm->setId( 'pageownership-form' );

		$htmlForm->setMethod( 'post' );

		$htmlForm->setSubmitCallback( [ $this, 'onSubmit' ] );

		$htmlForm->showCancel();

		$htmlForm->setCancelTarget( $this->localTitle->getLocalURL() );

		$htmlForm->setSubmitTextMsg( 'pageownership-manageownership-form-button-submit' );

		$out->addWikiMsg(
			'pageownership-manageownership-form-updated',
			$this->localTitle->getFullText()
		);

		$htmlForm->prepareForm();

		$result = $htmlForm->tryAuthorizedSubmit();

		// @TODO goback and list all rows
		$htmlForm->setAction(
			wfAppendQuery( $this->localTitle->getLocalURL(),
				'edit=' . ( !empty( $this->latest_id ) ? $this->latest_id : $id ) )
			// $this->localTitle->getLocalURL()
		);

		if ( !$new || $this->latest_id ) {
			$htmlForm->addButton(
				[
				'type' => 'button',
				'name' => 'action',
				'value' => 'delete',
				'href' => $this->localTitle->getLocalURL(),
				'label-message' => 'pageownership-manageownership-form-button-delete',
				'flags' => [ 'destructive' ]
				]
			);
		}

		$htmlForm->displayForm( $result );
	}

	/**
	 * @param array $row
	 * @param Output $out
	 * @return array
	 */
	protected function getFormDescriptor( $row, $out ) {
		$formDescriptor = [];

		$section_prefix = '';

		if ( !$this->title ) {
			$formDescriptor['page_name'] = [
				'label-message' => 'pageownership-manageownership-form-page-label',
				'type' => 'title',
				'name' => 'page_name',
				'required' => true,
				'creatable' => false,
				'exists' => true,
				'section' => $section_prefix . 'form-fieldset-main',
				'help-message' => 'pageownership-manageownership-form-page-help',
				'default' => ( !empty( $row[ 'page_id' ] ) ? Title::newFromID( $row[ 'page_id' ] )->getFullText() : null )
			];
		}

		HTMLForm::$typeMappings['groupsusersmultiselect'] = \HTMLGroupsUsersMultiselectField::class;

		$groups = $this->groupsList();

		$formDescriptor['usernames'] = [
			'label-message' => 'pageownership-manageownership-form-username-label',
			'type' => 'groupsusersmultiselect',
			'name' => 'usernames',
			'required' => true,
			'section' => $section_prefix . 'form-fieldset-main',
			'help-message' => 'pageownership-manageownership-form-username-help',
			'default' => $row['usernames'],
			'options' => array_flip( $groups ),
		];

		$submitted = $this->request->getCheck( 'usernames' );

		if ( $submitted ) {
			$role = $this->request->getVal( 'role' );
		} else {
			$role = ( !empty( $row['role'] ) ? $row['role'] : 'editor' );
		}

		$formDescriptor['role'] = [
			'type' => 'select',
			'id' => 'pageownership_form_input_role',
			'section' => $section_prefix . 'form-fieldset-main',
			'name' => 'role',
			'required' => true,
			'label-message' => 'pageownership-manageownership-form-role-label',
			'options' => [ 'editor' => 'editor', 'admin' => 'admin', 'reader' => 'reader' ],
			'default' => $role,
			'help-message' => 'pageownership-manageownership-form-role-help'
		];

		if ( !$submitted ) {
			$permissions = ( !empty( $row['permissions'] ) ? explode( ',', $row['permissions'] ) : [] );

		} else {
			$permissions = [];

			// , 'manage properties'
			foreach ( [ 'role', 'edit', 'create', 'subpages' ] as $value ) {
				if ( $this->request->getBool( 'permissions_' . str_replace( ' ', '_', $value ) ) ) {
					$permissions[] = $value;
				}
			}
		}

		$out->addJsConfigVars( [
			'pageownership-manageownership-role' => $role,
			'pageownership-manageownership-permissions' => json_encode( $permissions ),
		] );

		$formDescriptor['permissions_edit'] = [
			'id' => 'pageownership_form_input_permissions_edit',
			'section' => $section_prefix . 'form-fieldset-main',
			'type' => 'toggle',
			'name' => 'permissions_edit',
			'default' => in_array( 'edit', $permissions ),
			'label-message' => 'pageownership-manageownership-form-permissions_edit-label',
			'help-message' => 'pageownership-manageownership-form-permissions_edit-help',
		];

		$formDescriptor['permissions_create'] = [
			'id' => 'pageownership_form_input_permissions_create',
			'section' => $section_prefix . 'form-fieldset-main',
			'type' => 'toggle',
			'name' => 'permissions_create',
			'default' => in_array( 'create', $permissions ),
			'label-message' => 'pageownership-manageownership-form-permissions_create-label',
			'help-message' => 'pageownership-manageownership-form-permissions_create-help',
		];

		// if ( class_exists('PageProperties') ) {
		// 	$formDescriptor['permissions_manage_properties'] = [
		// 		'id' => 'pageownership_form_input_permissions_manage_properties',
		// 		'section' => $section_prefix . 'form-fieldset-main',
		// 		'type' => 'toggle',
		// 		'name' => 'permissions_manage_properties',
		// 		'default' => in_array('manage properties', $permissions),
		// 		'label-message' => 'pageownership-manageownership-form-permissions_manage_properties-label',
		// 		'help-message' => 'pageownership-manageownership-form-permissions_manage_properties-help',
		// 	];
		//
		// }

		$formDescriptor['permissions_subpages'] = [
			'id' => 'pageownership_form_input_permissions_subpages',
			'section' => $section_prefix . 'form-fieldset-main',
			'type' => 'toggle',
			'default' => in_array( 'subpages', $permissions ),
			'name' => 'permissions_subpages',
			'label-message' => 'pageownership-manageownership-form-subpages-label',
			'help-message' => 'pageownership-manageownership-form-subpages-help',
		];

		return $formDescriptor;
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
	 * @param HtmlForm $htmlForm
	 * @return bool
	 */
	public function onSubmit( $data, $htmlForm ) {
		$request = $this->getRequest();

		$id = $request->getVal( 'edit' );

		$new = ( $id && $id === 'new' );

		$title = $this->title;

		if ( !$title ) {
			$title = Title::newFromText( $data[ 'page_name' ] );
		}

		if ( !$title ) {
			return false;
		}

		if ( empty( $data['usernames'] ) ) {
			return false;
		}

		$usernames = preg_split( "/[\r\n]+/", $data['usernames'], -1, PREG_SPLIT_NO_EMPTY );

		if ( !count( $usernames ) ) {
			return false;
		}

		$permissions = [];
		// , 'manage_properties'
		$permissions_list = [ 'edit', 'create', 'subpages' ];

		foreach ( $permissions_list as $value ) {
			if ( !empty( $request->getVal( 'permissions_' . $value ) ) ) {
				$permissions[] = str_replace( '_', ' ', $value );
			}
		}

		$role = $data['role'];

		\PageOwnership::setPageOwnership( $this->user->getName(), $title, $usernames, $permissions, $role, ( !$new ? $id : null ) );

		if ( $new ) {
			$dbr = wfGetDB( DB_MASTER );
			$this->latest_id = $dbr->selectField(
				'page_ownership',
				'id',
				[],
				__METHOD__,
				[ 'ORDER BY' => 'id DESC' ]
			);
		}

		\PageOwnership::invalidateCacheOfPagesWithAskQueriesRelatedToTitle( $this->title );
		\PageOwnership::invalidateCacheOfPagesWithTemplateLinksTo( $this->title );

		return true;
	}

	public function onSuccess() {
	}

	/**
	 * @param string $created_by
	 * @param array $usernames
	 * @param string $role
	 * @return string
	 */
	protected function showOptions( $created_by, $usernames, $role ) {
		$formDescriptor = [];

		$formDescriptor['created_by'] = [
			'label-message' => 'pageownership-manageownership-form-search-created_by-label',
			'type' => 'user',
			'name' => 'created_by',
			'required' => false,
			'help-message' => 'pageownership-manageownership-form-search-created_by-help',
			'default' => ( !empty( $created_by ) ? $created_by : null ),
		];

		HTMLForm::$typeMappings['groupsusersmultiselect'] = \HTMLGroupsUsersMultiselectField::class;

		$groups = $this->groupsList();

		$formDescriptor['usernames'] = [
			// 'id' => "abc",
			'label-message' => 'pageownership-manageownership-form-username-label',
			'type' => 'groupsusersmultiselect',
			'name' => 'usernames',
			// 'help-message' => 'pageownership-manageownership-form-username-help',
			'options' => array_flip( $groups ),
			'default' => ( !empty( $usernames ) ? $usernames : null ),
		];

		$formDescriptor['role'] = [
			'type' => 'select',
			'name' => 'role',
			'label-message' => 'pageownership-manageownership-form-search-role-label',
			'options' => [ '(all)' => 'all', 'editor' => 'editor', 'admin' => 'admin', 'reader' => 'reader' ],
			'default' => ( !empty( $role ) ? $role : 1 ),
			'help-message' => 'pageownership-manageownership-form-search-role-help'
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );

		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'pageownership-manageownership-form-search-legend' )
			->setSubmitText( $this->msg( 'pageownership-manageownership-form-search-submit' )->text() );

		return $htmlForm->prepareForm()->getHTML( false );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'pageownership';
	}

}
