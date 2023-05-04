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
 * @author thomas-topway-it <support@topway.it>
 * @copyright Copyright © 2021-2023, https://wikisphere.org
 */

$( function () {
	//  @see https://www.mediawiki.org/wiki/Manual:User_rights
	var actions = {
		reading: [ 'read' ],
		editing: [
			'applychangetags',
			'autocreateaccount',
			'createaccount',
			'createpage',
			'createtalk',
			'delete-redirect',
			'edit',
			'editsemiprotected',
			'editprotected',
			'minoredit',
			'move',
			'move-categorypages',
			'move-rootuserpages',
			'move-subpages',
			'movefile',
			'reupload',
			'reupload-own',
			'reupload-shared',
			'sendemail',
			'upload',
			'upload_by_url'
		],
		management: [
			'bigdelete',
			'block',
			'blockemail',
			'browsearchive',
			'changetags',
			'delete',
			'deletedhistory',
			'deletedtext',
			'deletelogentry',
			'deleterevision',
			'editcontentmodel',
			'editinterface',
			'editmyoptions',
			'editmyprivateinfo',
			'editmyusercss',
			'editmyuserjs',
			'editmyuserjsredirect',
			'editmyuserjson',
			'editmywatchlist',
			'editsitecss',
			'editsitejs',
			'editsitejson',
			'editusercss',
			'edituserjs',
			'edituserjson',
			'hideuser',
			'markbotedits',
			'mergehistory',
			'pagelang',
			'patrol',
			'patrolmarks',
			'protect',
			'rollback',
			'suppressionlog',
			'suppressrevision',
			'unblockself',
			'undelete',
			'userrights',
			'userrights-interwiki',
			'viewmyprivateinfo',
			'viewmywatchlist',
			'viewsuppressed'
		],
		administration: [
			'autopatrol',
			'deletechangetags',
			'import',
			'importupload',
			'managechangetags',
			'siteadmin',
			'unwatchedpages'
		],
		technical: [
			'apihighlimits',
			'autoconfirmed',
			'bot',
			'ipblock-exempt',
			'nominornewtalk',
			'noratelimit',
			'override-export-depth',
			'purge',
			'suppressredirect',
			'writeapi'
		]
	};

	$( function () {
		var messages = JSON.parse(
			mw.config.get( 'pageownership-permissions-groupPermissions-messages' )
		);

		var canManagePermissions = JSON.parse(
			mw.config.get( 'pageownership-canmanagepermissions' )
		);

		// console.log("actions", actions);
		// console.log("messages", messages);
		// console.log("canManagePermissions", canManagePermissions);

		function populateMenus( action, selected ) {
			var menuTagInputWidget;

			if ( action === 'add' ) {
				menuTagInputWidget = OO.ui.infuse(
					$( '#pageownership-permissions-field-add-permissions' )
				);
			} else if ( action === 'remove' ) {
				menuTagInputWidget = OO.ui.infuse(
					$( '#pageownership-permissions-field-remove-permissions' )
				);
			}

			var selectedItems = menuTagInputWidget.getValue();

			menuTagInputWidget.menu.clearItems();

			var options = [];
			for ( var i in actions ) {
				if ( action === 'add' && selected.indexOf( i ) !== -1 ) {
					continue;
				}

				if ( action === 'remove' && selected.indexOf( i ) === -1 ) {
					continue;
				}

				options.push(
					new OO.ui.MenuOptionWidget( {
						disabled: true,
						classes: [ 'pageownership-permissions-options-optgroup' ],
						data: i,
						// The following messages are used here:
						// * pageownership-permissions-actions-label-reading
						// * pageownership-permissions-actions-label-editing
						// * pageownership-permissions-actions-label-management
						// * pageownership-permissions-actions-label-administration
						// * pageownership-permissions-actions-label-technical
						label: mw.msg( 'pageownership-permissions-actions-label-' + i )
					} )
				);

				for ( var ii in actions[ i ] ) {
					options.push(
						new OO.ui.MenuOptionWidget( {
							classes: [ 'pageownership-permissions-options-option' ],
							data: actions[ i ][ ii ],
							label: actions[ i ][ ii ] in messages ?
								messages[ actions[ i ][ ii ] ] :
								actions[ i ][ ii ],
							// mw.msg( 'right-' + actions[i][ii] ),
							selected: selectedItems.indexOf( actions[ i ][ ii ] ) !== -1
						} )
					);
				}
			}

			menuTagInputWidget.menu.addItems( options );
		}

		if ( $( '#pageownership-permissions-field-permissions-bytype' ).get( 0 ) ) {
			var multiToggleButtonWidget = OO.ui.infuse(
				$( '#pageownership-permissions-field-permissions-bytype' )
			);

			multiToggleButtonWidget.on( 'change', function ( value ) {
				populateMenus( 'add', value );
				populateMenus( 'remove', value );
			} );

			populateMenus( 'add', multiToggleButtonWidget.getValue() );
			populateMenus( 'remove', multiToggleButtonWidget.getValue() );
		}

		// eslint-disable-next-line no-jquery/no-global-selector
		$( '#pageownership-form-permissions button[type="submit"]' ).on(
			'click',
			// eslint-disable-next-line no-unused-vars
			function ( val ) {
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
			}
		);

		// display every 3 days
		if (
			canManagePermissions &&
			!mw.cookie.get( 'pageownership-check-latest-version' )
		) {
			mw.loader.using( 'mediawiki.api', function () {
				new mw.Api()
					.postWithToken( 'csrf', {
						action: 'pageownership-check-latest-version'
					} )
					.done( function ( res ) {
						if ( 'pageownership-check-latest-version' in res ) {
							if ( res[ 'pageownership-check-latest-version' ].result === 2 ) {
								var messageWidget = new OO.ui.MessageWidget( {
									type: 'warning',
									label: new OO.ui.HtmlSnippet(
										mw.msg(
											'pageownership-jsmodule-pageproperties-outdated-version'
										)
									),
									// *** this does not work before ooui v0.43.0
									showClose: true
								} );
								var closeFunction = function () {
									var three_days = 3 * 86400;
									mw.cookie.set( 'pageownership-check-latest-version', true, {
										path: '/',
										expires: three_days
									} );
									$( messageWidget.$element ).parent().remove();
								};
								messageWidget.on( 'close', closeFunction );
								$( '#pageownership-panel-layout' )
									.first()
									.prepend(
										// eslint-disable-next-line no-jquery/no-parse-html-literal
										$( '<div><br/></div>' ).prepend( messageWidget.$element )
									);
								if (
									// eslint-disable-next-line no-jquery/no-class-state
									!messageWidget.$element.hasClass(
										'oo-ui-messageWidget-showClose'
									)
								) {
									messageWidget.$element.addClass(
										'oo-ui-messageWidget-showClose'
									);
									var closeButton = new OO.ui.ButtonWidget( {
										classes: [ 'oo-ui-messageWidget-close' ],
										framed: false,
										icon: 'close',
										label: OO.ui.msg(
											'ooui-popup-widget-close-button-aria-label'
										),
										invisibleLabel: true
									} );
									closeButton.on( 'click', closeFunction );
									messageWidget.$element.append( closeButton.$element );
								}
							}
						}
					} );
			} );
		}
	} );
} );
