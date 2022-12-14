<?php

namespace MediaWiki\Tests\Rest\Handler;

use ApiUsageException;
use FormatJson;
use HashConfig;
use MediaWiki\Languages\LanguageNameUtils;
use MediaWiki\Parser\MagicWordFactory;
use MediaWiki\Rest\Handler\UpdateHandler;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Tests\Unit\DummyServicesTrait;
use MediaWiki\Title\TitleFactory;
use MockTitleTrait;
use ParserFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Status;
use Title;
use Wikimedia\Message\MessageValue;
use Wikimedia\Message\ParamType;
use Wikimedia\Message\ScalarParam;
use Wikimedia\UUID\GlobalIdGenerator;
use WikitextContent;
use WikitextContentHandler;

/**
 * @covers \MediaWiki\Rest\Handler\UpdateHandler
 */
class UpdateHandlerTest extends \MediaWikiLangTestCase {
	use ActionModuleBasedHandlerTestTrait;
	use DummyServicesTrait;
	use MockTitleTrait;

	private function newHandler( $resultData, $throwException = null, $csrfSafe = false ) {
		$config = new HashConfig( [
			'RightsUrl' => 'https://creativecommons.org/licenses/by-sa/4.0/',
			'RightsText' => 'CC-BY-SA 4.0'
		] );

		$wikitextContentHandler = new WikitextContentHandler(
			CONTENT_MODEL_WIKITEXT,
			$this->createMock( TitleFactory::class ),
			$this->createMock( ParserFactory::class ),
			$this->createMock( GlobalIdGenerator::class ),
			$this->createMock( LanguageNameUtils::class ),
			$this->createMock( MagicWordFactory::class )
		);

		// Only wikitext is defined, returns specific handler instance
		$contentHandlerFactory = $this->getDummyContentHandlerFactory(
			[ CONTENT_MODEL_WIKITEXT => $wikitextContentHandler ]
		);

		// DummyServicesTrait::getDummyMediaWikiTitleCodec
		$titleCodec = $this->getDummyMediaWikiTitleCodec();

		/** @var RevisionLookup|MockObject $revisionLookup */
		$revisionLookup = $this->createNoOpMock(
			RevisionLookup::class,
			[ 'getRevisionById', 'getRevisionByTitle' ]
		);
		$revisionLookup->method( 'getRevisionById' )
			->willReturnCallback( function ( $id ) {
				$title = $this->makeMockTitle( __CLASS__ );
				$rev = new MutableRevisionRecord( $title );
				$rev->setId( $id );
				$rev->setContent( SlotRecord::MAIN, new WikitextContent( "Content of revision $id" ) );
				$rev->setTimestamp( '2020-01-01T01:02:03Z' );
				return $rev;
			} );
		$revisionLookup->method( 'getRevisionByTitle' )
			->willReturnCallback( static function ( $title ) {
				$rev = new MutableRevisionRecord( Title::castFromLinkTarget( $title ) );
				$rev->setId( 1234 );
				$rev->setContent( SlotRecord::MAIN, new WikitextContent( "Current content of $title" ) );
				$rev->setTimestamp( '2020-01-01T01:02:03Z' );
				return $rev;
			} );

		$handler = new UpdateHandler(
			$config,
			$contentHandlerFactory,
			$titleCodec,
			$titleCodec,
			$revisionLookup
		);

		$apiMain = $this->getApiMain( $csrfSafe );
		$dummyModule = $this->getDummyApiModule( $apiMain, 'edit', $resultData, $throwException );

		$handler->setApiMain( $apiMain );
		$handler->overrideActionModule(
			'edit',
			'action',
			$dummyModule
		);

		return $handler;
	}

	public function provideExecute() {
		yield "create with token" => [
			[ // Request data received by UpdateHandler
				'method' => 'PUT',
				'pathParams' => [ 'title' => 'Foo' ],
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'bodyContents' => json_encode( [
					'token' => 'TOKEN',
					'source' => 'Lorem Ipsum',
					'comment' => 'Testing'
				] ),
			],
			[ // Fake request expected to be passed into ApiEditPage
				'title' => 'Foo',
				'text' => 'Lorem Ipsum',
				'summary' => 'Testing',
				'createonly' => '1',
				'token' => 'TOKEN',
			],
			[ // Mock response returned by ApiEditPage
				"edit" => [
					"new" => true,
					"result" => "Success",
					"pageid" => 94542,
					"title" => "Foo",
					"contentmodel" => "wikitext",
					"oldrevid" => 0,
					"newrevid" => 371707,
					"newtimestamp" => "2018-12-18T16:59:42Z",
				]
			],
			[ // Response expected to be generated by UpdateHandler
				'id' => 94542,
				'title' => 'Foo',
				'key' => 'Foo',
				'content_model' => 'wikitext',
				'latest' => [
					'id' => 371707,
					'timestamp' => "2018-12-18T16:59:42Z"
				],
				'license' => [
					'url' => 'https://creativecommons.org/licenses/by-sa/4.0/',
					'title' => 'CC-BY-SA 4.0'
				],
				'source' => 'Content of revision 371707'
			],
			false
		];

		yield "create with model" => [
			[ // Request data received by UpdateHandler
				'method' => 'POST',
				'pathParams' => [ 'title' => 'Foo' ],
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'bodyContents' => json_encode( [
					'token' => 'TOKEN',
					'source' => 'Lorem Ipsum',
					'comment' => 'Testing',
					'content_model' => CONTENT_MODEL_WIKITEXT,
				] ),
			],
			[ // Fake request expected to be passed into ApiEditPage
				'title' => 'Foo',
				'text' => 'Lorem Ipsum',
				'summary' => 'Testing',
				'contentmodel' => 'wikitext',
				'createonly' => '1',
				'token' => 'TOKEN',
			],
			[ // Mock response returned by ApiEditPage
				"edit" => [
					"new" => true,
					"result" => "Success",
					"pageid" => 94542,
					"title" => "Foo",
					"contentmodel" => "wikitext",
					"oldrevid" => 0,
					"newrevid" => 371707,
					"newtimestamp" => "2018-12-18T16:59:42Z",
				]
			],
			[ // Response expected to be generated by UpdateHandler
				'id' => 94542,
				'title' => 'Foo',
				'key' => 'Foo',
				'content_model' => 'wikitext',
				'latest' => [
					'id' => 371707,
					'timestamp' => "2018-12-18T16:59:42Z"
				],
				'license' => [
					'url' => 'https://creativecommons.org/licenses/by-sa/4.0/',
					'title' => 'CC-BY-SA 4.0'
				],
				'source' => 'Content of revision 371707'
			],
			false
		];

		yield "update with token" => [
			[ // Request data received by UpdateHandler
				'method' => 'PUT',
				'pathParams' => [ 'title' => 'foo bar' ],
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'bodyContents' => json_encode( [
					'token' => 'TOKEN',
					'source' => 'Lorem Ipsum',
					'comment' => 'Testing',
					'latest' => [ 'id' => 789123 ],
				] ),
			],
			[ // Fake request expected to be passed into ApiEditPage
				'title' => 'foo bar',
				'text' => 'Lorem Ipsum',
				'summary' => 'Testing',
				'nocreate' => '1',
				'baserevid' => '789123',
				'token' => 'TOKEN',
			],
			[ // Mock response returned by ApiEditPage
				"edit" => [
					"result" => "Success",
					"pageid" => 94542,
					"title" => "Foo_bar",
					"contentmodel" => "wikitext",
					"oldrevid" => 371705,
					"newrevid" => 371707,
					"newtimestamp" => "2018-12-18T16:59:42Z",
				]
			],
			[ // Response expected to be generated by UpdateHandler
				'id' => 94542,
				'content_model' => 'wikitext',
				'title' => 'Foo bar',
				'key' => 'Foo_bar',
				'latest' => [
					'id' => 371707,
					'timestamp' => "2018-12-18T16:59:42Z"
				],
				'license' => [
					'url' => 'https://creativecommons.org/licenses/by-sa/4.0/',
					'title' => 'CC-BY-SA 4.0'
				],
				'source' => 'Content of revision 371707'
			],
			false
		];

		yield "update with model" => [
			[ // Request data received by UpdateHandler
				'method' => 'POST',
				'pathParams' => [ 'title' => 'foo bar' ],
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'bodyContents' => json_encode( [
					'source' => 'Lorem Ipsum',
					'comment' => 'Testing',
					'content_model' => CONTENT_MODEL_WIKITEXT,
					'latest' => [ 'id' => 789123 ],
				] ),
			],
			[ // Fake request expected to be passed into ApiEditPage
				'title' => 'foo bar',
				'text' => 'Lorem Ipsum',
				'summary' => 'Testing',
				'contentmodel' => 'wikitext',
				'nocreate' => '1',
				'baserevid' => '789123',
				'token' => '+\\',
			],
			[ // Mock response returned by ApiEditPage
				"edit" => [
					"result" => "Success",
					"pageid" => 94542,
					"title" => "Foo_bar",
					"contentmodel" => "wikitext",
					"oldrevid" => 371705,
					"newrevid" => 371707,
					"newtimestamp" => "2018-12-18T16:59:42Z",
				]
			],
			[ // Response expected to be generated by UpdateHandler
				'id' => 94542,
				'content_model' => 'wikitext',
				'title' => 'Foo bar',
				'key' => 'Foo_bar',
				'latest' => [
					'id' => 371707,
					'timestamp' => "2018-12-18T16:59:42Z"
				],
				'license' => [
					'url' => 'https://creativecommons.org/licenses/by-sa/4.0/',
					'title' => 'CC-BY-SA 4.0'
				],
				'source' => 'Content of revision 371707'
			],
			true
		];

		yield "update without token" => [
			[ // Request data received by UpdateHandler
				'method' => 'PUT',
				'pathParams' => [ 'title' => 'Foo' ],
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'bodyContents' => json_encode( [
					'source' => 'Lorem Ipsum',
					'comment' => 'Testing',
					'content_model' => CONTENT_MODEL_WIKITEXT,
					'latest' => [ 'id' => 789123 ],
				] ),
			],
			[ // Fake request expected to be passed into ApiEditPage
				'title' => 'Foo',
				'text' => 'Lorem Ipsum',
				'summary' => 'Testing',
				'contentmodel' => 'wikitext',
				'nocreate' => '1',
				'baserevid' => '789123',
				'token' => '+\\', // use known-good token for current user (anon)
			],
			[ // Mock response returned by ApiEditPage
				"edit" => [
					"result" => "Success",
					"pageid" => 94542,
					"title" => "Foo",
					"contentmodel" => "wikitext",
					"oldrevid" => 371705,
					"newrevid" => 371707,
					"newtimestamp" => "2018-12-18T16:59:42Z",
				]
			],
			[ // Response expected to be generated by UpdateHandler
				'id' => 94542,
				'content_model' => 'wikitext',
				'latest' => [
					'id' => 371707,
					'timestamp' => "2018-12-18T16:59:42Z"
				],
				'license' => [
					'url' => 'https://creativecommons.org/licenses/by-sa/4.0/',
					'title' => 'CC-BY-SA 4.0'
				],
			],
			true
		];

		yield "null-edit (unchanged)" => [
			[ // Request data received by UpdateHandler
				'method' => 'PUT',
				'pathParams' => [ 'title' => 'Foo' ],
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'bodyContents' => json_encode( [
					'source' => 'Lorem Ipsum',
					'comment' => 'Testing',
					'content_model' => CONTENT_MODEL_WIKITEXT,
					'latest' => [ 'id' => 789123 ],
				] ),
			],
			[ // Fake request expected to be passed into ApiEditPage
				'title' => 'Foo',
				'text' => 'Lorem Ipsum',
				'summary' => 'Testing',
				'contentmodel' => 'wikitext',
				'nocreate' => '1',
				'baserevid' => '789123',
				'token' => '+\\', // use known-good token for current user (anon)
			],
			[ // Mock response returned by ApiEditPage
				"edit" => [
					"result" => "Success",
					"pageid" => 94542,
					"title" => "Foo",
					"contentmodel" => "wikitext",
					"nochange" => "", // null-edit!
			]
			],
			[ // Response expected to be generated by UpdateHandler
				'id' => 94542,
				'content_model' => 'wikitext',
				'latest' => [
					'id' => 1234, // ID of current rev, as defined in newHandler()
					'timestamp' => '2020-01-01T01:02:03Z' // see fake RevisionStore in newHandler()
				],
				'license' => [
					'url' => 'https://creativecommons.org/licenses/by-sa/4.0/',
					'title' => 'CC-BY-SA 4.0'
				],
			],
			true
		];
	}

	/**
	 * @dataProvider provideExecute
	 */
	public function testExecute(
		$requestData,
		$expectedActionParams,
		$actionResult,
		$expectedResponse,
		$csrfSafe
	) {
		$request = new RequestData( $requestData );

		$handler = $this->newHandler( $actionResult, null, $csrfSafe );

		$responseData = $this->executeHandlerAndGetBodyData(
			$handler, $request, [], [], [], [], null, $csrfSafe
		);

		// Check parameters passed to ApiEditPage by UpdateHandler based on $requestData
		foreach ( $expectedActionParams as $key => $value ) {
			$this->assertSame(
				$value,
				$handler->getApiMain()->getVal( $key ),
				"ApiEditPage param: $key"
			);
		}

		// Check response that UpdateHandler created after receiving $actionResult from ApiEditPage
		foreach ( $expectedResponse as $key => $value ) {
			$this->assertArrayHasKey( $key, $responseData );
			$this->assertSame(
				$value,
				$responseData[ $key ],
				"UpdateHandler response field: $key"
			);
		}
	}

	public function provideBodyValidation() {
		yield "missing source field" => [
			[ // Request data received by UpdateHandler
				'method' => 'PUT',
				'pathParams' => [ 'title' => 'Foo' ],
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'bodyContents' => json_encode( [
					'token' => 'TOKEN',
					'comment' => 'Testing',
					'content_model' => CONTENT_MODEL_WIKITEXT,
				] ),
			],
			new MessageValue( 'rest-missing-body-field', [ 'source' ] ),
		];
		yield "missing comment field" => [
			[ // Request data received by UpdateHandler
				'method' => 'PUT',
				'pathParams' => [ 'title' => 'Foo' ],
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'bodyContents' => json_encode( [
					'token' => 'TOKEN',
					'source' => 'Lorem Ipsum',
					'content_model' => CONTENT_MODEL_WIKITEXT,
				] ),
			],
			new MessageValue( 'rest-missing-body-field', [ 'comment' ] ),
		];
	}

	/**
	 * @dataProvider provideBodyValidation
	 */
	public function testBodyValidation( array $requestData, MessageValue $expectedMessage ) {
		$request = new RequestData( $requestData );

		$handler = $this->newHandler( [] );

		$exception = $this->executeHandlerAndGetHttpException( $handler, $request );

		$this->assertSame( 400, $exception->getCode(), 'HTTP status' );
		$this->assertInstanceOf( LocalizedHttpException::class, $exception );

		/** @var LocalizedHttpException $exception */
		$this->assertEquals( $expectedMessage, $exception->getMessageValue() );
	}

	public function provideHeaderValidation() {
		yield "bad content type" => [
			[ // Request data received by UpdateHandler
				'method' => 'PUT',
				'pathParams' => [ 'title' => 'Foo' ],
				'headers' => [
					'Content-Type' => 'text/plain',
				],
				'bodyContents' => json_encode( [
					'token' => 'TOKEN',
					'source' => 'Lorem Ipsum',
					'comment' => 'Testing',
					'content_model' => CONTENT_MODEL_WIKITEXT,
				] ),
			],
			415
		];
	}

	/**
	 * @dataProvider provideHeaderValidation
	 */
	public function testHeaderValidation( array $requestData, $expectedStatus ) {
		$request = new RequestData( $requestData );

		$handler = $this->newHandler( [] );

		$exception = $this->executeHandlerAndGetHttpException( $handler, $request );

		$this->assertSame( $expectedStatus, $exception->getCode(), 'HTTP status' );
	}

	/**
	 * FIXME: Can't access MW services in a dataProvider.
	 */
	public function provideErrorMapping() {
		yield "missingtitle" => [
			new ApiUsageException( null, Status::newFatal( 'apierror-missingtitle' ) ),
			new LocalizedHttpException( new MessageValue( 'apierror-missingtitle' ), 404 ),
		];
		yield "protectedpage" => [
			new ApiUsageException( null, Status::newFatal( 'apierror-protectedpage' ) ),
			new LocalizedHttpException( new MessageValue( 'apierror-protectedpage' ), 403 ),
		];
		yield "articleexists" => [
			new ApiUsageException( null, Status::newFatal( 'apierror-articleexists' ) ),
			new LocalizedHttpException(
				new MessageValue( 'rest-update-cannot-create-page', [ 'Foo' ] ),
				409
			),
		];
		yield "editconflict" => [
			new ApiUsageException( null, Status::newFatal( 'apierror-editconflict' ) ),
			new LocalizedHttpException( new MessageValue( 'apierror-editconflict' ), 409 ),
		];
		yield "ratelimited" => [
			new ApiUsageException( null, Status::newFatal( 'apierror-ratelimited' ) ),
			new LocalizedHttpException( new MessageValue( 'apierror-ratelimited' ), 429 ),
		];
		yield "badtoken" => [
			new ApiUsageException(
				null,
				Status::newFatal( 'apierror-badtoken', [ 'plaintext' => 'BAD' ] )
			),
			new LocalizedHttpException(
				new MessageValue(
					'apierror-badtoken',
					[ new ScalarParam( ParamType::PLAINTEXT, 'BAD' ) ]
				), 403
			),
		];

		// Unmapped errors should be passed through with a status 400.
		yield "no-direct-editing" => [
			new ApiUsageException( null, Status::newFatal( 'apierror-no-direct-editing' ) ),
			new LocalizedHttpException( new MessageValue( 'apierror-no-direct-editing' ), 400 ),
		];
		yield "badformat" => [
			new ApiUsageException( null, Status::newFatal( 'apierror-badformat' ) ),
			new LocalizedHttpException( new MessageValue( 'apierror-badformat' ), 400 ),
		];
		yield "emptypage" => [
			new ApiUsageException( null, Status::newFatal( 'apierror-emptypage' ) ),
			new LocalizedHttpException( new MessageValue( 'apierror-emptypage' ), 400 ),
		];
	}

	public function testErrorMapping() {
		foreach ( $this->provideErrorMapping() as $expected ) {
			$apiUsageException = $expected[0];
			$expectedHttpException = $expected[1];
			$requestData = [ // Request data received by UpdateHandler
				'method' => 'POST',
				'pathParams' => [ 'title' => 'Foo' ],
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'bodyContents' => json_encode( [
					'source' => 'Lorem Ipsum',
					'comment' => 'Testing',
					'content_model' => CONTENT_MODEL_WIKITEXT,
				] ),
			];
			$request = new RequestData( $requestData );

			$handler = $this->newHandler( [], $apiUsageException );

			$exception = $this->executeHandlerAndGetHttpException( $handler, $request );

			$this->assertSame( $expectedHttpException->getMessage(), $exception->getMessage() );
			$this->assertSame( $expectedHttpException->getCode(), $exception->getCode(), 'HTTP status' );

			$errorData = $exception->getErrorData();
			if ( $expectedHttpException->getErrorData() ) {
				foreach ( $expectedHttpException->getErrorData() as $key => $value ) {
					$this->assertSame( $value, $errorData[$key], 'Error data key $key' );
				}
			}

			if ( $expectedHttpException instanceof LocalizedHttpException ) {
				/** @var LocalizedHttpException $exception */
				$this->assertEquals(
					$expectedHttpException->getMessageValue(),
					$exception->getMessageValue()
				);
			}
		}
	}

	public function testConflictOutput() {
		$requestData = [ // Request data received by UpdateHandler
			'method' => 'POST',
			'pathParams' => [ 'title' => 'Foo' ],
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'bodyContents' => json_encode( [
				'latest' => [
					'id' => 17,
				],
				'source' => 'Lorem Ipsum',
				'comment' => 'Testing'
			] ),
		];
		$request = new RequestData( $requestData );

		$apiUsageException = new ApiUsageException( null, Status::newFatal( 'apierror-editconflict' ) );
		$handler = $this->newHandler( [], $apiUsageException );
		$handler->setJsonDiffFunction( [ $this, 'fakeJsonDiff' ] );

		$exception = $this->executeHandlerAndGetHttpException( $handler, $request );

		$this->assertSame( 409, $exception->getCode(), 'HTTP status' );

		$expectedData = [
			'local' => [
				'from' => 'Content of revision 17',
				'to' => 'Lorem Ipsum',
			],
			'remote' => [
				'from' => 'Content of revision 17',
				'to' => 'Current content of 0:Foo',
			],
			'base' => 17,
			'current' => 1234
		];

		$errorData = $exception->getErrorData();
		foreach ( $expectedData as $key => $value ) {
			$this->assertSame( $value, $errorData[$key], "Error data key $key" );
		}
	}

	public function fakeJsonDiff( $fromText, $toText ) {
		return FormatJson::encode( [
			'from' => $fromText,
			'to' => $toText
		] );
	}

}
