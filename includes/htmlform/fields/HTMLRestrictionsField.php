<?php

use MediaWiki\Request\WebRequest;
use MediaWiki\Status\Status;
use Wikimedia\IPUtils;

/**
 * Class for updating an MWRestrictions value (which is, currently, basically just an IP address
 * list).
 *
 * Will be represented as a textarea with one address per line, with intelligent defaults for
 * label, help text and row count.
 *
 * The value returned will be an MWRestrictions or the input string if it was not a list of
 * valid IP ranges.
 *
 */
class HTMLRestrictionsField extends HTMLTextAreaField {
	protected const DEFAULT_ROWS = 5;

	/**
	 * @stable to call
	 * @inheritDoc
	 */
	public function __construct( array $params ) {
		parent::__construct( $params );
		if ( !$this->mLabel ) {
			$this->mLabel = $this->msg( 'restrictionsfield-label' )->parse();
		}
	}

	public function getHelpText() {
		return parent::getHelpText() ?? $this->msg( 'restrictionsfield-help' )->parse();
	}

	/**
	 * @param WebRequest $request
	 * @return string|MWRestrictions Restrictions object or original string if invalid
	 */
	public function loadDataFromRequest( $request ) {
		if ( !$request->getCheck( $this->mName ) ) {
			return $this->getDefault();
		}

		$value = rtrim( $request->getText( $this->mName ), "\r\n" );
		$ips = $value === '' ? [] : explode( "\n", $value );
		try {
			return MWRestrictions::newFromArray( [ 'IPAddresses' => $ips ] );
		} catch ( InvalidArgumentException $e ) {
			return $value;
		}
	}

	/**
	 * @return MWRestrictions
	 */
	public function getDefault() {
		return parent::getDefault() ?? MWRestrictions::newDefault();
	}

	/**
	 * @param string|MWRestrictions $value The value the field was submitted with
	 * @param array $alldata The data collected from the form
	 *
	 * @return bool|string|Message True on success, or String/Message error to display, or
	 *   false to fail validation without displaying an error.
	 */
	public function validate( $value, $alldata ) {
		if ( $this->isHidden( $alldata ) ) {
			return true;
		}

		if (
			isset( $this->mParams['required'] ) && $this->mParams['required'] !== false
			&& $value instanceof MWRestrictions && !$value->toArray()['IPAddresses']
		) {
			return $this->msg( 'htmlform-required' );
		}

		if ( is_string( $value ) ) {
			// MWRestrictions::newFromArray failed; one of the IP ranges must be invalid
			$status = Status::newGood();
			foreach ( explode( "\n", $value ) as $range ) {
				if ( !IPUtils::isIPAddress( $range ) ) {
					$status->fatal( 'restrictionsfield-badip', $range );
				}
			}
			if ( $status->isOK() ) {
				$status->fatal( 'unknown-error' );
			}
			return $status->getMessage();
		}

		if ( isset( $this->mValidationCallback ) ) {
			return call_user_func( $this->mValidationCallback, $value, $alldata, $this->mParent );
		}

		return true;
	}

	/**
	 * @param string|MWRestrictions $value
	 * @return string
	 */
	public function getInputHTML( $value ) {
		if ( $value instanceof MWRestrictions ) {
			$value = implode( "\n", $value->toArray()['IPAddresses'] );
		}
		return parent::getInputHTML( $value );
	}

	/**
	 * @param MWRestrictions $value
	 * @return string
	 */
	public function getInputOOUI( $value ) {
		if ( $value instanceof MWRestrictions ) {
			$value = implode( "\n", $value->toArray()['IPAddresses'] );
		}
		return parent::getInputOOUI( $value );
	}
}
