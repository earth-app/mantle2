<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\Core\Serialization\Yaml;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\Article;
use Drupal\mantle2\Custom\Event;
use Drupal\mantle2\Custom\Prompt;
use Drupal\mantle2\Service\ActivityHelper;
use Drupal\mantle2\Service\ArticlesHelper;
use Drupal\mantle2\Service\PromptsHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\user\UserInterface;

class CampaignHelper
{
	public static function getCampaigns()
	{
		$path =
			Drupal::service('extension.list.module')->getPath('mantle2') .
			'/data/email_campaigns.yml';

		if (!file_exists($path)) {
			Drupal::logger('mantle2')->error('Email campaigns file not found at %path', [
				'%path' => $path,
			]);
			return [];
		}

		return Yaml::decode(file_get_contents($path));
	}

	public static function getCampaign(string $key): ?array
	{
		$campaigns = self::getCampaigns();

		// Try to find by ID or by index
		foreach ($campaigns as $i => $campaign) {
			if (isset($campaign['id']) && $campaign['id'] === $key) {
				return $campaign;
			}

			if ($i === $key) {
				return $campaign;
			}
		}

		return null;
	}

	// Filters

	public static function unverifiedFilter(UserInterface $user): bool
	{
		// filter out inactive users
		if (self::inactiveFilter($user)) {
			return false;
		}

		return !UsersHelper::isEmailVerified($user);
	}

	public static function verifiedFilter(UserInterface $user): bool
	{
		return UsersHelper::isEmailVerified($user);
	}

	public static function inactiveFilter(UserInterface $user): bool
	{
		$lastLogin = $user->getLastLoginTime();
		if ($lastLogin === 0) {
			return true;
		}
		$inactiveThreshold = strtotime('-2 weeks');
		$ignoreThreshold = strtotime('-1 month'); // ignore dead users (no login for over a month)
		if ($lastLogin < $ignoreThreshold) {
			return false;
		}

		return $lastLogin < $inactiveThreshold;
	}

	/// Global Filters

	private static int $newActivityThreshold = 7;

	public static function newActivitiesFilter(): bool
	{
		$activities = ActivityHelper::getActivitiesCreatedInLastDays(self::$newActivityThreshold);
		if (empty($activities)) {
			return false;
		}

		return true;
	}

	// Placeholders

	private static function getPlaceholderCallbacks(
		UserInterface $user,
		array $cachedObjects = [],
	): array {
		return [
			// User
			'{user.id}' => fn() => $user->id(),
			'{user.identifier}' => fn() => UsersHelper::getName($user, UsersHelper::cloud()) ??
				"@{$user->getAccountName()}",
			'{user.first_name}' => fn() => UsersHelper::getFirstName($user, UsersHelper::cloud()) ??
				$user->getAccountName(),
			'{user.last_name}' => fn() => UsersHelper::getLastName($user, UsersHelper::cloud()) ??
				'',
			'{user.username}' => fn() => $user->getAccountName(),
			'{user.email}' => fn() => $user->getEmail(),
			// Activity
			'{activity.recommended}' => function () use ($user) {
				/** @var Activity|null $activity */
				$activity = self::getRecommendedActivity($user) ?? null;
				return $activity
					? self::formatActivity($activity)
					: 'No recommended activity found';
			},
			'{activity.recommended.title}' => function () use ($user) {
				$activity = self::getRecommendedActivity($user);
				return $activity ? $activity->getName() : 'No recommended activity found';
			},
			'{activity.random}' => function () use ($cachedObjects) {
				$activity = $cachedObjects['randomActivity'] ?? ActivityHelper::getRandomActivity();
				return $activity ? self::formatActivity($activity) : 'No random activity found';
			},
			'{activity.random.title}' => function () use ($cachedObjects) {
				$activity = $cachedObjects['randomActivity'] ?? ActivityHelper::getRandomActivity();
				return $activity ? $activity->getName() : 'No random activity found';
			},
			'{activity.weekly}' => function () {
				$activities = ActivityHelper::getRandomActivities(6);
				if (empty($activities)) {
					return 'No weekly activities found';
				}

				return implode("\n", array_map([self::class, 'formatActivity'], $activities));
			},
			'{activity.last_added}' => function () {
				$acitvities = ActivityHelper::getActivitiesCreatedInLastDays(5);
				if (empty($acitvities)) {
					return 'No recently added activities found';
				}

				// limit to 10 activities
				$acitvities = array_slice($acitvities, 0, 10);

				return implode("\n", array_map([self::class, 'formatActivity'], $acitvities));
			},
			// Prompts
			'{prompt.random}' => function () use ($cachedObjects) {
				$prompt = $cachedObjects['randomPrompt'] ?? PromptsHelper::getRandomPrompt();
				return $prompt ? self::formatPrompt($prompt) : 'No random prompt found';
			},
			'{prompt.random.title}' => function () use ($cachedObjects) {
				$prompt = $cachedObjects['randomPrompt'] ?? PromptsHelper::getRandomPrompt();
				return $prompt ? $prompt->getPrompt() : 'No random prompt found';
			},
			'{prompt.weekly}' => function () {
				$prompts = PromptsHelper::getRandomPrompts();
				if (empty($prompts)) {
					return 'No weekly prompts found';
				}

				return implode("\n", array_map([self::class, 'formatPrompt'], $prompts));
			},
			// Articles
			'{article.random}' => function () use ($cachedObjects) {
				$article = $cachedObjects['randomArticle'] ?? ArticlesHelper::getRandomArticle();
				return $article ? self::formatArticle($article) : 'No random article found';
			},
			'{article.random.title}' => function () use ($cachedObjects) {
				$article = $cachedObjects['randomArticle'] ?? ArticlesHelper::getRandomArticle();
				return $article ? $article->getTitle() : 'No article found';
			},
			'{article.weekly}' => function () {
				$articles = ArticlesHelper::getRandomArticles(3);
				if (empty($articles)) {
					return 'No weekly articles found';
				}

				return implode("\n", array_map([self::class, 'formatArticle'], $articles));
			},
			// Events
			'{event.upcoming}' => function () use ($cachedObjects) {
				$event = $cachedObjects['randomEvent'] ?? EventsHelper::getRandomEvent(true);
				return $event ? self::formatEvent($event) : 'No upcoming event found';
			},
			'{event.upcoming.title}' => function () use ($cachedObjects) {
				$event = $cachedObjects['randomEvent'] ?? EventsHelper::getRandomEvent(true);
				return $event ? $event->getName() : 'No upcoming event found';
			},
		];
	}

	private static array $randomPlaceholders = [
		'{activity.random}',
		'{activity.random.title}',
		'{prompt.random}',
		'{prompt.random.title}',
		'{article.random}',
		'{article.random.title}',
		'{event.upcoming}',
		'{event.upcoming.title}',
	];

	public static function runPlaceholders(
		string $text,
		UserInterface $user,
		bool $repeat = true,
	): string {
		return self::replacePlaceholders($text, $user, $repeat);
	}

	public static function processCampaign(array $campaign, UserInterface $user): array
	{
		$repeat = $campaign['repeat'] ?? true;
		if (is_string($repeat)) {
			$repeat = $repeat !== 'false' && $repeat !== '0' && $repeat !== '';
		} else {
			$repeat = (bool) $repeat;
		}

		// fetch cached objects once if repeat is false
		$cachedObjects = [];
		if (!$repeat) {
			$cachedObjects = [
				'randomPrompt' => PromptsHelper::getRandomPrompt(),
				'randomArticle' => ArticlesHelper::getRandomArticle(),
				'randomActivity' => ActivityHelper::getRandomActivity(),
				'randomEvent' => EventsHelper::getRandomEvent(true),
			];
		}

		$processed = $campaign;
		if (isset($campaign['title'])) {
			$processed['title'] = self::replacePlaceholders(
				$campaign['title'],
				$user,
				$repeat,
				$cachedObjects,
			);
		}
		if (isset($campaign['body'])) {
			$processed['body'] = self::replacePlaceholders(
				$campaign['body'],
				$user,
				$repeat,
				$cachedObjects,
			);
		}

		return $processed;
	}

	private static function replacePlaceholders(
		string $text,
		UserInterface $user,
		bool $repeat = true,
		array $cachedObjects = [],
	): string {
		$placeholders = self::getPlaceholderCallbacks($user, $cachedObjects);

		foreach ($placeholders as $placeholder => $callback) {
			if (!str_contains($text, $placeholder)) {
				continue;
			}

			if (!$repeat) {
				// Use cached values for all occurrences
				$text = str_replace($placeholder, (string) $callback(), $text);
			} else {
				// For random placeholders, recompute each time
				if (in_array($placeholder, self::$randomPlaceholders)) {
					while (str_contains($text, $placeholder)) {
						$pos = strpos($text, $placeholder);
						if ($pos === false) {
							break;
						}
						$text = substr_replace(
							$text,
							(string) $callback(),
							$pos,
							strlen($placeholder),
						);
					}
				} else {
					$text = str_replace($placeholder, (string) $callback(), $text);
				}
			}
		}

		return $text;
	}

	private static function getRecommendedActivity(UserInterface $user): ?Activity
	{
		$activities = UsersHelper::recommendActivities($user, 100);
		$filtered = array_filter(
			$activities,
			fn($activity) => $activity != null && $activity instanceof Activity,
		);
		return $filtered ? reset($filtered) : null;
	}

	private static array $emojiMap = [
		'hobby' => '🎨',
		'sport' => '💪',
		'work' => '💼',
		'study' => '📚',
		'travel' => '✈️',
		'social' => '🤝',
		'relaxation' => '🧘',
		'health' => '🍎',
		'project' => '🛠️',
		'personal_goals' => '🎯',
		'community_service' => '🌍',
		'creative' => '🎭',
		'family' => '👪',
		'holiday' => '🎉',
		'entertainment' => '🎬',
		'learning' => '🧠',
		'nature' => '🌲',
		'technology' => '💻',
		'art' => '🖌️',
		'spirituality' => '🕉️',
		'finance' => '💰',
		'home_improvement' => '🏡',
		'pets' => '🐾',
		'fashion' => '👗',
		'other' => '🔖',
	];

	private static function formatActivity(Activity $activity): string
	{
		$name = $activity->getName();
		$id = $activity->getId();
		$desc = trim($activity->getDescription());
		$desc = strlen($desc) > 250 ? substr($desc, 0, 247) . '...' : $desc;

		// find three emojis for matching types
		$emojis = '';
		$i = 0;
		foreach ($activity->getTypes() as $type) {
			if ($i >= 3) {
				break;
			}

			$typeLower = strtolower($type);
			if (isset(self::$emojiMap[$typeLower])) {
				$emojis .= self::$emojiMap[$typeLower] . ' ';
				$i++;
			}
		}

		return "[**$emojis $name**](https://app.earth-app.com/activities/$id)\n*$desc*\n";
	}

	private static function formatPrompt(Prompt $prompt): string
	{
		$promptText = $prompt->getPrompt();
		$id = $prompt->getId();
		$owner = $prompt->getOwner();
		$ownerUsername = $owner ? $owner->getAccountName() : 'Unknown';

		return "[**$promptText**](https://app.earth-app.com/prompts/$id) by @$ownerUsername\n";
	}

	private static function formatArticle(Article $article): string
	{
		$title = $article->getTitle();
		$authorObj = $article->getAuthor();
		$author = $authorObj ? $authorObj->getAccountName() : 'Unknown';
		$date = date('F j, Y', $article->getCreatedAt());
		$id = $article->getId();
		$summary = trim($article->getContent());
		$summary = strlen($summary) > 700 ? substr($summary, 0, 697) . '...' : $summary;

		return "[**$title** by @$author](https://app.earth-app.com/articles/$id)\n*$date*\n\n$summary\n";
	}

	private static function formatEvent(Event $event): string
	{
		$name = $event->getName();
		$description = trim($event->getDescription());
		$description =
			strlen($description) > 300 ? substr($description, 0, 297) . '...' : $description;
		$id = $event->getId();
		// Convert milliseconds to seconds for date formatting
		$date = date('F j, Y', $event->getRawDate() / 1000);

		return "[**$name**](https://app.earth-app.com/events/$id)\n*$date*\n$description\n";
	}

	// Cron Job

	public static $variation = 14400; // 4 hour variation

	// cron runs every hour according to drupal configuration
	public static function runEmailCampaigns(): void
	{
		$campaigns = self::getCampaigns();
		$time = Drupal::time()->getCurrentTime();

		// Track which users have been sent a campaign this cron run
		$sentThisRun = [];

		// get all users, excluding anonymous and root user
		$userStorage = Drupal::entityTypeManager()->getStorage('user');
		$query = $userStorage->getQuery()->condition('uid', 1, '>')->accessCheck(false);
		$uids = $query->execute();

		if (empty($uids)) {
			return;
		}

		$users = $userStorage->loadMultiple($uids);

		// store global filter results to avoid redundant checks
		$globalFilterResults = [];

		/** @var \Drupal\user\UserInterface $user */
		foreach ($users as $user) {
			$userId = $user->id();

			if (isset($sentThisRun[$userId])) {
				continue;
			}

			if (!UsersHelper::isSubscribed($user)) {
				continue;
			}

			// find the most overdue campaign for this user and prioritize that
			$mostOverdueCampaign = null;
			$maxOverdueAmount = 0;

			foreach ($campaigns as $campaign) {
				if (!isset($campaign['id']) || !isset($campaign['interval'])) {
					continue;
				}

				$campaignId = $campaign['id'];
				$interval = (int) $campaign['interval'];
				$globalFilterName = $campaign['global_filter'] ?? null;

				// check global filter
				if ($globalFilterName && method_exists(self::class, $globalFilterName)) {
					if (!array_key_exists($globalFilterName, $globalFilterResults)) {
						$result = self::$globalFilterName();
						$globalFilterResults[$globalFilterName] = $result;
					}

					if (!$globalFilterResults[$globalFilterName]) {
						continue;
					}
				}

				$filterName = $campaign['filter'] ?? null;

				// check filter
				if ($filterName && method_exists(self::class, $filterName)) {
					if (!self::$filterName($user)) {
						continue;
					}
				}

				$redisKey = "campaign:{$campaignId}:user:{$userId}";
				$lastSentData = RedisHelper::get($redisKey);

				$shouldSend = false;
				$overdueAmount = 0;

				if ($lastSentData && isset($lastSentData['sent_at'])) {
					$lastSent = (int) $lastSentData['sent_at'];
					$nextSend = $lastSent + $interval;

					// add random variation (+ or - $variation seconds)
					$variation = rand(-self::$variation, self::$variation);
					$nextSendWithVariation = $nextSend + $variation;

					if ($time >= $nextSendWithVariation) {
						$shouldSend = true;
						$overdueAmount = $time - $nextSendWithVariation;
					}
				} else {
					// first time sending - add initial random variation to stagger sends
					$initialVariation = rand(0, self::$variation);
					if ($time >= $initialVariation) {
						$shouldSend = true;
						// for new campaigns, prioritize by how long they've been available
						$overdueAmount = $time;
					}
				}

				// track the most overdue campaign
				if ($shouldSend && $overdueAmount > $maxOverdueAmount) {
					$maxOverdueAmount = $overdueAmount;
					$mostOverdueCampaign = [
						'campaign' => $campaign,
						'id' => $campaignId,
						'interval' => $interval,
						'redis_key' => $redisKey,
					];
				}
			}

			// send the most overdue campaign if found
			if ($mostOverdueCampaign) {
				$processedCampaign = self::processCampaign($mostOverdueCampaign['campaign'], $user);
				$success = UsersHelper::sendEmailCampaign($mostOverdueCampaign['id'], $user, [
					'processed_campaign' => $processedCampaign,
				]);
				if ($success) {
					$ttl = $mostOverdueCampaign['interval'] + self::$variation * 2 + 86400; // Add extra day buffer
					RedisHelper::set(
						$mostOverdueCampaign['redis_key'],
						[
							'sent_at' => $time,
							'campaign_id' => $mostOverdueCampaign['id'],
						],
						$ttl,
					);

					// mark that this user has received a campaign this cron run
					$sentThisRun[$userId] = true;

					Drupal::logger('mantle2')->info(
						"Sent campaign '%campaign' to user %user (@%name)",
						[
							'%campaign' => $mostOverdueCampaign['id'],
							'%user' => $userId,
							'%name' => $user->getAccountName(),
						],
					);
				}
			}
		}
	}
}
