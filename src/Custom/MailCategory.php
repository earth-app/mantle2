<?php

namespace Drupal\mantle2\Custom;

enum MailCategory: string
{
	// account safety and identity; a user must never be able to opt out of these
	case SECURITY = 'security';

	// money movement and plan state
	case BILLING = 'billing';

	// decisions taken about the user's own content
	case MODERATION = 'moderation';

	// operational digests sent only to administrators
	case OPS = 'ops';

	// keys and other account service notices
	case ACCOUNT = 'account';

	// another user acted toward this user
	case SOCIAL = 'social';

	// quest and progression activity
	case QUEST = 'quest';

	// recurring roll-up of things already waiting in the app
	case DIGEST = 'digest';

	// product announcements and offers
	case ANNOUNCEMENTS = 'announcements';

	// onboarding nudges in the first weeks
	case LIFECYCLE = 'lifecycle';

	// win-back for users who have drifted away
	case REENGAGEMENT = 'reengagement';

	public function isUnsubscribable(): bool
	{
		return match ($this) {
			self::SOCIAL,
			self::QUEST,
			self::DIGEST,
			self::ANNOUNCEMENTS,
			self::LIFECYCLE,
			self::REENGAGEMENT
				=> true,
			self::SECURITY, self::BILLING, self::MODERATION, self::OPS, self::ACCOUNT => false,
		};
	}

	public function isUrgent(): bool
	{
		return match ($this) {
			self::SECURITY, self::BILLING, self::MODERATION, self::OPS, self::ACCOUNT => true,
			self::SOCIAL,
			self::QUEST,
			self::DIGEST,
			self::ANNOUNCEMENTS,
			self::LIFECYCLE,
			self::REENGAGEMENT
				=> false,
		};
	}

	public static function forMailKey(string $key): self
	{
		return match ($key) {
			'new_login',
			'new_password',
			'password_reset',
			'email_verification',
			'login_verification',
			'email_change_notification',
			'email_change_verification',
			'email_change_confirmed',
			'oauth_provider_linked',
			'oauth_provider_unlinked',
			'oauth_email_auto_set',
			'account_disabled',
			'account_enabled',
			'account_deleted',
			'account_deletion_warning',
			'welcome'
				=> self::SECURITY,
			'plan_trial',
			'plan_trial_warning',
			'plan_trial_expired',
			'subscription_activated',
			'subscription_renewed',
			'subscription_canceled',
			'subscription_refunded',
			'payment_failed_warning',
			'renewal_reminder',
			'price_change_notice',
			'trial_code_redeemed'
				=> self::BILLING,
			'content_moderation',
			'activity_staged_submitted',
			'activity_staged_approved',
			'activity_staged_denied',
			'verified_publisher_submitted',
			'verified_publisher_approved',
			'verified_publisher_denied',
			'verified_publisher_revoked'
				=> self::MODERATION,
			'moderation_digest', 'activity_staged_digest', 'activity_staged_urgent' => self::OPS,
			'api_key_expiring', 'api_key_expired' => self::ACCOUNT,
			'quest_challenge' => self::SOCIAL,
			'unread_notifications_reminder' => self::DIGEST,
			'trial_broadcast' => self::ANNOUNCEMENTS,
			default => self::SECURITY,
		};
	}

	/**
	 * The stream a stored notification belongs to, keyed on Notification::$source.
	 *
	 * `source` is overloaded across the codebase: it is a category at most callsites and an
	 * `@handle` at the kudos, challenge and creation-fanout ones, so a leading `@` is read as a
	 * person acting toward the user.
	 */
	public static function forNotificationSource(string $source): self
	{
		if (str_starts_with($source, '@')) {
			return self::SOCIAL;
		}

		return match ($source) {
			'billing' => self::BILLING,
			'moderation' => self::MODERATION,
			'staging', 'verified_publisher' => self::MODERATION,
			'quest', 'badge' => self::QUEST,
			'trailmark' => self::SOCIAL,
			default => self::ACCOUNT,
		};
	}
}
