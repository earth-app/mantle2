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
}
