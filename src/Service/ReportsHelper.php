<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\comment\Entity\Comment;
use Drupal\mantle2\Custom\AccountType;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Exception;

class ReportsHelper
{
	// shared enums (must match the cross-repo report spec)
	public const CONTENT_TYPES = [
		'prompt',
		'prompt_response',
		'article',
		'event',
		'event_image',
		'user',
	];

	public const REASONS = [
		'hate_speech',
		'harassment',
		'sexual',
		'violence',
		'spam',
		'misinformation',
		'self_harm',
		'illegal',
		'other',
	];

	public const STATUSES = ['pending', 'dismissed', 'actioned', 'auto_removed', 'expired'];

	// state key holding the last daily-digest run timestamp (epoch seconds)
	public const DIGEST_STATE_KEY = 'mantle2.reports.last_digest';

	// per-user state key prefix holding the epoch-ms re-enable timestamp for a 1-month disable
	public const REENABLE_STATE_KEY = 'mantle2.reports.reenable';

	/**
	 * Resolve a reportable piece of content. Returns ['owner_id' => int|null, 'preview' => string]
	 * when the content exists, or null when it does not.
	 *
	 * @param string $type one of CONTENT_TYPES
	 * @param string $id content id (int as string, or 32-hex for event_image)
	 * @param string|null $parentId parent prompt id (prompt_response) or event id (event_image)
	 */
	public static function resolveContent(
		string $type,
		string $id,
		?string $parentId = null,
	): ?array {
		switch ($type) {
			case 'prompt':
				$node = Node::load((int) $id);
				if (!$node || $node->getType() !== 'prompt') {
					return null;
				}
				return [
					'owner_id' => (int) ($node->get('field_owner_id')->value ?? 0) ?: null,
					'preview' => self::snippet((string) ($node->get('field_prompt')->value ?? '')),
				];

			case 'prompt_response':
				if ($parentId === null) {
					return null;
				}
				$prompt = Node::load((int) $parentId);
				if (!$prompt || $prompt->getType() !== 'prompt') {
					return null;
				}
				$comment = Comment::load((int) $id);
				if (!$comment || $comment->getCommentedEntityId() != $prompt->id()) {
					return null;
				}
				$body = $comment->hasField('comment_body')
					? (string) ($comment->get('comment_body')->value ?? '')
					: '';
				return [
					'owner_id' => (int) $comment->getOwnerId() ?: null,
					'preview' => self::snippet($body),
				];

			case 'article':
				$node = Node::load((int) $id);
				if (!$node || $node->getType() !== 'article') {
					return null;
				}
				$article = ArticlesHelper::nodeToArticle($node);

				return [
					'owner_id' => (int) $article->getAuthorId() ?: null,
					'preview' => self::snippet($article->getTitle()),
				];

			case 'event':
				$node = Node::load((int) $id);
				if (!$node || $node->getType() !== 'event') {
					return null;
				}
				$event = EventsHelper::nodeToEvent($node);
				return [
					'owner_id' => (int) $event->getHostId() ?: null,
					'preview' => self::snippet($event->getName()),
				];

			case 'event_image':
				if ($parentId === null) {
					return null;
				}
				try {
					$submission = EventsHelper::retrieveImageSubmission(
						null,
						(int) $parentId,
						$id,
						null,
						null,
						null,
						null,
					);
				} catch (Exception) {
					return null;
				}
				if (!$submission) {
					return null;
				}
				return [
					'owner_id' => (int) $submission->user_id ?: null,
					'preview' => self::snippet($submission->caption ?: 'Event image submission'),
				];

			case 'user':
				$user = User::load((int) $id);
				if (!$user) {
					return null;
				}
				return [
					'owner_id' => (int) $user->id(),
					'preview' => '@' . $user->getAccountName(),
				];

			default:
				return null;
		}
	}

	/**
	 * Delete a piece of content with admin bypass (used by moderation actions).
	 * Returns true on success, false if the content could not be found/removed.
	 */
	public static function deleteContent(string $type, string $id, ?string $parentId = null): bool
	{
		try {
			switch ($type) {
				case 'prompt':
				case 'article':
				case 'event':
					$node = Node::load((int) $id);
					if (!$node) {
						return false;
					}
					$node->delete();
					return true;

				case 'prompt_response':
					$comment = Comment::load((int) $id);
					if (!$comment) {
						return false;
					}
					$comment->delete();
					return true;

				case 'event_image':
					if ($parentId === null) {
						return false;
					}
					return EventsHelper::deleteImageSubmission($id, null, (int) $parentId);

				case 'user':
					// removing a user as moderation = permanent ban, not entity delete
					$user = User::load((int) $id);
					if (!$user) {
						return false;
					}
					self::banUser($user);
					return true;

				default:
					return false;
			}
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to delete reported %type %id: %message', [
				'%type' => $type,
				'%id' => $id,
				'%message' => $e->getMessage(),
			]);
			return false;
		}
	}

	/**
	 * Record a strike with cloud for $ownerId and enforce the returned account action.
	 * Returns the cloud action string ('none'|'disable_1_month'|'permanent_ban').
	 */
	public static function recordStrikeAndEnforce(
		int $ownerId,
		string $contentType,
		string $contentId,
		string $reason,
	): string {
		// system content (uid 1) and administrators never accrue strikes; content may still be removed
		if ($ownerId <= 1) {
			return 'none';
		}
		$owner = User::load($ownerId);
		if ($owner instanceof UserInterface && UsersHelper::isAdmin($owner)) {
			return 'none';
		}

		$action = 'none';
		try {
			$result = CloudHelper::sendRequest('/v1/users/' . $ownerId . '/strikes', 'POST', [
				'content_type' => $contentType,
				'content_id' => $contentId,
				'reason' => $reason,
				'source' => 'user',
			]);
			$action = is_string($result['action'] ?? null) ? $result['action'] : 'none';
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to record strike for user %uid: %message', [
				'%uid' => $ownerId,
				'%message' => $e->getMessage(),
			]);
		}

		$user = User::load($ownerId);
		if ($user instanceof UserInterface) {
			self::enforceAction($user, $action);
		}

		return $action;
	}

	// apply cloud's strike threshold action to the account
	public static function enforceAction(UserInterface $user, string $action): void
	{
		switch ($action) {
			case 'disable_1_month':
				self::disableForOneMonth($user);
				break;
			case 'permanent_ban':
				self::banUser($user);
				break;
			default:
				// 'none' — nothing to enforce
				break;
		}
	}

	// disable a user for 30 days; cron lifts it once reenable_at passes
	public static function disableForOneMonth(UserInterface $user): void
	{
		UsersHelper::setDisabled($user, true);
		try {
			$user->save();
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to disable user %uid: %message', [
				'%uid' => $user->id(),
				'%message' => $e->getMessage(),
			]);
			return;
		}

		$reenableAt = (time() + 30 * 24 * 60 * 60) * 1000; // epoch ms
		Drupal::state()->set(self::REENABLE_STATE_KEY . '.' . $user->id(), $reenableAt);
		self::trackReenable((int) $user->id());

		Drupal::logger('mantle2')->notice(
			'[reports] Disabled user %uid for 30 days (re-enable at %at)',
			['%uid' => $user->id(), '%at' => gmdate('c', (int) ($reenableAt / 1000))],
		);
	}

	// permanently disable a user and blacklist their username + email in cloud
	public static function banUser(UserInterface $user): void
	{
		UsersHelper::setDisabled($user, true);
		try {
			$user->save();
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('Failed to ban user %uid: %message', [
				'%uid' => $user->id(),
				'%message' => $e->getMessage(),
			]);
		}

		// no re-enable for permanent bans
		Drupal::state()->delete(self::REENABLE_STATE_KEY . '.' . $user->id());

		$entries = [['kind' => 'username', 'value' => $user->getAccountName()]];
		if ($user->getEmail()) {
			$entries[] = ['kind' => 'email', 'value' => $user->getEmail()];
		}

		foreach ($entries as $entry) {
			try {
				CloudHelper::sendRequest('/v1/admin/blacklist', 'POST', [
					'kind' => $entry['kind'],
					'value' => $entry['value'],
					'reason' => 'Permanent ban from content moderation',
					'added_by' => 'moderation',
				]);
			} catch (Exception $e) {
				Drupal::logger('mantle2')->error(
					'Failed to blacklist %kind for banned user %uid: %message',
					[
						'%kind' => $entry['kind'],
						'%uid' => $user->id(),
						'%message' => $e->getMessage(),
					],
				);
			}
		}

		Drupal::logger('mantle2')->notice('[reports] Permanently banned user %uid', [
			'%uid' => $user->id(),
		]);
	}

	public static function notifyUser(
		UserInterface $user,
		string $role,
		string $contentType,
		string $action,
		?string $notes = null,
	): void {
		$readableType = ucwords(str_replace('_', ' ', $contentType));

		if ($role === 'reporter') {
			$title = 'Report Reviewed';
			$message = "Thanks for your report. Our team has reviewed the $readableType you reported and taken appropriate action.";
		} else {
			$title = 'Content Moderation Notice';
			if ($action === 'ban_user') {
				$message =
					'Your account has been suspended for violating our community guidelines.';
			} elseif ($action === 'delete_content') {
				$message = "Your $readableType was removed for violating our community guidelines.";
			} else {
				$message = "A moderation decision was made regarding your $readableType.";
			}
		}

		if ($notes) {
			$message .= "\n\nNote from moderators: $notes";
		}

		// in-app notification
		UsersHelper::addNotification(
			$user,
			$title,
			$message,
			'/profile/notifications',
			'warning',
			'moderation',
		);

		// email (non-unsubscribable: account/moderation transactional)
		UsersHelper::sendEmail(
			$user,
			'content_moderation',
			[
				'role' => $role,
				'content_type' => $readableType,
				'action' => $action,
				'notes' => $notes ?? '',
				'time' => date(DATE_ATOM),
			],
			false,
		);
	}

	public static function runDailyDigest(): void
	{
		$now = time();
		$last = (int) Drupal::state()->get(self::DIGEST_STATE_KEY, 0);
		if ($now - $last < 24 * 60 * 60) {
			return;
		}

		// mark first so a slow/failed send doesn't re-fire every cron tick
		Drupal::state()->set(self::DIGEST_STATE_KEY, $now);

		$count = 0;
		try {
			$data = CloudHelper::sendRequest('/v1/reports', 'GET', [
				'status' => 'pending',
				'limit' => 1,
			]);
			if (isset($data['total'])) {
				$count = (int) $data['total'];
			} elseif (isset($data['reports']) && is_array($data['reports'])) {
				$count = count($data['reports']);
			}
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error(
				'[reports] Failed to fetch pending count for digest: %m',
				[
					'%m' => $e->getMessage(),
				],
			);
			return;
		}

		// conditional: skip email entirely when nothing awaits moderation
		if ($count <= 0) {
			return;
		}

		foreach (self::getAdminUsers() as $admin) {
			UsersHelper::sendEmail(
				$admin,
				'moderation_digest',
				['count' => $count, 'time' => date(DATE_ATOM)],
				false,
			);
		}

		Drupal::logger('mantle2')->notice('[reports] Sent moderation digest: %count pending', [
			'%count' => $count,
		]);
	}

	// re-enable users whose 1-month disable window has elapsed
	public static function reenableExpiredDisables(): void
	{
		// state has no prefix scan; track ids via a single index value
		$ids = Drupal::state()->get(self::REENABLE_STATE_KEY . '.index', []);
		if (!is_array($ids) || empty($ids)) {
			return;
		}

		$now = time() * 1000; // epoch ms
		$remaining = [];
		$reenabled = 0;

		foreach ($ids as $uid) {
			$at = (int) Drupal::state()->get(self::REENABLE_STATE_KEY . '.' . $uid, 0);
			if ($at === 0) {
				continue; // cleared (e.g. permanent ban)
			}

			if ($at > $now) {
				$remaining[] = $uid;
				continue;
			}

			$user = User::load($uid);
			if ($user instanceof UserInterface && $user->isBlocked()) {
				UsersHelper::setDisabled($user, false);
				try {
					$user->save();
					$reenabled++;
				} catch (Exception $e) {
					Drupal::logger('mantle2')->error(
						'[reports] Failed to re-enable user %uid: %m',
						[
							'%uid' => $uid,
							'%m' => $e->getMessage(),
						],
					);
					$remaining[] = $uid;
					continue;
				}
			}

			Drupal::state()->delete(self::REENABLE_STATE_KEY . '.' . $uid);
		}

		Drupal::state()->set(self::REENABLE_STATE_KEY . '.index', $remaining);

		if ($reenabled > 0) {
			Drupal::logger('mantle2')->notice(
				'[reports] Re-enabled %count user(s) after 1-month disable',
				[
					'%count' => $reenabled,
				],
			);
		}
	}

	// record a uid in the re-enable index so the cron can find it later
	public static function trackReenable(int $uid): void
	{
		$ids = Drupal::state()->get(self::REENABLE_STATE_KEY . '.index', []);
		if (!is_array($ids)) {
			$ids = [];
		}
		if (!in_array($uid, $ids, true)) {
			$ids[] = $uid;
			Drupal::state()->set(self::REENABLE_STATE_KEY . '.index', $ids);
		}
	}

	// load all administrator-account users
	public static function getAdminUsers(): array
	{
		try {
			$storage = Drupal::entityTypeManager()->getStorage('user');
			$uids = $storage
				->getQuery()
				->accessCheck(false)
				->condition('status', 1)
				->condition('roles', 'administrator')
				->execute();

			$users = $uids ? $storage->loadMultiple($uids) : [];

			// also catch ADMINISTRATOR account-type users without the role
			// field_account_type stores the ordinal index into AccountType::cases()
			$adminOrdinal = GeneralHelper::findOrdinal(
				AccountType::cases(),
				AccountType::ADMINISTRATOR,
			);
			$typeUids = $storage
				->getQuery()
				->accessCheck(false)
				->condition('status', 1)
				->condition('field_account_type', $adminOrdinal)
				->execute();

			foreach ($typeUids ? $storage->loadMultiple($typeUids) : [] as $u) {
				$users[$u->id()] = $u;
			}

			return array_filter($users, fn($u) => $u instanceof UserInterface && $u->id() != 1);
		} catch (Exception $e) {
			Drupal::logger('mantle2')->error('[reports] Failed to load admin users: %m', [
				'%m' => $e->getMessage(),
			]);
			return [];
		}
	}

	// resolve a username for a uid, used to hydrate admin report lists
	public static function usernameFor(?int $uid): ?string
	{
		if (!$uid) {
			return null;
		}
		$user = User::load($uid);
		return $user instanceof UserInterface ? $user->getAccountName() : null;
	}

	// trim long content to a short preview for admin lists
	private static function snippet(string $text, int $max = 140): string
	{
		$text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
		if (mb_strlen($text) <= $max) {
			return $text;
		}
		return mb_substr($text, 0, $max - 1) . '…';
	}
}
