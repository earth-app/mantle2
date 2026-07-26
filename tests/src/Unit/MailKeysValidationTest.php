<?php

namespace Drupal\Tests\mantle2\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for emails that shipped as raw HTML source.
 *
 * mantle2_mail_alter() used to gate `Content-Type: text/html` on a hand-maintained
 * allow-list. content_moderation and moderation_digest were added to mantle2_mail() and
 * never to that list, so they kept core's text/plain default; drupal/smtp then calls
 * PHPMailer's IsHTML(FALSE) for any non-text/html type and ships the whole
 * <!DOCTYPE html> document as a text part. The header is now set unconditionally in
 * mantle2_mail(), which makes the bug class unreachable. These tests keep it that way.
 */
class MailKeysValidationTest extends TestCase
{
	private static string $source;
	private static string $mailBody;
	private static string $alterBody;

	public static function setUpBeforeClass(): void
	{
		$path = dirname(__DIR__, 3) . '/mantle2.module';
		if (!file_exists($path)) {
			self::fail('Module file not found: ' . $path);
		}

		self::$source = file_get_contents($path);
		self::$mailBody = self::functionSource('mantle2_mail');
		self::$alterBody = self::functionSource('mantle2_mail_alter');
	}

	private static function functionSource(string $name): string
	{
		$start = strpos(self::$source, "function $name(");
		if ($start === false) {
			self::fail("Function $name() not found in mantle2.module");
		}

		$open = strpos(self::$source, '{', $start);
		$depth = 0;
		for ($i = $open; $i < strlen(self::$source); $i++) {
			if (self::$source[$i] === '{') {
				$depth++;
			} elseif (self::$source[$i] === '}') {
				$depth--;
				if ($depth === 0) {
					return substr(self::$source, $open, $i - $open + 1);
				}
			}
		}

		self::fail("Could not find the end of $name()");
	}

	/** @return string[] every key mantle2_mail() renders */
	public static function mailKeys(): array
	{
		if (!isset(self::$mailBody)) {
			self::setUpBeforeClass();
		}

		preg_match_all("/case '([a-z0-9_]+)':/", self::$mailBody, $matches);

		return array_values(array_unique($matches[1]));
	}

	#[Test]
	#[TestDox('mantle2_mail() sets an HTML Content-Type for every key it renders')]
	#[Group('mantle2/email')]
	public function testContentTypeIsUnconditional(): void
	{
		$this->assertStringContainsString(
			"\$message['headers']['Content-Type'] = 'text/html; charset=UTF-8';",
			self::$mailBody,
			'mantle2_mail() must set the HTML Content-Type itself, not delegate to an allow-list.',
		);
		$this->assertStringContainsString(
			"\$message['headers']['MIME-Version'] = '1.0';",
			self::$mailBody,
		);
	}

	#[Test]
	#[TestDox('mantle2_mail_alter() no longer gates Content-Type behind an allow-list')]
	#[Group('mantle2/email')]
	public function testAlterHasNoAllowList(): void
	{
		$this->assertStringNotContainsString(
			"\$message['headers']['Content-Type']",
			self::$alterBody,
			'A per-key Content-Type allow-list is exactly the bug that shipped raw HTML.',
		);
		$this->assertStringNotContainsString('mantle2_email_verification', self::$alterBody);
		$this->assertStringNotContainsString('mantle2_moderation_digest', self::$alterBody);
	}

	#[Test]
	#[TestDox('The two historically broken keys are still rendered by mantle2_mail()')]
	#[Group('mantle2/email')]
	public function testHistoricallyBrokenKeysArePresent(): void
	{
		$keys = self::mailKeys();

		$this->assertContains('content_moderation', $keys);
		$this->assertContains('moderation_digest', $keys);
	}

	#[Test]
	#[TestDox('Every case assigns $markdown and none appends to the body itself')]
	#[Group('mantle2/email')]
	public function testBodyIsAppendedExactlyOnce(): void
	{
		$appends = substr_count(self::$mailBody, "\$message['body'][] =");
		$this->assertSame(
			1,
			$appends,
			'The body must be built once after the switch; a per-case append is how a new key ' .
				'silently skips the shared tail.',
		);

		$this->assertSame(
			1,
			substr_count(self::$mailBody, '$parser->toHtml('),
			'toHtml() must be called exactly once, in the shared tail.',
		);
	}

	#[Test]
	#[TestDox('A key that builds no markdown is logged rather than sent empty')]
	#[Group('mantle2/email')]
	public function testUnbuiltKeyFailsLoudly(): void
	{
		$this->assertStringContainsString('$markdown === null', self::$mailBody);
		$this->assertStringContainsString('No mail body was built for email key', self::$mailBody);
	}

	#[Test]
	#[TestDox('The unsubscribable key list exists once and both mail hooks read it')]
	#[Group('mantle2/email')]
	public function testUnsubscribableListIsShared(): void
	{
		$this->assertStringContainsString('const MANTLE2_UNSUBSCRIBABLE_MAIL_KEYS', self::$source);
		$this->assertStringContainsString('MANTLE2_UNSUBSCRIBABLE_MAIL_KEYS', self::$mailBody);
		$this->assertStringContainsString('MANTLE2_UNSUBSCRIBABLE_MAIL_KEYS', self::$alterBody);

		// the literal list must not be spelled out a second time anywhere
		$this->assertSame(
			1,
			substr_count(self::$source, "'oauth_provider_unlinked',"),
			'The unsubscribable keys are duplicated; there must be exactly one list.',
		);
	}

	#[Test]
	#[TestDox('The staging and verified publisher keys are all rendered')]
	#[Group('mantle2/email')]
	public function testStagingKeysArePresent(): void
	{
		$keys = self::mailKeys();

		foreach (
			[
				'activity_staged_submitted',
				'activity_staged_digest',
				'activity_staged_urgent',
				'activity_staged_approved',
				'activity_staged_denied',
				'verified_publisher_submitted',
				'verified_publisher_approved',
				'verified_publisher_denied',
				'verified_publisher_revoked',
			]
			as $key
		) {
			$this->assertContains($key, $keys);
		}
	}

	#[Test]
	#[TestDox('Every rendered key sets a subject')]
	#[Group('mantle2/email')]
	public function testEveryKeySetsASubject(): void
	{
		$blocks = preg_split("/case '[a-z0-9_]+':/", self::$mailBody);
		array_shift($blocks);

		$keys = self::mailKeys();
		foreach ($blocks as $index => $block) {
			// fallthrough cases share the following block; only assert on ones with content
			if (trim($block) === '') {
				continue;
			}
			$this->assertStringContainsString(
				"\$message['subject']",
				$block,
				"Key {$keys[$index]} does not set a subject",
			);
		}
	}
}
