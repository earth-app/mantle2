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

		return Yaml::decode(file_get_contents($path));
	}

	public static function getCampaign(string $key): ?array
	{
		$campaigns = self::getCampaigns();
		return $campaigns[$key] ?? null;
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

		return "[$name](https://app.earth-app.com/activities/$id)\n$desc\n";
	}

	private static function formatPrompt(Prompt $prompt): string
	{
		$promptText = $prompt->getPrompt();
		$id = $prompt->getId();
		$ownerUsername = $prompt->getOwner()->getAccountName();

		return "[$promptText](https://app.earth-app.com/prompts/$id)\nby $ownerUsername\n";
	}

	private static function formatArticle(Article $article): string
	{
		$title = $article->getTitle();
		$author = $article->getAuthor()->getAccountName();
		$date = date('F j, Y', $article->getCreatedAt());
		$id = $article->getId();
		$summary = trim($article->getContent());
		$summary = strlen($summary) > 250 ? substr($summary, 0, 247) . '...' : $summary;

		return "[$title by @$author](https://app.earth-app.com/articles/$id)\n$date\n\n$summary\n";
	}
}
