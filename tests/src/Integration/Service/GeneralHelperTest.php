<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class GeneralHelperTest extends IntegrationTestBase
{
	// Request Utilities

	#[Test]
	#[TestDox('emailVerificationRequired discriminates verify vs add and carries the flag')]
	#[Group('mantle2/util')]
	public function emailVerificationRequired(): void
	{
		$verify = GeneralHelper::emailVerificationRequired('post a comment', true);
		$this->assertSame(Response::HTTP_FORBIDDEN, $verify->getStatusCode());
		$verifyBody = json_decode($verify->getContent(), true);
		$this->assertSame(403, $verifyBody['code']);
		$this->assertSame('EMAIL_VERIFICATION_REQUIRED', $verifyBody['reason']);
		$this->assertTrue($verifyBody['has_email']);
		$this->assertSame('You must verify your email to post a comment.', $verifyBody['message']);

		$add = GeneralHelper::emailVerificationRequired('post a comment', false);
		$addBody = json_decode($add->getContent(), true);
		$this->assertFalse($addBody['has_email']);
		$this->assertSame('You must add your email to post a comment.', $addBody['message']);

		$default = GeneralHelper::emailVerificationRequired();
		$defaultBody = json_decode($default->getContent(), true);
		$this->assertSame(
			'You must verify your email to perform this action.',
			$defaultBody['message'],
		);
	}

	// Response Utilities

	#[Test]
	#[TestDox('queryInt parses ints, falls back on blanks/non-numerics, and honors defaults')]
	#[Group('mantle2/util')]
	#[DataProvider('queryIntProvider')]
	public function queryInt(mixed $raw, ?int $default, ?int $expected): void
	{
		$request = Request::create('/');
		$request->query = new InputBag($raw === null ? [] : ['n' => $raw]);
		$this->assertSame($expected, GeneralHelper::queryInt($request, 'n', $default));
	}

	public static function queryIntProvider(): array
	{
		return [
			'valid int string' => ['42', 0, 42],
			'negative int string' => ['-7', 0, -7],
			'zero' => ['0', 9, 0],
			'blank string' => ['', 5, 5],
			'whitespace only' => ['   ', 5, 5],
			'non numeric' => ['abc', 3, 3],
			'float rejected' => ['1.5', 3, 3],
			'missing key' => [null, 11, 11],
			'missing key null default' => [null, null, null],
			'array value ignored' => [['1'], 4, 4],
			'padded int trimmed' => ['  8  ', 0, 8],
		];
	}

	#[Test]
	#[TestDox('paginatedParameters rejects an over-long search term')]
	#[Group('mantle2/util/http')]
	public function paginatedParametersSearchTooLong(): void
	{
		$request = Request::create('/');
		$request->query = new InputBag(['search' => str_repeat('a', 151)]);
		$params = GeneralHelper::paginatedParameters($request);
		$this->assertInstanceOf(Response::class, $params);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $params->getStatusCode());
		$this->assertStringContainsString('too long', $params->getContent());
	}

	#[Test]
	#[TestDox('paginatedParameters trims and preserves a valid search term')]
	#[Group('mantle2/util/http')]
	public function paginatedParametersTrimsSearch(): void
	{
		$request = Request::create('/');
		$request->query = new InputBag(['search' => '  hello  ', 'limit' => '150', 'page' => '3']);
		$params = GeneralHelper::paginatedParameters($request, 150);
		$this->assertIsArray($params);
		$this->assertSame('hello', $params['search']);
		$this->assertSame(150, $params['limit']);
		$this->assertSame(3, $params['page']);
		$this->assertSame('desc', $params['sort']);
	}

	// Content Validation

	#[Test]
	#[TestDox('validateUserContent returns clean text, censors, or 400s and logs on a hard reject')]
	#[Group('mantle2/util')]
	public function validateUserContent(): void
	{
		$clean = GeneralHelper::validateUserContent('a friendly hello', false, 'bio');
		$this->assertSame('a friendly hello', $clean);

		$censored = GeneralHelper::validateUserContent('what the fuck', true, 'bio');
		$this->assertSame('what the ****', $censored);

		$rejected = GeneralHelper::validateUserContent('what the fuck', false, 'bio', 42);
		$this->assertInstanceOf(Response::class, $rejected);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $rejected->getStatusCode());
		$body = json_decode($rejected->getContent(), true);
		$this->assertSame(400, $body['code']);
		$this->assertStringContainsString('Bio contains inappropriate content', $body['message']);
	}

	// MOTD Cycle

	#[Test]
	#[TestDox('cycleMotd advances the stored index and rotates through the built-in list')]
	#[Group('mantle2/util')]
	public function cycleMotdAdvances(): void
	{
		$this->assertNull(RedisHelper::get('motd'));

		GeneralHelper::cycleMotd();
		$first = RedisHelper::get('motd');
		$this->assertSame('Welcome to The Earth App!', $first['motd']);
		$this->assertSame('mdi:earth', $first['icon']);
		$this->assertSame(1, RedisHelper::get('motd_cycle_index')['value']);

		GeneralHelper::cycleMotd();
		$second = RedisHelper::get('motd');
		$this->assertNotSame($first['motd'], $second['motd']);
		$this->assertSame(2, RedisHelper::get('motd_cycle_index')['value']);
	}

	#[Test]
	#[TestDox('cycleMotd skips rotation when the motd was set manually')]
	#[Group('mantle2/util')]
	public function cycleMotdSkipsWhenManual(): void
	{
		RedisHelper::set('motd_set_by', ['value' => 5], 3600);
		RedisHelper::set('motd', ['motd' => 'Manual message', 'type' => 'info'], 3600);

		GeneralHelper::cycleMotd();

		$this->assertSame('Manual message', RedisHelper::get('motd')['motd']);
		$this->assertNull(RedisHelper::get('motd_cycle_index'));
	}

	// Error Response Builders

	#[Test]
	#[TestDox('response builders emit the right status code and code/message envelope')]
	#[Group('mantle2/util')]
	public function responseBuilders(): void
	{
		$cases = [
			[GeneralHelper::badRequest('b'), 400],
			[GeneralHelper::unauthorized('u'), 401],
			[GeneralHelper::paymentRequired('p'), 402],
			[GeneralHelper::forbidden('f'), 403],
			[GeneralHelper::notFound('n'), 404],
			[GeneralHelper::conflict('c'), 409],
			[GeneralHelper::gone('g'), 410],
			[GeneralHelper::internalError('i'), 500],
		];
		foreach ($cases as [$response, $code]) {
			$this->assertSame($code, $response->getStatusCode());
			$body = json_decode($response->getContent(), true);
			$this->assertSame($code, $body['code']);
			$this->assertNotEmpty($body['message']);
		}

		// defaults fill in a message
		$this->assertSame(
			'Bad Request',
			json_decode(GeneralHelper::badRequest()->getContent(), true)['message'],
		);
		$this->assertSame(
			'Not Found',
			json_decode(GeneralHelper::notFound()->getContent(), true)['message'],
		);
	}

	// Response Utilities

	#[Test]
	#[TestDox('formatId zero-pads to 24, preserves 24-char values, and truncates overlong ones')]
	#[Group('mantle2/util')]
	public function formatId(): void
	{
		$padded = GeneralHelper::formatId(42);
		$this->assertSame(24, strlen($padded));
		$this->assertSame('000000000000000000000042', $padded);

		$already = str_repeat('a', 24);
		$this->assertSame($already, GeneralHelper::formatId($already));

		$overlong = GeneralHelper::formatId(str_repeat('b', 30));
		$this->assertSame(24, strlen($overlong));
		$this->assertSame(str_repeat('b', 24), $overlong);
	}

	#[Test]
	#[TestDox('dateToIso emits an ISO-8601 UTC timestamp')]
	#[Group('mantle2/util')]
	public function dateToIso(): void
	{
		$this->assertSame('1970-01-01T00:00:00+00:00', GeneralHelper::dateToIso(0));
		$this->assertSame('2021-01-01T00:00:00+00:00', GeneralHelper::dateToIso(1609459200));
	}

	#[Test]
	#[TestDox('findOrdinal returns the strict index or -1 when absent')]
	#[Group('mantle2/util')]
	public function findOrdinal(): void
	{
		$arr = ['a', 'b', 'c'];
		$this->assertSame(0, GeneralHelper::findOrdinal($arr, 'a'));
		$this->assertSame(2, GeneralHelper::findOrdinal($arr, 'c'));
		$this->assertSame(-1, GeneralHelper::findOrdinal($arr, 'z'));
		// strict comparison: '1' is not 1
		$this->assertSame(-1, GeneralHelper::findOrdinal([1, 2], '1'));
	}

	#[Test]
	#[TestDox('intToHex renders a 6-digit hex string masked to 24 bits')]
	#[Group('mantle2/util')]
	public function intToHex(): void
	{
		$this->assertSame('#000000', GeneralHelper::intToHex(0));
		$this->assertSame('#FFFFFF', GeneralHelper::intToHex(0xffffff));
		$this->assertSame('#112233', GeneralHelper::intToHex(0x112233));
		// bits above 24 are masked off
		$this->assertSame('#000001', GeneralHelper::intToHex(0x1000001));
	}

	// Network Utilities

	#[Test]
	#[TestDox('getBearerToken extracts a Bearer credential and ignores other schemes')]
	#[Group('mantle2/util')]
	public function getBearerToken(): void
	{
		$req = Request::create('/');
		$req->headers->set('Authorization', 'Bearer abc.def');
		$this->assertSame('abc.def', GeneralHelper::getBearerToken($req));

		$basic = Request::create('/');
		$basic->headers->set('Authorization', 'Basic ' . base64_encode('u:p'));
		$this->assertNull(GeneralHelper::getBearerToken($basic));

		$this->assertNull(GeneralHelper::getBearerToken(Request::create('/')));
	}

	#[Test]
	#[TestDox('getBasicAuth decodes credentials and rejects malformed headers')]
	#[Group('mantle2/util')]
	public function getBasicAuth(): void
	{
		$req = Request::create('/');
		$req->headers->set('Authorization', 'Basic ' . base64_encode('alice:s3cret:with:colons'));
		$creds = GeneralHelper::getBasicAuth($req);
		$this->assertSame('alice', $creds['username']);
		$this->assertSame('s3cret:with:colons', $creds['password']);

		// no colon -> not a valid pair
		$noColon = Request::create('/');
		$noColon->headers->set('Authorization', 'Basic ' . base64_encode('justusername'));
		$this->assertNull(GeneralHelper::getBasicAuth($noColon));

		// bearer scheme is not basic
		$bearer = Request::create('/');
		$bearer->headers->set('Authorization', 'Bearer xyz');
		$this->assertNull(GeneralHelper::getBasicAuth($bearer));

		$this->assertNull(GeneralHelper::getBasicAuth(Request::create('/')));
	}

	// Data URL

	#[Test]
	#[TestDox('fromDataURL decodes a base64 data url and returns the raw bytes with mime')]
	#[Group('mantle2/util')]
	public function fromDataUrlValid(): void
	{
		$payload = 'hello world';
		$url = 'data:text/plain;base64,' . base64_encode($payload);
		$response = GeneralHelper::fromDataURL($url);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertSame($payload, $response->getContent());
		$this->assertSame('text/plain', $response->headers->get('Content-Type'));
		$this->assertSame((string) strlen($payload), $response->headers->get('Content-Length'));
	}

	public static function badDataUrlProvider(): array
	{
		return [
			'empty' => ['', Response::HTTP_NOT_FOUND],
			'no data prefix' => ['http://example.com/x.png', Response::HTTP_BAD_REQUEST],
			'no comma' => ['data:text/plain;base64', Response::HTTP_BAD_REQUEST],
			'not base64' => ['data:text/plain,plainvalue', Response::HTTP_BAD_REQUEST],
			'missing mime match' => ['data:base64,' . 'aGk=', Response::HTTP_BAD_REQUEST],
		];
	}

	#[Test]
	#[TestDox('fromDataURL rejects malformed data urls')]
	#[Group('mantle2/util')]
	#[DataProvider('badDataUrlProvider')]
	public function fromDataUrlInvalid(string $url, int $expected): void
	{
		$response = GeneralHelper::fromDataURL($url);
		$this->assertSame($expected, $response->getStatusCode());
	}

	// Pagination edge cases

	#[Test]
	#[TestDox('paginatedParameters rejects bad limit, bad page, and bad sort values')]
	#[Group('mantle2/util/http')]
	public function paginatedParametersRejectsBadValues(): void
	{
		$zeroLimit = Request::create('/');
		$zeroLimit->query = new InputBag(['limit' => '0']);
		$this->assertSame(
			Response::HTTP_BAD_REQUEST,
			GeneralHelper::paginatedParameters($zeroLimit)->getStatusCode(),
		);

		$overLimit = Request::create('/');
		$overLimit->query = new InputBag(['limit' => '101']);
		$this->assertSame(
			Response::HTTP_BAD_REQUEST,
			GeneralHelper::paginatedParameters($overLimit)->getStatusCode(),
		);

		$badPage = Request::create('/');
		$badPage->query = new InputBag(['page' => '0']);
		$this->assertSame(
			Response::HTTP_BAD_REQUEST,
			GeneralHelper::paginatedParameters($badPage)->getStatusCode(),
		);

		$badSort = Request::create('/');
		$badSort->query = new InputBag(['sort' => 'sideways']);
		$res = GeneralHelper::paginatedParameters($badSort);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $res->getStatusCode());
		$this->assertStringContainsString('Invalid sort order', $res->getContent());

		// rand and mixed-case sort are accepted and lowercased
		$randSort = Request::create('/');
		$randSort->query = new InputBag(['sort' => 'RAND']);
		$this->assertSame('rand', GeneralHelper::paginatedParameters($randSort)['sort']);

		// defaults when nothing supplied
		$defaults = GeneralHelper::paginatedParameters(Request::create('/'));
		$this->assertSame(25, $defaults['limit']);
		$this->assertSame(1, $defaults['page']);
		$this->assertSame('', $defaults['search']);
	}

	// Flagging / Censoring

	#[Test]
	#[TestDox('isFlagged catches bad words, short strings pass, and leetspeak is normalized')]
	#[Group('mantle2/util')]
	public function isFlagged(): void
	{
		$clean = GeneralHelper::isFlagged('a wholesome sentence about the ocean');
		$this->assertSame('', $clean['flagged']);
		$this->assertSame('', $clean['matched_word']);

		// too short to evaluate
		$this->assertSame('', GeneralHelper::isFlagged('ok')['flagged']);

		$flagged = GeneralHelper::isFlagged('you piece of shit');
		$this->assertSame('you piece of shit', $flagged['flagged']);
		$this->assertSame('shit', $flagged['matched_word']);

		// leetspeak normalization still catches it
		$this->assertNotSame('', GeneralHelper::isFlagged('what the fuck')['flagged']);

		// innocent substring words are not flagged (boundary-required word)
		$this->assertSame('', GeneralHelper::isFlagged('the raccoon ran away')['flagged']);
	}

	#[Test]
	#[
		TestDox(
			'censorText masks flagged words, keeps whitespace, and no-ops on clean or short text',
		),
	]
	#[Group('mantle2/util')]
	public function censorText(): void
	{
		$this->assertSame('ok', GeneralHelper::censorText('ok'));
		$this->assertSame('a clean line here', GeneralHelper::censorText('a clean line here'));

		$censored = GeneralHelper::censorText('what the fuck man');
		$this->assertSame('what the **** man', $censored);
		// whitespace and word count preserved
		$this->assertSame(4, count(explode(' ', $censored)));
	}
}
