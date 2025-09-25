<?php

namespace Drupal\mantle2\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

class CorsSubscriber implements EventSubscriberInterface
{
	public static function getSubscribedEvents()
	{
		return [
			KernelEvents::RESPONSE => 'onRespond',
		];
	}

	public function onRespond(ResponseEvent $event)
	{
		$response = $event->getResponse();

		$response->headers->set('Access-Control-Allow-Origin', 'https://api.earth-app.com');
		$response->headers->set(
			'Access-Control-Allow-Methods',
			'GET, POST, PUT, PATCH, DELETE, OPTIONS',
		);
		$response->headers->set(
			'Access-Control-Allow-Headers',
			'Content-Type, Authorization, Accept, X-Requested-With, X-Admin-Key',
		);
		$response->headers->set('Access-Control-Allow-Credentials', 'true');
		$response->headers->set('Access-Control-Max-Age', '3600');

		$event->setResponse($response);
	}
}
