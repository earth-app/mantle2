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
			if (!str_starts_with($path, '/v2/')) {
				continue;
			}

			$options = $route->getOptions();
			$methods = $route->getMethods();
			if (empty($methods)) {
				$methods = ['GET'];
			}

			if (array_key_exists('body/description', $options)) {
				$requestBody = [
					'description' => array_key_exists('body/description', $options)
						? $options['body/description']
						: 'Request object',
					'required' => array_key_exists('body/required', $options)
						? $options['body/required']
						: true,
					'content' => [
						'application/json' => [
							'schema' => $this->resolveSchemaSpecifier(
								$options['body/schema'] ?? null,
							),
						],
					],
				];
			} else {
				$requestBody = [];
			}

			$responses = array_filter(
				[
					'200' =>
						$options['schema/200'] ?? null
							? [
								'description' => 'Successful response',
								'content' => [
									$options['schema/200/type'] ?? 'application/json' => [
										'schema' => $this->resolveSchemaSpecifier(
											$options['schema/200'],
										),
									],
								],
							]
							: null,
					'201' =>
						$options['schema/201'] ?? null
							? [
								'description' => 'Resource created',
								'content' => [
									array_key_exists('schema/201/type', $options)
										? $options['schema/201/type']
										: 'application/json' => [
										'schema' => $this->resolveSchemaSpecifier(
											$options['schema/201'],
										),
									],
								],
							]
							: null,
					'204' =>
						$options['schema/204'] ?? null ? ['description' => 'No Content'] : null,
					'400' =>
						$options['schema/400'] ?? null
							? Mantle2Schemas::E400($options['schema/400'])
							: null,
					'401' =>
						$options['schema/401'] ?? null
							? Mantle2Schemas::E401($options['schema/401'])
							: null,
					'402' =>
						$options['schema/402'] ?? null
							? Mantle2Schemas::E402($options['schema/402'])
							: null,
					'403' =>
						$options['schema/403'] ?? null
							? Mantle2Schemas::E403($options['schema/403'])
							: null,
					'404' =>
						$options['schema/404'] ?? null
							? Mantle2Schemas::E404($options['schema/404'])
							: null,
				],
				fn($v) => $v !== null,
			);

			$pathItem = $paths[$path] ?? [];
			foreach ($methods as $method) {
				$parameters = [];
				preg_match_all('/\{(\w+)}/', $path, $matches);
				foreach ($matches[1] as $param) {
					$paramSettings = $options['parameters'][$param];
					$type = $paramSettings['type'] ?? 'string';
					if (str_starts_with($type, 'entity:')) {
						$type = 'integer';
					}

					$schema = ['type' => $type];

					if (isset($paramSettings['enum'])) {
						$schema['enum'] = $paramSettings['enum'];
					}

					$parameters[] = [
						'name' => $param,
						'in' => 'path',
						'required' => true,
						'schema' => $schema,
					];
				}

				if (array_key_exists('query', $options) && is_array($options['query'])) {
					foreach ($options['query'] as $queryParam => $paramConfig) {
						$schema = ['type' => $paramConfig['type'] ?? 'string'];
						if (isset($paramConfig['enum'])) {
							$schema['enum'] = $paramConfig['enum'];
						}

						if (isset($paramConfig['minimum'])) {
							$schema['minimum'] = $paramConfig['minimum'];
						}

						if (isset($paramConfig['maximum'])) {
							$schema['maximum'] = $paramConfig['maximum'];
						}

						if (isset($paramConfig['default'])) {
							$schema['default'] = $paramConfig['default'];
						}

						$parameters[] = [
							'name' => $queryParam,
							'in' => 'query',
							'required' => $paramConfig['required'] ?? false,
							'description' => $paramConfig['description'] ?? '',
							'schema' => $schema,
						];
					}
				}

				$method0 = strtolower($method);
				$pathItem[$method0] = [
					'summary' => $route->getDefault('_title') ?? $name,
					'description' => $options['description'] ?? '',
					'parameters' => $parameters,
					'responses' => $responses,
					'tags' => explode(',', $options['tags'] ?? '') ?? [],
				];

				if ($method !== 'GET' && !empty($requestBody)) {
					$pathItem[$method0]['requestBody'] = $requestBody;
				}
			}

			$paths[$path] = $pathItem;
		}

		$schema = [
			'openapi' => '3.1.0',
			'info' => [
				'title' => '@earth-app/mantle2',
				'description' => 'Backend API for The Earth App, powered by Drupal',
				'version' => '1.0.0',
			],
			'servers' => [
				[
					'url' => 'https://api.earth-app.com',
					'description' => 'Production Server',
				],
				[
					'url' => 'http://127.0.0.1:8787',
					'description' => 'Local Development Server',
				],
				[
					'url' => 'https://mantle2.ddev.site',
					'description' => 'DDEV Development Server',
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

	private function resolveSchemaSpecifier($spec): array
	{
		if (!is_string($spec)) {
			return ['type' => 'object'];
		}

		$spec = trim($spec);
		if ($spec === '') {
			return ['type' => 'object'];
		}

		// Property reference: "$propName"
		if ($spec[0] === '$') {
			$prop = substr($spec, 1);
			if ($prop !== '' && property_exists(Mantle2Schemas::class, $prop)) {
				return Mantle2Schemas::${$prop};
			}
		}

		// Method reference: "method" or "method()"
		$method = $spec;
		if (str_ends_with($method, '()')) {
			$method = substr($method, 0, -2);
		}
		if ($method !== '' && method_exists(Mantle2Schemas::class, $method)) {
			return Mantle2Schemas::$method();
		}

		// Fallback
		return ['type' => 'object'];
	}
}
