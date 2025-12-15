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

	private array $allowedOrigins = [
		'https://api.earth-app.com',
		'https://earth-app.com',
		'https://app.earth-app.com',
		'https://cloud.earth-app.com',
		'capacitor://localhost', // ios
		'http://localhost', // android
		'http://localhost:3000',
		'http://127.0.0.1:3000',
		'http://localhost:3001',
		'http://127.0.0.1:3001',
	];

	public function onRespond(ResponseEvent $event)
	{
		$request = $event->getRequest();
		$origin = $request->headers->get('Origin');
		$response = $event->getResponse();

		if (in_array($origin, $this->allowedOrigins)) {
			$response->headers->set('Access-Control-Allow-Origin', $origin);
		} else {
			$response->headers->set('Access-Control-Allow-Origin', $this->allowedOrigins[0]); // Default to the first allowed origin
		}

		$response->headers->set('Vary', 'Origin');
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
