<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\ApiKey;
use Drupal\mantle2\Custom\ApiKeyScope;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The token format is:
 *
 *     EA<YY><32-hex random>U<24-hex zero-padded user id>G<16-hex zero-padded ms timestamp>
 *
 * which lets the server cheaply pre-validate a bearer before any DB lookup:
 *   - prefix is "EA"
 *   - the embedded year matches its own embedded timestamp
 *   - the embedded user id matches the row's user_id (catches impersonation)
 */
class ApiKeysHelper
{
	#region scopes

	public static function sessionOnly(): array
	{
		return [
			// auth mutations
			'mantle2.users.login',
			'mantle2.users.login.verify_new_ip',
			'mantle2.users.logout',
			'mantle2.users.create',
			'mantle2.users.reset_password',

			// account destructive
			'mantle2.users.id.delete',
			'mantle2.users.username.delete',
			'mantle2.users.current.delete',
			'mantle2.users.id.change_password',
			'mantle2.users.username.change_password',
			'mantle2.users.current.change_password',

			// oauth
			'mantle2.users.oauth.discord',
			'mantle2.users.oauth.github',
			'mantle2.users.oauth.microsoft',
			'mantle2.users.oauth.facebook',
			'mantle2.users.oauth.google',
			'mantle2.users.oauth.apple',
			'mantle2.users.oauth.discord.unlink',
			'mantle2.users.oauth.github.unlink',
			'mantle2.users.oauth.microsoft.unlink',
			'mantle2.users.oauth.facebook.unlink',
			'mantle2.users.oauth.google.unlink',
			'mantle2.users.oauth.apple.unlink',

			// api key management
			'mantle2.api_keys.list',
			'mantle2.api_keys.create',
			'mantle2.api_keys.get',
			'mantle2.api_keys.patch',
			'mantle2.api_keys.delete',
			'mantle2.api_keys.revoke_all',
			'mantle2.api_keys.list_by_user',

			// admin surface
			'mantle2.admin.blacklist.list',
			'mantle2.admin.blacklist.add',
			'mantle2.admin.blacklist.remove',
			'mantle2.admin.analytics',
			'mantle2.motd.set',
			'mantle2.users.id.set_account_type',
			'mantle2.users.username.set_account_type',
			'mantle2.users.current.set_account_type',
			'mantle2.users.id.create_account_type_trial',
			'mantle2.users.username.create_account_type_trial',
			'mantle2.users.current.create_account_type_trial',
		];
	}

	public static function routeScopes(): array
	{
		return [
			// public routes -> always allowed
			'mantle2.hello' => '',
			'mantle2.info' => '',
			'mantle2.motd.get' => '',
			'mantle2.openapi' => '',
			'mantle2.swaggerui' => '',
			'mantle2.users.unsubscribe' => '',
			'mantle2.users.unsubscribe.post' => '',

			// users (self)
			'mantle2.users.current.get' => ApiKeyScope::USER_READ_PROFILE,
			'mantle2.users.current.patch' => ApiKeyScope::USER_EDIT,
			'mantle2.users.current.patch_field_privacy' => ApiKeyScope::USER_EDIT_PRIVACY,
			'mantle2.users.current.get_profile_photo' => ApiKeyScope::USER_READ_PROFILE,
			'mantle2.users.current.set_profile_photo' => ApiKeyScope::USER_EDIT_PHOTO,
			'mantle2.users.current.profile_photo.cosmetic.get' => ApiKeyScope::USER_READ_PROFILE,
			'mantle2.users.current.profile_photo.cosmetic.set' => ApiKeyScope::USER_EDIT_COSMETIC,
			'mantle2.users.current.profile_photo.purchase_cosmetic' =>
				ApiKeyScope::USER_EDIT_COSMETIC,
			'mantle2.users.current.send_email_verification' => ApiKeyScope::USER_EDIT_EMAIL,
			'mantle2.users.current.verify_email' => ApiKeyScope::USER_EDIT_EMAIL,
			'mantle2.users.current.subscribe' => ApiKeyScope::USER_EDIT_SUBSCRIPTION,
			'mantle2.users.current.unsubscribe' => ApiKeyScope::USER_EDIT_SUBSCRIPTION,
			'mantle2.users.current.regenerate_profile_photo' => ApiKeyScope::USER_EDIT_PHOTO,

			// users (by id/username)
			'mantle2.users' => ApiKeyScope::USERS_READ_LIST,
			'mantle2.users.id.get' => ApiKeyScope::USERS_READ_PROFILE,
			'mantle2.users.username.get' => ApiKeyScope::USERS_READ_PROFILE,
			'mantle2.users.id.patch' => ApiKeyScope::USER_EDIT,
			'mantle2.users.username.patch' => ApiKeyScope::USER_EDIT,
			'mantle2.users.id.patch_field_privacy' => ApiKeyScope::USER_EDIT_PRIVACY,
			'mantle2.users.username.patch_field_privacy' => ApiKeyScope::USER_EDIT_PRIVACY,
			'mantle2.users.id.get_profile_photo' => ApiKeyScope::USERS_READ_PHOTO,
			'mantle2.users.username.get_profile_photo' => ApiKeyScope::USERS_READ_PHOTO,
			'mantle2.users.id.set_profile_photo' => ApiKeyScope::USER_EDIT_PHOTO,
			'mantle2.users.username.set_profile_photo' => ApiKeyScope::USER_EDIT_PHOTO,
			'mantle2.users.id.profile_photo.cosmetic.get' => ApiKeyScope::USER_READ_PROFILE,
			'mantle2.users.username.profile_photo.cosmetic.get' => ApiKeyScope::USER_READ_PROFILE,
			'mantle2.users.id.profile_photo.cosmetic.set' => ApiKeyScope::USER_EDIT_COSMETIC,
			'mantle2.users.username.profile_photo.cosmetic.set' => ApiKeyScope::USER_EDIT_COSMETIC,
			'mantle2.users.id.profile_photo.purchase_cosmetic' => ApiKeyScope::USER_EDIT_COSMETIC,
			'mantle2.users.username.profile_photo.purchase_cosmetic' =>
				ApiKeyScope::USER_EDIT_COSMETIC,
			'mantle2.users.id.send_email_verification' => ApiKeyScope::USER_EDIT_EMAIL,
			'mantle2.users.username.send_email_verification' => ApiKeyScope::USER_EDIT_EMAIL,
			'mantle2.users.id.verify_email' => ApiKeyScope::USER_EDIT_EMAIL,
			'mantle2.users.username.verify_email' => ApiKeyScope::USER_EDIT_EMAIL,
			'mantle2.users.id.subscribe' => ApiKeyScope::USER_EDIT_SUBSCRIPTION,
			'mantle2.users.username.subscribe' => ApiKeyScope::USER_EDIT_SUBSCRIPTION,
			'mantle2.users.id.unsubscribe' => ApiKeyScope::USER_EDIT_SUBSCRIPTION,
			'mantle2.users.username.unsubscribe' => ApiKeyScope::USER_EDIT_SUBSCRIPTION,
			'mantle2.users.id.regenerate_profile_photo' => ApiKeyScope::USER_EDIT_PHOTO,
			'mantle2.users.username.regenerate_profile_photo' => ApiKeyScope::USER_EDIT_PHOTO,

			// friends & circle
			'mantle2.users.id.friends' => ApiKeyScope::FRIENDS_READ,
			'mantle2.users.username.friends' => ApiKeyScope::FRIENDS_READ,
			'mantle2.users.current.friends' => ApiKeyScope::FRIENDS_READ,
			'mantle2.users.id.friends.add' => ApiKeyScope::FRIENDS_WRITE_ADD,
			'mantle2.users.username.friends.add' => ApiKeyScope::FRIENDS_WRITE_ADD,
			'mantle2.users.current.friends.add' => ApiKeyScope::FRIENDS_WRITE_ADD,
			'mantle2.users.id.friends.remove' => ApiKeyScope::FRIENDS_WRITE_REMOVE,
			'mantle2.users.username.friends.remove' => ApiKeyScope::FRIENDS_WRITE_REMOVE,
			'mantle2.users.current.friends.remove' => ApiKeyScope::FRIENDS_WRITE_REMOVE,
			'mantle2.users.id.circle' => ApiKeyScope::CIRCLE_READ,
			'mantle2.users.username.circle' => ApiKeyScope::CIRCLE_READ,
			'mantle2.users.current.circle' => ApiKeyScope::CIRCLE_READ,
			'mantle2.users.id.circle.add' => ApiKeyScope::CIRCLE_WRITE_ADD,
			'mantle2.users.username.circle.add' => ApiKeyScope::CIRCLE_WRITE_ADD,
			'mantle2.users.current.circle.add' => ApiKeyScope::CIRCLE_WRITE_ADD,
			'mantle2.users.id.circle.remove' => ApiKeyScope::CIRCLE_WRITE_REMOVE,
			'mantle2.users.username.circle.remove' => ApiKeyScope::CIRCLE_WRITE_REMOVE,
			'mantle2.users.current.circle.remove' => ApiKeyScope::CIRCLE_WRITE_REMOVE,

			// activities
			'mantle2.activities' => ApiKeyScope::ACTIVITIES_READ,
			'mantle2.activities.random' => ApiKeyScope::ACTIVITIES_READ,
			'mantle2.activities.get' => ApiKeyScope::ACTIVITIES_READ,
			'mantle2.activities.list' => ApiKeyScope::ACTIVITIES_READ,
			'mantle2.activities.create' => ApiKeyScope::ACTIVITIES_WRITE_CATALOG,
			'mantle2.activities.update' => ApiKeyScope::ACTIVITIES_WRITE_CATALOG,
			'mantle2.activities.delete' => ApiKeyScope::ACTIVITIES_WRITE_CATALOG,
			'mantle2.users.id.activities' => ApiKeyScope::ACTIVITIES_READ,
			'mantle2.users.username.activities' => ApiKeyScope::ACTIVITIES_READ,
			'mantle2.users.current.activities' => ApiKeyScope::ACTIVITIES_READ,
			'mantle2.users.id.activities.set' => ApiKeyScope::ACTIVITIES_WRITE_SELF,
			'mantle2.users.username.activities.set' => ApiKeyScope::ACTIVITIES_WRITE_SELF,
			'mantle2.users.current.activities.set' => ApiKeyScope::ACTIVITIES_WRITE_SELF,
			'mantle2.users.id.activities.add' => ApiKeyScope::ACTIVITIES_WRITE_SELF,
			'mantle2.users.username.activities.add' => ApiKeyScope::ACTIVITIES_WRITE_SELF,
			'mantle2.users.current.activities.add' => ApiKeyScope::ACTIVITIES_WRITE_SELF,
			'mantle2.users.id.activities.remove' => ApiKeyScope::ACTIVITIES_WRITE_SELF,
			'mantle2.users.username.activities.remove' => ApiKeyScope::ACTIVITIES_WRITE_SELF,
			'mantle2.users.current.activities.remove' => ApiKeyScope::ACTIVITIES_WRITE_SELF,
			'mantle2.users.id.activities.recommend' => ApiKeyScope::ACTIVITIES_READ,
			'mantle2.users.username.activities.recommend' => ApiKeyScope::ACTIVITIES_READ,
			'mantle2.users.current.activities.recommend' => ApiKeyScope::ACTIVITIES_READ,

			// events
			'mantle2.events' => ApiKeyScope::EVENTS_READ,
			'mantle2.events.random' => ApiKeyScope::EVENTS_READ,
			'mantle2.events.get' => ApiKeyScope::EVENTS_READ,
			'mantle2.events.attendees' => ApiKeyScope::EVENTS_READ,
			'mantle2.events.create' => ApiKeyScope::EVENTS_WRITE_CREATE,
			'mantle2.events.update' => ApiKeyScope::EVENTS_WRITE_UPDATE,
			'mantle2.events.delete' => ApiKeyScope::EVENTS_WRITE_DELETE,
			'mantle2.events.signup' => ApiKeyScope::EVENTS_WRITE_RSVP,
			'mantle2.events.leave' => ApiKeyScope::EVENTS_WRITE_RSVP,
			'mantle2.events.cancel' => ApiKeyScope::EVENTS_WRITE_DELETE,
			'mantle2.events.uncancel' => ApiKeyScope::EVENTS_WRITE_DELETE,
			'mantle2.events.images.list' => ApiKeyScope::EVENTS_READ,
			'mantle2.events.images.create' => ApiKeyScope::EVENTS_WRITE_IMAGES,
			'mantle2.events.images.delete' => ApiKeyScope::EVENTS_WRITE_IMAGES,
			'mantle2.users.id.events' => ApiKeyScope::EVENTS_READ,
			'mantle2.users.username.events' => ApiKeyScope::EVENTS_READ,
			'mantle2.users.current.events' => ApiKeyScope::EVENTS_READ,
			'mantle2.users.id.events.attending' => ApiKeyScope::EVENTS_READ,
			'mantle2.users.username.events.attending' => ApiKeyScope::EVENTS_READ,
			'mantle2.users.current.events.attending' => ApiKeyScope::EVENTS_READ,
			'mantle2.users.id.events.images' => ApiKeyScope::EVENTS_READ,
			'mantle2.users.username.events.images' => ApiKeyScope::EVENTS_READ,
			'mantle2.users.current.events.images' => ApiKeyScope::EVENTS_READ,

			// prompts
			'mantle2.prompts' => ApiKeyScope::PROMPTS_READ,
			'mantle2.prompts.random' => ApiKeyScope::PROMPTS_READ,
			'mantle2.prompts.get' => ApiKeyScope::PROMPTS_READ,
			'mantle2.prompts.create' => ApiKeyScope::PROMPTS_WRITE_CREATE,
			'mantle2.prompts.update' => ApiKeyScope::PROMPTS_WRITE_UPDATE,
			'mantle2.prompts.delete' => ApiKeyScope::PROMPTS_WRITE_DELETE,
			'mantle2.prompts.responses' => ApiKeyScope::PROMPTS_READ,
			'mantle2.prompts.responses.get' => ApiKeyScope::PROMPTS_READ,
			'mantle2.prompts.responses.create' => ApiKeyScope::PROMPTS_WRITE_RESPOND,
			'mantle2.prompts.responses.update' => ApiKeyScope::PROMPTS_WRITE_RESPOND,
			'mantle2.prompts.responses.delete' => ApiKeyScope::PROMPTS_WRITE_RESPOND,
			'mantle2.users.id.prompts' => ApiKeyScope::PROMPTS_READ,
			'mantle2.users.username.prompts' => ApiKeyScope::PROMPTS_READ,
			'mantle2.users.current.prompts' => ApiKeyScope::PROMPTS_READ,

			// articles
			'mantle2.articles' => ApiKeyScope::ARTICLES_READ,
			'mantle2.articles.random' => ApiKeyScope::ARTICLES_READ,
			'mantle2.articles.get' => ApiKeyScope::ARTICLES_READ,
			'mantle2.articles.create' => ApiKeyScope::ARTICLES_WRITE_CREATE,
			'mantle2.articles.update' => ApiKeyScope::ARTICLES_WRITE_UPDATE,
			'mantle2.articles.delete' => ApiKeyScope::ARTICLES_WRITE_DELETE,
			'mantle2.articles.quiz.get' => ApiKeyScope::ARTICLES_READ,
			'mantle2.articles.create.quiz' => ApiKeyScope::ARTICLES_WRITE_QUIZ,
			'mantle2.articles.delete.quiz' => ApiKeyScope::ARTICLES_WRITE_QUIZ,
			'mantle2.users.id.articles' => ApiKeyScope::ARTICLES_READ,
			'mantle2.users.username.articles' => ApiKeyScope::ARTICLES_READ,
			'mantle2.users.current.articles' => ApiKeyScope::ARTICLES_READ,

			// quests, badges, impact points
			'mantle2.users.quests' => ApiKeyScope::QUESTS_READ,
			'mantle2.users.id.quest' => ApiKeyScope::QUESTS_READ,
			'mantle2.users.username.quest' => ApiKeyScope::QUESTS_READ,
			'mantle2.users.current.quest' => ApiKeyScope::QUESTS_READ,
			'mantle2.users.id.quest.step' => ApiKeyScope::QUESTS_READ,
			'mantle2.users.username.quest.step' => ApiKeyScope::QUESTS_READ,
			'mantle2.users.current.quest.step' => ApiKeyScope::QUESTS_READ,
			'mantle2.users.id.quest.history' => ApiKeyScope::QUESTS_READ,
			'mantle2.users.username.quest.history' => ApiKeyScope::QUESTS_READ,
			'mantle2.users.current.quest.history' => ApiKeyScope::QUESTS_READ,
			'mantle2.users.id.quest.history.entry' => ApiKeyScope::QUESTS_READ,
			'mantle2.users.username.quest.history.entry' => ApiKeyScope::QUESTS_READ,
			'mantle2.users.current.quest.history.entry' => ApiKeyScope::QUESTS_READ,
			'mantle2.users.id.quest.start' => ApiKeyScope::QUESTS_WRITE,
			'mantle2.users.username.quest.start' => ApiKeyScope::QUESTS_WRITE,
			'mantle2.users.current.quest.start' => ApiKeyScope::QUESTS_WRITE,
			'mantle2.users.id.quest.cancel' => ApiKeyScope::QUESTS_WRITE,
			'mantle2.users.username.quest.cancel' => ApiKeyScope::QUESTS_WRITE,
			'mantle2.users.current.quest.cancel' => ApiKeyScope::QUESTS_WRITE,

			'mantle2.users.badges' => ApiKeyScope::BADGES_READ,
			'mantle2.users.id.badges' => ApiKeyScope::BADGES_READ,
			'mantle2.users.username.badges' => ApiKeyScope::BADGES_READ,
			'mantle2.users.current.badges' => ApiKeyScope::BADGES_READ,
			'mantle2.users.id.badges.get' => ApiKeyScope::BADGES_READ,
			'mantle2.users.username.badges.get' => ApiKeyScope::BADGES_READ,
			'mantle2.users.current.badges.get' => ApiKeyScope::BADGES_READ,
			'mantle2.users.id.badges.mastery' => ApiKeyScope::BADGES_READ,
			'mantle2.users.username.badges.mastery' => ApiKeyScope::BADGES_READ,
			'mantle2.users.current.badges.mastery' => ApiKeyScope::BADGES_READ,
			'mantle2.users.id.badges.masteries' => ApiKeyScope::BADGES_READ,
			'mantle2.users.username.badges.masteries' => ApiKeyScope::BADGES_READ,
			'mantle2.users.current.badges.masteries' => ApiKeyScope::BADGES_READ,
			'mantle2.users.id.badges.mastery.generate' => ApiKeyScope::BADGES_WRITE_MASTERY,
			'mantle2.users.username.badges.mastery.generate' => ApiKeyScope::BADGES_WRITE_MASTERY,
			'mantle2.users.current.badges.mastery.generate' => ApiKeyScope::BADGES_WRITE_MASTERY,

			'mantle2.users.id.points' => ApiKeyScope::POINTS_READ,
			'mantle2.users.username.points' => ApiKeyScope::POINTS_READ,
			'mantle2.users.current.points' => ApiKeyScope::POINTS_READ,
			'mantle2.users.cosmetics' => ApiKeyScope::COSMETICS_READ,
			'mantle2.users.cosmetics.preview' => ApiKeyScope::COSMETICS_READ,

			// notifications
			'mantle2.users.id.notifications' => ApiKeyScope::NOTIFICATIONS_READ,
			'mantle2.users.username.notifications' => ApiKeyScope::NOTIFICATIONS_READ,
			'mantle2.users.current.notifications' => ApiKeyScope::NOTIFICATIONS_READ,
			'mantle2.users.id.notifications.get' => ApiKeyScope::NOTIFICATIONS_READ,
			'mantle2.users.username.notifications.get' => ApiKeyScope::NOTIFICATIONS_READ,
			'mantle2.users.current.notifications.get' => ApiKeyScope::NOTIFICATIONS_READ,
			'mantle2.users.id.notifications.mark_all_read' => ApiKeyScope::NOTIFICATIONS_WRITE,
			'mantle2.users.username.notifications.mark_all_read' =>
				ApiKeyScope::NOTIFICATIONS_WRITE,
			'mantle2.users.current.notifications.mark_all_read' => ApiKeyScope::NOTIFICATIONS_WRITE,
			'mantle2.users.id.notifications.mark_all_unread' => ApiKeyScope::NOTIFICATIONS_WRITE,
			'mantle2.users.username.notifications.mark_all_unread' =>
				ApiKeyScope::NOTIFICATIONS_WRITE,
			'mantle2.users.current.notifications.mark_all_unread' =>
				ApiKeyScope::NOTIFICATIONS_WRITE,
			'mantle2.users.id.notifications.mark_read' => ApiKeyScope::NOTIFICATIONS_WRITE,
			'mantle2.users.username.notifications.mark_read' => ApiKeyScope::NOTIFICATIONS_WRITE,
			'mantle2.users.current.notifications.mark_read' => ApiKeyScope::NOTIFICATIONS_WRITE,
			'mantle2.users.id.notifications.mark_unread' => ApiKeyScope::NOTIFICATIONS_WRITE,
			'mantle2.users.username.notifications.mark_unread' => ApiKeyScope::NOTIFICATIONS_WRITE,
			'mantle2.users.current.notifications.mark_unread' => ApiKeyScope::NOTIFICATIONS_WRITE,
			'mantle2.users.id.notifications.delete' => ApiKeyScope::NOTIFICATIONS_WRITE,
			'mantle2.users.username.notifications.delete' => ApiKeyScope::NOTIFICATIONS_WRITE,
			'mantle2.users.current.notifications.delete' => ApiKeyScope::NOTIFICATIONS_WRITE,
			'mantle2.users.id.notifications.clear' => ApiKeyScope::NOTIFICATIONS_WRITE,
			'mantle2.users.username.notifications.clear' => ApiKeyScope::NOTIFICATIONS_WRITE,
			'mantle2.users.current.notifications.clear' => ApiKeyScope::NOTIFICATIONS_WRITE,

			// catalog endpoints have no meaningful scope
			'mantle2.api_keys.scopes' => '',
		];
	}

	public static function isSessionOnly(string $routeName): bool
	{
		return in_array($routeName, self::sessionOnly(), true);
	}

	public static function scopeFor(string $routeName): ?string
	{
		$map = self::routeScopes();
		if (!array_key_exists($routeName, $map)) {
			return null; // fail closed (endpoint not accessible by API key)
		}
		return $map[$routeName];
	}

	#endregion

	public const TABLE = 'mantle2_api_keys';

	// preset expiration durations
	public const EXPIRY_PRESETS = [
		'7d' => 7 * 86400,
		'30d' => 30 * 86400,
		'60d' => 60 * 86400,
		'90d' => 90 * 86400,
		'180d' => 180 * 86400,
		'1y' => 365 * 86400,
	];

	// tier -> max active keys. Administrators are unlimited.
	public const TIER_LIMITS = [
		AccountType::FREE->name => 2,
		AccountType::PRO->name => 5,
		AccountType::WRITER->name => 5,
		AccountType::ORGANIZER->name => 25,
	];

	private static function db(): Connection
	{
		return Database::getConnection();
	}

	public static function maxKeysFor(UserInterface $user): int
	{
		if (UsersHelper::isAdmin($user)) {
			return PHP_INT_MAX;
		}
		$type = UsersHelper::getAccountType($user)->name;
		return self::TIER_LIMITS[$type] ?? self::TIER_LIMITS[AccountType::FREE->name];
	}

	public static function countActive(int $userId, int $now = 0): int
	{
		$now = $now > 0 ? $now : time();
		try {
			return (int) self::db()
				->select(self::TABLE, 't')
				->fields('t', ['id'])
				->condition('t.user_id', $userId)
				->isNull('t.revoked_at')
				->condition(
					self::db()
						->condition('OR')
						->isNull('t.expires_at')
						->condition('t.expires_at', $now, '>'),
				)
				->countQuery()
				->execute()
				->fetchField();
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error('Failed to count API keys: %m', [
				'%m' => $e->getMessage(),
			]);
			return 0;
		}
	}

	#region token format

	public static function buildToken(int $userId, string $randomHex, int $timestampMs): string
	{
		$year = (int) gmdate('y', (int) floor($timestampMs / 1000));
		$yy = str_pad((string) ($year % 100), 2, '0', STR_PAD_LEFT);
		$userHex = str_pad(dechex($userId), ApiKey::USER_HEX_LEN, '0', STR_PAD_LEFT);
		$tsHex = str_pad(dechex($timestampMs), ApiKey::TIMESTAMP_HEX_LEN, '0', STR_PAD_LEFT);

		return ApiKey::TOKEN_PREFIX . $yy . $randomHex . 'U' . $userHex . 'G' . $tsHex;
	}

	/**
	 * Pre-validate token shape without hitting the DB. Returns a parsed
	 * payload on success, or null on any structural problem (wrong prefix,
	 * wrong length, year mismatch, etc.).
	 *
	 * @return array{year_two_digit:int, random:string, user_id:int, timestamp_ms:int}|null
	 */
	public static function parseToken(string $token): ?array
	{
		if (strlen($token) !== ApiKey::TOTAL_LENGTH) {
			return null;
		}
		if (!str_starts_with($token, ApiKey::TOKEN_PREFIX)) {
			return null;
		}

		$yy = substr($token, 2, 2);
		if (!ctype_digit($yy)) {
			return null;
		}

		$randomEnd = 4 + ApiKey::RANDOM_HEX_LEN;
		$random = substr($token, 4, ApiKey::RANDOM_HEX_LEN);
		if (!ctype_xdigit($random)) {
			return null;
		}

		// 'U' separator
		if ($token[$randomEnd] !== 'U') {
			return null;
		}

		$userHex = substr($token, $randomEnd + 1, ApiKey::USER_HEX_LEN);
		if (!ctype_xdigit($userHex)) {
			return null;
		}

		$gPos = $randomEnd + 1 + ApiKey::USER_HEX_LEN;
		if ($token[$gPos] !== 'G') {
			return null;
		}

		$tsHex = substr($token, $gPos + 1, ApiKey::TIMESTAMP_HEX_LEN);
		if (!ctype_xdigit($tsHex)) {
			return null;
		}

		$ts = hexdec($tsHex);
		if (!is_int($ts) || $ts <= 0) {
			return null;
		}

		// Year embedded in YY must match year embedded in timestamp.
		$expectedYY = (int) gmdate('y', (int) floor($ts / 1000)) % 100;
		if ((int) $yy !== $expectedYY) {
			return null;
		}

		$uid = hexdec($userHex);
		if (!is_int($uid) || $uid < 0) {
			return null;
		}

		return [
			'year_two_digit' => (int) $yy,
			'random' => $random,
			'user_id' => $uid,
			'timestamp_ms' => (int) $ts,
		];
	}

	public static function looksLikeApiKey(string $token): bool
	{
		return str_starts_with($token, ApiKey::TOKEN_PREFIX) &&
			strlen($token) === ApiKey::TOTAL_LENGTH;
	}

	public static function hashToken(string $token): string
	{
		return hash('sha256', $token);
	}

	#endregion

	#region issuance

	/**
	 * Issue a brand-new API key. Returns ['token' => raw, 'key' => ApiKey] on
	 * success or a string error code on failure: 'limit', 'no_email',
	 * 'invalid_name', 'invalid_description', 'invalid_scope', 'invalid_expiry'.
	 *
	 * The raw token is returned exactly once; storage is hashed.
	 *
	 * @param int[]|null $expiresAtUnix Absolute expiry as a unix timestamp, or null for no expiry.
	 * @return array{token:string,key:ApiKey}|string
	 */
	public static function issue(
		UserInterface $user,
		string $name,
		?string $description,
		array $scopes,
		?int $expiresAtUnix,
	): array|string {
		$user->getEmail() ?? '';
		if (!UsersHelper::hasEmail($user)) {
			return 'no_email';
		}

		$name = trim($name);
		if (mb_strlen($name) < ApiKey::NAME_MIN || mb_strlen($name) > ApiKey::NAME_MAX) {
			return 'invalid_name';
		}

		if ($description !== null) {
			$description = trim($description);
			if ($description === '') {
				$description = null;
			} elseif (mb_strlen($description) > ApiKey::DESCRIPTION_MAX) {
				return 'invalid_description';
			}
		}

		$scopes = array_values(array_unique(array_filter($scopes, 'is_string')));
		if (empty($scopes)) {
			return 'invalid_scope';
		}
		foreach ($scopes as $scope) {
			if (!ApiKeyScope::isValid($scope)) {
				return 'invalid_scope';
			}
		}

		$now = time();
		if ($expiresAtUnix !== null) {
			if ($expiresAtUnix <= $now + 60 || $expiresAtUnix > $now + 10 * 365 * 86400) {
				return 'invalid_expiry';
			}
		}

		if (self::countActive((int) $user->id(), $now) >= self::maxKeysFor($user)) {
			return 'limit';
		}

		$random = bin2hex(random_bytes(ApiKey::RANDOM_HEX_LEN / 2));
		$timestampMs = (int) (microtime(true) * 1000);
		$token = self::buildToken((int) $user->id(), $random, $timestampMs);
		$hash = self::hashToken($token);
		$prefix = substr($token, 0, ApiKey::PUBLIC_PREFIX_LEN);

		try {
			$id = (int) self::db()
				->insert(self::TABLE)
				->fields([
					'key_id' => '', // populated post-insert
					'user_id' => (int) $user->id(),
					'token_hash' => $hash,
					'token_prefix' => $prefix,
					'name' => $name,
					'description' => $description,
					'scopes' => json_encode($scopes),
					'created_at' => $now,
					'expires_at' => $expiresAtUnix,
					'last_used_at' => null,
					'last_used_ip' => null,
					'revoked_at' => null,
					'warned_1w' => 0,
					'warned_1d' => 0,
					'expired_notified' => 0,
				])
				->execute();
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error('Failed to insert API key: %m', [
				'%m' => $e->getMessage(),
			]);
			return 'invalid_scope'; // generic; UI can show "could not create"
		}

		$keyId = GeneralHelper::formatId($id);
		self::db()
			->update(self::TABLE)
			->fields(['key_id' => $keyId])
			->condition('id', $id)
			->execute();

		Drupal::logger('mantle2')->notice('API key %k issued for user %u (%n scopes)', [
			'%k' => $keyId,
			'%u' => $user->id(),
			'%n' => count($scopes),
		]);

		$row = self::db()
			->select(self::TABLE, 't')
			->fields('t')
			->condition('id', $id)
			->execute()
			->fetchAssoc();

		return [
			'token' => $token,
			'key' => ApiKey::fromRow($row ?: []),
		];
	}

	#endregion

	#region lookup / auth

	/**
	 * Resolve a bearer token to (user, key) when it is a valid, unrevoked,
	 * unexpired API key. Returns null on any failure.
	 *
	 * Side effect: bumps `last_used_at` / `last_used_ip` for telemetry. Done
	 * inline (cheap UPDATE on hash) rather than queued because the cost is
	 * dominated by the bearer round trip already.
	 *
	 * @return array{user:UserInterface,key:ApiKey}|null
	 */
	public static function lookupByToken(string $token, ?Request $request = null): ?array
	{
		$parsed = self::parseToken($token);
		if ($parsed === null) {
			return null;
		}

		$hash = self::hashToken($token);

		try {
			$row = self::db()
				->select(self::TABLE, 't')
				->fields('t')
				->condition('t.token_hash', $hash)
				->execute()
				->fetchAssoc();
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error('Failed to load API key by hash: %m', [
				'%m' => $e->getMessage(),
			]);
			return null;
		}

		if (!$row) {
			return null;
		}

		$key = ApiKey::fromRow($row);

		if ((int) $key->getUserId() !== (int) $parsed['user_id']) {
			// Token claims one user id but is stored against another. Treat
			// as forgery and refuse.
			return null;
		}

		if ($key->isRevoked() || $key->isExpired()) {
			return null;
		}

		$user = User::load($key->getUserId());
		if (!$user instanceof UserInterface) {
			return null;
		}

		// Refuse to authenticate disabled users via API key. UsersHelper has
		// the same check on session tokens; mirror it here.
		if (UsersHelper::isDisabled($user)) {
			return null;
		}

		// Telemetry update: keep cheap. Don't fail the auth if it errors.
		try {
			$ip = $request?->getClientIp();
			self::db()
				->update(self::TABLE)
				->fields([
					'last_used_at' => time(),
					'last_used_ip' => $ip ? substr($ip, 0, 45) : null,
				])
				->condition('id', $key->getId())
				->execute();
		} catch (\Throwable) {
			// best-effort
		}

		return ['user' => $user, 'key' => $key];
	}

	#endregion

	#region list / mutate

	/**
	 * List a user's keys (active + revoked + expired). Sorted by created_at
	 * desc.
	 *
	 * @return ApiKey[]
	 */
	public static function listForUser(int $userId): array
	{
		try {
			$rows = self::db()
				->select(self::TABLE, 't')
				->fields('t')
				->condition('t.user_id', $userId)
				->orderBy('t.created_at', 'DESC')
				->execute()
				->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error('Failed to list API keys: %m', [
				'%m' => $e->getMessage(),
			]);
			return [];
		}

		return array_map(fn(array $row) => ApiKey::fromRow($row), $rows);
	}

	public static function getByKeyId(string $keyId, int $userId): ?ApiKey
	{
		try {
			$row = self::db()
				->select(self::TABLE, 't')
				->fields('t')
				->condition('t.key_id', $keyId)
				->condition('t.user_id', $userId)
				->execute()
				->fetchAssoc();
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error('Failed to load API key: %m', [
				'%m' => $e->getMessage(),
			]);
			return null;
		}

		return $row ? ApiKey::fromRow($row) : null;
	}

	/**
	 * Mutate name / description / scopes on an active key. Returns the
	 * refreshed key or a string error code: 'not_found', 'invalid_name',
	 * 'invalid_description', 'invalid_scope', 'revoked'.
	 */
	public static function update(
		string $keyId,
		int $userId,
		?string $name,
		?string $description,
		?array $scopes,
	): ApiKey|string {
		$existing = self::getByKeyId($keyId, $userId);
		if (!$existing) {
			return 'not_found';
		}
		if ($existing->isRevoked()) {
			return 'revoked';
		}

		$fields = [];

		if ($name !== null) {
			$name = trim($name);
			if (mb_strlen($name) < ApiKey::NAME_MIN || mb_strlen($name) > ApiKey::NAME_MAX) {
				return 'invalid_name';
			}
			$fields['name'] = $name;
		}

		if ($description !== null) {
			$description = trim($description);
			if ($description === '') {
				$fields['description'] = null;
			} else {
				if (mb_strlen($description) > ApiKey::DESCRIPTION_MAX) {
					return 'invalid_description';
				}
				$fields['description'] = $description;
			}
		}

		if ($scopes !== null) {
			$scopes = array_values(array_unique(array_filter($scopes, 'is_string')));
			if (empty($scopes)) {
				return 'invalid_scope';
			}
			foreach ($scopes as $scope) {
				if (!ApiKeyScope::isValid($scope)) {
					return 'invalid_scope';
				}
			}
			$fields['scopes'] = json_encode($scopes);
		}

		if (empty($fields)) {
			return $existing;
		}

		try {
			self::db()
				->update(self::TABLE)
				->fields($fields)
				->condition('id', $existing->getId())
				->execute();
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error('Failed to update API key: %m', [
				'%m' => $e->getMessage(),
			]);
			return 'invalid_scope';
		}

		return self::getByKeyId($keyId, $userId) ?? $existing;
	}

	/**
	 * Revoke (soft-delete) a key. Returns true if revocation happened, false
	 * if the key was missing or already revoked.
	 */
	public static function revoke(string $keyId, int $userId): bool
	{
		$key = self::getByKeyId($keyId, $userId);
		if (!$key || $key->isRevoked()) {
			return false;
		}

		try {
			self::db()
				->update(self::TABLE)
				->fields(['revoked_at' => time()])
				->condition('id', $key->getId())
				->execute();
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error('Failed to revoke API key: %m', [
				'%m' => $e->getMessage(),
			]);
			return false;
		}

		Drupal::logger('mantle2')->notice('API key %k revoked for user %u', [
			'%k' => $keyId,
			'%u' => $userId,
		]);

		return true;
	}

	/**
	 * Revoke every active key for a user. Used by account-disable enforcement
	 * paths to ensure issued keys cannot survive a ban.
	 */
	public static function revokeAllForUser(int $userId): int
	{
		try {
			return (int) self::db()
				->update(self::TABLE)
				->fields(['revoked_at' => time()])
				->condition('user_id', $userId)
				->isNull('revoked_at')
				->execute();
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error('Failed to revoke all API keys for %u: %m', [
				'%u' => $userId,
				'%m' => $e->getMessage(),
			]);
			return 0;
		}
	}

	#endregion

	#region cron / notify

	/**
	 * Cron entrypoint: sends 1-week / 1-day warnings, sends expiry confirmation
	 * once an active key's `expires_at` passes, and prunes deeply expired or
	 * revoked rows to keep the table bounded.
	 */
	public static function checkExpirations(int $now = 0): void
	{
		$now = $now > 0 ? $now : time();
		$db = self::db();

		// 1-week warnings.
		try {
			$rows = $db
				->select(self::TABLE, 't')
				->fields('t')
				->isNotNull('t.expires_at')
				->isNull('t.revoked_at')
				->condition('t.warned_1w', 0)
				->condition('t.expires_at', $now, '>')
				->condition('t.expires_at', $now + 7 * 86400, '<=')
				->execute()
				->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable) {
			$rows = [];
		}

		foreach ($rows as $row) {
			$key = ApiKey::fromRow($row);
			$user = User::load($key->getUserId());
			if (!$user instanceof UserInterface) {
				continue;
			}
			self::notifyUpcoming($user, $key, '1 week');
			$db->update(self::TABLE)
				->fields(['warned_1w' => 1])
				->condition('id', $key->getId())
				->execute();
		}

		// 1-day warnings.
		try {
			$rows = $db
				->select(self::TABLE, 't')
				->fields('t')
				->isNotNull('t.expires_at')
				->isNull('t.revoked_at')
				->condition('t.warned_1d', 0)
				->condition('t.expires_at', $now, '>')
				->condition('t.expires_at', $now + 86400, '<=')
				->execute()
				->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable) {
			$rows = [];
		}

		foreach ($rows as $row) {
			$key = ApiKey::fromRow($row);
			$user = User::load($key->getUserId());
			if (!$user instanceof UserInterface) {
				continue;
			}
			self::notifyUpcoming($user, $key, 'tomorrow');
			$db->update(self::TABLE)
				->fields(['warned_1d' => 1])
				->condition('id', $key->getId())
				->execute();
		}

		// Expiration notifications (and mark expired_notified=1 so we don't repeat).
		try {
			$rows = $db
				->select(self::TABLE, 't')
				->fields('t')
				->isNotNull('t.expires_at')
				->isNull('t.revoked_at')
				->condition('t.expired_notified', 0)
				->condition('t.expires_at', $now, '<=')
				->execute()
				->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable) {
			$rows = [];
		}

		foreach ($rows as $row) {
			$key = ApiKey::fromRow($row);
			$user = User::load($key->getUserId());
			if ($user instanceof UserInterface) {
				self::notifyExpired($user, $key);
			}
			$db->update(self::TABLE)
				->fields(['expired_notified' => 1])
				->condition('id', $key->getId())
				->execute();
		}

		// Prune keys revoked or expired more than 90 days ago to bound the
		// table. Recent history is kept so users still see "Expired 3 days
		// ago" in the management UI.
		$cutoff = $now - 90 * 86400;
		try {
			$db->delete(self::TABLE)
				->condition(
					$db
						->condition('OR')
						->condition('revoked_at', $cutoff, '<')
						->condition(
							$db
								->condition('AND')
								->isNotNull('expires_at')
								->condition('expires_at', $cutoff, '<'),
						),
				)
				->execute();
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error('Failed to prune old API keys: %m', [
				'%m' => $e->getMessage(),
			]);
		}
	}

	private static function notifyUpcoming(UserInterface $user, ApiKey $key, string $window): void
	{
		$friendly = $window === 'tomorrow' ? 'tomorrow' : 'in ' . $window;
		UsersHelper::addNotification(
			$user,
			'API key expiring ' . $window,
			"Your API key '{$key->getName()}' will expire $friendly. Rotate it in your account settings to avoid disruption.",
			'/profile/edit#api-keys',
			'warning',
			'system',
		);
		UsersHelper::sendEmail(
			$user,
			'api_key_expiring',
			[
				'key_name' => $key->getName(),
				'key_prefix' => $key->getTokenPrefix(),
				'window' => $window,
				'expires_at' => $key->getExpiresAt(),
			],
			false,
		);
	}

	private static function notifyExpired(UserInterface $user, ApiKey $key): void
	{
		UsersHelper::addNotification(
			$user,
			'API key expired',
			"Your API key '{$key->getName()}' has expired and can no longer authenticate requests.",
			'/profile/edit#api-keys',
			'warning',
			'system',
		);
		UsersHelper::sendEmail(
			$user,
			'api_key_expired',
			[
				'key_name' => $key->getName(),
				'key_prefix' => $key->getTokenPrefix(),
				'expires_at' => $key->getExpiresAt(),
			],
			false,
		);
	}

	#endregion
}
