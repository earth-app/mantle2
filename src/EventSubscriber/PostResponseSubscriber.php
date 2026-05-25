<?php

namespace Drupal\mantle2\EventSubscriber;

use DateTime;
use DateTimeZone;
use Drupal\mantle2\Service\ArticlesHelper;
use Drupal\mantle2\Service\PointsHelper;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

class PostResponseSubscriber implements EventSubscriberInterface
{
	private const NOTIFY_CREATION_RATE_LIMIT = 5;
	private const NOTIFY_CREATION_RATE_WINDOW = 3600;

	/**
	 * Returns true and increments the counter when the adder is still under the
	 * per-hour creation-notification budget. Returns false (no side effects)
	 * once the budget is exhausted, so bulk creates from earth-app/cloud do not
	 * fan out to FCM. Failures in Redis are treated as "allow" so we never
	 * accidentally suppress real-user notifications.
	 */
	private static function tryConsumeCreationNotifyBudget(UserInterface $adder): bool
	{
		$key = 'notify_creation_rate_limit_' . $adder->id();
		$now = time();
		$data = RedisHelper::get($key);

		$windowStart = $data['window_start'] ?? null;
		$count = $data['count'] ?? 0;

		if (!is_int($windowStart) || $now - $windowStart >= self::NOTIFY_CREATION_RATE_WINDOW) {
			$windowStart = $now;
			$count = 0;
		}

		if ($count >= self::NOTIFY_CREATION_RATE_LIMIT) {
			return false;
		}

		$ttl = max(1, self::NOTIFY_CREATION_RATE_WINDOW - ($now - $windowStart));
		RedisHelper::set(
			$key,
			[
				'window_start' => $windowStart,
				'count' => $count + 1,
			],
			$ttl,
		);
		return true;
	}

	private static function notifyAddedByCreation(
		UserInterface $user,
		string $title,
		string $message,
		string $link,
	): void {
		$page = 1;
		$batchSize = 100;
		$maxPages = 1000;

		while ($page <= $maxPages) {
			$addedBy = UsersHelper::getAddedBy($user, $batchSize, $page);
			if (empty($addedBy)) {
				break;
			}

			foreach ($addedBy as $adder) {
				if (!self::tryConsumeCreationNotifyBudget($adder)) {
					continue;
				}

				UsersHelper::addNotification(
					$adder,
					$title,
					$message,
					$link,
					'info',
					'@' . $user->getAccountName(),
				);
			}

			if (count($addedBy) < $batchSize) {
				break;
			}

			$page++;
		}
	}

	/**
	 * Resolves the user's local timezone from their stored ISO-2 country code,
	 * falling back to UTC. Used for time-of-day badges (night_owl / early_bird)
	 * since the user model has no explicit timezone field.
	 */
	private static function userTimezone(UserInterface $user): DateTimeZone
	{
		$country = strtoupper(trim((string) ($user->get('field_country')->value ?? '')));
		if (strlen($country) === 2) {
			try {
				$ids = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $country);
				if (!empty($ids)) {
					return new DateTimeZone($ids[0]);
				}
			} catch (\Throwable $e) {
				// fall through to UTC
			}
		}
		return new DateTimeZone('UTC');
	}

	public static function getSubscribedEvents()
	{
		return [
			KernelEvents::TERMINATE => 'onTerminate',
		];
	}

	private static function callbacks(): array
	{
		return [
			'POST mantle2.articles.create' => function (?UserInterface $user, array $data) {
				if ($user == null) {
					return;
				}
				$id = $data['id'] ?? null;
				if (empty($id)) {
					return;
				}

				$article = ArticlesHelper::loadArticleNode($id);
				$message = 'Click to view what they wrote about';
				if ($article) {
					$title = $article->getTitle();
					if (!empty($title)) {
						$message = $title;
					}
				}

				UsersHelper::trackBadgeProgress($user, 'articles_created', $id);
				self::notifyAddedByCreation(
					$user,
					'New Article from @' . $user->getAccountName(),
					$message,
					'/articles/' . $id,
				);
			},
			'POST mantle2.prompts.create' => function (?UserInterface $user, array $data) {
				if ($user == null) {
					return;
				}
				$id = $data['id'] ?? null;
				if (empty($id)) {
					return;
				}

				$prompt = $data['prompt'] ?? null;
				$message = 'Click to see what they have to say';
				if (is_string($prompt) && $prompt !== '') {
					$message = $prompt;
				}

				UsersHelper::trackBadgeProgress($user, 'prompts_created', $id);
				self::notifyAddedByCreation(
					$user,
					'New Prompt from @' . $user->getAccountName(),
					$message,
					'/prompts/' . $id,
				);
			},
			'POST mantle2.prompts.responses.create' => function (
				?UserInterface $user,
				array $data,
			) {
				if ($user == null) {
					return;
				}
				$id = $data['id'] ?? null;
				if ($id) {
					UsersHelper::trackBadgeProgress($user, 'prompts_responded', $id);
				}

				// check quest progress for responding to prompts
				PointsHelper::checkQuestProgress($user, $data, ['respond_to_prompt']);
			},
			'POST mantle2.events.create' => function (?UserInterface $user, array $data) {
				if ($user == null) {
					return;
				}
				$id = $data['id'] ?? null;
				if (empty($id)) {
					return;
				}

				UsersHelper::trackBadgeProgress($user, 'events_created', $id);
				self::notifyAddedByCreation(
					$user,
					'New Event from @' . $user->getAccountName(),
					'Your friend ' . $user->getDisplayName() . ' has created a new event!',
					'/events/' . $id,
				);
			},
			'PUT mantle2.users.current.friends.add' => function (
				?UserInterface $user,
				array $data,
			) {
				if ($user == null) {
					return;
				}
				$friendData = $data['friend'] ?? null;
				if (!$friendData) {
					return;
				}
				$friendId = $friendData['id'] ?? null;
				if (!$friendId) {
					return;
				}

				UsersHelper::trackBadgeProgress($user, 'friends_added', $friendId);

				$friend = User::load((int) $friendId);
				if (!$friend) {
					return;
				}

				// you_know_ball: become friends with an administrator
				if (UsersHelper::isAdmin($friend)) {
					UsersHelper::grantBadge($user, 'you_know_ball');
				}

				// outreacher: become friends with someone from a different country
				$userCountry = strtoupper(
					trim((string) ($user->get('field_country')->value ?? '')),
				);
				$friendCountry = strtoupper(
					trim((string) ($friend->get('field_country')->value ?? '')),
				);
				if (
					$userCountry !== '' &&
					$friendCountry !== '' &&
					$userCountry !== $friendCountry
				) {
					UsersHelper::grantBadge($user, 'outreacher');
				}
			},
			'PUT mantle2.users.current.circle.add' => function (?UserInterface $user, array $data) {
				if ($user == null) {
					return;
				}
				// close_friends: add someone to your close friends
				UsersHelper::grantBadge($user, 'close_friends');
			},
			'POST mantle2.events.signup' => function (?UserInterface $user, array $data) {
				if ($user == null) {
					return;
				}

				$tz = self::userTimezone($user);
				try {
					$hour = (int) new DateTime('now', $tz)->format('G');
				} catch (\Throwable $e) {
					return;
				}

				// night_owl: signed up between 12 AM and 4 AM local time
				if ($hour >= 0 && $hour < 4) {
					UsersHelper::grantBadge($user, 'night_owl');
					return;
				}

				// early_bird: signed up between 4 AM and 9 AM local time
				if ($hour >= 4 && $hour < 9) {
					UsersHelper::grantBadge($user, 'early_bird');
				}
			},
			'PATCH mantle2.users.current.activities.set' => function (
				?UserInterface $user,
				array $data,
			) {
				if ($user == null) {
					return;
				}
				$activities = $data['activities'] ?? null;
				if (is_array($activities)) {
					$names = array_map(fn($a) => $a['name'] ?? null, $activities);
					$names = array_filter($names, fn($n) => $n !== null);
					if (!empty($names)) {
						UsersHelper::trackBadgeProgress($user, 'activities_added', $names);
					}
				}
			},
		];
	}

	public function onTerminate(TerminateEvent $event): void
	{
		$request = $event->getRequest();
		$response = $event->getResponse();

		$route = $request->attributes->get('_route');
		$method = $request->getMethod();

		$callbacks = self::callbacks();
		$keys = ["$method $route", "* $route", "$method *"];

		foreach ($keys as $key) {
			if (isset($callbacks[$key])) {
				/** @var callable(?UserInterface, array): void */
				$callback = $callbacks[$key];
				$user = UsersHelper::getOwnerOfRequest($request);
				$data = json_decode($response->getContent(), true);
				$data = is_array($data) ? $data : [];
				$callback($user, $data);
			}
		}
	}
}
