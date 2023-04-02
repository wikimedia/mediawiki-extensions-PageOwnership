/*!
 * MediaWiki Widgets - MenuTagMultiselectWidget class.
 *
 * @copyright 2017 MediaWiki Widgets Team and others; see AUTHORS.txt
 * @license The MIT License (MIT); see LICENSE.txt
 */
( function () {
	/**
	 * MenuTagMultiselectWidget can be used to input list of tags in a single
	 * line.
	 *
	 * This extends TagMultiselectWidget by adding an invisible textarea
	 * element which will be used to submit the values of the tags
	 *
	 * If used inside HTML form the results will be sent as the list of
	 * newline-separated tags.
	 *
	 * @class
	 * @extends OO.ui.MenuTagMultiselectWidget
	 *
	 * @constructor
	 * @param {Object} [config] Configuration options
	 * @cfg {string} [name] Name of input to submit results (when used in HTML forms)
	 */
	mw.widgets.MenuTagMultiselectWidget = function MwWidgetsMenuTagMultiselectWidget( config ) {

		// set options are set in allowedValues,
		// otherwise extend includes/widget/TagMultiselectWidget.php
		// eslint-disable-next-line no-jquery/no-map-util
		config.options = $.map( config.options, function ( value, key ) {
			return { data: key, label: value };
		} );

		// Parent constructor
		mw.widgets.MenuTagMultiselectWidget.parent.call( this, $.extend( {}, config, {} ) );

		// assign random name to prevent browser
		// native autocomplete menu
		this.input.$input.attr( 'name', Date.now() );

		if ( 'name' in config ) {
			// Use this instead of <input type="hidden">, because hidden inputs do not have separate
			// 'value' and 'defaultValue' properties.
			this.$hiddenInput = $( '<textarea>' )
				.addClass( 'oo-ui-element-hidden' )
				.attr( 'name', config.name )
				.appendTo( this.$element );
			// Update with preset values
			this.updateHiddenInput();
			// Set the default value (it might be different from just being empty)
			this.$hiddenInput.prop( 'defaultValue', this.getValue().join( '\n' ) );
		}

		// Events
		// When list of selected tags changes, update hidden input
		this.connect( this, {
			change: 'onMultiselectChange'
		} );
	};

	/* Setup */

	OO.inheritClass( mw.widgets.MenuTagMultiselectWidget, OO.ui.MenuTagMultiselectWidget );

	/* Methods */

	/**
	 * If used inside HTML form, then update hiddenInput with list of
	 * newline-separated tags.
	 *
	 * @private
	 */
	mw.widgets.MenuTagMultiselectWidget.prototype.updateHiddenInput = function () {
		if ( '$hiddenInput' in this ) {
			this.$hiddenInput.val( this.getValue().join( '\n' ) );
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
	mw.widgets.MenuTagMultiselectWidget.prototype.onMultiselectChange = function () {
		this.updateHiddenInput();
		this.input.setValue( '' );
	};

}() );
