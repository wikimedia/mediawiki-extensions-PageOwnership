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

( function () {
	mw.widgets.MultiToggleButtonWidget =
		function MwWidgetsMultiToggleButtonWidget( config ) {

			// ***important, add position: relative to display
			// property native validation
			config.classes.push( 'pageownership-button-multiselect-widget' );

			// Parent constructor
			mw.widgets.MultiToggleButtonWidget.parent.call(
				this,
				Object.assign( {}, config, {} )
			);

			// Events
			// When list of selected tags changes, update hidden input
			this.connect( this, {
				change: 'onMultiselectChange'
			} );

			this.options = config.options;

			var self = this;
			this.items = {};
			for ( var i in this.options ) {
				this.items[ i ] = new OO.ui.ToggleButtonWidget( {
					data: i,
					label: this.options[ i ],
					value: config.selected.includes( i )
				} );

				this.items[ i ].on( 'change', () => {
					self.emit( 'change', self.getValue() );
				} );
			}

			for ( var ii in this.items ) {
				this.$element.append( this.items[ ii ].$element );
			}

			if ( 'name' in config ) {
				this.$hiddenInput = $( '<textarea>' )
					.addClass( 'oo-ui-element-hidden' )
					.attr( 'name', config.name )
					.appendTo( this.$element );

				// Update with preset values
				this.updateHiddenInput();

				// Set the default value (it might be different from just being empty)
				this.$hiddenInput.prop( 'defaultValue', this.getValue().join( '\n' ) );
			}

		};

	OO.inheritClass( mw.widgets.MultiToggleButtonWidget, OO.ui.Widget );

	mw.widgets.MultiToggleButtonWidget.prototype.getValue = function () {
		var ret = [];
		for ( var i in this.items ) {
			if ( this.items[ i ].getValue() ) {
				ret.push( i );
			}
		}
		return ret;
	};

	/**
	 * If used inside HTML form, then update hiddenInput with list of
	 * newline-separated tags.
	 *
	 * @private
	 */
	mw.widgets.MultiToggleButtonWidget.prototype.updateHiddenInput = function () {
		if ( '$hiddenInput' in this ) {
			this.$hiddenInput.val( this.getValue().join( '\n' ) );
		}
	};

	/**
	 * React to the 'change' event.
	 *
	 * Updates the hidden input and clears the text from the text box.
	 */
	mw.widgets.MultiToggleButtonWidget.prototype.onMultiselectChange =
		function () {
			this.updateHiddenInput();
		};
}() );
