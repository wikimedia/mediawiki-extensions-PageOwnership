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
 * @copyright Copyright ©2021, https://wikisphere.org
 */


use MediaWiki\MediaWikiServices;


class PageOwnershipFunctions {


	/**
	 * @var UserGroupManager
	 */
	private static $userGroupManager;
	private static $Parser;


	public function __constructStatic()
	{
		self::$userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();
		self::$Parser = MediaWikiServices::getInstance()->getParser();
	}


	/**
	 * @return array
	 */
	public static function getUserGroups( $user, $replace_asterisk = false ) {

		$user_groups = self::$userGroupManager->getUserEffectiveGroups( $user );

		$user_groups[] = $user->getName();

		if ( array_search( '*', $user_groups ) === false ) {
			$user_groups[] = '*';
		}

		if ( $replace_asterisk ) {
			$key = array_search( '*', $user_groups );
			$user_groups[ $key ] = 'all';
		}

		return $user_groups;

	}



	public static function addHeaditem( $outputPage, $items  )
	{
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



	public static function isAuthorized( $user, $title )
	{
		global $wgPageOwnershipAuthorizedEditors;

		$allowed_groups = [ 'sysop' ];
		
		if ( is_array( $wgPageOwnershipAuthorizedEditors ) ) {
			$allowed_groups = array_unique (array_merge ( $allowed_groups, $wgPageOwnershipAuthorizedEditors) );
		}

		$user_groups = self::getUserGroups( $user );

		return sizeof( array_intersect( $allowed_groups, $user_groups ) );

	}


	public static function page_ancestors( $title, $exclude_current = true )
	{
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
				if ($title_->isKnown() ) {
					$output[] = $title_;
				}
			
			}
				
		}

		return $output;
	}


	// ***this avoids storing parser output in the cache,
	// not retrieving from it !!
	public static function disableCaching( $parser = null ) {
		
		if ( !$parser ) {
			$parser = self::$Parser;
		}

		if ( $parser->getOutput() ) {
			$parser->getOutput()->updateCacheExpiry( 0 );
		}

		RequestContext::getMain()->getOutput()->enableClientCache( false );

	}


}


PageOwnershipFunctions::__constructStatic();


