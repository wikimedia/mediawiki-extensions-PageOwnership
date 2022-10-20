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

use MediaWiki\Linker\LinkRenderer;

class PageOwnershipPager extends TablePager {
	/** @var usernames */
	private $usernames;
	/** @var role */
	private $role;
	/** @var parentClass */
	private $parentClass;

	/**
	 * @param SpecialPageOwnership $parentClass
	 * @param string $created_by
	 * @param string $usernames
	 * @param string $role
	 * @param LinkRenderer $linkRenderer
	 */
	public function __construct( $parentClass, $created_by, $usernames, $role, LinkRenderer $linkRenderer ) {
		parent::__construct( $parentClass->getContext(), $linkRenderer );

		$this->parentClass = $parentClass;

		$this->created_by = ( !empty( $created_by ) ? $created_by : null );
		$this->usernames = ( !empty( $usernames ) ? $usernames : null );
		$this->role = ( !empty( $role ) ? $role : null );
	}

	/**
	 * @param IResultWrapper $result
	 */
	public function preprocessResults( $result ) {
	}

	/**
	 * @return array
	 */
	protected function getFieldNames() {
		$headers = [];

		if ( !$this->parentClass ) {
			return $headers;
		}

		if ( !$this->parentClass->title ) {
			$headers = [
				'page' => 'pageownership-manageownership-pager-header-page',
				'created_by' => 'pageownership-manageownership-pager-header-created_by',
			];
		}

		$headers = array_merge( $headers, [
			'usernames' => 'pageownership-manageownership-pager-header-usernames',
			'role' => 'pageownership-manageownership-pager-header-role',
			'permissions' => 'pageownership-manageownership-pager-header-permissions',
			'actions' => 'pageownership-manageownership-pager-header-actions',
		] );

		foreach ( $headers as $key => $val ) {
			$headers[$key] = $this->msg( $val )->text();
		}

		return $headers;
	}

	/**
	 * @param string $field
	 * @param string $value
	 * @return string HTML
	 * @throws MWException
	 */
	public function formatValue( $field, $value ) {
		/** @var object $row */
		$row = $this->mCurrentRow;
		$linkRenderer = $this->getLinkRenderer();

		switch ( $field ) {
			case 'page':
				$formatted = Linker::link( Title::newFromID( $row->page_id ) );
				break;

			case 'created_by':
				$formatted = $row->created_by;
				break;

			case 'usernames':
				$formatted = implode( ', ', explode( ',', $row->usernames ) );
				break;

			case 'role':
				$formatted = $row->role;
				break;

			case 'permissions':
				$formatted = ( $row->role != 'admin' ? implode( ', ', explode( ',', $row->permissions ) ) : 'all' );
				break;

			case 'actions':
				$link = '<span class="mw-ui-button mw-ui-progressive">edit</span>';
				$title = Title::newFromText( 'Special:PageOwnership' . ( $this->parentClass->title ? '/' . $this->parentClass->title->getText() : '' ) );
				$query = [ 'edit' => $row->id ];
				$formatted = Linker::link( $title, $link, [], $query );
				break;

			default:
				throw new MWException( "Unknown field '$field'" );
		}

		return $formatted;
	}

	/**
	 * @return array
	 */
	public function getQueryInfo() {
		$ret = [];

		$tables = [ 'page_ownership' ];
		$fields = [ '*' ];
		$join_conds = [];
		$conds = [];
		$options = [];

		if ( !empty( $this->created_by ) ) {
			$conds[ 'created_by' ] = $this->created_by;
		}

		if ( !empty( $this->usernames ) ) {
			$usernames = [];
			array_map( function ( $value ) use ( &$usernames ) {
				$usernames[] = 'FIND_IN_SET(' . $this->mDb->addQuotes( $value ) . ', usernames)';
			}, preg_split( "/[\r\n]+/", $this->usernames, -1, PREG_SPLIT_NO_EMPTY ) );

			$conds += [ $this->mDb->makeList( $usernames, LIST_OR ) ];
		}

		if ( !empty( $this->role ) && $this->role != 'all' ) {
			$conds[ 'role' ] = $this->role;
		}

		if ( $this->parentClass->title ) {
			$conds['page_id'] = $this->parentClass->title->getArticleID();
		}

		array_unique( $tables );

		$ret['tables'] = $tables;
		$ret['fields'] = $fields;
		$ret['join_conds'] = $join_conds;
		$ret['conds'] = $conds;
		$ret['options'] = $options;

		return $ret;
	}

	/**
	 * @return string
	 */
	protected function getTableClass() {
		return parent::getTableClass() . ' pageownership-manageownership-pager-table';
	}

	/**
	 * @return string
	 */
	public function getIndexField() {
		return 'created_at';
	}

	/**
	 * @return string
	 */
	public function getDefaultSort() {
		return 'created_at';
	}

	/**
	 * @param string $field
	 * @return bool
	 */
	protected function isFieldSortable( $field ) {
		// no index for sorting exists
		return false;
	}
}
