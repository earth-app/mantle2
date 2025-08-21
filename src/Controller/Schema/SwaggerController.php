<?php

namespace Drupal\mantle2\Controller\Schema;

use Drupal\Core\Controller\ControllerBase;

class SwaggerController extends ControllerBase
{
	public function swaggerPage()
	{
		$html = <<<HTML
		<!DOCTYPE html>
		<html lang="en">
		<head>
		  <meta charset="UTF-8">
		  <title>API Documentation</title>
		  <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/4.35.0/swagger-ui.css">
		</head>
		<body>
		  <div id="swagger-ui"></div>
		  <script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/4.35.0/swagger-ui-bundle.js"></script>
		  <script>
		    const ui = SwaggerUIBundle({
		      url: '/openapi',
		      dom_id: '#swagger-ui',
		      deepLinking: true,
		      presets: [
		        SwaggerUIBundle.presets.apis
		      ],
		      layout: "BaseLayout"
		    });
		  </script>
		</body>
		</html>
		HTML;

		return [
			'#type' => 'markup',
			'#markup' => $html,
		];
	}
}
