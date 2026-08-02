<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\Component\Serialization\Yaml;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\Article;
use Drupal\mantle2\Custom\Event;
use Drupal\mantle2\Custom\MailCategory;
use Drupal\mantle2\Custom\Prompt;
use Drupal\mantle2\Service\ActivityHelper;
use Drupal\mantle2\Service\ArticlesHelper;
use Drupal\mantle2\Service\EventsHelper;
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

		// Try to find by ID or by numeric index
		$indexKey = ctype_digit($key) ? (int) $key : null;
		foreach ($campaigns as $i => $campaign) {
			if (isset($campaign['id']) && $campaign['id'] === $key) {
				return $campaign;
			}

			// $key is always a string but $i is an int index, so match numeric keys explicitly
			if ($indexKey !== null && $i === $indexKey) {
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

	/**
	 * Verified accounts created within the onboarding window.
	 *
	 * Without the age bound, a first-send campaign fires immediately for the entire existing user
	 * base: the first-send path is `created + crc32 offset`, which is long past for every account.
	 */
	public static function newUserVerifiedFilter(UserInterface $user): bool
	{
		if (!self::verifiedFilter($user)) {
			return false;
		}

		return (int) $user->getCreatedTime() > strtotime('-14 days');
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
			'{activity.last_added.title}' => self::$noRecentActivitiesFoundText,
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
			'{activity.recommended}' => function () use ($user, $cachedObjects) {
				$activity =
					$cachedObjects['recommendedActivity'] ?? self::getRecommendedActivity($user);
				return $activity ? self::formatActivity($activity) : null;
			},
			'{activity.recommended.title}' => function () use ($user, $cachedObjects) {
				$activity =
					$cachedObjects['recommendedActivity'] ?? self::getRecommendedActivity($user);
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
			'{activity.last_added.title}' => function () {
				$activities = ActivityHelper::getActivitiesCreatedInLastDays(
					self::$newActivityThreshold,
				);
				$first = $activities ? reset($activities) : null;

				return $first instanceof Activity ? $first->getName() : null;
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
			// Assets
			'{asset.motd}' => fn() => self::weeklyMotdBanner(),
			'{asset.garden}' => fn() => self::assetImage(
				self::ASSET_GARDEN_BAND,
				'A quiet garden at dusk, with the moon over low hills and a river',
			),
			'{asset.app_qr}' => fn() => self::assetImage(
				self::ASSET_APP_QR,
				'Scan to download The Earth App on the App Store',
				'https://earth-app.com/ios',
			),
			'{asset.app_launch}' => fn() => self::assetImage(
				self::ASSET_APP_LAUNCH,
				'The Earth App on the App Store',
				'https://earth-app.com/ios',
			),
		];
	}

	// #region Assets

	// every email asset lives under one prefix, built by scripts/build-email-assets.sh
	private const ASSET_BASE = 'https://cdn.earth-app.com/marketing/email/';

	// resized derivatives; the originals are 0.7-1.3MB, far too heavy for an inbox
	private const ASSET_GARDEN_BAND = 'circle-garden_dusk_after.jpg';
	private const ASSET_APP_LAUNCH = 'launch_post_landscape.jpg';

	// white modules on a dark field, which iOS Camera reads and some Android scanners do not;
	// harmless while iOS is the only store listing, and the text link is the primary path anyway
	private const ASSET_APP_QR = 'qr_black.png';

	/**
	 * Slim banner strips, rotated weekly.
	 *
	 * The sentence is baked into the image, so the alt text carries it verbatim - Outlook blocks
	 * images by default and the message has to survive that. Originals are referenced directly
	 * because each is already 6-12KB.
	 *
	 * Two of the fourteen strips in the bucket are deliberately absent: "Sometimes your friends
	 * aren't really your friends." is ominous rather than encouraging, and "Cloud loves you
	 * unconditionally." reads as parasocial toward the @cloud account. Both are fine in-app where
	 * the user chose to look; neither belongs in an unsolicited inbox.
	 *
	 * Sentences are transcribed from the rendered images, so a copy change means re-rendering the
	 * strip and updating the alt text together.
	 */
	public const MOTD_BANNERS = [
		['file' => 'motd_last_outside.png', 'alt' => 'When was the last time you were outside?'],
		['file' => 'motd_air_smell.png', 'alt' => 'What does the air smell like?'],
		['file' => 'motd_deep_breath.png', 'alt' => 'Remember to take a deep breath.'],
		['file' => 'motd_long_week.png', 'alt' => "It's been a long week."],
		['file' => 'motd_doing_great.png', 'alt' => "You're doing great."],
		['file' => 'motd_will_be_fine.png', 'alt' => 'You will be fine.'],
		['file' => 'motd_life_not_bad.png', 'alt' => "Life isn't so bad."],
		[
			'file' => 'motd_ok_eventually.png',
			'alt' => "If you're not ok now, you will be eventually.",
		],
		['file' => 'motd_no_wrong_answer.png', 'alt' => 'Sometimes there is no wrong answer.'],
		['file' => 'motd_move_forward.png', 'alt' => 'Life is about moving forward.'],
		['file' => 'motd_earth_app_has_you.png', 'alt' => 'If nobody has you, The Earth App does.'],
		['file' => 'motd_smiling.png', 'alt' => 'A row of smiling faces'],
	];

	private static function assetImage(string $file, string $alt, ?string $href = null): string
	{
		$url = self::ASSET_BASE . $file;
		$image = '![' . $alt . '](' . $url . ')';

		// Apple requires the App Store badge to link to the store, and a linked image is only
		// recognised on its own line
		return $href === null ? $image : '[' . $image . '](' . $href . ')';
	}

	/**
	 * The banner for the current week, shared by every recipient.
	 *
	 * Rotating on the week rather than per user keeps a single cacheable image per send window and
	 * gives roughly three months before any strip repeats.
	 */
	private static function weeklyMotdBanner(): string
	{
		$week = (int) floor(Drupal::time()->getCurrentTime() / 604800);
		$banner = self::MOTD_BANNERS[$week % count(self::MOTD_BANNERS)];

		return self::assetImage($banner['file'], $banner['alt']);
	}

	// #endregion

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
				// title and body are expanded by separate calls, so without sharing this the
				// subject could name a different activity than the body
				'recommendedActivity' => self::getRecommendedActivity($user),
			];
		}

		$processed = $campaign;

		// a pool of framing templates rotates deterministically, and the chosen index rides along
		// as utm_content so click-through is attributable per variant rather than per campaign
		[$title, $variantId] = self::selectTitle($campaign, $user);
		if ($title !== null) {
			$processed['title'] = $title;
			$processed['variant_id'] = $variantId;
		}

		if (isset($processed['title'])) {
			$processed['title'] = self::replacePlaceholders(
				$processed['title'],
				$user,
				$repeat,
				$cachedObjects,
			);
		}

		foreach (['preheader', 'cta'] as $key) {
			if (!isset($processed[$key])) {
				continue;
			}

			if (is_string($processed[$key])) {
				$processed[$key] = self::replacePlaceholders(
					$processed[$key],
					$user,
					$repeat,
					$cachedObjects,
				);
				continue;
			}

			if (is_array($processed[$key])) {
				foreach ($processed[$key] as $field => $value) {
					if (is_string($value)) {
						$processed[$key][$field] = self::replacePlaceholders(
							$value,
							$user,
							$repeat,
							$cachedObjects,
						);
					}
				}
			}
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

	private static function selectTitle(array $campaign, UserInterface $user): array
	{
		$titles = $campaign['titles'] ?? null;
		if (!is_array($titles)) {
			return [$campaign['title'] ?? null, null];
		}

		$titles = array_values(array_filter($titles, fn($t) => is_string($t) && trim($t) !== ''));
		if ($titles === []) {
			return [$campaign['title'] ?? null, null];
		}

		$week = (int) floor(Drupal::time()->getCurrentTime() / 604800);
		$seed = ($campaign['id'] ?? '') . ':' . $user->id() . ':' . $week;
		$index = crc32($seed) % count($titles);

		return [$titles[$index], $index];
	}

	private static function campaignStrings(array $campaign): array
	{
		$strings = [];

		foreach (['title', 'body', 'preheader'] as $key) {
			if (isset($campaign[$key]) && is_string($campaign[$key])) {
				$strings[] = $campaign[$key];
			}
		}

		foreach (['titles', 'cta'] as $key) {
			if (!isset($campaign[$key]) || !is_array($campaign[$key])) {
				continue;
			}

			foreach ($campaign[$key] as $value) {
				if (is_string($value)) {
					$strings[] = $value;
				}
			}
		}

		return $strings;
	}

	private static function campaignContainsPlaceholder(array $campaign, string $placeholder): bool
	{
		foreach (self::campaignStrings($campaign) as $string) {
			if (str_contains($string, $placeholder)) {
				return true;
			}
		}

		return false;
	}

	private static function campaignContainsText(array $campaign, string $text): bool
	{
		foreach (self::campaignStrings($campaign) as $string) {
			if (str_contains($string, $text)) {
				return true;
			}
		}

		return false;
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
		$filtered = array_filter($activities, fn($activity) => $activity != null);
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

	/**
	 * How much prose a single content block may contribute to an email body.
	 *
	 * Gmail clips at roughly 102KB of raw HTML, mid-tag, which can truncate the unsubscribe
	 * footer and turn a layout problem into a compliance one. An email teases and links; it does
	 * not inline the article.
	 */
	public const ACTIVITY_SUMMARY_LENGTH = 180;
	public const ARTICLE_SUMMARY_LENGTH = 240;
	public const EVENT_SUMMARY_LENGTH = 240;

	private static function truncate(string $text, int $limit): string
	{
		$text = trim($text);
		if (strlen($text) <= $limit) {
			return $text;
		}

		return rtrim(substr($text, 0, $limit - 3)) . '...';
	}

	private static function formatActivity(Activity $activity): string
	{
		$name = $activity->getName();
		$id = $activity->getId();
		$desc = self::truncate($activity->getDescription(), self::ACTIVITY_SUMMARY_LENGTH);

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

		// each emoji already carries a trailing space, so a separator here rendered as
		// "**  Name**" with emojis and "** Name**" with none
		$label = trim($emojis . $name);

		return "[**$label**](https://app.earth-app.com/activities/$id)\n*$desc*\n";
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
		$summary = self::truncate($article->getContent(), self::ARTICLE_SUMMARY_LENGTH);

		return "[**$title** by @$author](https://app.earth-app.com/articles/$id)\n*$date*\n\n$summary\n";
	}

	private static function formatEvent(Event $event): string
	{
		$name = $event->getName();
		$description = self::truncate($event->getDescription(), self::EVENT_SUMMARY_LENGTH);
		$id = $event->getId();
		// Convert milliseconds to seconds for date formatting
		$date = date('F j, Y', $event->getRawDate() / 1000);

		return "[**$name**](https://app.earth-app.com/events/$id)\n*$date*\n$description\n";
	}

	// Cron Job

	public static $variation = 21600; // 6 hour variation

	/**
	 * Minimum gap between two marketing emails to the same person.
	 *
	 * Cron already caps one send per user per run, but nothing capped sends across runs, so a user
	 * eligible for four campaigns received roughly one a day. Fitz et al. 2019 found the benefit of
	 * a digest belongs to wide spacing, not to batching; tightening the cadence bought nothing.
	 */
	public const MARKETING_COOLDOWN = 604800;

	/**
	 * Stop all marketing to an account dormant this long.
	 *
	 * Google ties sending reputation to engaged recipients and Yahoo asks senders to monitor
	 * inactives; suppression is the cheapest lever on complaint rate there is.
	 */
	public const SUPPRESS_AFTER = 15552000;

	/**
	 * One in this many accounts never receives marketing, permanently.
	 *
	 * Without a holdout there is no way to answer whether any of this is incremental, which is the
	 * only question that actually matters about a send.
	 */
	public const HOLDOUT_DIVISOR = 20;

	/**
	 * Ceiling on campaign emails per cron run.
	 *
	 * The provider starts new accounts on a conservative daily quota (1,000/day at the time of
	 * writing) that widens with reputation, and the send marker is written BEFORE delivery, so a
	 * quota rejection makes users silently miss a cycle rather than retry. Capping the run keeps a
	 * single tick from spending the day's allowance; whoever is left is picked up next hour with no
	 * marker written. It also matches Google's own guidance to raise volume gradually.
	 */
	public const MAX_SENDS_PER_RUN = 40;

	public static function isMarketingHoldout(UserInterface $user): bool
	{
		return crc32('holdout:' . $user->id()) % self::HOLDOUT_DIVISOR === 0;
	}

	private static function lastMarketingKey(int|string $userId): string
	{
		return "campaign:last_marketing:user:{$userId}";
	}

	private static function isInMarketingCooldown(int|string $userId, int $time): bool
	{
		$last = RedisHelper::get(self::lastMarketingKey($userId));
		if (!is_array($last) || !isset($last['sent_at'])) {
			return false;
		}

		return $time - (int) $last['sent_at'] < self::MARKETING_COOLDOWN;
	}

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
			try {
				$userId = $user->id();

				if (isset($sentThisRun[$userId])) {
					continue;
				}

				if (count($sentThisRun) >= self::MAX_SENDS_PER_RUN) {
					Drupal::logger('mantle2')->info(
						'Campaign run hit the per-run cap of %cap sends; the rest continue next tick',
						['%cap' => self::MAX_SENDS_PER_RUN],
					);
					break;
				}

				// a dormant account and a holdout account are both permanently marketing-silent;
				// transactional mail is unaffected because it never routes through here
				$lastLogin = (int) $user->getLastLoginTime();
				$dormant = $lastLogin > 0 && $time - $lastLogin > self::SUPPRESS_AFTER;
				$marketingSilent =
					$dormant ||
					self::isMarketingHoldout($user) ||
					self::isInMarketingCooldown($userId, $time);

				// find the most overdue campaign for this user and prioritize that
				$mostOverdueCampaign = null;
				$maxOverdueAmount = -1;

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
					if ($unsubscribable) {
						// the weekly cap, the holdout and dormancy only bind marketing; a
						// transactional campaign like verify_email keeps its own schedule
						if ($marketingSilent) {
							continue;
						}

						$category = MailCategory::tryFrom((string) ($campaign['category'] ?? ''));
						if (!UsersHelper::isSubscribedTo($user, $category)) {
							continue;
						}
					}

					$redisKey = "campaign:{$campaignId}:user:{$userId}";
					$lastSentData = RedisHelper::get($redisKey);

					// a finite series stops nagging; without this verify_email runs forever
					$maxSends = isset($campaign['max_sends']) ? (int) $campaign['max_sends'] : 0;
					if ($maxSends > 0 && (int) ($lastSentData['count'] ?? 0) >= $maxSends) {
						continue;
					}

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
						// first time sending - stagger by a deterministic per-(user, campaign) offset
						// from the user's creation time so new users don't all fire in the same cron tick
						// and old users compete on the same scale as already-overdue campaigns
						$userCreated = (int) $user->getCreatedTime();
						$initialOffset = crc32("{$campaignId}:{$userId}") % self::$variation;
						$firstAvailable = $userCreated + $initialOffset;

						if ($time >= $firstAvailable) {
							$shouldSend = true;
							$overdueAmount = $time - $firstAvailable;
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
							'max_sends' => $maxSends,
							'sent_count' => (int) ($lastSentData['count'] ?? 0),
							'unsubscribable' => $unsubscribable,
						];
					}
				}

				// send the most overdue campaign if found
				if ($mostOverdueCampaign) {
					$campaignId = $mostOverdueCampaign['id'];
					$processedCampaign = $mostOverdueCampaign['processed_campaign'];

					$ttl = $mostOverdueCampaign['interval'] + self::$variation * 2 + 86400;

					// a capped series has to outlive its own interval or the counter resets and
					// max_sends silently becomes a no-op; a cache flush still restarts the series
					if ($mostOverdueCampaign['max_sends'] > 0) {
						$ttl = max($ttl, 31536000);
					}

					RedisHelper::set(
						$mostOverdueCampaign['redis_key'],
						[
							'sent_at' => $time,
							'campaign_id' => $campaignId,
							'count' => $mostOverdueCampaign['sent_count'] + 1,
						],
						$ttl,
					);

					// one shared marker across every marketing campaign is what actually caps the
					// weekly volume; per-campaign intervals alone never could
					if ($mostOverdueCampaign['unsubscribable']) {
						RedisHelper::set(
							self::lastMarketingKey($userId),
							['sent_at' => $time, 'campaign_id' => $campaignId],
							self::MARKETING_COOLDOWN + 86400,
						);
					}

					$sentThisRun[$userId] = true;

					try {
						UsersHelper::sendEmailCampaign($campaignId, $user, [
							'processed_campaign' => $processedCampaign,
						]);

						Drupal::logger('mantle2')->info(
							"Sent campaign '%campaign' to user %user (@%name)",
							[
								'%campaign' => $campaignId,
								'%user' => $userId,
								'%name' => $user->getAccountName(),
							],
						);
					} catch (\Throwable $sendError) {
						Drupal::logger('mantle2')->error(
							"Failed to deliver campaign '%campaign' to user %user (@%name): %message",
							[
								'%campaign' => $campaignId,
								'%user' => $userId,
								'%name' => $user->getAccountName(),
								'%message' => $sendError->getMessage(),
							],
						);
					}
				}
			} catch (\Throwable $userError) {
				Drupal::logger('mantle2')->error(
					'Campaign processing failed for user %uid: %message',
					[
						'%uid' => $user->id(),
						'%message' => $userError->getMessage(),
					],
				);
			}
		}
	}
}
