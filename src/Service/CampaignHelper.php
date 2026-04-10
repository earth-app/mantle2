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
		return $lastLogin < $inactiveThreshold;
	}

	public static function activeFilter(UserInterface $user): bool
	{
		return !self::inactiveFilter($user);
	}

	public static function activeVerifiedFilter(UserInterface $user): bool
	{
		return self::activeFilter($user) && self::verifiedFilter($user);
	}

	/// Global Filters

	private static int $newActivityThreshold = 5;
	private static string $noRecentActivitiesFoundText = 'No recently added activities found';
	private static string $activityLastAddedPlaceholder = '{activity.last_added}';

	private static function getMissingContentPlaceholders(): array
	{
		$noRecommendedActivityFoundText = 'No recommended activity found';
		$noRandomActivityFoundText = 'No random activity found';
		$noWeeklyActivitiesFoundText = 'No weekly activities found';
		$noRandomPromptFoundText = 'No random prompt found';
		$noWeeklyPromptsFoundText = 'No weekly prompts found';
		$noRandomArticleFoundText = 'No random article found';
		$noArticleFoundText = 'No article found';
		$noWeeklyArticlesFoundText = 'No weekly articles found';
		$noUpcomingEventFoundText = 'No upcoming event found';

		return [
			'{activity.recommended}' => $noRecommendedActivityFoundText,
			'{activity.recommended.title}' => $noRecommendedActivityFoundText,
			'{activity.random}' => $noRandomActivityFoundText,
			'{activity.random.title}' => $noRandomActivityFoundText,
			'{activity.weekly}' => $noWeeklyActivitiesFoundText,
			self::$activityLastAddedPlaceholder => self::$noRecentActivitiesFoundText,
			'{prompt.random}' => $noRandomPromptFoundText,
			'{prompt.random.title}' => $noRandomPromptFoundText,
			'{prompt.weekly}' => $noWeeklyPromptsFoundText,
			'{article.random}' => $noRandomArticleFoundText,
			'{article.random.title}' => $noArticleFoundText,
			'{article.weekly}' => $noWeeklyArticlesFoundText,
			'{event.upcoming}' => $noUpcomingEventFoundText,
			'{event.upcoming.title}' => $noUpcomingEventFoundText,
		];
	}

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
				$activity = self::getRecommendedActivity($user);
				return $activity ? self::formatActivity($activity) : null;
			},
			'{activity.recommended.title}' => function () use ($user) {
				$activity = self::getRecommendedActivity($user);
				return $activity ? $activity->getName() : null;
			},
			'{activity.random}' => function () use ($cachedObjects) {
				$activity = $cachedObjects['randomActivity'] ?? ActivityHelper::getRandomActivity();
				return $activity ? self::formatActivity($activity) : null;
			},
			'{activity.random.title}' => function () use ($cachedObjects) {
				$activity = $cachedObjects['randomActivity'] ?? ActivityHelper::getRandomActivity();
				return $activity ? $activity->getName() : null;
			},
			'{activity.weekly}' => function () {
				$activities = ActivityHelper::getRandomActivities(6);
				if (empty($activities)) {
					return null;
				}

				return implode("\n", array_map([self::class, 'formatActivity'], $activities));
			},
			'{activity.last_added}' => function () {
				$activities = ActivityHelper::getActivitiesCreatedInLastDays(
					self::$newActivityThreshold,
				);
				if (empty($activities)) {
					return null;
				}

				// limit to 10 activities
				$activities = array_slice($activities, 0, 10);

				return implode("\n", array_map([self::class, 'formatActivity'], $activities));
			},
			// Prompts
			'{prompt.random}' => function () use ($cachedObjects) {
				$prompt = $cachedObjects['randomPrompt'] ?? PromptsHelper::getRandomPrompt();
				return $prompt ? self::formatPrompt($prompt) : null;
			},
			'{prompt.random.title}' => function () use ($cachedObjects) {
				$prompt = $cachedObjects['randomPrompt'] ?? PromptsHelper::getRandomPrompt();
				return $prompt ? $prompt->getPrompt() : null;
			},
			'{prompt.weekly}' => function () {
				$prompts = PromptsHelper::getRandomPrompts();
				if (empty($prompts)) {
					return null;
				}

				return implode("\n", array_map([self::class, 'formatPrompt'], $prompts));
			},
			// Articles
			'{article.random}' => function () use ($cachedObjects) {
				$article = $cachedObjects['randomArticle'] ?? ArticlesHelper::getRandomArticle();
				return $article ? self::formatArticle($article) : null;
			},
			'{article.random.title}' => function () use ($cachedObjects) {
				$article = $cachedObjects['randomArticle'] ?? ArticlesHelper::getRandomArticle();
				return $article ? $article->getTitle() : null;
			},
			'{article.weekly}' => function () {
				$articles = ArticlesHelper::getRandomArticles(3);
				if (empty($articles)) {
					return null;
				}

				return implode("\n", array_map([self::class, 'formatArticle'], $articles));
			},
			// Events
			'{event.upcoming}' => function () use ($cachedObjects) {
				$event = $cachedObjects['randomEvent'] ?? EventsHelper::getRandomEvent(true);
				return $event ? self::formatEvent($event) : null;
			},
			'{event.upcoming.title}' => function () use ($cachedObjects) {
				$event = $cachedObjects['randomEvent'] ?? EventsHelper::getRandomEvent(true);
				return $event ? $event->getName() : null;
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

	private static function campaignContainsPlaceholder(array $campaign, string $placeholder): bool
	{
		$titleHasPlaceholder =
			isset($campaign['title']) &&
			is_string($campaign['title']) &&
			str_contains($campaign['title'], $placeholder);
		$bodyHasPlaceholder =
			isset($campaign['body']) &&
			is_string($campaign['body']) &&
			str_contains($campaign['body'], $placeholder);

		return $titleHasPlaceholder || $bodyHasPlaceholder;
	}

	private static function campaignContainsText(array $campaign, string $text): bool
	{
		$titleHasText =
			isset($campaign['title']) &&
			is_string($campaign['title']) &&
			str_contains($campaign['title'], $text);
		$bodyHasText =
			isset($campaign['body']) &&
			is_string($campaign['body']) &&
			str_contains($campaign['body'], $text);

		return $titleHasText || $bodyHasText;
	}

	private static function shouldSkipCampaign(array $campaign, array $processedCampaign): bool
	{
		foreach (self::getMissingContentPlaceholders() as $placeholder => $missingContentText) {
			if (!self::campaignContainsPlaceholder($campaign, $placeholder)) {
				continue;
			}

			if (self::campaignContainsText($processedCampaign, $missingContentText)) {
				return true;
			}
		}

		return false;
	}

	private static function resolvePlaceholderValue(
		string $placeholder,
		callable $callback,
		array $missingContentPlaceholders,
	): string {
		$callbackValue = $callback();

		if ($callbackValue === null) {
			return $missingContentPlaceholders[$placeholder] ?? '';
		}

		return (string) $callbackValue;
	}

	private static function replacePlaceholders(
		string $text,
		UserInterface $user,
		bool $repeat = true,
		array $cachedObjects = [],
	): string {
		$placeholders = self::getPlaceholderCallbacks($user, $cachedObjects);
		$missingContentPlaceholders = self::getMissingContentPlaceholders();

		foreach ($placeholders as $placeholder => $callback) {
			if (!str_contains($text, $placeholder)) {
				continue;
			}

			if (!$repeat) {
				// Use cached values for all occurrences
				$replacement = self::resolvePlaceholderValue(
					$placeholder,
					$callback,
					$missingContentPlaceholders,
				);
				$text = str_replace($placeholder, $replacement, $text);
			} else {
				// For random placeholders, recompute each time
				if (in_array($placeholder, self::$randomPlaceholders)) {
					while (str_contains($text, $placeholder)) {
						$pos = strpos($text, $placeholder);
						if ($pos === false) {
							break;
						}

						$replacement = self::resolvePlaceholderValue(
							$placeholder,
							$callback,
							$missingContentPlaceholders,
						);
						$text = substr_replace($text, $replacement, $pos, strlen($placeholder));
					}
				} else {
					$replacement = self::resolvePlaceholderValue(
						$placeholder,
						$callback,
						$missingContentPlaceholders,
					);
					$text = str_replace($placeholder, $replacement, $text);
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
		$summary = strlen($summary) > 1500 ? substr($summary, 0, 1497) . '...' : $summary;

		return "[**$title** by @$author](https://app.earth-app.com/articles/$id)\n*$date*\n\n$summary\n";
	}

	private static function formatEvent(Event $event): string
	{
		$name = $event->getName();
		$description = trim($event->getDescription());
		$description =
			strlen($description) > 800 ? substr($description, 0, 797) . '...' : $description;
		$id = $event->getId();
		// Convert milliseconds to seconds for date formatting
		$date = date('F j, Y', $event->getRawDate() / 1000);

		return "[**$name**](https://app.earth-app.com/events/$id)\n*$date*\n$description\n";
	}

	// Cron Job

	public static $variation = 21600; // 6 hour variation

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

				// skip if this campaign respects subscription and user unsubscribed
				$unsubscribable = $campaign['unsubscribable'] ?? true;
				if ($unsubscribable && !UsersHelper::isSubscribed($user)) {
					continue;
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

				if (!$shouldSend) {
					continue;
				}

				$processedCampaign = self::processCampaign($campaign, $user);
				if (self::shouldSkipCampaign($campaign, $processedCampaign)) {
					Drupal::logger('mantle2')->info(
						"Skipped campaign '%campaign' for user %user (@%name): missing placeholder content",
						[
							'%campaign' => $campaignId,
							'%user' => $userId,
							'%name' => $user->getAccountName(),
						],
					);
					continue;
				}

				// track the most overdue campaign
				if ($overdueAmount > $maxOverdueAmount) {
					$maxOverdueAmount = $overdueAmount;
					$mostOverdueCampaign = [
						'campaign' => $campaign,
						'processed_campaign' => $processedCampaign,
						'id' => $campaignId,
						'interval' => $interval,
						'redis_key' => $redisKey,
					];
				}
			}

			// send the most overdue campaign if found
			if ($mostOverdueCampaign) {
				$processedCampaign = $mostOverdueCampaign['processed_campaign'];
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
