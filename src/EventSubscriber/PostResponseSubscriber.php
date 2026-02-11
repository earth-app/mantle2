<?php

namespace Drupal\mantle2\EventSubscriber;

use Drupal\mantle2\Service\UsersHelper;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

class PostResponseSubscriber implements EventSubscriberInterface
{
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
				if ($id) {
					UsersHelper::trackBadgeProgress($user, 'articles_created', $id);
				}
			},
			'POST mantle2.prompts.create' => function (?UserInterface $user, array $data) {
				if ($user == null) {
					return;
				}
				$id = $data['id'] ?? null;
				if ($id) {
					UsersHelper::trackBadgeProgress($user, 'prompts_created', $id);
				}
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
			},
			'POST mantle2.events.create' => function (?UserInterface $user, array $data) {
				if ($user == null) {
					return;
				}
				$id = $data['id'] ?? null;
				if ($id) {
					UsersHelper::trackBadgeProgress($user, 'events_created', $id);
				}
			},
			'PUT mantle2.users.id.friends.add' => function (?UserInterface $user, array $data) {
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
			'PUT mantle2.users.username.friends.add' => function (
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
			'PATCH mantle2.users.id.activities.set' => function (
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
			'PATCH mantle2.users.username.activities.set' => function (
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
				$callback($user, $data);
			}
		}
	}
}
