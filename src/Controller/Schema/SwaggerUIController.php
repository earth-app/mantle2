<?php

namespace Drupal\mantle2\Controller\Schema;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Response;

class SwaggerUIController extends ControllerBase
{
	public function getSwaggerUI(): Response
	{
		$openapiUrl = Url::fromRoute('mantle2.openapi', [], ['absolute' => false])->toString();
		$openapiUrl = htmlspecialchars($openapiUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		$html = <<<HTML
		<!doctype html>
		<html lang="en">
			<head>
				<meta charset="utf-8" />
				<meta name="viewport" content="width=device-width, initial-scale=1" />
				<meta name="author" content="The Earth App" />
				<meta name="description" content="API documentation for The Earth App" />

				<meta name="theme-color" content="#43b54d" />
				<meta name="og:title" content="Swagger UI" />
				<meta name="og:description" content="API documentation for The Earth App" />
				<meta name="og:image" content="https://cdn.earth-app.com/earth-app.png" />
				<meta name="og:type" content="website" />
				<meta name="og:locale" content="en_US" />
				<meta name="twitter:card" content="summary" />
				<meta name="twitter:title" content="Swagger UI" />
				<meta name="twitter:description" content="API documentation for The Earth App" />
				<meta name="twitter:image" content="https://cdn.earth-app.com/earth-app.png" />
				<meta name="twitter:creator" content="@the_earth_app" />

				<title>The Earth App | Swagger UI</title>

				<link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css" />
				<link rel="icon" href="https://cdn.earth-app.com/earth-app.png" type="image/png" />
				<link rel="apple-touch-icon" href="https://cdn.earth-app.com/earth-app.png" type="image/png" />
				<link rel="shortcut icon" href="https://cdn.earth-app.com/earth-app.ico" type="image/x-icon" />

				<style>
					html, body { margin: 0; padding: 0; height: 100%; }
					#swagger-ui { height: 100%; }
				</style>
			</head>
			<body>
				<div id="swagger-ui"></div>

				<script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js" crossorigin></script>
				<script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js" crossorigin></script>
				<script>
					window.addEventListener('load', function () {
						// Initialize Swagger UI pointing at the module's OpenAPI endpoint.
						window.ui = SwaggerUIBundle({
							url: '{$openapiUrl}',
							dom_id: '#swagger-ui',
							deepLinking: true,
							docExpansion: 'list',
							displayOperationId: true,
							persistAuthorization: true,
							presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset]
						});
					});
				</script>
			</body>
		</html>
		HTML;

		$response = new Response($html, 200, [
			'Content-Type' => 'text/html; charset=UTF-8',
		]);

		return $response;
	}
}
