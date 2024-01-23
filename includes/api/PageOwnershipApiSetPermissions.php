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
 * @copyright Copyright ©2021-2024, https://wikisphere.org
 */

class PageOwnershipApiSetPermissions extends ApiBase {

	/**
	 * @inheritDoc
	 */
	public function isWriteMode() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function mustBePosted(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$user = $this->getUser();
		$allowedGroups = [ 'sysop', 'bureaucrat', 'pageownership-admin' ];
		$userGroups = \PageOwnership::getUserGroups( $user, true );

		if ( !$user->isAllowed( 'pageownership-canmanagepermissions' )
			// execute if user is in the admin group
			&& !count( array_intersect( $allowedGroups, $userGroups ) ) ) {
			$this->dieWithError( 'apierror-pageownership-permissions-error' );
		}

		$params = $this->extractRequestParams();
		$result = $this->getResult();

		$map = [
			'usernames' => 'usernames',
			'permissions-by-type' => 'permissions_by_type',
			// 'permissions-by-group' => 'permissions_by_group',
			'additional-rights' => 'additional_rights',
			'add-permissions' => 'add_permissions',
			'remove-permissions' => 'remove_permissions',
			'pageids' => 'pages',
			'namespaces' => 'namespaces'
		];

		$row = [];
		foreach ( $params as $key => $value ) {
			if ( !array_key_exists( $key, $map ) ) {
				continue;
			}
			$row[$map[$key]] = preg_split( '/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY );
		}

		if ( empty( $row['usernames'] ) ) {
			$this->dieWithError( 'apierror-pageownership-api-nousernames' );
		}

		$id = ( !empty( $params['id'] ) ? $params['id'] : null );
		$result_ = \PageOwnership::setPermissions( $user->getName(), $row, $id );

		$result->addValue( [ $this->getModuleName() ], 'result', $result_, ApiResult::NO_VALIDATE );
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams() {
		return [
			'usernames' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true
			],
			'permissions-by-type' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => false
			],
			// 'permissions-by-group' => [
			// 	ApiBase::PARAM_TYPE => 'string',
			// 	ApiBase::PARAM_REQUIRED => false
			// ],
			'additional-right' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => false
			],
			'add-permissions' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => false
			],
			'remove-permissions' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => false
			],
			'pageids' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => false
			],
			'namespaces' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => false
			],
			'id' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_REQUIRED => false
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function needsToken() {
		return 'csrf';
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamplesMessages() {
		return [
			'action=pageownership-set-permissions'
			=> 'apihelp-pageownership-set-permissions-example-1'
		];
	}
}
