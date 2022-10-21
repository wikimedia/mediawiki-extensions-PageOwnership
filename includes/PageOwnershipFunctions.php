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

class PageOwnershipFunctions {

	/**
	 * @param MediaWiki\User\UserGroupManager $userGroupManager
	 * @param User $user
	 * @param bool $replace_asterisk
	 * @return array
	 */
	public static function getUserGroups( $userGroupManager, $user, $replace_asterisk = false ) {
		$user_groups = $userGroupManager->getUserEffectiveGroups( $user );
		// $user_groups[] = $user->getName();

		if ( array_search( '*', $user_groups ) === false ) {
			$user_groups[] = '*';
		}

		if ( $replace_asterisk ) {
			$key = array_search( '*', $user_groups );
			$user_groups[ $key ] = 'all';
		}

		return $user_groups;
	}

	/**
	 * @param OutputPage $outputPage
	 * @param array $items
	 * @return array
	 */
	public static function addHeaditem( $outputPage, $items ) {
		foreach ( $items as $key => $val ) {

			list( $type, $url ) = $val;

			switch ( $type ) {
				case 'stylesheet':
					$item = '<link rel="stylesheet" href="' . $url . '" />';
					break;

				case 'script':
					$item = '<script src="' . $url . '"></script>';
					break;

			}

			// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
			$outputPage->addHeadItem( 'PageOwnership_head_item' . $key, $item );
		}
	}

	/**
	 * @param User $user
	 * @param Title $title
	 * @return bool
	 */
	public static function isAuthorized( $user, $title ) {
		$authorized = ( array_key_exists( 'wgPageOwnershipAuthorizedEditors', $GLOBALS ) ? $GLOBALS[ 'wgPageOwnershipAuthorizedEditors' ] : null );
		if ( empty( $authorized ) ) {
			$authorized = [];
		}
		if ( !is_array( $authorized ) ) {
			$authorized = preg_split( "/\s*,\s*/", $authorized, -1, PREG_SPLIT_NO_EMPTY );
		}
		$allowed_groups = [ 'sysop' ];
		$authorized = array_unique( array_merge( $authorized, [ 'sysop' ] ) );

		$userGroupManager = \PageOwnership::getUserGroupManager();

		// ***the following avoids that an user
		// impersonates a group through the username
		$all_groups = array_merge( $userGroupManager->listAllGroups(), $userGroupManager->listAllImplicitGroups() );

		$authorized_users = array_diff( $authorized, $all_groups );

		$authorized_groups = array_intersect( $authorized, $all_groups );

		$user_groups = self::getUserGroups( $userGroupManager, $user );

		$isAuthorized = count( array_intersect( $authorized_groups, $user_groups ) );

		if ( !$isAuthorized ) {
			$isAuthorized = in_array( $user->getName(), $authorized_users );
		}

		return $isAuthorized;
	}

	/**
	 * @param Title $title
	 * @param bool $exclude_current
	 * @return array
	 */
	public static function page_ancestors( $title, $exclude_current = true ) {
		$output = [];

		$title_parts = explode( '/', $title->getText() );

		if ( $exclude_current ) {
			array_pop( $title_parts );
		}

		$path = [];

		foreach ( $title_parts as $value ) {
			$path[] = $value;
			$title_text = implode( '/', $path );

			if ( $title->getText() == $title_text ) {
				$output[] = $title;

			} else {
				$title_ = Title::newFromText( $title_text );
				if ( $title_->isKnown() ) {
					$output[] = $title_;
				}
			}
		}

		return $output;
	}

	/**
	 * *** this avoids storing the parser output in the cache,
	 * *** not retrieving an uncached version of a page !!
	 * @param Parser|null $parser
	 */
	public static function disableCaching( $parser = null ) {
		if ( !$parser ) {
			$parser = \PageOwnership::$Parser;
		}

		if ( $parser->getOutput() ) {
			$parser->getOutput()->updateCacheExpiry( 0 );
		}

		// @credits Zabe
		$output = RequestContext::getMain()->getOutput();
		if ( method_exists( $output, 'disableClientCache' ) ) {
			// MW 1.38+
			$output->disableClientCache();
		} else {
			$output->enableClientCache( false );
		}
	}
}
