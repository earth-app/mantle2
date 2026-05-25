<?php

namespace Drupal\mantle2\EventSubscriber;

use Drupal\mantle2\Service\ArticlesHelper;
use Drupal\mantle2\Service\PointsHelper;
use Drupal\mantle2\Service\RedisHelper;
use Drupal\mantle2\Service\UsersHelper;
use Drupal\node\Entity\Node;
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
				$message =
					$article ? $article->getTitle() : '' ?: 'Click to view what they wrote about';

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
				$message =
					is_string($prompt) ? $prompt : '' ?: 'Click to see what they have to say';

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
				if ($friendData) {
					$friendId = $friendData['id'] ?? null;
					if ($friendId) {
						UsersHelper::trackBadgeProgress($user, 'friends_added', $friendId);
					}
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
