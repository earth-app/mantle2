<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\MailCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The classification three separate consumers depend on.
 *
 * Whether sendEmail() attaches unsubscribe params, whether mantle2_mail_alter() emits List-*
 * headers, and whether addNotification() may interrupt someone all derive from this enum, so a
 * wrong answer here skews all three at once.
 */
class MailCategoryTest extends TestCase
{
	#[Test]
	#[TestDox('Security, billing, moderation, ops and account mail can never be opted out of')]
	#[Group('mantle2/util')]
	public function testTransactionalStreamsAreNotUnsubscribable(): void
	{
		foreach (
			[
				MailCategory::SECURITY,
				MailCategory::BILLING,
				MailCategory::MODERATION,
				MailCategory::OPS,
				MailCategory::ACCOUNT,
			]
			as $category
		) {
			$this->assertFalse(
				$category->isUnsubscribable(),
				"{$category->value} must not offer an unsubscribe.",
			);

			// the same streams bypass the notification cooldown, for the same reason
			$this->assertTrue($category->isUrgent(), "{$category->value} must not be spaced out.");
		}
	}

	#[Test]
	#[TestDox('Digest, announcement, lifecycle, reengagement, social and quest mail is optional')]
	#[Group('mantle2/util')]
	public function testOptionalStreamsAreUnsubscribable(): void
	{
		foreach (
			[
				MailCategory::DIGEST,
				MailCategory::ANNOUNCEMENTS,
				MailCategory::LIFECYCLE,
				MailCategory::REENGAGEMENT,
				MailCategory::SOCIAL,
				MailCategory::QUEST,
			]
			as $category
		) {
			$this->assertTrue(
				$category->isUnsubscribable(),
				"{$category->value} must be opt-out-able.",
			);
			$this->assertFalse($category->isUrgent(), "{$category->value} must be spaceable.");
		}
	}

	#[Test]
	#[TestDox('Every case answers both questions, so a new case cannot be forgotten')]
	#[Group('mantle2/util')]
	public function testEveryCaseIsClassified(): void
	{
		foreach (MailCategory::cases() as $category) {
			// match() without a default throws UnhandledMatchError on an unmapped case, which is
			// exactly the loud failure we want when someone adds a category
			$this->assertIsBool($category->isUnsubscribable());
			$this->assertIsBool($category->isUrgent());
		}
	}

	public static function mailKeyProvider(): array
	{
		return [
			'password reset' => ['password_reset', MailCategory::SECURITY],
			'new login alert' => ['new_login', MailCategory::SECURITY],
			'oauth link' => ['oauth_provider_linked', MailCategory::SECURITY],
			'welcome' => ['welcome', MailCategory::SECURITY],
			'renewal' => ['subscription_renewed', MailCategory::BILLING],
			'price change' => ['price_change_notice', MailCategory::BILLING],
			'moderation notice' => ['content_moderation', MailCategory::MODERATION],
			'staged approval' => ['activity_staged_approved', MailCategory::MODERATION],
			'admin digest' => ['moderation_digest', MailCategory::OPS],
			'api key' => ['api_key_expiring', MailCategory::ACCOUNT],
			'challenge' => ['quest_challenge', MailCategory::SOCIAL],
			'unread digest' => ['unread_notifications_reminder', MailCategory::DIGEST],
			'broadcast' => ['trial_broadcast', MailCategory::ANNOUNCEMENTS],
		];
	}

	#[Test]
	#[TestDox('Mail key $key classifies as the expected stream')]
	#[Group('mantle2/util')]
	#[DataProvider('mailKeyProvider')]
	public function testMailKeyClassification(string $key, MailCategory $expected): void
	{
		$this->assertSame($expected, MailCategory::forMailKey($key));
	}

	#[Test]
	#[TestDox('The recurring digest is unsubscribable and security alerts are not')]
	#[Group('mantle2/util')]
	public function testTheInversionThatShipped(): void
	{
		// the previous hand-maintained list had this exactly backwards: it gave unsubscribe
		// headers to four security notifications and none to the recurring digest
		$this->assertTrue(
			MailCategory::forMailKey('unread_notifications_reminder')->isUnsubscribable(),
		);

		foreach (
			['new_login', 'new_password', 'oauth_provider_linked', 'oauth_provider_unlinked']
			as $securityKey
		) {
			$this->assertFalse(
				MailCategory::forMailKey($securityKey)->isUnsubscribable(),
				"$securityKey is a security alert and must not be opt-out-able.",
			);
		}
	}

	#[Test]
	#[TestDox('An unknown mail key falls back to the stream that cannot be opted out of')]
	#[Group('mantle2/util')]
	public function testUnknownKeyFallsBackSafely(): void
	{
		// failing closed means a new key never silently gains an unsubscribe affordance, and never
		// gets suppressed by a preference nobody set
		$category = MailCategory::forMailKey('some_key_that_does_not_exist');
		$this->assertSame(MailCategory::SECURITY, $category);
		$this->assertFalse($category->isUnsubscribable());
	}

	public static function notificationSourceProvider(): array
	{
		return [
			'actor handle' => ['@gmitch215', MailCategory::SOCIAL],
			'another handle' => ['@someone.else', MailCategory::SOCIAL],
			'billing' => ['billing', MailCategory::BILLING],
			'moderation' => ['moderation', MailCategory::MODERATION],
			'staging' => ['staging', MailCategory::MODERATION],
			'verified publisher' => ['verified_publisher', MailCategory::MODERATION],
			'quest' => ['quest', MailCategory::QUEST],
			'badge' => ['badge', MailCategory::QUEST],
			'trailmark' => ['trailmark', MailCategory::SOCIAL],
			'system' => ['system', MailCategory::ACCOUNT],
			'unknown' => ['something_new', MailCategory::ACCOUNT],
		];
	}

	#[Test]
	#[TestDox('Notification source $source classifies as the expected stream')]
	#[Group('mantle2/util')]
	#[DataProvider('notificationSourceProvider')]
	public function testNotificationSourceClassification(
		string $source,
		MailCategory $expected,
	): void {
		$this->assertSame($expected, MailCategory::forNotificationSource($source));
	}
}
