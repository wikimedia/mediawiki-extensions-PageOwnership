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
 * @author thomas-topway-it <thomas.topway.it@mail.com>
 * @copyright Copyright © 2021-2022, https://wikisphere.org
 */

$( function () {

	function infused( id ) {
		if ( $( '#' + id ).get( 0 ) ) {
			return OO.ui.infuse( $( '#' + id ) );
		}

		return { getValue: function () {}, on: function () {} };
	}

	// set values
	var default_role = mw.config.get( 'pageownership-manageownership-role' );
	var default_permissions = mw.config.get( 'pageownership-manageownership-permissions' );

	var input_role = infused( 'pageownership_form_input_role' );

	var permissions = {
		reader: {
			edit: { checked: false, disabled: true },
			create: { checked: false, disabled: true },
			// 'manage_properties': {'checked': false, 'disabled': true},
			subpages: { checked: false, disabled: false }
		},
		editor: {
			edit: { checked: false, disabled: false },
			create: { checked: false, disabled: false },
			// 'manage_properties': {'checked': false, 'disabled': false},
			subpages: { checked: false, disabled: false }
		},
		admin: {
			edit: { checked: true, disabled: true },
			create: { checked: true, disabled: true },
			// 'manage_properties': {'checked': true, 'disabled': true},
			subpages: { checked: true, disabled: true }
		}
	};

	function updateCheckboxes( val ) {

		for ( var i in permissions[ val ] ) {
			$( '#pageownership_form_input_permissions_' + i + ' input' ).prop( 'checked', ( val === default_role && default_permissions.indexOf( i.replace( '_', ' ' ) ) > -1 ? true : permissions[ val ][ i ].checked ) );
			$( '#pageownership_form_input_permissions_' + i + ' input' ).prop( 'disabled', permissions[ val ][ i ].disabled );
		}

	}

	input_role.on( 'change', function ( val ) {
		updateCheckboxes( val );
	} );

	updateCheckboxes( input_role.getValue() );

	// eslint-disable-next-line no-jquery/no-global-selector, no-unused-vars
	$( '#pageownership-form,pageownership-form-permissions button[type="submit"]' ).on( 'click', function ( val ) {
		if ( $( this ).val() === 'delete' ) {
			// eslint-disable-next-line no-alert
			if ( !confirm( mw.msg( 'pageownership-jsmodule-deleteitemconfirm' ) ) ) {
				return false;
			}

			// eslint-disable-next-line no-jquery/no-sizzle
			$( this )
				.closest( 'form' )
				.find( ':input' )
				.each( function ( i, el ) {
					$( el ).removeAttr( 'required' );
				} );
		}
	} );

} );
