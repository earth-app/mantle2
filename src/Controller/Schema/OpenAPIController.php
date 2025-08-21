<?php

namespace Drupal\mantle2\Controller\Schema;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteProviderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Route;

class OpenAPIController extends ControllerBase
{
	/** @var RouteProviderInterface */
	protected $routeProvider;

	public function __construct(RouteProviderInterface $route_provider)
	{
		$this->routeProvider = $route_provider;
	}

	public static function create(ContainerInterface $container)
	{
		return new static($container->get('router.route_provider'));
	}

	public function getSchema()
	{
		$paths = [];

		$allRoutes = $this->routeProvider->getAllRoutes();

		foreach ($allRoutes as $name => $route) {
			/** @var Route $route */
			$path = $route->getPath();

			// Only include /v2/* routes
			if (strpos($path, '/v2/') !== 0) {
				continue;
			}

			$methods = $route->getMethods();
			if (empty($methods)) {
				$methods = ['GET'];
			}

			$pathItem = [];
			foreach ($methods as $method) {
				$parameters = [];
				preg_match_all('/\{(\w+)\}/', $path, $matches);
				foreach ($matches[1] as $param) {
					$parameters[] = [
						'name' => $param,
						'in' => 'path',
						'required' => true,
						'schema' => ['type' => 'string'],
					];
				}

				$pathItem[strtolower($method)] = [
					'summary' => $route->getDefault('_title') ?? $name,
					'parameters' => $parameters,
					'responses' => [
						'200' => ['description' => 'Successful response'],
						'404' => ['description' => 'Not found'],
					],
				];
			}

			$paths[$path] = $pathItem;
		}

		$schema = [
			'openapi' => '3.0.3',
			'info' => [
				'title' => 'My API',
				'version' => '1.0.0',
			],
			'paths' => $paths,
		];

		return new JsonResponse($schema);
	}
}
