<?php

namespace Drupal\mantle2\EventSubscriber;

use Drupal\mantle2\Custom\ApiKey;
use Drupal\mantle2\Service\ApiKeysHelper;
use Drupal\mantle2\Service\GeneralHelper;
use Drupal\mantle2\Service\UsersHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces API-key scopes and the session-only blocklist.
 *
 * Runs after the rate-limit subscriber (lower priority) so that throttling
 * decisions don't depend on auth state. Three outcomes per request:
 *
 *  1. Caller has no API key (session token / anonymous): no-op.
 *  2. Caller has an API key with the required scope: no-op.
 *  3. Caller has an API key but it's out of scope (or the route is
 *     session-only):
 *       - For non-GET methods: 403.
 *       - For GET: demote the request to anonymous so the controller's own
 *         visibility checks decide whether to serve it. This matches the
 *         "treat as anonymous when the action is publicly readable" rule.
 *     For session-only routes the demotion path doesn't apply — those
 *     routes always 403 on API keys regardless of HTTP method.
 */
class ApiKeyScopeSubscriber implements EventSubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		// Lower than RateLimitSubscriber (300) and ResponseCacheSubscriber (400)
		// so throttling and cache hits short-circuit first.
		return [
			KernelEvents::REQUEST => ['onKernelRequest', 200],
		];
	}

	public function onKernelRequest(RequestEvent $event): void
	{
		if (!$event->isMainRequest()) {
			return;
		}

		$request = $event->getRequest();
		$path = $request->getPathInfo() ?? '';
		if (!str_starts_with($path, '/v2/')) {
			return;
		}

		$key = UsersHelper::getRequestApiKey($request);
		if (!$key instanceof ApiKey) {
			return;
		}

		$routeName = (string) $request->attributes->get('_route');
		if ($routeName === '') {
			return;
		}

		// session-only routes always 403 on API keys, regardless of method
		if (ApiKeysHelper::isSessionOnly($routeName)) {
			$event->setResponse(
				GeneralHelper::forbidden('API keys are not accepted for this endpoint'),
			);
			return;
		}

		$required = ApiKeysHelper::scopeFor($routeName);

		// fail closed
		if ($required === null) {
			$this->denyOrDemote($event, $request, 'API keys are not accepted for this endpoint');
			return;
		}

		// empty string = explicitly public
		if ($required === '') {
			return;
		}

		if ($key->hasScope($required)) {
			return;
		}

		$this->denyOrDemote($event, $request, "API key is missing required scope: $required");
	}

	private function denyOrDemote(
		RequestEvent $event,
		\Symfony\Component\HttpFoundation\Request $request,
		string $message,
	): void {
		if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
			// Demote: tell getOwnerOfRequest() to act as if the request were
			// anonymous. Controllers' own visibility checks will then gate
			// the response (PUBLIC profiles still serve, PRIVATE 404).
			$request->attributes->set('_mantle2_anon_demoted', true);
			return;
		}

		$event->setResponse(GeneralHelper::forbidden($message));
	}
}
