<?php

namespace Drupal\Tests\mantle2\Integration;

use Drupal\mantle2\Custom\Notification;
use Drupal\Tests\mantle2\Unit\MailKeysValidationTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * In-kernel proof that every mail key ships as HTML.
 *
 * drupal/smtp calls PHPMailer's IsHTML(FALSE) for any non-text/html content type, which
 * is what made content_moderation and moderation_digest arrive as raw markup. That call
 * is not reachable in a kernel test, so the Content-Type header is the correct proxy:
 * get it right and drupal/smtp does the right thing.
 */
class MailRenderingTest extends IntegrationTestBase
{
	public static function mailKeyProvider(): array
	{
		$data = [];
		foreach (MailKeysValidationTest::mailKeys() as $key) {
			$data[$key] = [$key];
		}

		return $data;
	}

	/** plausible params for every key, merged over a permissive default */
	private function paramsFor(string $key): array
	{
		$base = [
			'verification_code' => 'ABC123',
			'ip' => '203.0.113.4',
			'additional_ips' => '203.0.113.5',
			'user_agent' => 'Mozilla/5.0',
			'referer' => 'https://app.earth-app.com',
			'time' => date(DATE_ATOM),
			'provider' => 'google',
			'new_email' => 'new@example.com',
			'old_email' => 'old@example.com',
			'challenger' => 'someone',
			'quest_title' => 'Walk a Mile',
			'quest_id' => 'q1',
			'count' => 3,
			'reason' => 'testing',
			'notes' => 'a note',
			'role' => 'author',
			'content_type' => 'prompt',
			'action' => 'removed',
			'tier' => 'PRO',
			'days' => 7,
			'amount' => '4.99',
			'currency' => 'USD',
			'code' => 'TRIALCODE',
			'expires_at' => date(DATE_ATOM),
			'renewal_date' => date(DATE_ATOM),
			'old_price' => '9.99',
			'new_price' => '14.99',
			'effective_date' => date(DATE_ATOM),
			'key_name' => 'CI key',
			'key_id' => 'EA26ABCDEFGHIJ',
			'deadline' => date(DATE_ATOM),
			'activity' => 'bouldering',
			'activities' => 'bouldering, kayaking',
			'submitter' => 'organizer',
			'applicant' => 'organizer',
			'organization' => 'Test Org',
			'revoked_staged' => 2,
			'count_organizer' => 1,
			'count_automated' => 2,
			'soonest_deadline' => date(DATE_ATOM),
			'urgent_count' => 1,
			'reset_link' => 'https://app.earth-app.com/reset-password?token=abc',
			'unsubscribe_url' => 'https://app.earth-app.com/unsubscribe',
			'unsubscribe_api_url' => 'https://api.earth-app.com/v2/unsubscribe?token=abc',
		];

		if ($key === 'unread_notifications_reminder') {
			$base['notifications'] = [
				new Notification('n1', 'Title', 'Message', time(), false, 'info', 'system', '/x'),
			];
		}

		return $base;
	}

	/**
	 * Invoke hook_mail directly.
	 *
	 * Going through MailManager here would measure test_mail_collector, which inherits
	 * PhpMail::format() and runs htmlToText over the body. The hook's own output is what
	 * drupal/smtp actually ships.
	 */
	private function renderMail(string $key, array $params): array
	{
		$message = [
			'id' => "mantle2_$key",
			'module' => 'mantle2',
			'key' => $key,
			'to' => 'recipient@example.com',
			'from' => '',
			'langcode' => 'en',
			'params' => $params,
			'send' => true,
			'subject' => '',
			'body' => [],
			'headers' => [],
		];

		mantle2_mail($key, $message, $params);

		return $message;
	}

	#[Test]
	#[TestDox('Every mail key renders an HTML document with an HTML Content-Type')]
	#[Group('mantle2/email')]
	#[DataProvider('mailKeyProvider')]
	public function testKeyRendersAsHtml(string $key): void
	{
		$user = $this->createUser();
		$message = $this->renderMail($key, $this->paramsFor($key) + ['user' => $user]);

		$this->assertNotEmpty($message['subject'], "Key $key has no subject");
		$this->assertStringStartsWith(
			'text/html',
			$message['headers']['Content-Type'] ?? '',
			"Key $key would be shipped as plain text and arrive as raw markup",
		);
		$this->assertCount(1, $message['body'], "Key $key must append exactly one body part");
		$this->assertStringStartsWith('<!DOCTYPE html>', $message['body'][0]);
		$this->assertStringContainsString(
			'Thank you for using The Earth App!',
			$message['body'][0],
		);
	}

	#[Test]
	#[TestDox('The HTML Content-Type survives the full MailManager pipeline')]
	#[Group('mantle2/email')]
	public function testHeaderSurvivesMailManager(): void
	{
		$user = $this->createUser();

		$this->container
			->get('plugin.manager.mail')
			->mail(
				'mantle2',
				'welcome',
				$user->getEmail(),
				'en',
				$this->paramsFor('welcome') + ['user' => $user],
			);

		$sent = \Drupal::state()->get('system.test_mail_collector') ?? [];
		$mail = end($sent);

		$this->assertSame('mantle2_welcome', $mail['id']);
		$this->assertStringStartsWith('text/html', $mail['headers']['Content-Type'] ?? '');
	}

	#[Test]
	#[TestDox('The two historically broken keys render as HTML')]
	#[Group('mantle2/email')]
	public function testHistoricallyBrokenKeys(): void
	{
		foreach (['content_moderation', 'moderation_digest'] as $key) {
			$user = $this->createUser();
			$message = $this->renderMail($key, $this->paramsFor($key) + ['user' => $user]);

			$this->assertStringStartsWith('text/html', $message['headers']['Content-Type'] ?? '');
			$this->assertStringStartsWith('<!DOCTYPE html>', $message['body'][0]);
			$this->assertStringNotContainsString(
				'&lt;!DOCTYPE',
				$message['body'][0],
				"Key $key double-escaped its own markup",
			);
		}
	}

	#[Test]
	#[TestDox('A key with no matching case produces no mail rather than an empty body')]
	#[Group('mantle2/email')]
	public function testUnknownKeyProducesNoBody(): void
	{
		$user = $this->createUser();
		$message = $this->renderMail('not_a_real_key', ['user' => $user]);

		$this->assertSame(
			[],
			$message['body'],
			'An unhandled key must not ship a body; the shared tail should have bailed.',
		);
		$this->assertArrayNotHasKey('Content-Type', $message['headers']);
	}

	#[Test]
	#[TestDox('An unsubscribable key gains List-Unsubscribe headers and others do not')]
	#[Group('mantle2/email')]
	public function testListHeaders(): void
	{
		$user = $this->createUser();

		$this->container
			->get('plugin.manager.mail')
			->mail(
				'mantle2',
				'new_login',
				$user->getEmail(),
				'en',
				$this->paramsFor('new_login') + ['user' => $user],
			);
		$sent = \Drupal::state()->get('system.test_mail_collector') ?? [];
		$mail = end($sent);
		$this->assertArrayHasKey('List-Unsubscribe', $mail['headers']);
		$this->assertSame('List-Unsubscribe=One-Click', $mail['headers']['List-Unsubscribe-Post']);

		$this->container
			->get('plugin.manager.mail')
			->mail(
				'mantle2',
				'welcome',
				$user->getEmail(),
				'en',
				$this->paramsFor('welcome') + ['user' => $user],
			);
		$sent = \Drupal::state()->get('system.test_mail_collector') ?? [];
		$welcome = end($sent);
		$this->assertArrayNotHasKey('List-Unsubscribe', $welcome['headers']);
	}
}
