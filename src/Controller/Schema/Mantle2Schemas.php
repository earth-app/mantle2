<?php /** @noinspection PhpUnused */

namespace Drupal\mantle2\Controller\Schema;

use Drupal\mantle2\Custom\ActivityType;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Custom\EventType;
use Drupal\mantle2\Custom\Privacy;
use Drupal\mantle2\Service\OAuthHelper;

class Mantle2Schemas
{
	// Error Response Base Schema
	public static array $errorResponse = [
		'type' => 'object',
		'properties' => [
			'error' => ['type' => 'string', 'example' => 'Error message'],
			'code' => ['type' => 'number', 'example' => 400],
		],
		'required' => ['error', 'code'],
	];

	public static array $conflictError = [
		'type' => 'object',
		'properties' => [
			'error' => ['type' => 'string', 'example' => 'Conflict'],
			'message' => ['type' => 'string', 'example' => 'Duplicate entry found'],
		],
		'required' => ['error', 'message'],
	];

	public static array $rateLimitError = [
		'type' => 'object',
		'properties' => [
			'error' => ['type' => 'string', 'example' => 'Rate limit exceeded'],
			'message' => [
				'type' => 'string',
				'example' => 'Please wait 120 seconds before trying again',
			],
			'retry_after' => [
				'type' => 'integer',
				'example' => 120,
				'description' => 'Seconds to wait before retrying',
			],
		],
		'required' => ['error', 'message', 'retry_after'],
	];

	// Error Responses

	public static function E400($description = 'Bad request'): array
	{
		return [
			'description' => $description,
			'content' => [
				'application/json' => [
					'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
				],
			],
		];
	}

	public static function E401($description = 'Unauthorized'): array
	{
		return [
			'description' => $description,
			'content' => [
				'application/json' => [
					'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
				],
			],
		];
	}

	public static function E402($description = 'Payment Required'): array
	{
		return [
			'description' => $description,
			'content' => [
				'application/json' => [
					'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
				],
			],
		];
	}

	public static function E403($description = 'Forbidden'): array
	{
		return [
			'description' => $description,
			'content' => [
				'application/json' => [
					'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
				],
			],
		];
	}

	public static function E404($description = 'Not found'): array
	{
		return [
			'description' => $description,
			'content' => [
				'application/json' => [
					'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
				],
			],
		];
	}

	public static function E409($description = 'Duplicate entry'): array
	{
		return [
			'description' => $description,
			'content' => [
				'application/json' => [
					'schema' => ['$ref' => '#/components/schemas/ConflictError'],
				],
			],
		];
	}

	public static function E429($description = 'Rate limit exceeded'): array
	{
		return [
			'description' => $description,
			'content' => [
				'application/json' => [
					'schema' => ['$ref' => '#/components/schemas/RateLimitError'],
				],
			],
		];
	} // Root Types (schemas)

	public static array $info = [
		'type' => 'object',
		'properties' => [
			'name' => ['type' => 'string', 'example' => 'mantle'],
			'title' => ['type' => 'string', 'example' => 'Earth App'],
			'version' => ['type' => 'string', 'example' => '1.0.0'],
			'description' => ['type' => 'string', 'example' => 'Backend API for The Earth App'],
			'date' => ['type' => 'string', 'example' => '2025-05-11'],
		],
		'required' => ['name', 'title', 'version', 'description', 'date'],
	];

	public static function paginated($itemSchema): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'page' => [
					'type' => 'integer',
					'minimum' => 1,
					'example' => 1,
					'description' => 'Current page number',
				],
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'example' => 25,
					'description' => 'Number of items per page',
				],
				'total' => [
					'type' => 'integer',
					'minimum' => 0,
					'example' => 100,
					'description' => 'Total number of items',
				],
				'items' => [
					'type' => 'array',
					'items' => $itemSchema,
					'description' => 'List of items on the current page',
				],
			],
			'required' => ['page', 'limit', 'total', 'items'],
		];
	}

	public static function sortOrder(): array
	{
		return [
			'type' => 'string',
			'enum' => ['asc', 'desc', 'rand'],
			'default' => 'desc',
			'description' =>
				'Sort order: asc (ascending/oldest first), desc (descending/newest first), rand (random)',
		];
	}

	public static array $paginatedParameters = [
		[
			'name' => 'page',
			'in' => 'query',
			'description' => 'Page number (default: 1)',
			'required' => false,
			'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
		],
		[
			'name' => 'limit',
			'in' => 'query',
			'description' => 'Number of items per page (default: 25, max: 250)',
			'required' => false,
			'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 250, 'default' => 25],
		],
		[
			'name' => 'search',
			'in' => 'query',
			'description' => 'Search query (max 40 characters)',
			'required' => false,
			'schema' => ['type' => 'string', 'maxLength' => 40, 'default' => ''],
		],
		[
			'name' => 'sort',
			'in' => 'query',
			'description' =>
				'Sort order: asc (ascending/oldest first), desc (descending/newest first), rand (random)',
			'required' => false,
			'schema' => [
				'type' => 'string',
				'enum' => ['asc', 'desc', 'rand'],
				'default' => 'desc',
			],
		],
	];

	public static array $paginatedParams = [
		'type' => 'object',
		'properties' => [
			'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
			'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 250, 'default' => 25],
			'search' => ['type' => 'string', 'maxLength' => 40, 'default' => ''],
			'sort' => ['type' => 'string', 'enum' => ['asc', 'desc', 'rand'], 'default' => 'desc'],
		],
		'required' => ['page', 'limit', 'search', 'sort'],
	];

	// String Types
	public static array $text = ['type' => 'string', 'example' => 'Hello World'];
	public static function text(
		$maxLength = 100,
		$minLength = 1,
		$example = 'Hello World',
		$description = 'The text',
	): array {
		return [
			'type' => 'string',
			'minLength' => $minLength,
			'maxLength' => $maxLength,
			'example' => $example,
			'description' => $description,
		];
	}

	public static array $number = [
		'type' => 'number',
		'minimum' => 0,
		'maximum' => 9999999999,
		'example' => 1234567890,
	];
	public static array $id = [
		'type' => 'string',
		'minLength' => 24,
		'maxLength' => 24,
		'example' => '012345678987654321012345',
	];
	public static array $username = [
		'type' => 'string',
		'minLength' => 3,
		'maxLength' => 30,
		'pattern' => '^[a-zA-Z0-9_.-]+$',
		'example' => 'johndoe',
	];
	public static array $password = [
		'type' => 'string',
		'minLength' => 8,
		'maxLength' => 100,
		'pattern' => "^[a-zA-Z0-9!@#$%^&*()_+={}\[\]:;\"'<>.,?\/\\|-]+$", // At least 8 characters, letters, numbers, special chars
		'example' => 'password123',
	];
	public static array $email = [
		'type' => 'string',
		'format' => 'email',
		'example' => 'me@company.com',
	];
	public static array $date = [
		'type' => 'string',
		'format' => 'date-time',
		'example' => '2025-05-11T10:00:00Z',
	];
	public static array $hexCode = [
		'type' => 'string',
		'minLength' => 7,
		'maxLength' => 7,
		'pattern' => '^#[0-9A-Fa-f]{6}$',
		'examples' => ['#ffd700', '#FF5733', '#33ff57', '#3357ff', '#F0F8FF'],
		'description' => 'A valid hex color code',
	];
	public static array $bool = ['type' => 'boolean', 'example' => true];
	public static array $sessionToken = [
		'type' => 'string',
		'example' => '6401d132d5663c98c3a1e3796085a7b37cd0e9d3ec81b40dcf19a86c1df81c30',
		'minLength' => 64,
		'maxLength' => 64,
		'pattern' => '^[a-f0-9]{64}$',
		'description' => 'The session token for the user',
	];
	public static array $name = [
		'type' => 'string',
		'minLength' => 2,
		'maxLength' => 50,
		'example' => 'John',
	];
	public static array $dataUrl = [
		'type' => 'string',
		'format' => 'data-url',
		'description' => 'Data URL of the submitted image',
		'example' =>
			'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxISEhUTEhIVFhUXFxgXFxgYFxgVFRcXFxgXFxgYHSggGBolHRcVITEhJSkrLi4uFx8zODMsNygtLisBCgoKDg0OGxAQGy0lHyUt',
	];

	// Parameter Schemas
	public static array $usernameParam = [
		'type' => 'string',
		'minLength' => 5,
		'maxLength' => 21,
		'pattern' => '^@([a-zA-Z0-9_]{3,20})$',
		'example' => '@johndoe',
	];
	public static array $idParam = [
		'type' => 'string',
		'minLength' => 24,
		'maxLength' => 24,
		'example' => 'eyb2cCNwc73b197cnsHbDqiU',
	];
	public static array $uuidParam = [
		'type' => 'string',
		'format' => 'uuid',
		'minLength' => 36,
		'maxLength' => 36,
		'example' => '123e4567-e89b-12d3-a456-426614174000',
	];

	// Enum-like Types (as strings with examples)
	public static function activityTypes(): array
	{
		return array_map(fn(ActivityType $t) => $t->value, ActivityType::cases());
	}
	public static function activityType(): array
	{
		return [
			'type' => 'string',
			'example' => 'HOBBY',
			'enum' => self::activityTypes(),
		];
	}

	public static function visibility(): array
	{
		return [
			'type' => 'string',
			'example' => 'PUBLIC',
			'enum' => array_map(fn($case) => $case->value, Visibility::cases()),
		];
	}

	public static function userPrivacy(): array
	{
		return [
			'type' => 'string',
			'example' => 'MUTUAL',
			'enum' => array_map(fn($case) => $case->value, Privacy::cases()),
		];
	}
	public static function eventType(): array
	{
		return [
			'type' => 'string',
			'example' => 'IN_PERSON',
			'enum' => array_map(fn($case) => $case->value, EventType::cases()),
		];
	}

	// Array Types
	public static array $stringArray = [
		'type' => 'array',
		'items' => ['type' => 'string'],
		'example' => ['example1', 'example2', 'example3'],
	];
	public static function idArray(): array
	{
		return [
			'type' => 'array',
			'items' => self::$id,
			'example' => ['bu72behwJd9wjfoz98enfoaw', 'audyrehwJd9wjfoz98enfoaw'],
		];
	}

	// Request Objects
	public static function userCreate(): array
	{
		return [
			'type' => 'object',
			'oneOf' => [
				[
					'description' => 'Traditional username/password signup',
					'properties' => [
						'username' => self::$username,
						'password' => self::$password,
						'email' => self::$email,
						'first_name' => self::text(50),
						'last_name' => self::text(50),
					],
					'required' => ['username', 'password'],
				],
				[
					'description' => 'OAuth provider signup',
					'properties' => [
						'oauth_provider' => [
							'type' => 'string',
							'enum' => OAuthHelper::$providers,
							'description' => 'OAuth provider to use for account creation',
						],
						'id_token' => [
							'type' => 'string',
							'description' => 'JWT id_token from OAuth provider',
						],
						'username' => array_merge(self::$username, [
							'description' =>
								'Optional custom username (auto-generated if not provided)',
						]),
					],
					'required' => ['oauth_provider', 'id_token'],
				],
			],
		];
	}

	public static function userUpdate(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'username' => self::$username,
				'email' => array_merge(self::$email, [
					'description' =>
						'Email address. If changed, will trigger email verification process and not be updated immediately.',
				]),
				'first_name' => self::text(50),
				'last_name' => self::text(50),
				'address' => self::text(),
				'bio' => self::text(500),
				'country' => self::text(2),
				'phone_number' => self::$number,
				'visibility' => self::visibility(),
				'subscribed' => self::$bool,
			],
		];
	}
	public static function userUpdateJson(): array
	{
		return [
			'$schema' => 'http://json-schema.org/draft-07/schema#',
			...self::userUpdate(),
		];
	}

	public static function userFieldPrivacy(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'name' => self::userPrivacy(),
				'bio' => self::userPrivacy(),
				'phone_number' => self::userPrivacy(),
				'country' => self::userPrivacy(),
				'email' => self::userPrivacy(),
				'address' => self::userPrivacy(),
				'activities' => self::userPrivacy(),
				'events' => self::userPrivacy(),
				'friends' => self::userPrivacy(),
				'last_login' => self::userPrivacy(),
				'account_type' => self::userPrivacy(),
				'circle' => self::userPrivacy(),
			],
		];
	}

	public static function userFieldPrivacyJson(): array
	{
		return [
			'$schema' => 'http://json-schema.org/draft-07/schema#',
			'type' => 'object',
			'$defs' => [
				'privacy' => [
					'type' => 'string',
					'enum' => array_map(fn($case) => $case->value, Privacy::cases()),
				],
				'never_public_privacy' => [
					'type' => 'string',
					'enum' => array_filter(
						array_map(fn($case) => $case->value, Privacy::cases()),
						fn($value) => $value !== 'PUBLIC',
					),
					'default' => 'PRIVATE',
				],
			],
			'properties' => [
				'name' => ['$ref' => '#/$defs/privacy', 'default' => 'PUBLIC'],
				'bio' => ['$ref' => '#/$defs/privacy', 'default' => 'PUBLIC'],
				'phone_number' => ['$ref' => '#/$defs/never_public_privacy', 'default' => 'CIRCLE'],
				'country' => ['$ref' => '#/$defs/privacy', 'default' => 'MUTUAL'],
				'email' => ['$ref' => '#/$defs/privacy', 'default' => 'MUTUAL'],
				'address' => ['$ref' => '#/$defs/never_public_privacy', 'default' => 'PRIVATE'],
				'activities' => ['$ref' => '#/$defs/privacy', 'default' => 'PUBLIC'],
				'events' => ['$ref' => '#/$defs/privacy', 'default' => 'MUTUAL'],
				'friends' => ['$ref' => '#/$defs/privacy', 'default' => 'PUBLIC'],
				'last_login' => ['$ref' => '#/$defs/privacy', 'default' => 'PUBLIC'],
				'account_type' => ['$ref' => '#/$defs/privacy', 'default' => 'PUBLIC'],
				'circle' => ['$ref' => '#/$defs/never_public_privacy', 'default' => 'PRIVATE'],
				'impact_points' => ['$ref' => '#/$defs/privacy', 'default' => 'PUBLIC'],
				'badges' => ['$ref' => '#/$defs/privacy', 'default' => 'PUBLIC'],
			],
		];
	}

	public static function eventCreate(): array
	{
		$data = self::eventUpdate();
		return [...$data, 'required' => ['name', 'type', 'date', 'visibility']];
	}

	public static function eventUpdate(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'name' => self::text(50),
				'description' => self::text(3000),
				'type' => self::eventType(),
				'activities' => ['type' => 'array', 'items' => self::activityType()],
				'location' => [
					'type' => 'object',
					'properties' => [
						'latitude' => ['type' => 'number', 'example' => 37.7749],
						'longitude' => ['type' => 'number', 'example' => -122.4194],
					],
				],
				'date' => ['type' => 'integer', 'example' => 1736400000000],
				'end_date' => ['type' => 'integer', 'example' => 1736403600000],
				'visibility' => self::visibility(),
				'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
			],
		];
	}

	public static function promptCreate(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'prompt' => self::$text,
				'visibility' => self::userPrivacy(),
			],
			'required' => ['prompt'],
		];
	}

	public static array $promptResponseBody = [
		'type' => 'object',
		'properties' => ['content' => ['type' => 'string', 'maxLength' => 700]],
		'required' => ['content'],
	];

	public static array $passwordResetBody = [
		'type' => 'object',
		'properties' => [
			'new_password' => [
				'type' => 'string',
				'minLength' => 8,
				'maxLength' => 100,
				'pattern' => "^[a-zA-Z0-9!@#$%^&*()_+={}\[\]:;\"'<>.,?\/\\|-]+$",
				'description' => 'New password for the user account',
			],
		],
		'required' => ['new_password'],
	];

	public static array $passwordChangeBody = [
		'type' => 'object',
		'properties' => [
			'current_password' => [
				'type' => 'string',
				'description' =>
					'Current password for verification (optional if using reset token)',
			],
			'new_password' => [
				'type' => 'string',
				'minLength' => 8,
				'maxLength' => 100,
				'pattern' => "^[a-zA-Z0-9!@#$%^&*()_+={}\[\]:;\"'<>.,?\/\\|-]+$",
				'description' => 'New password for the user account',
			],
		],
		'required' => ['new_password'],
	];

	public static array $passwordChangeFlexibleBody = [
		'type' => 'object',
		'properties' => [
			'new_password' => [
				'type' => 'string',
				'minLength' => 8,
				'maxLength' => 100,
				'pattern' => "^[a-zA-Z0-9!@#$%^&*()_+={}\[\]:;\"'<>.,?\/\\|-]+$",
				'description' => 'New password for the user account',
			],
		],
		'required' => ['new_password'],
		'description' =>
			'Request body for password change. Authentication can be done via either reset token (query parameter) or current password (old_password query parameter).',
	];

	public static array $passwordSetBody = [
		'type' => 'object',
		'properties' => [
			'password' => [
				'type' => 'string',
				'minLength' => 8,
				'maxLength' => 100,
				'pattern' => "^[a-zA-Z0-9!@#$%^&*()_+={}\[\]:;\"'<>.,?\/\\|-]+$",
				'description' => 'Password to set for the user account',
			],
			'old_password' => [
				'type' => 'string',
				'description' =>
					'Current password (required only if user already has a password set)',
			],
		],
		'required' => ['password'],
		'description' =>
			'Request body for setting password. For OAuth-only users, only password is required. For users with existing passwords, old_password must be provided.',
	];

	public static array $oauthLoginBody = [
		'type' => 'object',
		'properties' => [
			'id_token' => [
				'type' => 'string',
				'description' =>
					'JWT id_token received from OAuth provider after successful authentication',
				'example' => 'eyJhbGciOiJSUzI1NiIsImtpZCI6IjFlOWdkazcifQ...',
			],
		],
		'required' => ['id_token'],
	];

	public static array $articleBody = [
		'type' => 'object',
		'properties' => [
			'title' => ['type' => 'string', 'example' => 'Hello World', 'maxLength' => 100],
			'description' => [
				'type' => 'string',
				'example' => 'Hello World',
				'maxLength' => 512,
			],
			'tags' => [
				'type' => 'array',
				'items' => ['type' => 'string', 'example' => 'Hello World', 'maxLength' => 30],
				'maxItems' => 10,
				'example' => ['tag1', 'tag2', 'tag3'],
				'default' => [],
			],
			'content' => [
				'type' => 'string',
				'example' =>
					'The phrase "Hello World" is commonly used in programming as a simple test message to demonstrate the basic syntax of a programming language or to verify that a system is functioning correctly. It is often the first program written by beginners when learning a new programming language.',
				'minLength' => 50,
				'maxLength' => 10000,
			],
			'ocean' => [
				'type' => 'object',
				'properties' => [
					'title' => ['type' => 'string'],
					'url' => ['type' => 'string', 'format' => 'uri'],
					'author' => ['type' => 'string'],
					'source' => ['type' => 'string'],
					'links' => [
						'type' => 'object',
						'additionalProperties' => ['type' => 'string', 'format' => 'uri'],
					],
					'abstract' => ['type' => 'string', 'maxLength' => 10000, 'minLength' => 50],
					'content' => ['type' => 'string', 'maxLength' => 10000, 'minLength' => 50],
					'theme_color' => [
						'type' => 'string',
						'pattern' => '^#[0-9A-Fa-f]{6}$',
					],
					'keywords' => [
						'type' => 'array',
						'items' => ['type' => 'string', 'maxLength' => 35],
						'maxItems' => 25,
					],
					'date' => ['type' => 'string', 'format' => 'date-time'],
					'favicon' => ['type' => 'string', 'format' => 'uri'],
				],
				'required' => ['title', 'url', 'author', 'source', 'content', 'date'],
			],
			'color' => [
				'type' => 'string',
				'pattern' => '^#[0-9A-Fa-f]{6}$',
			],
		],
		'required' => ['title', 'description', 'content'],
	];

	public static function activityCreate(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['type' => 'string', 'example' => 'hiking'],
				'name' => self::$text,
				'description' => self::$text,
				'types' => ['type' => 'array', 'items' => self::activityType()],
				'aliases' => ['type' => 'array', 'items' => ['type' => 'string']],
				'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
			],
			'required' => ['id', 'name', 'types'],
		];
	}

	public static function activityUpdate(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'name' => self::$text,
				'description' => self::$text,
				'types' => ['type' => 'array', 'items' => self::activityType()],
				'aliases' => ['type' => 'array', 'items' => ['type' => 'string']],
				'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
			],
		];
	}

	public static array $userActivitiesSet = [
		'type' => 'array',
		'items' => ['type' => 'string'],
		'example' => ['hiking', 'swimming', 'cycling'],
	];

	// Return Objects
	public static function motdResponse(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'motd' => ['type' => 'string', 'example' => 'Welcome to the Earth App!'],
				'ttl' => [
					'type' => 'integer',
					'example' => 3600,
					'description' => 'Time in seconds until the MOTD should be refreshed',
				],
				'icon' => [
					'type' => 'string',
					'example' => 'mdi:earth',
					'description' => 'Optional icon identifier for the MOTD',
				],
				'type' => [
					'type' => 'string',
					'enum' => ['info', 'warning', 'error', 'success'],
					'default' => 'info',
					'description' =>
						'Type of the MOTD, can be used to indicate severity or category',
				],
			],
			'required' => ['motd', 'ttl'],
		];
	}

	public static function user(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['$ref' => '#/components/schemas/Number'],
				'username' => ['$ref' => '#/components/schemas/Username'],
				'created_at' => ['$ref' => '#/components/schemas/Date'],
				'updated_at' => ['$ref' => '#/components/schemas/Date'],
				'last_login' => ['$ref' => '#/components/schemas/Date'],
				'account' => [
					'type' => 'object',
					'properties' => [
						'id' => ['$ref' => '#/components/schemas/Id'],
						'username' => ['$ref' => '#/components/schemas/Username'],
						'email' => ['$ref' => '#/components/schemas/Email'],
						'first_name' => ['$ref' => '#/components/schemas/Name'],
						'last_name' => ['$ref' => '#/components/schemas/Name'],
						'address' => self::text(),
						'bio' => self::text(500),
						'country' => ['type' => 'string'],
						'phone_number' => ['type' => 'integer'],
						'visibility' => ['$ref' => '#/components/schemas/Visibility'],
						'email_verified' => ['$ref' => '#/components/schemas/Bool'],
						'subscribed' => ['$ref' => '#/components/schemas/Bool'],
						'has_password' => [
							'type' => 'boolean',
							'description' =>
								'Whether user has a password set (false for OAuth-only accounts)',
							'example' => true,
						],
						'linked_providers' => [
							'type' => 'array',
							'items' => [
								'type' => 'string',
								'enum' => OAuthHelper::$providers,
							],
							'description' => 'List of OAuth providers linked to this account',
							'example' => ['discord', 'github'],
						],
						'field_privacy' => ['$ref' => '#/components/schemas/UserFieldPrivacy'],
					],
				],
				'activities' => [
					'type' => 'array',
					'items' => ['$ref' => '#/components/schemas/Activity'],
				],
				'is_friend' => [
					'type' => 'boolean',
					'description' => 'Indicates if the user is a friend of the requesting user',
					'example' => true,
				],
				'is_my_friend' => [
					'type' => 'boolean',
					'description' => 'Indicates if the requesting user is a friend of this user',
					'example' => false,
				],
				'is_mutual' => [
					'type' => 'boolean',
					'description' =>
						'Indicates if the user is a mutual friend with the requesting user',
					'example' => false,
				],
				'friends' => ['$ref' => '#/components/schemas/IdArray'],
				'added_count' => [
					'type' => 'integer',
					'example' => 42,
					'description' => 'Total number of friends the user has added',
				],
				'mutual_count' => [
					'type' => 'integer',
					'example' => 10,
					'description' => 'Number of mutual friends with the requesting user',
				],
				'non_mutual_count' => [
					'type' => 'integer',
					'example' => 32,
					'description' =>
						'Number of non-mutual friends the user has (friends only added by them)',
				],
				'email_change_pending' => [
					'type' => 'boolean',
					'description' => 'Indicates if an email change verification is pending',
					'example' => true,
				],
				'is_in_circle' => [
					'type' => 'boolean',
					'description' =>
						'Indicates if the user is in the private circle of the requesting user',
					'example' => false,
				],
				'is_in_my_circle' => [
					'type' => 'boolean',
					'description' =>
						'Indicates if the requesting user is in this user\'s private circle',
					'example' => true,
				],
				'circle' => ['$ref' => '#/components/schemas/IdArray'],
				'circle_count' => [
					'type' => 'integer',
					'example' => 5,
					'description' => 'Number of users in the user\'s private circle',
				],
				'max_circle_count' => [
					'type' => 'integer',
					'example' => 100,
					'description' => 'Maximum number of users allowed in the private circle',
				],
				'message' => [
					'type' => 'string',
					'description' => 'Optional message about the operation performed',
					'example' =>
						'User updated successfully. Email change verification sent to new address.',
				],
			],
			'required' => ['id', 'username', 'created_at', 'updated_at', 'account', 'activities'],
		];
	}
	public static function users(): array
	{
		return self::paginated(self::user());
	}

	public static function signupResponse(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'user' => ['$ref' => '#/components/schemas/User'],
				'session_token' => ['$ref' => '#/components/schemas/SessionToken'],
			],
			'required' => ['user', 'session_token'],
		];
	}

	public static function loginResponse(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['$ref' => '#/components/schemas/Id'],
				'username' => ['$ref' => '#/components/schemas/Username'],
				'session_token' => ['$ref' => '#/components/schemas/SessionToken'],
			],
			'required' => ['id', 'username', 'session_token'],
		];
	}

	public static function logoutResponse(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'message' => ['type' => 'string', 'example' => 'Logout successful'],
				'session_token' => ['$ref' => '#/components/schemas/SessionToken'],
				'user' => ['$ref' => '#/components/schemas/User'],
			],
			'required' => ['message', 'session_token'],
		];
	}

	public static function emailVerificationSent(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'message' => [
					'type' => 'string',
					'example' => 'Verification email sent to user@example.com',
				],
				'email' => ['$ref' => '#/components/schemas/Email'],
			],
			'required' => ['message'],
		];
	}

	public static function emailVerified(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'message' => [
					'type' => 'string',
					'example' => 'Email verified successfully for user@example.com',
				],
				'email' => ['$ref' => '#/components/schemas/Email'],
			],
			'required' => ['message'],
		];
	}

	public static function emailChangeInitiated(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'message' => [
					'type' => 'string',
					'example' => 'Email change verification sent',
				],
				'new_email' => array_merge(self::$email, [
					'description' => 'The new email address that verification was sent to',
				]),
			],
			'required' => ['message', 'new_email'],
		];
	}

	public static function emailChangeCompleted(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'message' => [
					'type' => 'string',
					'example' => 'Email changed successfully',
				],
				'new_email' => array_merge(self::$email, [
					'description' => 'The new email address that was set',
				]),
				'email_verified' => [
					'type' => 'boolean',
					'example' => false,
					'description' =>
						'Whether the new email is verified (always false after email change)',
				],
			],
			'required' => ['message', 'new_email', 'email_verified'],
		];
	}

	public static function passwordChangeResponse(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'message' => [
					'type' => 'string',
					'example' => 'Password changed successfully',
				],
			],
			'required' => ['message'],
		];
	}

	public static function passwordSetResponse(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'message' => [
					'type' => 'string',
					'example' => 'Password set successfully',
				],
				'has_password' => [
					'type' => 'boolean',
					'example' => true,
					'description' => 'Indicates whether the user now has a password set',
				],
			],
			'required' => ['message', 'has_password'],
		];
	}

	public static function oauthLoginResponse(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'user' => ['$ref' => '#/components/schemas/User'],
				'session_token' => ['$ref' => '#/components/schemas/SessionToken'],
			],
			'required' => ['user', 'session_token'],
		];
	}

	public static function subscriptionResponse(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'message' => [
					'type' => 'string',
					'example' => 'Successfully subscribed to marketing emails',
				],
				'subscribed' => [
					'type' => 'boolean',
					'example' => true,
				],
			],
			'required' => ['message', 'subscribed'],
		];
	}

	public static function notification(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['$ref' => '#/components/schemas/Id'],
				'user_id' => ['$ref' => '#/components/schemas/Id'],
				'type' => ['type' => 'string', 'example' => 'info'],
				'title' => self::text(100, 1, 'Notification Title'),
				'message' => self::text(500, 1, 'You have a new message.'),
				'source' => ['type' => 'string', 'example' => 'system'],
				'created_at' => ['type' => 'integer', 'example' => 1736400000000],
				'link' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
				'read' => ['$ref' => '#/components/schemas/Bool'],
			],
			'required' => ['id', 'type', 'source', 'title', 'message', 'created_at', 'read'],
		];
	}

	public static function notifications(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'unread_count' => ['$ref' => '#/components/schemas/Number'],
				'has_warnings' => ['$ref' => '#/components/schemas/Bool'],
				'has_errors' => ['$ref' => '#/components/schemas/Bool'],
				'items' => [
					'type' => 'array',
					'items' => ['$ref' => '#/components/schemas/Notification'],
				],
			],
		];
	}

	public static function userNotificationCreateJson(): array
	{
		return [
			'$schema' => 'http://json-schema.org/draft-07/schema#',
			'type' => 'object',
			'properties' => [
				'type' => [
					'type' => 'string',
					'example' => 'info',
					'enum' => ['info', 'warning', 'error', 'success'],
					'description' => 'Type of notification (e.g., info, warning, error)',
				],
				'title' => self::text(100, 1, 'Notification Title'),
				'description' => self::text(500, 1, 'You have a new message.'),
				'source' => [
					'type' => 'string',
					'example' => 'system',
					'description' => 'Source of the notification (e.g., system, user)',
				],
				'link' => [
					'type' => 'string',
					'format' => 'uri',
					'nullable' => true,
					'description' => 'Optional link associated with the notification',
				],
			],
			'required' => ['type', 'title', 'description', 'source'],
		];
	}

	public static function friendResponse(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'user' => ['$ref' => '#/components/schemas/User'],
				'friend' => ['$ref' => '#/components/schemas/User'],
				'is_mutual' => [
					'type' => 'boolean',
					'description' =>
						'Indicates if the friendship is mutual (both users have added each other)',
					'example' => true,
				],
			],
			'required' => ['user', 'is_mutual', 'friend'],
		];
	}

	public static function badge(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['type' => 'string', 'example' => 'you_know_ball'],
				'name' => self::text(100),
				'description' => self::text(500),
				'icon' => ['type' => 'string', 'example' => 'mdi:star'],
				'rarity' => [
					'type' => 'string',
					'enum' => ['normal', 'rare', 'amazing', 'green'],
					'description' => 'Rarity level of the badge',
					'example' => 'rare',
				],
				'tracker_id' => [
					'type' => 'string',
					'nullable' => true,
					'description' => 'Optional ID of the tracker associated with this badge',
					'example' => 'activities_added',
				],
			],
			'required' => ['id', 'name', 'description', 'icon', 'rarity'],
		];
	}
	public static function badges(): array
	{
		return [
			'type' => 'array',
			'items' => ['$ref' => '#/components/schemas/Badge'],
		];
	}

	public static function userBadge(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['type' => 'string', 'example' => 'you_know_ball'],
				'name' => self::text(100),
				'description' => self::text(500),
				'icon' => ['type' => 'string', 'example' => 'mdi:star'],
				'rarity' => [
					'type' => 'string',
					'enum' => ['normal', 'rare', 'amazing', 'green'],
					'description' => 'Rarity level of the badge',
					'example' => 'rare',
				],
				'tracker_id' => [
					'type' => 'string',
					'nullable' => true,
					'description' => 'Optional ID of the tracker associated with this badge',
					'example' => 'activities_added',
				],
				'user_id' => ['$ref' => '#/components/schemas/Id'],
				'granted' => ['$ref' => '#/components/schemas/Bool'],
				'granted_at' => ['$ref' => '#/components/schemas/Date'],
				'progress' => [
					'type' => 'number',
					'example' => 0.75,
					'minimum' => 0.0,
					'maximum' => 1.0,
					'description' => 'Progress towards earning the badge (0.0 to 1.0)',
				],
			],
			'required' => [
				'id',
				'name',
				'description',
				'icon',
				'rarity',
				'user_id',
				'granted',
				'progress',
			],
		];
	}
	public static function userBadges(): array
	{
		return [
			'type' => 'array',
			'items' => ['$ref' => '#/components/schemas/UserBadge'],
		];
	}

	public static function event(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['$ref' => '#/components/schemas/Id'],
				'hostId' => ['$ref' => '#/components/schemas/Id'],
				'host' => ['$ref' => '#/components/schemas/User'],
				'name' => self::text(50),
				'description' => self::text(3000),
				'type' => ['$ref' => '#/components/schemas/EventType'],
				'activities' => [
					'type' => 'array',
					'items' => ['$ref' => '#/components/schemas/ActivityType'],
				],
				'location' => [
					'type' => 'object',
					'properties' => [
						'latitude' => ['type' => 'number', 'example' => 37.7749],
						'longitude' => ['type' => 'number', 'example' => -122.4194],
					],
				],
				'date' => ['$ref' => '#/components/schemas/Date'],
				'end_date' => ['$ref' => '#/components/schemas/Date'],
				'visibility' => ['$ref' => '#/components/schemas/Visibility'],
				'attendee_count' => ['$ref' => '#/components/schemas/Number'],
				'is_attending' => ['$ref' => '#/components/schemas/Bool'],
				'created_at' => ['$ref' => '#/components/schemas/Date'],
				'updated_at' => ['$ref' => '#/components/schemas/Date'],
				'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
			],
			'required' => [
				'id',
				'hostId',
				'host',
				'name',
				'description',
				'type',
				'activities',
				'location',
				'date',
			],
		];
	}
	public static function events(): array
	{
		return self::paginated(self::event());
	}
	public static function eventsList(): array
	{
		return ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Event']];
	}
	public static function attendeeResponse(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'user' => ['$ref' => '#/components/schemas/User'],
				'event' => ['$ref' => '#/components/schemas/Event'],
			],
			'required' => ['user', 'event'],
		];
	}

	public static function eventImageSubmission(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'submission_id' => self::$uuidParam,
				'event_id' => ['$ref' => '#/components/schemas/Id'],
				'user_id' => ['$ref' => '#/components/schemas/Id'],
				'image' => ['$ref' => '#/components/schemas/DataUrl'],
				'score' => ['$ref' => '#/components/schemas/EventImageSubmissionScore'],
				'caption' => [
					'type' => 'string',
					'description' => 'Caption generated by the AI for the submitted image',
					'example' => 'A beautiful sunset over the mountains',
				],
				'scored_at' => ['$ref' => '#/components/schemas/Date'],
				'timestamp' => [
					'type' => 'integer',
					'description' => 'Unix timestamp of when the submission was created',
					'example' => 1736400000,
				],
			],
			'required' => ['submission_id', 'event_id', 'user_id'],
			'description' => 'Object representing an image submission for an event',
		];
	}
	public static function eventImageSubmissions(): array
	{
		return self::paginated(self::eventImageSubmission());
	}

	public static function eventImageSubmissionScore(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'score' => [
					'type' => 'number',
					'minimum' => 0,
					'maximum' => 1,
					'description' => 'Score given to the image submission (0 to 1)',
					'example' => 0.75,
				],
				'breakdown' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'id' => [
								'type' => 'string',
								'example' => 'creativity',
								'description' => 'Aspect of the submission being scored',
							],
							'similarity' => [
								'type' => 'number',
								'minimum' => -1,
								'maximum' => 1,
								'description' =>
									'Similarity score for this aspect (-1 to 1), if applicable',
								'example' => 0.8,
							],
							'normalized' => [
								'type' => 'number',
								'minimum' => 0,
								'maximum' => 1,
								'description' =>
									'Normalized score for this aspect (0 to 1), if applicable',
								'example' => 0.9,
							],
							'weighted' => [
								'type' => 'number',
								'minimum' => 0,
								'maximum' => 1,
								'description' =>
									'Weighted score for this aspect (0 to weight), if applicable',
								'example' => 0.15,
							],
						],
						'required' => ['id', 'similarity', 'normalized', 'weighted'],
					],
				],
			],
		];
	}

	public static function eventImageSubmissionData(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'photo_url' => ['$ref' => '#/components/schemas/DataUrl'],
			],
			'required' => ['photo_url'],
			'description' =>
				'Object representing the data required to submit an image for an event',
		];
	}

	public static function eventImageSubmissionResponse(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'message' => [
					'type' => 'string',
					'example' => 'Image submitted successfully',
				],
				'event_id' => ['$ref' => '#/components/schemas/Id'],
				'user_id' => ['$ref' => '#/components/schemas/Id'],
				'submission_id' => self::$uuidParam,
				'photo_url' => ['$ref' => '#/components/schemas/DataUrl'],
			],
			'required' => ['message'],
		];
	}

	public static function activity(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['type' => 'string', 'example' => 'hiking'],
				'name' => ['$ref' => '#/components/schemas/Text'],
				'description' => ['$ref' => '#/components/schemas/Text'],
				'types' => [
					'type' => 'array',
					'items' => ['$ref' => '#/components/schemas/ActivityType'],
				],
				'created_at' => ['$ref' => '#/components/schemas/Date'],
				'updated_at' => ['$ref' => '#/components/schemas/Date'],
				'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
			],
			'required' => ['id', 'name', 'types', 'created_at', 'fields'],
		];
	}
	public static function activities(): array
	{
		return self::paginated(self::activity());
	}
	public static function activitiesList(): array
	{
		return ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Activity']];
	}
	public static function activitiesJson(): array
	{
		return [
			'$schema' => 'http://json-schema.org/draft-07/schema#',
			'type' => 'object',
			'properties' => [
				'id' => ['type' => 'string'],
				'name' => ['type' => 'string'],
				'description' => ['type' => 'string'],
				'types' => ['type' => 'array', 'items' => ['type' => 'string']],
				'created_at' => ['type' => 'string', 'format' => 'date-time'],
				'updated_at' => ['type' => 'string', 'format' => 'date-time'],
				'fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
			],
			'required' => ['id', 'name', 'types', 'created_at', 'fields'],
		];
	}
	public static function activitiesIds(): array
	{
		return self::paginated([
			'type' => 'array',
			'items' => [
				'type' => 'string',
				'examples' => ['hiking', 'swimming', 'reading', 'coding', 'snowboarding'],
			],
		]);
	}
	public static function activitiesIdList(): array
	{
		return [
			'$schema' => 'http://json-schema.org/draft-07/schema#',
			'type' => 'array',
			'items' => [
				'type' => 'string',
				'examples' => ['hiking', 'swimming', 'reading', 'coding', 'snowboarding'],
			],
		];
	}

	public static function prompt(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['$ref' => '#/components/schemas/Id'],
				'prompt' => ['$ref' => '#/components/schemas/Text'],
				'visibility' => ['$ref' => '#/components/schemas/UserPrivacy'],
				'responses_count' => ['$ref' => '#/components/schemas/Number'],
				'owner_id' => ['$ref' => '#/components/schemas/Id'],
				'owner' => ['$ref' => '#/components/schemas/User'],
				'created_at' => ['$ref' => '#/components/schemas/Date'],
				'updated_at' => ['$ref' => '#/components/schemas/Date'],
			],
			'required' => ['id', 'prompt', 'visibility', 'created_at', 'responses_count', 'owner'],
		];
	}
	public static function prompts(): array
	{
		return self::paginated(self::prompt());
	}
	public static function promptsList(): array
	{
		return ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Prompt']];
	}

	public static function promptResponse(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['$ref' => '#/components/schemas/Id'],
				'prompt_id' => ['$ref' => '#/components/schemas/Id'],
				'response' => ['type' => 'string', 'example' => 'Hello World', 'maxLength' => 700],
				'owner' => ['$ref' => '#/components/schemas/User'],
				'created_at' => ['$ref' => '#/components/schemas/Date'],
				'updated_at' => ['$ref' => '#/components/schemas/Date'],
			],
			'required' => ['id', 'prompt_id', 'response', 'created_at'],
		];
	}
	public static function promptResponses(): array
	{
		return self::paginated(self::promptResponse());
	}

	public static function promptResponsesCount(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'prompt' => ['$ref' => '#/components/schemas/Prompt'],
				'count' => ['type' => 'integer', 'example' => 42],
			],
			'required' => ['prompt', 'count'],
		];
	}

	public static function article(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['$ref' => '#/components/schemas/Id'],
				'title' => ['type' => 'string', 'example' => 'Hello World', 'maxLength' => 100],
				'description' => [
					'type' => 'string',
					'example' => 'Hello World',
					'maxLength' => 512,
				],
				'tags' => [
					'type' => 'array',
					'items' => ['type' => 'string', 'example' => 'Hello World', 'maxLength' => 30],
					'maxItems' => 10,
					'example' => ['tag1', 'tag2', 'tag3'],
					'default' => [],
				],
				'content' => [
					'type' => 'string',
					'example' => 'Hello World',
					'minLength' => 50,
					'maxLength' => 10000,
				],
				'color' => ['$ref' => '#/components/schemas/Number'],
				'color_hex' => ['$ref' => '#/components/schemas/HexCode'],
				'author_id' => ['$ref' => '#/components/schemas/Id'],
				'author' => ['$ref' => '#/components/schemas/User'],
				'can_edit' => [
					'type' => 'boolean',
					'description' => 'Indicates if the requesting user can edit this article',
					'example' => true,
				],
				'created_at' => ['$ref' => '#/components/schemas/Date'],
				'updated_at' => ['$ref' => '#/components/schemas/Date'],
				'ocean' => ['$ref' => '#/components/schemas/OceanArticle'],
			],
			'required' => [
				'id',
				'title',
				'description',
				'tags',
				'content',
				'created_at',
				'author_id',
			],
		];
	}
	public static function articles(): array
	{
		return self::paginated(self::article());
	}
	public static function articlesList(): array
	{
		return ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Article']];
	}

	public static function oceanArticle(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'title' => self::$text,
				'url' => ['type' => 'string', 'format' => 'uri'],
				'author' => self::$text,
				'source' => self::$text,
				'links' => [
					'type' => 'object',
					'additionalProperties' => ['type' => 'string', 'format' => 'uri'],
				],
				'abstract' => self::text(
					10000,
					50,
					'The phrase "Hello World" is commonly used in programming as a simple test message to demonstrate the basic syntax of a programming language or to verify that a system is functioning correctly. It is often the first program written by beginners when learning a new programming language.',
				),
				'content' => self::text(
					10000,
					50,
					'Hello World first appeared in 1972 when Brian Kernighan wrote it as an example in a tutorial for the B programming language. The tradition continued with the C programming language, where it was used in the book "The C Programming Language" by Brian Kernighan and Dennis Ritchie. Since then, "Hello World" has become a ubiquitous example in programming literature and tutorials across many languages. It symbolizes the starting point for learning a new programming language or environment.',
				),
				'theme_color' => self::$hexCode,
				'keywords' => [
					'type' => 'array',
					'items' => self::text(35),
					'example' => ['keyword1', 'keyword2'],
					'maxItems' => 25,
				],
				'date' => self::$date,
				'favicon' => ['type' => 'string', 'format' => 'uri'],
			],
			'required' => ['title', 'url', 'author', 'source', 'content', 'date'],
		];
	}

	public static function articleCreate(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string', 'example' => 'Hello World', 'maxLength' => 100],
				'description' => [
					'type' => 'string',
					'example' => 'Hello World',
					'maxLength' => 512,
				],
				'tags' => [
					'type' => 'array',
					'items' => ['type' => 'string', 'example' => 'Hello World', 'maxLength' => 30],
					'maxItems' => 10,
					'example' => ['tag1', 'tag2', 'tag3'],
					'default' => [],
				],
				'content' => [
					'type' => 'string',
					'example' =>
						'The phrase "Hello World" is commonly used in programming as a simple test message to demonstrate the basic syntax of a programming language or to verify that a system is functioning correctly. It is often the first program written by beginners when learning a new programming language.',
					'minLength' => 50,
					'maxLength' => 10000,
				],
				'color' => self::$hexCode,
				'ocean' => self::oceanArticle(),
			],
			'required' => ['title', 'description', 'content'],
		];
	}

	public static function articleUpdate(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string', 'example' => 'Hello World', 'maxLength' => 100],
				'description' => [
					'type' => 'string',
					'example' => 'Hello World',
					'maxLength' => 512,
				],
				'tags' => [
					'type' => 'array',
					'items' => ['type' => 'string', 'example' => 'Hello World', 'maxLength' => 30],
					'maxItems' => 10,
					'example' => ['tag1', 'tag2', 'tag3'],
					'default' => [],
				],
				'content' => [
					'type' => 'string',
					'example' =>
						'The phrase "Hello World" is commonly used in programming as a simple test message to demonstrate the basic syntax of a programming language or to verify that a system is functioning correctly. It is often the first program written by beginners when learning a new programming language.',
					'minLength' => 50,
					'maxLength' => 10000,
				],
				'ocean' => self::oceanArticle(),
				'color' => self::$hexCode,
			],
		];
	}

	public static function articleQuiz(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'questions' => [
					'type' => 'array',
					'items' => ['$ref' => '#/components/schemas/ArticleQuizQuestion'],
				],
				'summary' => [
					'type' => 'object',
					'properties' => [
						'total' => [
							'type' => 'integer',
							'example' => 5,
							'description' => 'Total number of quiz questions',
						],
						'multiple_choice_count' => [
							'type' => 'integer',
							'example' => 3,
							'description' => 'Number of multiple choice questions',
						],
						'true_false_count' => [
							'type' => 'integer',
							'example' => 2,
							'description' => 'Number of true/false questions',
						],
					],
					'required' => ['total', 'multiple_choice_count', 'true_false_count'],
				],
			],
			'required' => ['questions', 'summary'],
		];
	}

	public static function articleQuizQuestion(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'question' => self::text(150, 1, 'What is the capital of France?'),
				'type' => [
					'type' => 'string',
					'enum' => ['true_false', 'multiple_choice'],
					'description' => 'Type of quiz question',
					'example' => 'multiple_choice',
				],
				'options' => [
					'type' => 'array',
					'items' => self::text(100),
					'example' => ['Paris', 'London', 'Berlin', 'Madrid'],
					'description' =>
						'Answer options for multiple choice questions. Not required for true/false questions.',
				],
				'correct_answer' => self::text(
					150,
					1,
					'Paris',
					'The correct answer to the quiz question',
				),
				'correct_answer_index' => [
					'type' => 'integer',
					'example' => 0,
					'description' =>
						'Index of the correct answer in the options array for multiple choice questions. Not required for true/false questions.',
				],
				'is_true' => self::$bool,
				'is_false' => self::$bool,
			],
			'required' => ['question', 'type', 'options', 'correct_answer', 'correct_answer_index'],
		];
	}

	/**
	 * Get all schemas for OpenAPI components/schemas section.
	 * This method returns all reusable schema definitions that can be
	 * referenced via $ref in the OpenAPI spec.
	 */
	public static function getAllSchemas(): array
	{
		return [
			// Error schemas
			'ErrorResponse' => self::$errorResponse,
			'ConflictError' => self::$conflictError,
			'RateLimitError' => self::$rateLimitError,

			// Core types
			'Info' => self::$info,
			'Text' => self::$text,
			'Number' => self::$number,
			'Id' => self::$id,
			'Username' => self::$username,
			'Password' => self::$password,
			'Email' => self::$email,
			'Date' => self::$date,
			'HexCode' => self::$hexCode,
			'Bool' => self::$bool,
			'SessionToken' => self::$sessionToken,
			'Name' => self::$name,
			'DataUrl' => self::$dataUrl,

			// Parameters
			'UsernameParam' => self::$usernameParam,
			'IdParam' => self::$idParam,
			'UuidParam' => self::$uuidParam,

			// Enums
			'ActivityType' => self::activityType(),
			'Visibility' => self::visibility(),
			'UserPrivacy' => self::userPrivacy(),
			'EventType' => self::eventType(),
			'SortOrder' => self::sortOrder(),

			// Arrays
			'StringArray' => self::$stringArray,
			'IdArray' => self::idArray(),

			// Request bodies
			'UserCreate' => self::userCreate(),
			'UserUpdate' => self::userUpdate(),
			'UserUpdateJson' => self::userUpdateJson(),
			'UserFieldPrivacy' => self::userFieldPrivacy(),
			'UserFieldPrivacyJson' => self::userFieldPrivacyJson(),
			'EventCreate' => self::eventCreate(),
			'EventUpdate' => self::eventUpdate(),
			'PromptCreate' => self::promptCreate(),
			'PromptResponseBody' => self::$promptResponseBody,
			'PasswordResetBody' => self::$passwordResetBody,
			'PasswordChangeBody' => self::$passwordChangeBody,
			'PasswordChangeFlexibleBody' => self::$passwordChangeFlexibleBody,
			'PasswordSetBody' => self::$passwordSetBody,
			'OAuthLoginBody' => self::$oauthLoginBody,
			'ArticleBody' => self::$articleBody,
			'ActivityCreate' => self::activityCreate(),
			'ActivityUpdate' => self::activityUpdate(),
			'UserActivitiesSet' => self::$userActivitiesSet,

			// Response objects
			'User' => self::user(),
			'SignupResponse' => self::signupResponse(),
			'LoginResponse' => self::loginResponse(),
			'LogoutResponse' => self::logoutResponse(),
			'EmailVerificationSent' => self::emailVerificationSent(),
			'EmailVerified' => self::emailVerified(),
			'EmailChangeInitiated' => self::emailChangeInitiated(),
			'EmailChangeCompleted' => self::emailChangeCompleted(),
			'PasswordChangeResponse' => self::passwordChangeResponse(),
			'PasswordSetResponse' => self::passwordSetResponse(),
			'OAuthLoginResponse' => self::oauthLoginResponse(),
			'SubscriptionResponse' => self::subscriptionResponse(),
			'Notification' => self::notification(),
			'FriendResponse' => self::friendResponse(),
			'Badge' => self::badge(),
			'UserBadge' => self::userBadge(),
			'Event' => self::event(),
			'EventImageSubmission' => self::eventImageSubmission(),
			'EventImageSubmissionScore' => self::eventImageSubmissionScore(),
			'AttendeeResponse' => self::attendeeResponse(),
			'Activity' => self::activity(),
			'ActivitiesJson' => self::activitiesJson(),
			'Prompt' => self::prompt(),
			'PromptResponse' => self::promptResponse(),
			'PromptResponsesCount' => self::promptResponsesCount(),
			'Article' => self::article(),
			'OceanArticle' => self::oceanArticle(),
			'ArticleCreate' => self::articleCreate(),
			'ArticleUpdate' => self::articleUpdate(),
			'ArticleQuiz' => self::articleQuiz(),
			'ArticleQuizQuestion' => self::articleQuizQuestion(),
		];
	}
}
