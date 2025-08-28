<?php

namespace Drupal\mantle2\EventSubscriber;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ApiExceptionSubscriber implements EventSubscriberInterface
{
	public static function getSubscribedEvents()
	{
		return [
			KernelEvents::EXCEPTION => ['onException', 100],
		];
	}

	public function onException(ExceptionEvent $event)
	{
		$request = $event->getRequest();

		// Only affect /v2/* requests
		if (strpos($request->getPathInfo(), '/v2/') !== 0) {
			return;
		}

		$exception = $event->getThrowable();
		$statusCode = 500;
		$message = 'Internal server error';
		$extra = [
			'exception' => get_class($exception),
			'message' => $exception->getMessage(),
			'stack_trace' => $exception->getTrace(),
		];

		if ($exception instanceof HttpExceptionInterface) {
			$statusCode = $exception->getStatusCode();
			$message = $exception->getMessage() ?: $this->getDefaultMessage($statusCode);
		} elseif ($exception instanceof \Exception) {
			$statusCode = 500;
			$message = 'Internal server error';
		}

		$response = new JsonResponse(
			['error' => $message, 'code' => $statusCode, 'extra' => $extra],
			$statusCode,
			[
				'Content-Type' => 'application/json',
			],
		);

		$event->setResponse($response);
	}

	private function getDefaultMessage($statusCode)
	{
		switch ($statusCode) {
			case 403:
				return 'Access denied';
			case 404:
				return 'Not found';
			default:
				return 'Error';
		}
	}
}
