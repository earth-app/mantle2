<?php

namespace Drupal\mantle2\Service;

use Drupal;
use Drupal\Core\Serialization\Yaml;
use Drupal\mantle2\Custom\Activity;
use Drupal\mantle2\Custom\Article;
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

	// Placeholders

	public static function runPlaceholders(string $text, UserInterface $user): string
	{
		// lazy loading placeholders for improved performance
		$placeholders = [
			// User
			'{user.id}' => fn() => $user->id(),
			'{user.identifier' => fn() => UsersHelper::getName($user, UsersHelper::cloud()) ??
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
			'{activity.random}' => function () {
				$activity = ActivityHelper::getRandomActivity();
				return $activity ? self::formatActivity($activity) : 'No random activity found';
			},
			// Prompts
			'{prompt.random}' => function () {
				$prompt = PromptsHelper::getRandomPrompt();
				return $prompt ? self::formatPrompt($prompt) : 'No random prompt found';
			},
			// Articles
			'{article.random}' => function () {
				$article = ArticlesHelper::getRandomArticle();
				return $article ? self::formatArticle($article) : 'No random article found';
			},
		];

		foreach ($placeholders as $placeholder => $callback) {
			if (str_contains($text, $placeholder)) {
				$text = str_replace($placeholder, $callback(), $text);
			}
		}

		return $text;
	}

	private static function getRecommendedActivity(UserInterface $user): ?Activity
	{
		$activities = UsersHelper::recommendActivities($user, 100);
		return array_filter(
			$activities,
			fn($activity) => $activity != null && $activity instanceof Activity,
		)[0] ?? null;
	}

	private static function formatActivity(Activity $activity): string
	{
		$name = $activity->getName();
		$id = $activity->getId();
		$desc = trim($activity->getDescription());
		$desc = strlen($desc) > 150 ? substr($desc, 0, 147) . '...' : $desc;

		return "[**$name**](https://app.earth-app.com/activities/$id)\n*$desc*\n";
	}

	private static function formatPrompt(Prompt $prompt): string
	{
		$promptText = $prompt->getPrompt();
		$id = $prompt->getId();
		$ownerUsername = $prompt->getOwner()->getAccountName();

		return "[**$promptText**](https://app.earth-app.com/prompts/$id)\nby $ownerUsername\n";
	}

	private static function formatArticle(Article $article): string
	{
		$title = $article->getTitle();
		$author = $article->getAuthor()->getAccountName();
		$date = date('F j, Y', $article->getCreatedAt());
		$id = $article->getId();
		$summary = trim($article->getContent());
		$summary = strlen($summary) > 250 ? substr($summary, 0, 247) . '...' : $summary;

		return "[**$title** by @$author](https://app.earth-app.com/articles/$id)\n$date\n\n$summary\n";
	}

	// Cron Job

	public static $variation = 14400; // 4 hour variation

	// cron runs every hour according to drupal configuration
	public static function runEmailCampaigns(): void
	{
		$campaigns = self::getCampaigns();
		$time = Drupal::time()->getCurrentTime();

		foreach ($campaigns as $campaign) {
			if (!isset($campaign['id']) || !isset($campaign['interval'])) {
				continue;
			}

			$campaignId = $campaign['id'];
			$interval = (int) $campaign['interval'];
			$filterName = $campaign['filter'] ?? null;

			// Get all users
			$userStorage = Drupal::entityTypeManager()->getStorage('user');
			$query = $userStorage->getQuery()->condition('uid', 0, '>')->accessCheck(false);
			$uids = $query->execute();

			if (empty($uids)) {
				continue;
			}

			$users = $userStorage->loadMultiple($uids);

			foreach ($users as $user) {
				// Check if user is subscribed
				if (!UsersHelper::isSubscribed($user)) {
					continue;
				}

				// Apply filter if specified
				if ($filterName && method_exists(self::class, $filterName)) {
					if (!self::$filterName($user)) {
						continue;
					}
				}

				// Check Redis for last send time
				$redisKey = "campaign:{$campaignId}:user:{$user->id()}";
				$lastSentData = RedisHelper::get($redisKey);

				if ($lastSentData && isset($lastSentData['sent_at'])) {
					$lastSent = (int) $lastSentData['sent_at'];
					$nextSend = $lastSent + $interval;

					// Add random variation (± $variation seconds)
					$variation = rand(-self::$variation, self::$variation);
					$nextSendWithVariation = $nextSend + $variation;

					// Check if it's time to send
					if ($time < $nextSendWithVariation) {
						continue;
					}
				} else {
					// First time sending - add initial random variation to stagger sends
					$initialVariation = rand(0, self::$variation);
					if ($time < $initialVariation) {
						continue;
					}
				}

				// Send the email
				$success = UsersHelper::sendEmailCampaign($campaignId, $user);

				if ($success) {
					// Store send time in Redis with TTL slightly longer than interval
					$ttl = $interval + self::$variation * 2 + 86400; // Add extra day buffer
					RedisHelper::set(
						$redisKey,
						[
							'sent_at' => $time,
							'campaign_id' => $campaignId,
						],
						$ttl,
					);

					Drupal::logger('mantle2')->info('Sent campaign %campaign to user %user', [
						'%campaign' => $campaignId,
						'%user' => $user->id(),
					]);
				}
			}
		}
	}
}
