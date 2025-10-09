<?php

namespace Drupal\Tests\mantle2\Unit;

use Drupal\mantle2\Service\GeneralHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

class GeneralUnitTest extends TestCase
{
	#[Test]
	#[TestDox('Test ID formatting to 24 characters')]
	#[Group('mantle2/util')]
	public function testFormatId()
	{
		$this->assertEquals('000000000000000000000123', GeneralHelper::formatId('0123'));
		$this->assertEquals('000000000000000000001234', GeneralHelper::formatId('1234'));
		$this->assertEquals('000000000000000000012345', GeneralHelper::formatId('00012345'));
		$this->assertEquals('000000000000000012345600', GeneralHelper::formatId('12345600'));
		$this->assertEquals('000000000000000123456789', GeneralHelper::formatId('123456789'));
		$this->assertEquals('000000000000123456789012', GeneralHelper::formatId('123456789012'));
		$this->assertEquals('000000000123456789012345', GeneralHelper::formatId('123456789012345'));
		$this->assertEquals(
			'000000123456789012345678',
			GeneralHelper::formatId('123456789012345678'),
		);
		$this->assertEquals(
			'123456789012345678901234',
			GeneralHelper::formatId('123456789012345678901234'),
		);

		$this->assertEquals('000000000000000000000000', GeneralHelper::formatId(0));
		$this->assertEquals('000000000000000000000001', GeneralHelper::formatId(1));
		$this->assertEquals('000000000000000000000123', GeneralHelper::formatId(123));
		$this->assertEquals('000000000000000123456789', GeneralHelper::formatId(123456789));

		$this->assertEquals('000000000000000000000000', GeneralHelper::formatId(''));
	}

	#[Test]
	#[TestDox('Test conversion of timestamps to ISO 8601 date strings')]
	#[Group('mantle2/util')]
	public function testDateToIso()
	{
		$this->assertEquals('2022-12-28T18:46:40+00:00', GeneralHelper::dateToIso(1672253200));
		$this->assertEquals('2054-09-05T20:33:20+00:00', GeneralHelper::dateToIso(2672253200));
		$this->assertEquals('1970-01-01T00:00:00+00:00', GeneralHelper::dateToIso(0));
		$this->assertEquals('2021-01-01T00:00:00+00:00', GeneralHelper::dateToIso(1609459200));
		$this->assertEquals('2021-07-13T07:14:56+00:00', GeneralHelper::dateToIso(1626160496));
		$this->assertEquals('2025-06-15T15:25:30+00:00', GeneralHelper::dateToIso(1750001130));
	}

	#[Test]
	#[TestDox('Test data URL parsing')]
	#[Group('mantle2/util')]
	public function testFromDataURL()
	{
		$url0 = 'data:text/plain;base64,SGVsbG8sIFdvcmxkIQ==';
		$result0 = GeneralHelper::fromDataURL($url0);
		$this->assertEquals('Hello, World!', $result0->getContent());

		$url1 = 'data:text/plain;base64,VGhpcyBpcyBhIHRlc3QgbWVzc2FnZSB3aXRoIHNwYWNlcy4=';
		$result1 = GeneralHelper::fromDataURL($url1);
		$this->assertEquals('This is a test message with spaces.', $result1->getContent());

		$url2 =
			'data:text/plain;base64,VGhpcyB1c2VzIHNwZWNpYWwgY2hhcmFjdGVycyAqIC8gLiAsICEgQCMgJCAlIF4gJiBcJyAoICk=';
		$result2 = GeneralHelper::fromDataURL($url2);
		$this->assertEquals(
			'This uses special characters * / . , ! @# $ % ^ & \\\' ( )',
			$result2->getContent(),
		);

		$result3 = GeneralHelper::fromDataURL('');
		$this->assertEquals(404, $result3->getStatusCode());
		$this->assertEquals('{"code":404,"message":"Data not found"}', $result3->getContent());

		$url4 = 'data:text/plain;base10,SGVsbG8sIFdvcmxkIQ==';
		$result4 = GeneralHelper::fromDataURL($url4);
		$this->assertEquals(400, $result4->getStatusCode());
		$this->assertEquals('{"code":400,"message":"Invalid data"}', $result4->getContent());

		$url5 = 'data:text/plain;base64SGVsbG8sIFdvcmxkIQ==';
		$result5 = GeneralHelper::fromDataURL($url5);
		$this->assertEquals(400, $result5->getStatusCode());
		$this->assertEquals('{"code":400,"message":"Invalid data URL"}', $result5->getContent());

		$url6 = 'data:text/plain;base64,InvalidBase64==';
		$result6 = GeneralHelper::fromDataURL($url6);
		$this->assertEquals(500, $result6->getStatusCode());
		$this->assertEquals(
			'{"code":500,"message":"Failed to decode data"}',
			$result6->getContent(),
		);
	}

	#[Test]
	#[TestDox('Finding position $ordinal of a value $value in array')]
	#[Group('mantle2/util')]
	#[DataProvider('findOrdinalProvider')]
	public function testFindOrdinal(int $ordinal, mixed $value, array $array)
	{
		$this->assertEquals($ordinal, GeneralHelper::findOrdinal($array, $value));
	}

	public static function findOrdinalProvider()
	{
		return [
			'case 1' => [2, 1, [3, 2, 1, 0]],
			'case 2' => [0, 3, [3, 2, 1, 0]],
			'case 3' => [3, 0, [3, 2, 1, 0]],
			'case 4' => [-1, 4, [3, 2, 1, 0]],
			'case 5' => [1, 'b', ['a', 'b', 'c']],
			'case 6' => [0, 'a', ['a', 'b', 'c']],
			'case 7' => [2, 'c', ['a', 'b', 'c']],
			'case 8' => [-1, 'd', ['a', 'b', 'c']],
			'case 9' => [1, null, [true, null, false]],
			'case 10' => [0, true, [true, null, false]],
			'case 11' => [2, false, [true, null, false]],
			'case 12' => [-1, '0', [0, 1, 2]],
			'case 13' => [0, 0, [0, 1, 2]],
			'case 14' => [4, 2, [1, 3, 5, 7, 2, 8, 10, 11, 15, 6]],
			'case 15' => [-1, 4, [1, 3, 5, 7, 2, 8, 10, 11, 15, 6]],
			'case 16' => [5, 8, [1, 3, 5, 7, 2, 8, 10, 11, 15, 6]],
			'case 17' => [0, 'apple', ['apple', 'banana', 'cherry']],
			'case 18' => [2, 'cherry', ['apple', 'banana', 'cherry']],
			'case 19' => [-1, 'x', []],
			'case 20' => [-1, 1, []],
		];
	}

	#[Test]
	#[TestDox('Test integer to hex color conversion: $value -> $expected')]
	#[Group('mantle2/util')]
	#[DataProvider('intToHexProvider')]
	public function testIntToHex(int $value, string $expected)
	{
		$this->assertEquals($expected, GeneralHelper::intToHex($value));
	}

	public static function intToHexProvider()
	{
		return [
			'case 1' => [255, '#0000FF'],
			'case 2' => [0, '#000000'],
			'case 3' => [16, '#000010'],
			'case 4' => [4095, '#000FFF'],
			'case 5' => [65535, '#00FFFF'],
			'case 6' => [1, '#000001'],
			'case 7' => [15, '#00000F'],
			'case 8' => [256, '#000100'],
			'case 9' => [4096, '#001000'],
			'case 10' => [123456, '#01E240'],
			'case 11' => [16777215, '#FFFFFF'],
			'case 12' => [8388608, '#800000'],
			'case 13' => [16711680, '#FF0000'],
			'case 14' => [32768, '#008000'],
			'case 15' => [4294950912, '#FFC000'],
			'case 16' => [16711935, '#FF00FF'],
		];
	}

	#[Test]
	#[TestDox('Test flagged word detection')]
	#[Group('mantle2/util')]
	#[DataProvider('flaggedProvider')]
	public function testFlagged(bool $expected, string $value)
	{
		$result = GeneralHelper::isFlagged($value);
		$isFlagged = !empty($result['flagged']);
		$this->assertEquals($expected, $isFlagged);
	}

	public static function flaggedProvider()
	{
		return [
			'case 1' => [true, 'ass'],
			'case 2' => [false, 'test'],
			'case 3' => [false, 'assumption'],
			'case 4' => [true, 'fucking'],
			'case 5' => [true, 'shited'],
			'case 6' => [true, 'cunt'],
			'case 7' => [false, 'sh*t'],
			'case 8' => [false, 'f**k'],
			'case 9' => [true, 'th0t'],
			'case 10' => [false, 'sextillion'],
			'case 11' => [true, 'c4nn4b1s'],
			'case 12' => [true, 's3x'],
			'case 13' => [true, 'cannabis'],
			'case 14' => [true, 's h i t'],
			'case 15' => [true, 'fu ck i  n g'],
			'case 16' => [true, 'f3 ck1 n g wh0  r e'],
			'case 17' => [true, 'c3.nn4b1s'],
			'case 18' => [true, 'a-s.s'],
			'case 19' => [true, 'f4ck1ng'],
			'case 20' => [true, 'i would really not like to shit myself today'],
			'case 21' => [true, 'sexy'],
			'case 22' => [false, 'forethought'],
			'case 23' => [true, 'shitty'],
			'case 24' => [false, 'today is a really great day to be a viking'],
			'case 25' => [false, 'i think the question fundamentally asks the wrong things'],
			'case 26' => [true, '$    h 1 7 7 3  7'],
			'case 27' => [false, 'n0rm@l t3xt th4t 1sn\'t fl@g73d'],
		];
	}

	// Networking Tests

	#[Test]
	#[TestDox('Test HTTP response utilities for common status codes')]
	#[Group('mantle2/util/http')]
	public function testRequestUtilities()
	{
		$badRequest = GeneralHelper::badRequest('Test message');
		$this->assertEquals(400, $badRequest->getStatusCode());
		$this->assertEquals('{"code":400,"message":"Test message"}', $badRequest->getContent());

		$unauthorized = GeneralHelper::unauthorized('Unauthorized access');
		$this->assertEquals(401, $unauthorized->getStatusCode());
		$this->assertEquals(
			'{"code":401,"message":"Unauthorized access"}',
			$unauthorized->getContent(),
		);

		$paymentRequired = GeneralHelper::paymentRequired('Payment required');
		$this->assertEquals(402, $paymentRequired->getStatusCode());
		$this->assertEquals(
			'{"code":402,"message":"Payment required"}',
			$paymentRequired->getContent(),
		);

		$forbidden = GeneralHelper::forbidden('Access forbidden');
		$this->assertEquals(403, $forbidden->getStatusCode());
		$this->assertEquals('{"code":403,"message":"Access forbidden"}', $forbidden->getContent());

		$notFound = GeneralHelper::notFound('Resource not found');
		$this->assertEquals(404, $notFound->getStatusCode());
		$this->assertEquals('{"code":404,"message":"Resource not found"}', $notFound->getContent());

		$conflict = GeneralHelper::conflict('Conflict occurred');
		$this->assertEquals(409, $conflict->getStatusCode());
		$this->assertEquals('{"code":409,"message":"Conflict occurred"}', $conflict->getContent());

		$internalError = GeneralHelper::internalError('Internal server error');
		$this->assertEquals(500, $internalError->getStatusCode());
		$this->assertEquals(
			'{"code":500,"message":"Internal server error"}',
			$internalError->getContent(),
		);
	}

	#[Test]
	#[TestDox('Test retrieve bearer token')]
	#[Group('mantle2/util/http')]
	public function testGetBearerToken()
	{
		$request = $this->createMock(Request::class);
		$headers = $this->createMock(HeaderBag::class);
		$headers
			->expects($this->once())
			->method('get')
			->with('Authorization')
			->willReturn('Bearer test_token');
		$request->headers = $headers;

		$this->assertEquals('test_token', GeneralHelper::getBearerToken($request));
	}

	#[Test]
	#[TestDox('Test retrieve bearer token with no header')]
	#[Group('mantle2/util/http')]
	public function testGetBearerTokenWithNoHeader()
	{
		$request = $this->createMock(Request::class);
		$headers = $this->createMock(HeaderBag::class);
		$headers->expects($this->once())->method('get')->with('Authorization')->willReturn(null);
		$request->headers = $headers;

		$this->assertEquals(null, GeneralHelper::getBearerToken($request));
	}

	#[Test]
	#[TestDox('Test retrieve basic auth credentials')]
	#[Group('mantle2/util/http')]
	public function testGetBasicAuth()
	{
		$request = $this->createMock(Request::class);
		$headers = $this->createMock(HeaderBag::class);
		$headers
			->expects($this->once())
			->method('get')
			->with('Authorization')
			->willReturn('Basic dGVzdF91c2VyOnRlc3RfcGFzc3dvcmQ=');
		$request->headers = $headers;

		$credentials = GeneralHelper::getBasicAuth($request);
		$this->assertEquals(
			['username' => 'test_user', 'password' => 'test_password'],
			$credentials,
		);
	}

	#[Test]
	#[TestDox('Test retrieve basic auth credentials with invalid header')]
	#[Group('mantle2/util/http')]
	public function testGetBasicAuthWithInvalidHeader()
	{
		$request = $this->createMock(Request::class);
		$headers = $this->createMock(HeaderBag::class);
		$headers
			->expects($this->once())
			->method('get')
			->with('Authorization')
			->willReturn('Basic invalid_token');
		$request->headers = $headers;

		$credentials = GeneralHelper::getBasicAuth($request);
		$this->assertEquals(null, $credentials);
	}

	#[Test]
	#[TestDox('Test retrieve basic auth credentials with no header')]
	#[Group('mantle2/util/http')]
	public function testGetBasicAuthWithNoHeader()
	{
		$request = $this->createMock(Request::class);
		$headers = $this->createMock(HeaderBag::class);
		$headers->expects($this->once())->method('get')->with('Authorization')->willReturn(null);
		$request->headers = $headers;

		$credentials = GeneralHelper::getBasicAuth($request);
		$this->assertEquals(null, $credentials);
	}

	#[Test]
	#[TestDox('Test retrieve paginated parameters')]
	#[Group('mantle2/util/http')]
	public function testCreatePaginatedParameters()
	{
		$request1 = $this->createMock(Request::class);
		$request1->query = new InputBag(['limit' => '10', 'page' => '2', 'search' => 'abc']);

		$params = GeneralHelper::paginatedParameters($request1);
		$this->assertEquals(['limit' => 10, 'page' => 2, 'search' => 'abc'], $params);

		$request2 = $this->createMock(Request::class);
		$request2->query = new InputBag(['limit' => '0', 'page' => '-1']);
		$params = GeneralHelper::paginatedParameters($request2);
		$this->assertIsNotArray($params);
		$this->assertEquals(400, $params->getStatusCode());
		$this->assertEquals(
			'{"code":400,"message":"Invalid limit \u00270\u0027: must be between 1 and 100"}',
			$params->getContent(),
		);

		$request3 = $this->createMock(Request::class);
		$request3->query = new InputBag(['limit' => '151', 'page' => '1']);
		$params = GeneralHelper::paginatedParameters($request3, 150);
		$this->assertIsNotArray($params);
		$this->assertEquals(400, $params->getStatusCode());
		$this->assertEquals(
			'{"code":400,"message":"Invalid limit \u0027151\u0027: must be between 1 and 150"}',
			$params->getContent(),
		);

		$request4 = $this->createMock(Request::class);
		$request4->query = new InputBag([]);
		$params = GeneralHelper::paginatedParameters($request4);
		$this->assertEquals(['limit' => 25, 'page' => 1, 'search' => ''], $params);

		$request5 = $this->createMock(Request::class);
		$request5->query = new InputBag(['limit' => '25', 'page' => '1', 'search' => 1234]);
		$params = GeneralHelper::paginatedParameters($request5);
		$this->assertIsNotArray($params);
		$this->assertEquals(400, $params->getStatusCode());
		$this->assertEquals('{"code":400,"message":"Invalid search term"}', $params->getContent());

		$request6 = $this->createMock(Request::class);
		$request6->query = new InputBag(['limit' => '5', 'page' => '-1']);
		$params = GeneralHelper::paginatedParameters($request6);
		$this->assertIsNotArray($params);
		$this->assertEquals(400, $params->getStatusCode());
		$this->assertEquals(
			'{"code":400,"message":"Invalid page \u0027-1\u0027"}',
			$params->getContent(),
		);
	}
}
