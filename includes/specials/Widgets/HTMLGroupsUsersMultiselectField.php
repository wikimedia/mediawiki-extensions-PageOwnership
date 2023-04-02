<?php

include_once __DIR__ . '/GroupsUsersMultiselectWidget.php';

use MediaWiki\MediaWikiServices;
use Wikimedia\IPUtils;

class HTMLGroupsUsersMultiselectField extends HTMLUsersMultiselectField {

	/** @var groups */
	private $groups;

	/** @var userFactory */
	private $userFactory;

	/**
	 * @inheritDoc
	 */
	public function __construct( $params ) {
		$params = wfArrayPlus2d( $params, [
				'exists' => false,
				'ipallowed' => false,
				'iprange' => false,
				'iprangelimits' => [
					'IPv4' => 0,
					'IPv6' => 0,
				],
			]
		);

		$this->groups = $this->groupsList();
		$this->userFactory = MediaWikiServices::getInstance()->getUserFactory();

		parent::__construct( $params );
	}

	/**
	 * @inheritDoc
	 */
	public function loadDataFromRequest( $request ) {
		$value = $request->getText( $this->mName, $this->getDefault() );

		$usersArray = explode( "\n", $value );
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
				$normalizedUsers[] = $user;
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
		return implode( "\n", $uniqueUsers );
	}

	/**
	 * @inheritDoc
	 */
	public function validate( $value, $alldata ) {
		if ( !$this->mParams['exists'] ) {
			return true;
		}

		if ( $value === null ) {
			return false;
		}

		// $value is a string, because HTMLForm fields store their values as strings
		$usersArray = explode( "\n", $value );

		if ( isset( $this->mParams['max'] ) && ( count( $usersArray ) > $this->mParams['max'] ) ) {
			return $this->msg( 'htmlform-multiselect-toomany', $this->mParams['max'] );
		}

		foreach ( $usersArray as $value ) {

			// ***edited
			if ( in_array( $value, $this->groups ) ) {
				continue;
			}

			$result = parent::validate( $value, $alldata );
			if ( $result !== true ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * @see includes/specials/SpecialListGroupRights.php
	 * @return bool
	 */
	private function groupsList() {
		$ret = [];

		$context = RequestContext::getMain();
		$config = $context->getConfig();

		// $config = $this->getConfig();
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

		if ( method_exists( Language::class, 'getGroupName' ) ) {
			// MW 1.38+
			// $lang = $this->getLanguage();
			$lang = $context->getLanguage();
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
	 * @inheritDoc
	 */
	public function getInputOOUI( $value ) {
		$params = [ 'name' => $this->mName ];

		if ( isset( $this->mParams['id'] ) ) {
			$params['id'] = $this->mParams['id'];
		}

		if ( isset( $this->mParams['disabled'] ) ) {
			$params['disabled'] = $this->mParams['disabled'];
		}

		if ( isset( $this->mParams['default'] ) ) {
			$params['default'] = $this->mParams['default'];
		}

		if ( isset( $this->mParams['placeholder'] ) ) {
			$params['placeholder'] = $this->mParams['placeholder'];
		} else {
			$params['placeholder'] = $this->msg( 'mw-widgets-usersmultiselect-placeholder' )->plain();
		}

		if ( isset( $this->mParams['max'] ) ) {
			$params['tagLimit'] = $this->mParams['max'];
		}

		if ( isset( $this->mParams['ipallowed'] ) ) {
			$params['ipAllowed'] = $this->mParams['ipallowed'];
		}

		if ( isset( $this->mParams['iprange'] ) ) {
			$params['ipRangeAllowed'] = $this->mParams['iprange'];
		}

		if ( isset( $this->mParams['iprangelimits'] ) ) {
			$params['ipRangeLimits'] = $this->mParams['iprangelimits'];
		}

		if ( isset( $this->mParams['input'] ) ) {
			$params['input'] = $this->mParams['input'];
		}

		// ***edited
		if ( isset( $this->mParams['options'] ) ) {
			$params['options'] = $this->mParams['options'];
		}

		// ***edited
		if ( empty( $params['allowedValues'] ) ) {
			$params['allowedValues'] = array_keys( $params['options'] );
		}

		if ( $value !== null ) {
			// $value is a string, but the widget expects an array
			$params['default'] = $value === '' ? [] : explode( "\n", $value );
		}

		// Make the field auto-infusable when it's used inside a legacy HTMLForm rather than OOUIHTMLForm
		$params['infusable'] = true;
		$params['classes'] = [ 'mw-htmlform-field-autoinfuse' ];
		$widget = new \GroupsUsersMultiselectWidget( $params );
		$widget->setAttributes( [ 'data-mw-modules' => implode( ',', $this->getOOUIModules() ) ] );

		return $widget;
	}

	/**
	 * @inheritDoc
	 */
	protected function getOOUIModules() {
		return [ 'ext.PageOwnership.GroupsUsersMultiselectWidget' ];
	}
}
