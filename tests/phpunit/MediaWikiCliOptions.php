<?php

/**
 * @todo Get rid of this class, the options we don't need (e.g. for filebackend, bagostuff and jobqueue
 * we should have dedicated test subclasses), and use getenv directly in calling code.
 */
final class MediaWikiCliOptions {
	/**
	 * @fixme This is an awful hack.
	 */
	public static $additionalOptions = [
		'use-filebackend' => null,
		'use-bagostuff' => null,
		'use-jobqueue' => null,
		'use-normal-tables' => false,
		'reuse-db' => false,
	];

	public static function initialize() : void {
		self::$additionalOptions['use-normal-tables'] = (bool)getenv( 'PHPUNIT_USE_NORMAL_TABLES' );
		self::$additionalOptions['reuse-db'] = (bool)getenv( 'PHPUNIT_REUSE_DB' );
		self::$additionalOptions['use-filebackend'] = getenv( 'PHPUNIT_USE_FILEBACKEND' ) ?: null;
		self::$additionalOptions['use-bagostuff'] = getenv( 'PHPUNIT_USE_BAGOSTUFF' ) ?: null;
		self::$additionalOptions['use-jobqueue'] = getenv( 'PHPUNIT_USE_JOBQUEUE' ) ?: null;
	}
}
