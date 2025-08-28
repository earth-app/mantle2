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
	protected RouteProviderInterface $routeProvider;

	public function __construct(RouteProviderInterface $route_provider)
	{
		$this->routeProvider = $route_provider;
	}

	public static function create(ContainerInterface $container): OpenAPIController
	{
		return new static($container->get('router.route_provider'));
	}

	public function getSchema(): JsonResponse
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

			$options = $route->getOptions();
			$methods = $route->getMethods();
			if (empty($methods)) {
				$methods = ['GET'];
			}

			$responses = array_filter(
				[
					'200' => $options['schema/200']
						? ['description' => 'Successful response']
						: null,
					'201' => $options['schema/201'] ? ['description' => 'Resource created'] : null,
					'400' => $options['schema/400']
						? Mantle2Schemas::E400($options['schema/400'])
						: null,
					'401' => $options['schema/401']
						? Mantle2Schemas::E401($options['schema/401'])
						: null,
					'402' => $options['schema/402']
						? Mantle2Schemas::E402($options['schema/402'])
						: null,
					'403' => $options['schema/403']
						? Mantle2Schemas::E403($options['schema/403'])
						: null,
					'404' => $options['schema/404']
						? Mantle2Schemas::E404($options['schema/404'])
						: null,
				],
				fn($v) => $v !== null,
			);

			$pathItem = [];
			foreach ($methods as $method) {
				$parameters = [];
				preg_match_all('/\{(\w+)}/', $path, $matches);
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
					'responses' => $responses,
					'tags' => $options['tags'] ?? [],
				];
			}

			$paths[$path] = $pathItem;
		}

		$schema = [
			'openapi' => '3.1.0',
			'info' => [
				'title' => 'mantle2',
				'description' => 'Backend API for The Earth App, powered by Drupal',
				'version' => '1.0.0',
			],
			'servers' => [
				[
					'url' => 'https://api.earth-app.com',
					'description' => 'Production Server',
				],
				[
					'url' => 'https://127.0.0.1',
					'description' => 'Local Development Server',
				],
			],
			'components' => [
				'securitySchemes' => [
					'BasicAuth' => [
						'type' => 'http',
						'scheme' => 'basic',
					],
					'BearerAuth' => [
						'type' => 'http',
						'scheme' => 'bearer',
						'bearerFormat' => 'JWT',
					],
				],
			],
			'security' => [
				[
					'BasicAuth' => [],
				],
				[
					'BearerAuth' => [],
				],
			],
			'paths' => $paths,
		];

		return new JsonResponse($schema);
	}
}
