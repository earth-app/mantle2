<?php

namespace Drupal\mantle2\EventSubscriber;

use Drupal;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ApiExceptionSubscriber implements EventSubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		return [
			KernelEvents::EXCEPTION => ['onException', 100],
		];
	}

	public function onException(ExceptionEvent $event): void
	{
		$request = $event->getRequest();

		// Only affect /v2/* requests
		if (!str_starts_with($request->getPathInfo(), '/v2/')) {
			return;
		}

		$exception = $event->getThrowable();
		$statusCode = 500;
		$message = 'Internal Server Error';

		if ($exception instanceof HttpExceptionInterface) {
			$statusCode = $exception->getStatusCode();
			$message =
				$statusCode >= 500
					? 'Internal Server Error'
					: ($exception->getMessage() ?:
					$this->getDefaultMessage($statusCode));
		} else {
			$exceptionCode = $exception->getCode();
			if ($exceptionCode >= 400 && $exceptionCode < 600) {
				$statusCode = $exceptionCode;
				$message =
					$statusCode >= 500
						? 'Internal Server Error'
						: ($exception->getMessage() ?:
						$this->getDefaultMessage($statusCode));
			}
		}

		if ($statusCode >= 500) {
			Drupal::logger('mantle2')->error('[api] Unhandled exception: @class :: @message', [
				'@class' => get_class($exception),
				'@message' => $exception->getMessage(),
			]);
		}

		$response = new JsonResponse(['message' => $message, 'code' => $statusCode], $statusCode, [
			'Content-Type' => 'application/json; charset=UTF-8',
		]);

		$event->setResponse($response);
	}

	private function getDefaultMessage(int $statusCode): string
	{
		return match ($statusCode) {
			400 => 'Bad Request',
			401 => 'Unauthorized',
			402 => 'Payment Required',
			403 => 'Forbidden',
			404 => 'Not Found',
			405 => 'Method Not Allowed',
			406 => 'Not Acceptable',
			415 => 'Unsupported Media Type',
			422 => 'Unprocessable Entity',
			429 => 'Too Many Requests',
			default => 'Internal Server Error',
		};
	}
}
