<?php

include_once __DIR__ . '/GroupsUsersMultiselectWidget.php';

class HTMLGroupsUsersMultiselectField extends HTMLUsersMultiselectField {

	/**
	 * @see includes/htmlform/HTMLFormField.php
	 * @param string|array $value
	 * @param array $alldata
	 * @return bool
	 */
	public function validate( $value, $alldata ) {
		if ( $this->isHidden( $alldata ) ) {
			return true;
		}

		if ( isset( $this->mParams['required'] )
			&& $this->mParams['required'] !== false
			&& $value === ''
		) {
			return $this->msg( 'htmlform-required' );
		}

		if ( isset( $this->mValidationCallback ) ) {
			return ( $this->mValidationCallback )( $value, $alldata, $this->mParent );
		}

		return true;
	}

	/**
	 * @see includes/htmlform/fields/HTMLUsersMultiselectField.php
	 * @param string|array $value
	 * @param array $alldata
	 * @return bool
	 */
	public function validate_( $value, $alldata ) {
		if ( !$this->mParams['exists'] ) {
			return true;
		}

		if ( $value === null ) {
			return false;
		}

		// $value is a string, because HTMLForm fields store their values as strings
		$usersArray = explode( "\n", $value );

		if ( isset( $this->mParams['max'] ) && ( count( $usersArray ) > $this->mParams['max'] ) ) {
			return $this->msg( 'htmlform-multiselect-toomany', $this->mParams['max'] );
		}

		foreach ( $usersArray as $username ) {
			$result = parent::validate( $username, $alldata );
			if ( $result !== true ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getInputOOUI( $value ) {
		$params = [ 'name' => $this->mName ];

		if ( isset( $this->mParams['id'] ) ) {
			$params['id'] = $this->mParams['id'];
		}

		if ( isset( $this->mParams['disabled'] ) ) {
			$params['disabled'] = $this->mParams['disabled'];
		}

		if ( isset( $this->mParams['default'] ) ) {
			$params['default'] = $this->mParams['default'];
		}

		if ( isset( $this->mParams['placeholder'] ) ) {
			$params['placeholder'] = $this->mParams['placeholder'];
		} else {
			$params['placeholder'] = $this->msg( 'mw-widgets-usersmultiselect-placeholder' )->plain();
		}

		if ( isset( $this->mParams['max'] ) ) {
			$params['tagLimit'] = $this->mParams['max'];
		}

		if ( isset( $this->mParams['ipallowed'] ) ) {
			$params['ipAllowed'] = $this->mParams['ipallowed'];
		}

		if ( isset( $this->mParams['iprange'] ) ) {
			$params['ipRangeAllowed'] = $this->mParams['iprange'];
		}

		if ( isset( $this->mParams['iprangelimits'] ) ) {
			$params['ipRangeLimits'] = $this->mParams['iprangelimits'];
		}

		if ( isset( $this->mParams['input'] ) ) {
			$params['input'] = $this->mParams['input'];
		}

		// ***edited
		if ( isset( $this->mParams['options'] ) ) {
			$params['options'] = $this->mParams['options'];
		}

		// ***edited
		if ( empty( $params['allowedValues'] ) ) {
			$params['allowedValues'] = array_keys( $params['options'] );
		}

		if ( $value !== null ) {
			// $value is a string, but the widget expects an array
			$params['default'] = $value === '' ? [] : explode( "\n", $value );
		}

		// Make the field auto-infusable when it's used inside a legacy HTMLForm rather than OOUIHTMLForm
		$params['infusable'] = true;
		$params['classes'] = [ 'mw-htmlform-field-autoinfuse' ];
		$widget = new \GroupsUsersMultiselectWidget( $params );
		$widget->setAttributes( [ 'data-mw-modules' => implode( ',', $this->getOOUIModules() ) ] );

		return $widget;
	}

	/**
	 * @inheritDoc
	 */
	protected function getOOUIModules() {
		return [ 'ext.PageOwnership.GroupsUsersMultiselectWidget' ];
	}
}
