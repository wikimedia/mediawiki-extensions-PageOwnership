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

use MediaWiki\Linker\LinkRenderer;

class PageOwnershipPermissionsPager extends TablePager {

	/** @var title */
	private $title;

	/** @var request */
	private $request;

	/** @var parentClass */
	private $parentClass;

	/**
	 * @param SpecialPageOwnership $parentClass
	 * @param Request $request
	 * @param LinkRenderer $linkRenderer
	 */
	public function __construct( $parentClass, $request, LinkRenderer $linkRenderer ) {
		parent::__construct( $parentClass->getContext(), $linkRenderer );

		$this->title = $parentClass->title;
		$this->request = $request;
		$this->parentClass = $parentClass;
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

		if ( !$this->title ) {
			$headers = [
				'created_by' => 'pageownership-managepermissions-pager-header-created_by',
			];
		}

		$headers = array_merge( $headers, [
			'usernames' => 'pageownership-managepermissions-pager-header-usernames',
			'permissions_by_type' => 'pageownership-managepermissions-pager-header-permissions-bytype',
			'additional_rights' => 'pageownership-managepermissions-pager-header-additional-rights',
			'add_permissions' => 'pageownership-managepermissions-pager-header-add-permissions',
			'remove_permissions' => 'pageownership-managepermissions-pager-header-remove-permissions',
		] );

		if ( !$this->title ) {
			$headers = array_merge( $headers, [
				'pages' => 'pageownership-managepermissions-pager-header-pages',
				'namespaces' => 'pageownership-managepermissions-pager-header-namespaces',
			] );
		}

		$headers = array_merge( $headers, [
			'actions' => 'pageownership-managepermissions-pager-header-actions',
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
			case 'created_by':
				$formatted = $row->created_by;
				break;

			case 'usernames':
				$formatted = implode( ', ', explode( ',', $this->parentClass->normalizeUsernames( $row->usernames, "," ) ) );
				break;

			case 'permissions_by_type':
				$self = $this;
				$formatted = implode( ', ', array_map( static function ( $value ) use ( $self ) {
					return $self->msg( "pageownership-permissions-actions-label-" . $value );
				}, array_filter( explode( ',', $row->permissions_by_type ) ) ) );
				break;

			case 'additional_rights':
			case 'add_permissions':
			case 'remove_permissions':
				$row = (array)$row;
				$arr = explode( ',', $row[$field] );
				$options = $this->parentClass->rightsMessages;
				$formatted = implode( ', ', array_map( static function ( $value ) use( $options ) {
					return $options[$value];
				}, array_filter( $arr ) ) );
				break;

			case 'pages':
				$formatted = implode( ', ', \PageOwnership::pageIDsToText( explode( ',', $row->pages ) ) );
				break;

			case 'namespaces':
				if ( $row->namespaces !== null && $row->namespaces !== "" ) {
					$options = $this->parentClass->namespacesOptions();
					$formatted = implode( ', ', array_map( static function ( $value ) use( $options ) {
						return $options[$value];
					}, explode( ',', $row->namespaces ) ) );
				} else {
					$formatted = "";
				}
				break;

			case 'actions':
				$link = '<span class="mw-ui-button mw-ui-progressive">edit</span>';
				$title = SpecialPage::getTitleFor( 'PageOwnershipPermissions', $this->title );
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

		$tables = [ 'pageownership_permissions' ];
		$fields = [ '*' ];
		$join_conds = [];
		$conds = [];
		$options = [];

		$created_by = $this->request->getVal( 'created_by' );

		if ( !empty( $created_by ) ) {
			$conds[ 'created_by' ] = $created_by;
		}

		if ( $this->title ) {
			$conds[] = 'FIND_IN_SET(' . $this->title->getArticleID() . ', pages)';
		}

		foreach ( [ 'usernames', 'permissions_by_type', 'additional_rights', 'add_permissions', 'remove_permissions', 'pages', 'namespaces' ] as $value ) {
			if ( !empty( $this->request->getVal( $value ) ) ) {
				$values = preg_split( "/[\r\n]+/", $this->request->getVal( $value ), -1, PREG_SPLIT_NO_EMPTY );
				if ( $value === 'pages' ) {
					$values = \PageOwnership::titleTextsToIDs( $values );
				}

				$sql = [];
				array_map( function ( $val ) use ( &$sql, $value ) {
					$sql[] = 'FIND_IN_SET(' . $this->mDb->addQuotes( $val ) . ', ' . $value . ')';
				}, $values );

				$conds += [ $this->mDb->makeList( $sql, LIST_OR ) ];
			}
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
		return parent::getTableClass() . ' pageownership-managepermissions-pager-table';
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
