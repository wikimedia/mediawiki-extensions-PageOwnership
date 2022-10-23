<?php

/**
 * Widget to select multiple users.
 *
 * @copyright 2017 MediaWiki Widgets Team and others; see AUTHORS.txt
 * @license MIT
 */

class GroupsUsersMultiselectWidget extends MediaWiki\Widget\TagMultiselectWidget {
	/**
	 * @var bool
	 */
	protected $ipAllowed;

	/**
	 * @var ipRangeAllowed
	 */
	protected $ipRangeAllowed;

	/**
	 * @var ipRangeLimits
	 */
	protected $ipRangeLimits;

	/**
	 * @param array $config Configuration options
	 * - bool $config['ipAllowed'] Accept valid IP addresses
	 * - bool $config['ipRangeAllowed'] Accept valid IP ranges
	 * - array $config['ipRangeLimits'] Maximum allowed IP range sizes
	 */
	public function __construct( array $config = [] ) {
		parent::__construct( $config );

		if ( isset( $config['ipAllowed'] ) ) {
			$this->ipAllowed = $config['ipAllowed'];
		}

		if ( isset( $config['ipRangeAllowed'] ) ) {
			$this->ipRangeAllowed = $config['ipRangeAllowed'];
		}

		if ( isset( $config['ipRangeLimits'] ) ) {
			$this->ipRangeLimits = $config['ipRangeLimits'];
		}

		// ***edited
		$this->options = $config['options'] ?? [];
	}

	/**
	 * @param array &$config
	 * @return array
	 */
	public function getConfig( &$config ) {
		if ( $this->ipAllowed !== null ) {
			$config['ipAllowed'] = $this->ipAllowed;
		}

		if ( $this->ipRangeAllowed !== null ) {
			$config['ipRangeAllowed'] = $this->ipRangeAllowed;
		}

		if ( $this->ipRangeLimits !== null ) {
			$config['ipRangeLimits'] = $this->ipRangeLimits;
		}

		if ( $this->ipRangeLimits !== null ) {
			$config['ipRangeLimits'] = $this->ipRangeLimits;
		}

		// ***edited
		$options = [];
		foreach ( $this->options as $key => $value ) {
			$options[] = [ 'data' => $key, 'label' => $value ];
		}
		$config['options'] = $options;

		return parent::getConfig( $config );
	}

	/**
	 * @return string
	 */
	protected function getJavaScriptClassName() {
		return 'mw.widgets.GroupsUsersMultiselectWidget';
	}
}
