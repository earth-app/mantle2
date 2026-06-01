<?php

namespace Drupal\mantle2\Custom;

class ApiKeyScope
{
	// user (self)
	public const USER_READ = 'user:read';
	public const USER_READ_PROFILE = 'user:read:profile';
	public const USER_READ_EMAIL = 'user:read:email';
	public const USER_READ_PRIVATE = 'user:read:private';
	public const USER_READ_OAUTH = 'user:read:oauth';

	public const USER_EDIT = 'user:edit';
	public const USER_EDIT_BIO = 'user:edit:bio';
	public const USER_EDIT_NAME = 'user:edit:name';
	public const USER_EDIT_EMAIL = 'user:edit:email';
	public const USER_EDIT_PRIVACY = 'user:edit:privacy';
	public const USER_EDIT_VISIBILITY = 'user:edit:visibility';
	public const USER_EDIT_PHOTO = 'user:edit:photo';
	public const USER_EDIT_COSMETIC = 'user:edit:cosmetic';
	public const USER_EDIT_SUBSCRIPTION = 'user:edit:subscription';

	// users (other)
	public const USERS_READ = 'users:read';
	public const USERS_READ_LIST = 'users:read:list';
	public const USERS_READ_PROFILE = 'users:read:profile';
	public const USERS_READ_PHOTO = 'users:read:photo';

	// friends & circle
	public const FRIENDS_READ = 'friends:read';
	public const FRIENDS_WRITE = 'friends:write';
	public const FRIENDS_WRITE_ADD = 'friends:write:add';
	public const FRIENDS_WRITE_REMOVE = 'friends:write:remove';

	public const CIRCLE_READ = 'circle:read';
	public const CIRCLE_WRITE = 'circle:write';
	public const CIRCLE_WRITE_ADD = 'circle:write:add';
	public const CIRCLE_WRITE_REMOVE = 'circle:write:remove';

	// activities
	public const ACTIVITIES_READ = 'activities:read';
	public const ACTIVITIES_WRITE = 'activities:write';
	public const ACTIVITIES_WRITE_SELF = 'activities:write:self';
	public const ACTIVITIES_WRITE_CATALOG = 'activities:write:catalog'; // admin

	// events
	public const EVENTS_READ = 'events:read';
	public const EVENTS_WRITE = 'events:write';
	public const EVENTS_WRITE_CREATE = 'events:write:create';
	public const EVENTS_WRITE_UPDATE = 'events:write:update';
	public const EVENTS_WRITE_DELETE = 'events:write:delete';
	public const EVENTS_WRITE_RSVP = 'events:write:rsvp';
	public const EVENTS_WRITE_IMAGES = 'events:write:images';

	// prompts
	public const PROMPTS_READ = 'prompts:read';
	public const PROMPTS_WRITE = 'prompts:write';
	public const PROMPTS_WRITE_CREATE = 'prompts:write:create';
	public const PROMPTS_WRITE_UPDATE = 'prompts:write:update';
	public const PROMPTS_WRITE_DELETE = 'prompts:write:delete';
	public const PROMPTS_WRITE_RESPOND = 'prompts:write:respond';

	// articles
	public const ARTICLES_READ = 'articles:read';
	public const ARTICLES_WRITE = 'articles:write';
	public const ARTICLES_WRITE_CREATE = 'articles:write:create';
	public const ARTICLES_WRITE_UPDATE = 'articles:write:update';
	public const ARTICLES_WRITE_DELETE = 'articles:write:delete';
	public const ARTICLES_WRITE_QUIZ = 'articles:write:quiz';

	// quests, badges, impact points, cosmetics
	public const QUESTS_READ = 'quests:read';
	public const QUESTS_WRITE = 'quests:write';

	public const BADGES_READ = 'badges:read';
	public const BADGES_WRITE_MASTERY = 'badges:write:mastery';

	public const POINTS_READ = 'points:read';

	public const COSMETICS_READ = 'cosmetics:read';

	// notifications
	public const NOTIFICATIONS_READ = 'notifications:read';
	public const NOTIFICATIONS_WRITE = 'notifications:write';

	public static function hierarchy(): array
	{
		return [
			self::USER_READ => [
				'description' => 'Read details of your own user account.',
				'children' => [
					self::USER_READ_PROFILE => [
						'description' => 'Read your public profile (name, bio, country).',
					],
					self::USER_READ_EMAIL => [
						'description' => 'Read your email address.',
					],
					self::USER_READ_PRIVATE => [
						'description' =>
							'Read fields gated by your own privacy settings (phone, address).',
					],
					self::USER_READ_OAUTH => [
						'description' => 'List OAuth providers linked to your account.',
					],
				],
			],
			self::USER_EDIT => [
				'description' => 'Modify your own user account.',
				'children' => [
					self::USER_EDIT_BIO => [
						'description' => 'Update bio and country.',
					],
					self::USER_EDIT_NAME => [
						'description' => 'Update first name, last name, and username.',
					],
					self::USER_EDIT_EMAIL => [
						'description' =>
							'Initiate an email change (still requires the verification code from the new email).',
					],
					self::USER_EDIT_PRIVACY => [
						'description' => 'Edit field-level privacy preferences.',
					],
					self::USER_EDIT_VISIBILITY => [
						'description' => 'Change account visibility (PUBLIC, UNLISTED, PRIVATE).',
					],
					self::USER_EDIT_PHOTO => [
						'description' => 'Upload or regenerate your profile photo.',
					],
					self::USER_EDIT_COSMETIC => [
						'description' => 'Set or purchase profile cosmetics.',
					],
					self::USER_EDIT_SUBSCRIPTION => [
						'description' => 'Subscribe or unsubscribe from marketing emails.',
					],
				],
			],
			self::USERS_READ => [
				'description' => 'Read other users (visibility-gated).',
				'children' => [
					self::USERS_READ_LIST => [
						'description' => 'List/discover other users.',
					],
					self::USERS_READ_PROFILE => [
						'description' => 'Read other users\' profiles.',
					],
					self::USERS_READ_PHOTO => [
						'description' => 'Read other users\' profile photos.',
					],
				],
			],
			self::FRIENDS_READ => [
				'description' => 'Read your friends list and friend relationships.',
			],
			self::FRIENDS_WRITE => [
				'description' => 'Add or remove friends.',
				'children' => [
					self::FRIENDS_WRITE_ADD => ['description' => 'Add friends.'],
					self::FRIENDS_WRITE_REMOVE => ['description' => 'Remove friends.'],
				],
			],
			self::CIRCLE_READ => [
				'description' => 'Read your private circle membership.',
			],
			self::CIRCLE_WRITE => [
				'description' => 'Modify your private circle.',
				'children' => [
					self::CIRCLE_WRITE_ADD => ['description' => 'Add members to your circle.'],
					self::CIRCLE_WRITE_REMOVE => [
						'description' => 'Remove members from your circle.',
					],
				],
			],
			self::ACTIVITIES_READ => [
				'description' => 'Read the activity catalog and your assigned activities.',
			],
			self::ACTIVITIES_WRITE => [
				'description' => 'Mutate activities.',
				'children' => [
					self::ACTIVITIES_WRITE_SELF => [
						'description' => 'Manage activities attached to your profile.',
					],
					self::ACTIVITIES_WRITE_CATALOG => [
						'description' => 'Create/update/delete catalog activities (admin only).',
					],
				],
			],
			self::EVENTS_READ => [
				'description' => 'Read events.',
			],
			self::EVENTS_WRITE => [
				'description' => 'Create, modify, or RSVP to events.',
				'children' => [
					self::EVENTS_WRITE_CREATE => ['description' => 'Create new events.'],
					self::EVENTS_WRITE_UPDATE => [
						'description' => 'Update existing events you host.',
					],
					self::EVENTS_WRITE_DELETE => ['description' => 'Delete or cancel events.'],
					self::EVENTS_WRITE_RSVP => ['description' => 'RSVP or leave events.'],
					self::EVENTS_WRITE_IMAGES => [
						'description' => 'Submit or remove event images.',
					],
				],
			],
			self::PROMPTS_READ => [
				'description' => 'Read prompts and their responses.',
			],
			self::PROMPTS_WRITE => [
				'description' => 'Mutate prompts and prompt responses.',
				'children' => [
					self::PROMPTS_WRITE_CREATE => [
						'description' => 'Create prompts (requires Pro tier or higher).',
					],
					self::PROMPTS_WRITE_UPDATE => ['description' => 'Update your prompts.'],
					self::PROMPTS_WRITE_DELETE => ['description' => 'Delete your prompts.'],
					self::PROMPTS_WRITE_RESPOND => [
						'description' => 'Create, update, or delete your responses to prompts.',
					],
				],
			],
			self::ARTICLES_READ => [
				'description' => 'Read articles and quizzes.',
			],
			self::ARTICLES_WRITE => [
				'description' => 'Author articles and quizzes.',
				'children' => [
					self::ARTICLES_WRITE_CREATE => [
						'description' => 'Create articles (requires Writer tier or higher).',
					],
					self::ARTICLES_WRITE_UPDATE => ['description' => 'Update your articles.'],
					self::ARTICLES_WRITE_DELETE => ['description' => 'Delete your articles.'],
					self::ARTICLES_WRITE_QUIZ => [
						'description' =>
							'Manage article quizzes (requires Organizer tier or higher).',
					],
				],
			],
			self::QUESTS_READ => ['description' => 'Read quests and your progress.'],
			self::QUESTS_WRITE => [
				'description' => 'Start, cancel, or advance quests.',
			],
			self::BADGES_READ => ['description' => 'Read badge catalog and earned badges.'],
			self::BADGES_WRITE_MASTERY => [
				'description' => 'Generate badge masteries (AI-generated micro-quests).',
			],
			self::POINTS_READ => ['description' => 'Read your impact points balance.'],
			self::COSMETICS_READ => ['description' => 'Read the cosmetics catalog.'],
			self::NOTIFICATIONS_READ => ['description' => 'Read your notifications.'],
			self::NOTIFICATIONS_WRITE => [
				'description' => 'Mark notifications read/unread and delete them.',
			],
		];
	}

	public static function all(): array
	{
		$out = [];
		$walk = function (array $tree) use (&$walk, &$out) {
			foreach ($tree as $name => $node) {
				$out[] = $name;
				if (!empty($node['children'])) {
					$walk($node['children']);
				}
			}
		};
		$walk(self::hierarchy());
		return $out;
	}

	/**
	 * All leaf scopes (those with no children)
	 *
	 * @return string[]
	 */
	public static function leaves(): array
	{
		$out = [];
		$walk = function (array $tree) use (&$walk, &$out) {
			foreach ($tree as $name => $node) {
				if (empty($node['children'])) {
					$out[] = $name;
				} else {
					$walk($node['children']);
				}
			}
		};
		$walk(self::hierarchy());
		return $out;
	}

	/**
	 * Expand a list of granted scopes (which may include parents) into the
	 * fully resolved set of leaf scopes that the key can access. A parent
	 * grant implies every leaf beneath it.
	 *
	 * Unknown scopes are dropped silently — callers should validate first.
	 *
	 * @param string[] $granted
	 * @return string[] Sorted, unique leaf scopes.
	 */
	public static function expand(array $granted): array
	{
		$out = [];
		$tree = self::hierarchy();

		$collectLeaves = function (array $node) use (&$collectLeaves): array {
			if (empty($node['children'])) {
				return [true]; // self only
			}
			$leaves = [];
			foreach ($node['children'] as $childName => $childNode) {
				$childLeaves = $collectLeaves($childNode);
				if ($childLeaves === [true]) {
					$leaves[] = $childName;
				} else {
					$leaves = array_merge($leaves, $childLeaves);
				}
			}
			return $leaves;
		};

		$findAndExpand = function (array $tree, string $target) use (
			&$findAndExpand,
			$collectLeaves,
		): ?array {
			foreach ($tree as $name => $node) {
				if ($name === $target) {
					$leaves = $collectLeaves($node);
					return $leaves === [true] ? [$target] : $leaves;
				}
				if (!empty($node['children'])) {
					$nested = $findAndExpand($node['children'], $target);
					if ($nested !== null) {
						return $nested;
					}
				}
			}
			return null;
		};

		foreach ($granted as $scope) {
			$expanded = $findAndExpand($tree, $scope);
			if ($expanded !== null) {
				$out = array_merge($out, $expanded);
			}
		}

		$out = array_values(array_unique($out));
		sort($out);
		return $out;
	}

	/**
	 * True when `$granted` covers `$required` via implicit-parent semantics.
	 * `$granted` may be the raw list or the expanded leaf list — the check
	 * walks up the colon chain.
	 */
	public static function satisfies(array $granted, string $required): bool
	{
		$grantedSet = array_flip($granted);
		if (isset($grantedSet[$required])) {
			return true;
		}

		// Walk up: user:edit:email -> user:edit -> user
		$parts = explode(':', $required);
		while (count($parts) > 1) {
			array_pop($parts);
			$parent = implode(':', $parts);
			if (isset($grantedSet[$parent])) {
				return true;
			}
		}

		return false;
	}

	public static function isValid(string $scope): bool
	{
		return in_array($scope, self::all(), true);
	}
}
