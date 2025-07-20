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

class PageOwnershipApiGetPermissions extends ApiBase {

	/**
	 * @inheritDoc
	 */
	public function isWriteMode() {
		return false;
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

		$result = $this->getResult();
		$params = $this->extractRequestParams();

		$map = [
			'usernames' => 'usernames',
			'pageids' => 'pages',
			'namespaces' => 'namespaces',
			'created-by' => 'created_by'
		];

		$row = [];
		foreach ( $map as $key => $value ) {
			if ( isset( $params[$key] ) ) {
				$row[$value] = preg_split( '/\s*,\s*/', $params[$key], -1, PREG_SPLIT_NO_EMPTY );
			} else {
				$row[$value] = null;
			}
		}

		$errors = [];
		$output = \PageOwnership::getPermissions(
			$row['usernames'],
			$row['pages'],
			$row['namespaces'],
			$row['created_by'],
			$params['id'] ?? null,
			$errors
		);

		$result->addValue( [ $this->getModuleName() ], 'result', $output, ApiResult::NO_VALIDATE );
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
			'pageids' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => false
			],
			'namespaces' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => false
			],
			'created-by' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => false
			],
			'id' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_REQUIRED => false
			]
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
			'action=pageownership-get-permissions'
			=> 'apihelp-pageownership-get-permissions-example-1'
		];
	}
}
