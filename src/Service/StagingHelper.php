<?php /** @noinspection PhpUnused */

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\ActivityType;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Review queue for activities proposed by verified publishers and the cloud worker.
 *
 * Submissions resolve automatically when their window closes: admin and cloud rows fail
 * OPEN (auto-publish), organizer rows fail CLOSED (auto-deny).
 */
class StagingHelper
{
	public const TABLE = 'mantle2_staged_activities';

	public const WINDOW_ORGANIZER = 48 * 3600;
	public const WINDOW_PRIVILEGED = 24 * 3600;
	public const URGENT_WINDOW = 12 * 3600;
	public const RETENTION = 90 * 86400;
	public const EXPIRY_BATCH = 200;
	public const MAX_PENDING_PER_ORGANIZER = 10;

	public const KIND_ORGANIZER = 'organizer';
	public const KIND_ADMIN = 'admin';
	public const KIND_CLOUD = 'cloud';
	public const KINDS = [self::KIND_ORGANIZER, self::KIND_ADMIN, self::KIND_CLOUD];

	public const STATE_PENDING = 'pending';
	public const STATE_APPROVED = 'approved';
	public const STATE_DENIED = 'denied';
	public const STATE_EXPIRED_PUBLISHED = 'expired_published';
	public const STATE_EXPIRED_DENIED = 'expired_denied';
	public const STATE_WITHDRAWN = 'withdrawn';
	public const STATES = [
		self::STATE_PENDING,
		self::STATE_APPROVED,
		self::STATE_DENIED,
		self::STATE_EXPIRED_PUBLISHED,
		self::STATE_EXPIRED_DENIED,
		self::STATE_WITHDRAWN,
	];

	public const SOURCES = ['api', 'cloud_discovery', 'admin_panel', 'drush'];

	public const DIGEST_STATE_KEY = 'mantle2.staging.last_digest';

	private static function db(): Connection
	{
		return Database::getConnection();
	}

	// #region Classification

	public static function kindFor(UserInterface $user): string
	{
		if ((int) $user->id() === (int) UsersHelper::cloud()->id()) {
			return self::KIND_CLOUD;
		}

		return UsersHelper::isAdmin($user) ? self::KIND_ADMIN : self::KIND_ORGANIZER;
	}

	public static function windowFor(string $kind): int
	{
		return $kind === self::KIND_ORGANIZER ? self::WINDOW_ORGANIZER : self::WINDOW_PRIVILEGED;
	}

	/**
	 * Written as a deny-list so an unknown or corrupted kind fails CLOSED.
	 */
	public static function failsOpen(string $kind): bool
	{
		return $kind !== self::KIND_ORGANIZER;
	}

	public static function dedupHash(string $activityId): string
	{
		return hash('sha256', strtolower(trim($activityId)));
	}

	// #endregion

	// #region Validation

	/**
	 * Shared activity-body validation for both the direct-create and staging paths.
	 *
	 * Error strings and their ordering are load-bearing; ActivityControllerTest asserts
	 * them verbatim.
	 */
	public static function validateActivityBody(
		mixed $body,
		bool $checkCatalog = true,
	): Activity|JsonResponse {
		if (!is_array($body) || array_keys($body) === range(0, count($body) - 1)) {
			return GeneralHelper::badRequest('Invalid JSON');
		}

		$id = $body['id'] ?? null;
		$name = $body['name'] ?? null;
		$description = $body['description'] ?? null;
		$types = $body['types'] ?? [];

		if (!$id || !$name || !$description || empty($types)) {
			return GeneralHelper::badRequest('Missing required fields');
		}

		if ($checkCatalog && ActivityHelper::getNodeByActivityId($id)) {
			return GeneralHelper::conflict("Activity with ID '$id' already exists");
		}

		if (!is_string($id) || !is_string($name) || !is_string($description) || !is_array($types)) {
			return GeneralHelper::badRequest('Invalid required field types');
		}

		foreach ($types as $type) {
			if (!is_string($type) || !ActivityType::tryFrom($type)) {
				return GeneralHelper::badRequest('Invalid activity type: ' . (string) $type);
			}
		}

		$fields = $body['fields'] ?? ['icon' => ''];
		$aliases = $body['aliases'] ?? [];

		if (!is_array($fields) || !is_array($aliases)) {
			return GeneralHelper::badRequest('Invalid optional field types');
		}

		foreach ($fields as $key => $value) {
			if (!is_string($key) || !is_string($value)) {
				return GeneralHelper::badRequest('Invalid field entry types');
			}
		}

		foreach ($aliases as $alias) {
			if (!is_string($alias)) {
				return GeneralHelper::badRequest('Invalid alias entry type');
			}
		}

		if (count($types) > Activity::MAX_TYPES) {
			return GeneralHelper::badRequest(
				'Too many activity types, max is ' . Activity::MAX_TYPES,
			);
		}

		return new Activity($id, $name, $types, $description, $aliases, $fields);
	}

	// #endregion

	// #region Write

	/**
	 * @return array|false the created row, or false on failure
	 */
	public static function stage(
		Activity $activity,
		UserInterface $submitter,
		?string $note = null,
		string $source = 'api',
		?int $now = null,
	): array|false {
		$now ??= time();
		$kind = self::kindFor($submitter);
		$hash = self::dedupHash($activity->getId());

		try {
			$id = (int) self::db()
				->insert(self::TABLE)
				->fields([
					'activity_id' => $activity->getId(),
					'dedup_hash' => $hash,
					'payload' => json_encode($activity->jsonSerialize()),
					'note' => $note,
					'submitter_id' => (int) $submitter->id(),
					'submitter_kind' => $kind,
					'source' => in_array($source, self::SOURCES, true) ? $source : 'api',
					'state' => self::STATE_PENDING,
					'submitted_at' => $now,
					'expires_at' => $now + self::windowFor($kind),
					'decided_at' => null,
					'reviewer_id' => null,
					'review_notes' => null,
					'published_nid' => null,
					'warned_12h' => 0,
					// organizer rows interrupt admins immediately; cloud/admin rows are
					// batched hourly by notifyNewSubmissions() to avoid evicting the 50-slot
					// notification history
					'notified_pending' => $kind === self::KIND_ORGANIZER ? 1 : 0,
				])
				->execute();
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error('[staging] Failed to stage %a: %m', [
				'%a' => $activity->getId(),
				'%m' => $e->getMessage(),
			]);
			return false;
		}

		$row = self::get($id);
		if (!$row) {
			return false;
		}

		if ($kind === self::KIND_ORGANIZER) {
			self::notifyAdminsOfSubmission($row, $submitter);
		}

		return $row;
	}

	public static function approve(
		int $id,
		UserInterface $reviewer,
		?string $notes = null,
		bool $force = false,
	): array|string|false {
		$row = self::get($id);
		if (!$row) {
			return false;
		}
		if ($row['state'] !== self::STATE_PENDING) {
			return 'already_decided';
		}

		return self::publish($row, $reviewer, self::STATE_APPROVED, $force, $notes);
	}

	public static function deny(
		int $id,
		UserInterface $reviewer,
		?string $notes = null,
	): array|string|false {
		$row = self::get($id);
		if (!$row) {
			return false;
		}
		if ($row['state'] !== self::STATE_PENDING) {
			return 'already_decided';
		}

		$claimed = self::claim($id, [
			'state' => self::STATE_DENIED,
			'decided_at' => time(),
			'reviewer_id' => (int) $reviewer->id(),
			'review_notes' => $notes,
		]);

		if (!$claimed) {
			return 'already_decided';
		}

		$fresh = self::get($id);
		if ($fresh) {
			self::notifySubmitterDecision($fresh, self::STATE_DENIED, $notes);
		}

		return $fresh ?: false;
	}

	public static function withdraw(int $id, UserInterface $actor): bool|string
	{
		$row = self::get($id);
		if (!$row) {
			return false;
		}

		$isOwner = (int) $row['submitter_id'] === (int) $actor->id();
		if (!$isOwner && !UsersHelper::isAdmin($actor)) {
			return 'forbidden';
		}
		if ($row['state'] !== self::STATE_PENDING) {
			return 'already_decided';
		}

		return self::claim($id, [
			'state' => self::STATE_WITHDRAWN,
			'decided_at' => time(),
			'reviewer_id' => (int) $actor->id(),
		]);
	}

	/**
	 * Deny every pending organizer submission from a user whose publisher status ended.
	 *
	 * Admin and cloud rows are deliberately untouched.
	 *
	 * @return int rows affected
	 */
	public static function revokePendingFor(
		int $submitterId,
		string $reason,
		?UserInterface $actor = null,
	): int {
		try {
			return (int) self::db()
				->update(self::TABLE)
				->fields([
					'state' => self::STATE_DENIED,
					'decided_at' => time(),
					'reviewer_id' => $actor ? (int) $actor->id() : null,
					'review_notes' => $reason,
				])
				->condition('submitter_id', $submitterId)
				->condition('state', self::STATE_PENDING)
				->condition('submitter_kind', self::KIND_ORGANIZER)
				->execute();
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error('[staging] Failed to revoke pending for %u: %m', [
				'%u' => $submitterId,
				'%m' => $e->getMessage(),
			]);
			return 0;
		}
	}

	// #endregion

	// #region Read

	public static function get(int $id): ?array
	{
		try {
			$row = self::db()
				->select(self::TABLE, 't')
				->fields('t')
				->condition('t.id', $id)
				->execute()
				?->fetchAssoc();
		} catch (\Throwable) {
			return null;
		}

		return $row ?: null;
	}

	public static function findPending(string $activityId): ?array
	{
		try {
			$row = self::db()
				->select(self::TABLE, 't')
				->fields('t')
				->condition('t.dedup_hash', self::dedupHash($activityId))
				->condition('t.state', self::STATE_PENDING)
				->range(0, 1)
				->execute()
				?->fetchAssoc();
		} catch (\Throwable) {
			return null;
		}

		return $row ?: null;
	}

	public static function listStaged(
		array $filters = [],
		int $page = 1,
		int $limit = 25,
		string $sort = 'asc',
	): array {
		$page = max(1, $page);
		$limit = max(1, min(100, $limit));

		try {
			$query = self::db()->select(self::TABLE, 't')->fields('t');
			$countQuery = self::db()->select(self::TABLE, 't');

			foreach (['state', 'submitter_kind', 'source'] as $column) {
				if (!empty($filters[$column])) {
					$query->condition("t.$column", $filters[$column]);
					$countQuery->condition("t.$column", $filters[$column]);
				}
			}
			if (!empty($filters['submitter_id'])) {
				$query->condition('t.submitter_id', (int) $filters['submitter_id']);
				$countQuery->condition('t.submitter_id', (int) $filters['submitter_id']);
			}
			if (!empty($filters['search'])) {
				$query->condition('t.activity_id', '%' . $filters['search'] . '%', 'LIKE');
				$countQuery->condition('t.activity_id', '%' . $filters['search'] . '%', 'LIKE');
			}

			$total = (int) $countQuery->countQuery()->execute()->fetchField();

			$rows = $query
				->orderBy('t.expires_at', strtolower($sort) === 'desc' ? 'DESC' : 'ASC')
				->range(($page - 1) * $limit, $limit)
				->execute()
				->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error('[staging] Failed to list staged: %m', [
				'%m' => $e->getMessage(),
			]);
			return ['items' => [], 'page' => $page, 'limit' => $limit, 'total' => 0];
		}

		return [
			'items' => array_map(fn(array $row) => self::rowToArray($row), $rows),
			'page' => $page,
			'limit' => $limit,
			'total' => $total,
		];
	}

	public static function listForUser(
		int $userId,
		?string $state = null,
		int $page = 1,
		int $limit = 25,
	): array {
		$filters = ['submitter_id' => $userId];
		if ($state) {
			$filters['state'] = $state;
		}

		return self::listStaged($filters, $page, $limit);
	}

	public static function countPending(?string $kind = null): int
	{
		try {
			$query = self::db()
				->select(self::TABLE, 't')
				->condition('t.state', self::STATE_PENDING);

			if ($kind) {
				$query->condition('t.submitter_kind', $kind);
			}

			return (int) $query->countQuery()->execute()->fetchField();
		} catch (\Throwable) {
			return 0;
		}
	}

	public static function countPendingFor(int $userId): int
	{
		try {
			return (int) self::db()
				->select(self::TABLE, 't')
				->condition('t.state', self::STATE_PENDING)
				->condition('t.submitter_id', $userId)
				->countQuery()
				->execute()
				->fetchField();
		} catch (\Throwable) {
			return 0;
		}
	}

	public static function soonestDeadline(): ?int
	{
		try {
			$value = self::db()
				->select(self::TABLE, 't')
				->fields('t', ['expires_at'])
				->condition('t.state', self::STATE_PENDING)
				->orderBy('t.expires_at', 'ASC')
				->range(0, 1)
				->execute()
				?->fetchField();
		} catch (\Throwable) {
			return null;
		}

		return $value === false || $value === null ? null : (int) $value;
	}

	// #endregion

	// #region Publishing

	/**
	 * Compare-and-swap on state='pending'; makes double-publish impossible without
	 * wrapping the whole flow in a transaction.
	 */
	private static function claim(int $id, array $fields): bool
	{
		try {
			return ((int) self::db()
				->update(self::TABLE)
				->fields($fields)
				->condition('id', $id)
				->condition('state', self::STATE_PENDING)
				->execute()) === 1;
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error('[staging] Failed to claim row %id: %m', [
				'%id' => $id,
				'%m' => $e->getMessage(),
			]);
			return false;
		}
	}

	private static function publish(
		array $row,
		?UserInterface $reviewer,
		string $newState,
		bool $force = false,
		?string $notes = null,
	): array|string|false {
		$id = (int) $row['id'];
		$now = time();

		if (
			!self::claim($id, [
				'state' => $newState,
				'decided_at' => $now,
				'reviewer_id' => $reviewer ? (int) $reviewer->id() : null,
				'review_notes' => $notes,
			])
		) {
			return 'already_decided';
		}

		// an admin may have created the id by hand since staging; correct the row rather
		// than producing a duplicate node
		if (ActivityHelper::getNodeByActivityId($row['activity_id'])) {
			self::finalize($id, [
				'state' => self::STATE_DENIED,
				'review_notes' => 'An activity with this id already exists in the catalog.',
			]);

			$fresh = self::get($id);
			if ($fresh) {
				self::notifySubmitterDecision(
					$fresh,
					self::STATE_DENIED,
					$fresh['review_notes'] ?? null,
				);
			}
			return 'catalog_conflict';
		}

		if ($row['submitter_kind'] === self::KIND_ORGANIZER && !$force) {
			$submitter = User::load((int) $row['submitter_id']);
			if (!$submitter || !UsersHelper::isVerifiedPublisher($submitter)) {
				self::finalize($id, [
					'state' => self::STATE_PENDING,
					'decided_at' => null,
					'reviewer_id' => null,
					'review_notes' => null,
				]);
				return 'not_verified';
			}
		}

		try {
			$activity = Activity::fromArray(json_decode($row['payload'], true) ?: []);
			// credit the proposer, not the reviewer
			$author = User::load((int) $row['submitter_id']) ?: null;
			$node = ActivityHelper::createActivity($activity, $author);

			self::finalize($id, ['published_nid' => (int) $node->id()]);
			RedisHelper::delete('request_cache:activities:list:*');

			$fresh = self::get($id);
			if ($fresh) {
				self::notifySubmitterDecision($fresh, self::STATE_APPROVED, $notes);
			}

			return $fresh ?: false;
		} catch (\Throwable $e) {
			// never leave the row terminal on failure, and never leave it pending with a
			// past deadline or it re-fires every tick
			self::finalize($id, [
				'state' => self::STATE_PENDING,
				'decided_at' => null,
				'reviewer_id' => null,
				'expires_at' => $now + 3600,
			]);

			Drupal::logger('mantle2')->error('[staging] Failed to publish row %id: %m', [
				'%id' => $id,
				'%m' => $e->getMessage(),
			]);
			return false;
		}
	}

	private static function expireDenied(array $row): void
	{
		$id = (int) $row['id'];

		if (
			!self::claim($id, [
				'state' => self::STATE_EXPIRED_DENIED,
				'decided_at' => time(),
				'reviewer_id' => null,
				'review_notes' =>
					'Automatically denied: the 48-hour review window expired without an administrator decision.',
			])
		) {
			return;
		}

		$fresh = self::get($id);
		if ($fresh) {
			self::notifySubmitterDecision(
				$fresh,
				self::STATE_DENIED,
				$fresh['review_notes'] ?? null,
			);
		}
	}

	/**
	 * Unconditional update, used after a row has already been claimed.
	 */
	private static function finalize(int $id, array $fields): void
	{
		try {
			self::db()->update(self::TABLE)->fields($fields)->condition('id', $id)->execute();
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error('[staging] Failed to finalize row %id: %m', [
				'%id' => $id,
				'%m' => $e->getMessage(),
			]);
		}
	}

	// #endregion

	// #region Cron

	/**
	 * Batch-notify administrators about cloud/admin submissions staged since the last run.
	 */
	public static function notifyNewSubmissions(): void
	{
		try {
			$rows = self::db()
				->select(self::TABLE, 't')
				->fields('t')
				->condition('t.state', self::STATE_PENDING)
				->condition('t.notified_pending', 0)
				->condition('t.submitter_kind', [self::KIND_CLOUD, self::KIND_ADMIN], 'IN')
				->execute()
				->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable) {
			return;
		}

		if (!$rows) {
			return;
		}

		$ids = array_map(fn(array $row) => (int) $row['id'], $rows);
		self::markNotified($ids);

		$count = count($rows);
		$soonest = min(array_map(fn(array $row) => (int) $row['expires_at'], $rows));
		$names = array_slice(array_map(fn(array $row) => $row['activity_id'], $rows), 0, 5);

		$message = sprintf(
			'%d new %s staged automatically (%s). Unreviewed submissions publish themselves after %s.',
			$count,
			$count === 1 ? 'activity was' : 'activities were',
			implode(', ', $names),
			GeneralHelper::dateToIso($soonest),
		);

		foreach (ReportsHelper::getAdminUsers() as $admin) {
			UsersHelper::addNotification(
				$admin,
				'Activities Awaiting Review',
				$message,
				'/admin?tab=approvals',
				'info',
				'staging',
			);
		}
	}

	public static function checkExpirations(int $now = 0): void
	{
		$now = $now > 0 ? $now : time();

		self::warnExpiringSoon($now);

		try {
			$rows = self::db()
				->select(self::TABLE, 't')
				->fields('t')
				->condition('t.state', self::STATE_PENDING)
				->condition('t.expires_at', $now, '<=')
				->orderBy('t.expires_at', 'ASC')
				->range(0, self::EXPIRY_BATCH)
				->execute()
				->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error('[staging] Failed to load expirations: %m', [
				'%m' => $e->getMessage(),
			]);
			return;
		}

		foreach ($rows as $row) {
			try {
				// the decision is a pure function of the kind captured at stage time, never
				// recomputed from the submitter's current role
				if (self::failsOpen($row['submitter_kind'])) {
					self::publish($row, null, self::STATE_EXPIRED_PUBLISHED);
				} else {
					self::expireDenied($row);
				}
			} catch (\Throwable $e) {
				Drupal::logger('mantle2')->error('[staging] Row %id failed to resolve: %m', [
					'%id' => $row['id'],
					'%m' => $e->getMessage(),
				]);
			}
		}
	}

	private static function warnExpiringSoon(int $now): void
	{
		try {
			$rows = self::db()
				->select(self::TABLE, 't')
				->fields('t')
				->condition('t.state', self::STATE_PENDING)
				->condition('t.submitter_kind', self::KIND_ORGANIZER)
				->condition('t.warned_12h', 0)
				->condition('t.expires_at', $now, '>')
				->condition('t.expires_at', $now + self::URGENT_WINDOW, '<=')
				->execute()
				->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Throwable) {
			return;
		}

		if (!$rows) {
			return;
		}

		// mark before sending so a mail failure cannot loop
		self::markWarned(array_map(fn(array $row) => (int) $row['id'], $rows));

		$soonest = min(array_map(fn(array $row) => (int) $row['expires_at'], $rows));
		$params = [
			'count' => count($rows),
			'deadline' => GeneralHelper::dateToIso($soonest),
			'activities' => implode(', ', array_map(fn(array $row) => $row['activity_id'], $rows)),
			'time' => date(DATE_ATOM),
		];

		foreach (ReportsHelper::getAdminUsers() as $admin) {
			UsersHelper::sendEmail($admin, 'activity_staged_urgent', $params, false);
		}
	}

	public static function runDailyDigest(): void
	{
		$now = time();
		$last = (int) Drupal::state()->get(self::DIGEST_STATE_KEY, 0);
		if ($now - $last < 86400) {
			return;
		}
		Drupal::state()->set(self::DIGEST_STATE_KEY, $now);

		$pending = self::countPending();
		if ($pending <= 0) {
			return;
		}

		$soonest = self::soonestDeadline();
		$params = [
			'count' => $pending,
			'count_organizer' => self::countPending(self::KIND_ORGANIZER),
			'count_automated' =>
				self::countPending(self::KIND_CLOUD) + self::countPending(self::KIND_ADMIN),
			'soonest_deadline' => $soonest ? GeneralHelper::dateToIso($soonest) : 'unknown',
			'time' => date(DATE_ATOM),
		];

		foreach (ReportsHelper::getAdminUsers() as $admin) {
			UsersHelper::sendEmail($admin, 'activity_staged_digest', $params, false);
		}

		Drupal::logger('mantle2')->notice('[staging] Sent staging digest: %count pending', [
			'%count' => $pending,
		]);
	}

	public static function pruneDecided(int $now = 0): void
	{
		$now = $now > 0 ? $now : time();

		try {
			self::db()
				->delete(self::TABLE)
				->isNotNull('decided_at')
				->condition('decided_at', $now - self::RETENTION, '<')
				->execute();
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error('[staging] Failed to prune decided rows: %m', [
				'%m' => $e->getMessage(),
			]);
		}
	}

	private static function markNotified(array $ids): void
	{
		self::flagRows($ids, 'notified_pending');
	}

	private static function markWarned(array $ids): void
	{
		self::flagRows($ids, 'warned_12h');
	}

	private static function flagRows(array $ids, string $column): void
	{
		if (!$ids) {
			return;
		}

		try {
			self::db()
				->update(self::TABLE)
				->fields([$column => 1])
				->condition('id', $ids, 'IN')
				->execute();
		} catch (\Throwable $e) {
			Drupal::logger('mantle2')->error('[staging] Failed to set %c: %m', [
				'%c' => $column,
				'%m' => $e->getMessage(),
			]);
		}
	}

	// #endregion

	// #region Notifications

	private static function notifyAdminsOfSubmission(array $row, UserInterface $submitter): void
	{
		$username = $submitter->getAccountName();
		$message = sprintf(
			'@%s submitted "%s" for review. It will be automatically denied if not approved by %s.',
			$username,
			$row['activity_id'],
			GeneralHelper::dateToIso((int) $row['expires_at']),
		);

		foreach (ReportsHelper::getAdminUsers() as $admin) {
			UsersHelper::addNotification(
				$admin,
				'Activity Awaiting Review',
				$message,
				'/admin?tab=approvals',
				'warning',
				'staging',
			);
			UsersHelper::sendEmail(
				$admin,
				'activity_staged_submitted',
				[
					'submitter' => $username,
					'activity' => $row['activity_id'],
					'deadline' => GeneralHelper::dateToIso((int) $row['expires_at']),
					'time' => date(DATE_ATOM),
				],
				false,
			);
		}
	}

	private static function notifySubmitterDecision(
		array $row,
		string $outcome,
		?string $notes,
	): void {
		$submitter = User::load((int) $row['submitter_id']);
		if (!$submitter) {
			return;
		}

		$approved = $outcome === self::STATE_APPROVED;
		$title = $approved ? 'Activity Published' : 'Activity Not Approved';
		$message = $approved
			? sprintf(
				'Your submission "%s" is now live in the activity catalog.',
				$row['activity_id'],
			)
			: sprintf('Your submission "%s" was not approved.', $row['activity_id']);

		if (!$approved && $notes) {
			$message .= ' ' . $notes;
		}

		// addNotification and sendEmail both skip uid 1, so cloud auto-publishes are silent
		UsersHelper::addNotification(
			$submitter,
			$title,
			$message,
			$approved ? '/activities/' . $row['activity_id'] : '/profile',
			$approved ? 'success' : 'warning',
			'staging',
		);
		UsersHelper::sendEmail(
			$submitter,
			$approved ? 'activity_staged_approved' : 'activity_staged_denied',
			[
				'activity' => $row['activity_id'],
				'notes' => $notes ?? '',
				'time' => date(DATE_ATOM),
			],
			false,
		);
	}

	// #endregion

	// #region Serialization

	public static function rowToArray(array $row, bool $includeInternal = false): array
	{
		$expiresAt = (int) $row['expires_at'];
		$kind = $row['submitter_kind'];
		$submitter = User::load((int) $row['submitter_id']);

		$data = [
			'id' => (int) $row['id'],
			'activity' => json_decode($row['payload'], true) ?: [],
			'note' => $row['note'],
			'state' => $row['state'],
			'submitter_kind' => $kind,
			'submitter' => $submitter
				? ['id' => (string) $submitter->id(), 'username' => $submitter->getAccountName()]
				: null,
			'source' => $row['source'],
			'submitted_at' => GeneralHelper::dateToIso((int) $row['submitted_at']),
			'expires_at' => GeneralHelper::dateToIso($expiresAt),
			'expires_in_seconds' => max(0, $expiresAt - time()),
			// surfaced so clients never re-derive the rule from submitter_kind
			'fails_open' => self::failsOpen($kind),
			'decided_at' => $row['decided_at']
				? GeneralHelper::dateToIso((int) $row['decided_at'])
				: null,
			'reviewer' => null,
			'review_notes' => $row['review_notes'],
			'published_activity_id' => null,
		];

		if (!empty($row['reviewer_id'])) {
			$reviewer = User::load((int) $row['reviewer_id']);
			if ($reviewer) {
				$data['reviewer'] = [
					'id' => (string) $reviewer->id(),
					'username' => $reviewer->getAccountName(),
				];
			}
		}

		if (!empty($row['published_nid'])) {
			$node = Node::load((int) $row['published_nid']);
			if ($node && $node->getType() === 'activity') {
				$data['published_activity_id'] = $node->get('field_activity_id')->value;
			}
		}

		if ($includeInternal) {
			$data['dedup_hash'] = $row['dedup_hash'];
			$data['published_nid'] = $row['published_nid'] ? (int) $row['published_nid'] : null;
		}

		return $data;
	}

	// #endregion
}
