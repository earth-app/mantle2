<?php

namespace Drupal\mantle2\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class RateLimitResponseSubscriber implements EventSubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		return [
			KernelEvents::RESPONSE => ['onKernelResponse', -128],
		];
	}

	public function onKernelResponse(ResponseEvent $event): void
	{
		if (!$event->isMainRequest()) {
			return;
		}
		$request = $event->getRequest();
		$response = $event->getResponse();

		$headers = $request->attributes->get('_mantle2_rl_headers');
		if (!is_array($headers)) {
			return;
		}

		if (isset($headers['global'])) {
			[$globalResult, $limit, $interval] = $headers['global'];
			$remaining = max(0, (int) ($globalResult['remaining'] ?? 0));
			$reset = (int) ($globalResult['resetTime'] ?? time());

			$response->headers->set('X-Global-RateLimit-Limit', (string) $limit);
			$response->headers->set('X-Global-RateLimit-Remaining', (string) $remaining);
			$response->headers->set('X-Global-RateLimit-Reset', (string) $reset);
		}

		if (isset($headers['endpoint'])) {
			[$endpointResult, $limit, $interval] = $headers['endpoint'];
			$remaining = max(0, (int) ($endpointResult['remaining'] ?? 0));
			$reset = (int) ($endpointResult['resetTime'] ?? time());

			$response->headers->set('X-RateLimit-Limit', (string) $limit);
			$response->headers->set('X-RateLimit-Remaining', (string) $remaining);
			$response->headers->set('X-RateLimit-Reset', (string) $reset);
		}
	}
}
