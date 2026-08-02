<?php

namespace Drupal\Tests\mantle2\Integration;

use Drupal\mantle2\Custom\MailCategory;
use Drupal\mantle2\Custom\Notification;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\Tests\mantle2\Unit\MailKeysValidationTest;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Yaml\Yaml;

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
	// campaign bodies expand content placeholders, which query node fields
	protected bool $installContentTypes = true;

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
		];

		// mirror sendEmail(): only an unsubscribable stream carries these, so the fixture cannot
		// hand a transactional key an unsubscribe affordance it would never really have
		if (MailCategory::forMailKey($key)->isUnsubscribable()) {
			$base['unsubscribe_url'] = 'https://api.earth-app.com/v2/users/unsubscribe?token=abc';
			$base['unsubscribe_api_url'] =
				'https://api.earth-app.com/v2/users/unsubscribe?token=abc';
		}

		if ($key === 'unread_notifications_reminder') {
			// constructor order is (id, userId, title, message, timestamp, link, type, source,
			// isRead); the previous call was scrambled, so this template rendered against garbage
			$base['notifications'] = [
				new Notification(
					'n1',
					'1',
					'Title',
					'Message',
					time(),
					'/x',
					'info',
					'system',
					false,
				),
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

	private function sendAndCollect(string $key, UserInterface $user): array
	{
		$this->container->get('plugin.manager.mail')->mail(
			'mantle2',
			$key,
			$user->getEmail(),
			'en',
			$this->paramsFor($key) + [
				'user' => $user,
			],
		);
		$sent = \Drupal::state()->get('system.test_mail_collector') ?? [];

		return end($sent);
	}

	#[Test]
	#[TestDox('A subscribed stream gains List-Unsubscribe headers and transactional mail does not')]
	#[Group('mantle2/email')]
	public function testListHeaders(): void
	{
		$user = $this->createUser();

		// the recurring digest is the case that regressed: it is exactly the mail Google requires
		// a working unsubscribe on, and the old hand-maintained key list omitted it
		$digest = $this->sendAndCollect('unread_notifications_reminder', $user);
		$this->assertArrayHasKey('List-Unsubscribe', $digest['headers']);
		$this->assertSame(
			'List-Unsubscribe=One-Click',
			$digest['headers']['List-Unsubscribe-Post'],
		);

		foreach (['new_login', 'new_password', 'password_reset', 'welcome'] as $transactional) {
			$mail = $this->sendAndCollect($transactional, $user);
			$this->assertArrayNotHasKey(
				'List-Unsubscribe',
				$mail['headers'],
				"$transactional is transactional and must not offer an unsubscribe.",
			);
			$this->assertArrayNotHasKey(
				'Precedence',
				$mail['headers'],
				"$transactional must not be marked bulk.",
			);
		}
	}

	public static function campaignProvider(): array
	{
		// parsed directly rather than through CampaignHelper: a data provider runs before the
		// Drupal container exists, and getCampaigns() resolves the path through a service
		$campaigns = Yaml::parseFile(dirname(__DIR__, 3) . '/data/email_campaigns.yml');

		$data = [];
		foreach ($campaigns as $campaign) {
			$data[$campaign['id']] = [$campaign['id']];
		}

		return $data;
	}

	/**
	 * Every campaign renders a button, an inbox preview line, and a body that cannot be clipped.
	 *
	 * Gmail truncates at roughly 102KB of raw HTML mid-tag, which can cut off the unsubscribe
	 * footer and turn a layout problem into a compliance one, so the budget here is 90KB.
	 */
	#[Test]
	#[TestDox('Campaign $id renders a CTA, a preheader, and stays well under the clip threshold')]
	#[Group('mantle2/email')]
	#[DataProvider('campaignProvider')]
	public function testCampaignRendersActionably(string $id): void
	{
		$user = $this->createUser();
		$message = $this->renderMail('campaign:' . $id, [
			'user' => $user,
			'unsubscribe_url' => 'https://api.earth-app.com/v2/users/unsubscribe?token=abc',
			'unsubscribe_api_url' => 'https://api.earth-app.com/v2/users/unsubscribe?token=abc',
		]);

		$html = $message['body'][0] ?? '';
		$this->assertNotEmpty($message['subject'], "Campaign $id has no subject");

		// the table-based button, which is what survives Outlook's Word rendering engine
		$this->assertStringContainsString(
			'<table role="presentation" style="margin: 24px 0;',
			$html,
			"Campaign $id rendered no CTA button; the toHtml options were dropped again.",
		);

		// the hidden preheader plus its zero-width padding, or Gmail scrapes the body instead
		$this->assertStringContainsString('mso-hide: all;', $html);
		$this->assertStringContainsString('&#847;&zwnj;&nbsp;', $html);

		// measured 4.1-5.1KB per campaign; the old insights body inlined three 1500-char articles
		$this->assertLessThan(
			92160,
			strlen($html),
			"Campaign $id renders " . strlen($html) . ' bytes and risks being clipped.',
		);
	}

	#[Test]
	#[TestDox('A suppressed address is never sent to again')]
	#[Group('mantle2/email')]
	public function testSuppressedAddressIsSkipped(): void
	{
		$user = $this->createUser();
		$email = $user->getEmail();

		\Drupal::state()->set('system.test_mail_collector', []);
		UsersHelper::sendEmail($user, 'welcome', ['user' => $user]);
		$this->assertCount(
			1,
			\Drupal::state()->get('system.test_mail_collector') ?? [],
			'Control: an unsuppressed address must receive mail.',
		);

		// nothing recorded a permanent failure before, so cron retried dead addresses every cycle
		// and the account bounce rate climbed until the provider threatened to pause sending
		UsersHelper::markEmailUndeliverable($email, 'mailbox_user_not_found');
		$this->assertTrue(UsersHelper::isEmailUndeliverable($email));

		\Drupal::state()->set('system.test_mail_collector', []);
		UsersHelper::sendEmail($user, 'welcome', ['user' => $user]);
		$this->assertSame(
			[],
			\Drupal::state()->get('system.test_mail_collector') ?? [],
			'A suppressed address must not be attempted again, not even for transactional mail.',
		);

		// and releasing it resumes sending, so a wrong suppression is recoverable
		UsersHelper::clearEmailSuppression($email);
		$this->assertFalse(UsersHelper::isEmailUndeliverable($email));

		UsersHelper::sendEmail($user, 'welcome', ['user' => $user]);
		$this->assertCount(1, \Drupal::state()->get('system.test_mail_collector') ?? []);
	}

	#[Test]
	#[TestDox('Suppression is keyed on the address, so it survives an email change')]
	#[Group('mantle2/email')]
	public function testSuppressionIsAddressKeyed(): void
	{
		$suppressed = 'dead-relay@privaterelay.appleid.com';
		UsersHelper::markEmailUndeliverable($suppressed, 'mailbox_user_not_found');

		// case and surrounding whitespace must not create a second, unsuppressed identity
		$this->assertTrue(UsersHelper::isEmailUndeliverable($suppressed));
		$this->assertTrue(UsersHelper::isEmailUndeliverable('Dead-Relay@PrivateRelay.AppleID.com'));
		$this->assertTrue(
			UsersHelper::isEmailUndeliverable('  dead-relay@privaterelay.appleid.com '),
		);
		$this->assertFalse(UsersHelper::isEmailUndeliverable('someone-else@gmail.com'));
	}

	#[Test]
	#[TestDox('Every unsubscribable key ships both the header and a visible body link')]
	#[Group('mantle2/email')]
	public function testUnsubscribableKeysShipBothAffordances(): void
	{
		$user = $this->createUser();

		foreach (MailKeysValidationTest::mailKeys() as $key) {
			if (!MailCategory::forMailKey($key)->isUnsubscribable()) {
				continue;
			}

			$mail = $this->sendAndCollect($key, $user);
			$this->assertArrayHasKey('List-Unsubscribe', $mail['headers'], "$key lost the header.");
			$this->assertStringContainsString(
				'Unsubscribe from these emails',
				implode('', (array) $mail['body']),
				"$key lost the visible unsubscribe link Google requires in the body.",
			);
		}
	}
}
