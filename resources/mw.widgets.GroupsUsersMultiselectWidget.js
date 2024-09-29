/*!
 * MediaWiki Widgets - GroupsUsersMultiselectWidget class.
 *
 * @copyright 2017 MediaWiki Widgets Team and others; see AUTHORS.txt
 * @license The MIT License (MIT); see LICENSE.txt
 */

/* eslint-disable max-len */
( function () {
	/**
	 * GroupsUsersMultiselectWidget can be used to input list of users in a single
	 * line.
	 *
	 * If used inside HTML form the results will be sent as the list of
	 * newline-separated usernames.
	 *
	 * This can be configured to accept IP addresses and/or ranges as well as
	 * usernames.
	 *
	 * @class
	 * @extends OO.ui.MenuTagMultiselectWidget
	 *
	 * @constructor
	 * @param {Object} [config] Configuration options
	 * @cfg {mw.Api} [api] Instance of mw.Api (or subclass thereof) to use for queries
	 * @cfg {number} [limit=10] Number of results to show in autocomplete menu
	 * @cfg {string} [name] Name of input to submit results (when used in HTML forms)
	 * @cfg {boolean} [ipAllowed=false] Show IP addresses in autocomplete menu
	 *  If false, single IP addresses are not allowed, even if IP ranges are allowed.
	 * @cfg {boolean} [ipRangeAllowed=false] Show IP ranges in autocomplete menu
	 * @cfg {Object} [ipRangeLimits] Maximum allowed IP ranges (defaults match HTMLUserTextField.php)
	 * @cfg {number} [ipRangeLimits.IPv4 = 16] Maximum allowed IPv4 range
	 * @cfg {number} [ipRangeLimits.IPv6 = 32] Maximum allowed IPv6 range
	 */

	mw.widgets.GroupsUsersMultiselectWidget =
		function MwWidgetsGroupsGroupsUsersMultiselectWidget( config ) {

			// ***edited
			mw.widgets.GroupsUsersMultiselectWidget.prototype.originalOptions =
				config.options;

			// Config initialization
			config = Object.assign(
				{
					limit: 10,
					ipAllowed: false,
					ipRangeAllowed: false,
					ipRangeLimits: {
						IPv4: 16,
						IPv6: 32
					}
				},
				config
			);

			// ***edited
			// see resources/lib/ooui/oojs-ui-widgets.js -> OoUiTagMultiselectWidget
			config.input = {

				// ***not working ?
				// required: true,
				autocomplete: false
				// indicatorElement: $('.oo-ui-tagMultiselectWidget-handle .oo-ui-indicatorElement-indicator')

			};

			/*
config.inputWidget = new OO.ui.TextInputWidget({
				required: true,
				autocomplete: false,
				placeholder: config.placeholder,
				classes: [ 'oo-ui-tagMultiselectWidget-input' ]
			} );
*/

			// Parent constructor
			mw.widgets.GroupsUsersMultiselectWidget.parent.call(
				this,
				Object.assign( {}, config, {} )
			);

			// Mixin constructors
			OO.ui.mixin.PendingElement.call(
				this,
				Object.assign( {}, config, { $pending: this.$handle } )
			);

			// Properties
			this.limit = config.limit;
			this.ipAllowed = config.ipAllowed;
			this.ipRangeAllowed = config.ipRangeAllowed;
			this.ipRangeLimits = config.ipRangeLimits;

			if ( 'name' in config ) {
				// Use this instead of <input type="hidden">, because hidden inputs do not have separate
				// 'value' and 'defaultValue' properties. The script on Special:Preferences
				// (mw.special.preferences.confirmClose) checks this property to see if a field was changed.
				this.$hiddenInput = $( '<textarea>' )
					.addClass( 'oo-ui-element-hidden' )
					.attr( 'name', config.name )
					.appendTo( this.$element );
				// Update with preset values
				this.updateHiddenInput();
				// Set the default value (it might be different from just being empty)
				this.$hiddenInput.prop(
					'defaultValue',
					this.getSelectedUsernames().join( '\n' )
				);
			}

			// Events
			// When list of selected usernames changes, update hidden input
			this.connect( this, {
				change: 'onMultiselectChange'
			} );

			// API init
			this.api = config.api || new mw.Api();
		};

	/* Setup */

	OO.inheritClass(
		mw.widgets.GroupsUsersMultiselectWidget,
		OO.ui.MenuTagMultiselectWidget
	);
	OO.mixinClass(
		mw.widgets.GroupsUsersMultiselectWidget,
		OO.ui.mixin.PendingElement
	);

	/* Methods */

	/**
	 * Get currently selected usernames
	 *
	 * @return {string[]} usernames
	 */
	mw.widgets.GroupsUsersMultiselectWidget.prototype.getSelectedUsernames =
		function () {
			return this.getValue();
		};

	/**
	 * Update autocomplete menu with items
	 *
	 * @private
	 */
	mw.widgets.GroupsUsersMultiselectWidget.prototype.updateMenuItems =
		function () {
			var isValidIp,
				isValidRange,
				inputValue = this.input.getValue();

			if ( inputValue === this.inputValue ) {
				// Do not restart api query if nothing has changed in the input
				return;
			} else {
				this.inputValue = inputValue;
			}

			this.api.abort(); // Abort all unfinished api requests

			if ( inputValue.length > 0 ) {
				this.pushPending();

				if ( this.ipAllowed || this.ipRangeAllowed ) {
					isValidIp = mw.util.isIPAddress( inputValue, false );
					isValidRange =
						!isValidIp &&
						mw.util.isIPAddress( inputValue, true ) &&
						this.validateIpRange( inputValue );
				}

				if (
					( this.ipAllowed && isValidIp ) ||
					( this.ipRangeAllowed && isValidRange )
				) {
					this.menu.clearItems();
					this.menu.addItems( [
						new OO.ui.MenuOptionWidget( {
							data: inputValue,
							label: inputValue
						} )
					] );
					this.menu.toggle( true );
					this.popPending();
				} else {
					this.api
						.get( {
							action: 'query',
							list: 'allusers',
							// Prefix of list=allusers is case sensitive. Normalise first
							// character to uppercase so that "fo" may yield "Foo".
							auprefix: inputValue[ 0 ].toUpperCase() + inputValue.slice( 1 ),
							aulimit: this.limit
						} )
						.done(
							( response ) => {
								var suggestions = response.query.allusers,
									selected = this.getSelectedUsernames();

								// Remove usernames, which are already selected from suggestions
								suggestions = suggestions
									.map( ( user ) => {
										if ( selected.indexOf( user.name ) === -1 ) {
											return new OO.ui.MenuOptionWidget( {
												data: user.name,
												label: user.name,
												id: user.name
											} );
										}
										return undefined;
									} )
									.filter( ( item ) => item !== undefined );

								// ***edited
								suggestions = suggestions.concat(
									mw.widgets.GroupsUsersMultiselectWidget.prototype.originalOptions
										// .filter( function ( val ) {
										// return val.data.indexOf( inputValue ) === 0;
										// } )
										.map( ( x ) => new OO.ui.MenuOptionWidget( {
											data: x.data,
											label: x.label
										} ) )
								);

								// Remove all items from menu add fill it with new
								this.menu.clearItems();
								this.menu.addItems( suggestions );

								if ( suggestions.length ) {
									// Enable Narrator focus on menu item, see T250762.
									this.menu.$focusOwner.attr(
										'aria-activedescendant',
										suggestions[ 0 ].$element.attr( 'id' )
									);
								}

								// Make the menu visible; it might not be if it was previously empty
								this.menu.toggle( true );

								this.popPending();
							}
						)
						.fail( this.popPending.bind( this ) );
				}
			} else {
				this.menu.clearItems();

				// ***edited
				this.menu.addItems(
					mw.widgets.GroupsUsersMultiselectWidget.prototype.originalOptions.map(
						( x ) => new OO.ui.MenuOptionWidget( {
							data: x.data,
							label: x.label
						} )
					)
				);
			}
		};

	/**
	 * @private
	 * @param {string} ipRange Valid IPv4 or IPv6 range
	 * @return {boolean} The IP range is within the size limit
	 */
	mw.widgets.GroupsUsersMultiselectWidget.prototype.validateIpRange = function (
		ipRange
	) {
		ipRange = ipRange.split( '/' );

		return (
			( mw.util.isIPv4Address( ipRange[ 0 ] ) &&
				+ipRange[ 1 ] >= this.ipRangeLimits.IPv4 ) ||
			( mw.util.isIPv6Address( ipRange[ 0 ] ) &&
				+ipRange[ 1 ] >= this.ipRangeLimits.IPv6 )
		);
	};

	mw.widgets.GroupsUsersMultiselectWidget.prototype.onInputChange =
		function () {
			mw.widgets.GroupsUsersMultiselectWidget.parent.prototype.onInputChange.apply(
				this,
				arguments
			);

			this.updateMenuItems();
		};

	/**
	 * If used inside HTML form, then update hiddenInput with list of
	 * newline-separated usernames.
	 *
	 * @private
	 */
	mw.widgets.GroupsUsersMultiselectWidget.prototype.updateHiddenInput =
		function () {
			if ( '$hiddenInput' in this ) {
				this.$hiddenInput.val( this.getSelectedUsernames().join( '\n' ) );
				// Trigger a 'change' event as if a user edited the text
				// (it is not triggered when changing the value from JS code).
				this.$hiddenInput.trigger( 'change' );
			}
		};

	/**
	 * React to the 'change' event.
	 *
	 * Updates the hidden input and clears the text from the text box.
	 */
	mw.widgets.GroupsUsersMultiselectWidget.prototype.onMultiselectChange =
		function () {
			this.updateHiddenInput();
			this.input.setValue( '' );
		};
}() );
