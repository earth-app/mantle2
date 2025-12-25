<?php /** @noinspection PhpUnused */

namespace Drupal\mantle2\Controller\Schema;

use Drupal\mantle2\Custom\ActivityType;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Custom\EventType;
use Drupal\mantle2\Custom\Privacy;
use Drupal\mantle2\Service\OAuthHelper;

class Mantle2Schemas
{
	// Error Responses

	public static function E400($description = 'Bad request'): array
	{
		return [
			'description' => $description,
			'content' => [
				'application/json' => [
					'schema' => [
						'type' => 'object',
						'properties' => [
							'error' => [
								'type' => 'string',
								'example' => $description,
							],
							'code' => [
								'type' => 'number',
								'example' => 400,
							],
						],
						'required' => ['error', 'code'],
					],
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
					'schema' => [
						'type' => 'object',
						'properties' => [
							'error' => [
								'type' => 'string',
								'example' => $description,
							],
							'code' => [
								'type' => 'number',
								'example' => 401,
							],
						],
						'required' => ['error', 'code'],
					],
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
					'schema' => [
						'type' => 'object',
						'properties' => [
							'error' => [
								'type' => 'string',
								'example' => $description,
							],
							'code' => [
								'type' => 'number',
								'example' => 402,
							],
						],
						'required' => ['error', 'code'],
					],
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
					'schema' => [
						'type' => 'object',
						'properties' => [
							'error' => [
								'type' => 'string',
								'example' => $description,
							],
							'code' => [
								'type' => 'number',
								'example' => 403,
							],
						],
						'required' => ['error', 'code'],
					],
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
					'schema' => [
						'type' => 'object',
						'properties' => [
							'error' => [
								'type' => 'string',
								'example' => $description,
							],
							'code' => [
								'type' => 'number',
								'example' => 404,
							],
						],
						'required' => ['error', 'code'],
					],
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
					'schema' => [
						'type' => 'object',
						'properties' => [
							'error' => ['type' => 'string', 'example' => 'Conflict'],
							'message' => ['type' => 'string', 'example' => 'Duplicate entry found'],
						],
						'required' => ['error', 'message'],
					],
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
					'schema' => [
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
					],
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
	public static function text($maxLength = 100, $minLength = 1, $example = 'Hello World'): array
	{
		return [
			'type' => 'string',
			'minLength' => $minLength,
			'maxLength' => $maxLength,
			'example' => $example,
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
			],
		];
	}

	public static function eventCreate(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'name' => self::text(50),
				'description' => self::text(3000),
				'type' => self::eventType(),
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
			],
			'required' => ['name', 'type', 'date', 'visibility'],
		];
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
				'date' => self::$date,
				'endDate' => self::$date,
				'visibility' => self::visibility(),
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
	public static function user(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => self::$number,
				'username' => self::$username,
				'created_at' => self::$date,
				'updated_at' => self::$date,
				'last_login' => self::$date,
				'account' => [
					'type' => 'object',
					'properties' => [
						'id' => self::$id,
						'username' => self::$username,
						'email' => self::$email,
						'first_name' => self::$name,
						'last_name' => self::$name,
						'address' => self::text(),
						'bio' => self::text(500),
						'country' => ['type' => 'string'],
						'phone_number' => ['type' => 'integer'],
						'visibility' => self::visibility(),
						'email_verified' => self::$bool,
						'subscribed' => self::$bool,
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
						'field_privacy' => self::userFieldPrivacy(),
					],
				],
				'activities' => self::activitiesList(),
				'friends' => self::idArray(),
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
			'properties' => ['user' => self::user(), 'session_token' => self::$sessionToken],
			'required' => ['user', 'session_token'],
		];
	}

	public static function loginResponse(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => self::$id,
				'username' => self::$username,
				'session_token' => self::$sessionToken,
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
				'session_token' => self::$sessionToken,
				'user' => self::user(),
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
				'email' => self::$email,
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
				'email' => self::$email,
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
				'user' => self::user(),
				'session_token' => self::$sessionToken,
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
				'id' => self::$id,
				'user_id' => self::$id,
				'type' => ['type' => 'string', 'example' => 'info'],
				'title' => self::text(100, 1, 'Notification Title'),
				'message' => self::text(500, 1, 'You have a new message.'),
				'source' => ['type' => 'string', 'example' => 'system'],
				'created_at' => ['type' => 'integer', 'example' => 1736400000000],
				'link' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
				'read' => self::$bool,
			],
			'required' => ['id', 'type', 'source', 'title', 'message', 'created_at', 'read'],
		];
	}

	public static function notifications(): array
	{
		return self::paginated([
			'type' => 'object',
			'properties' => [
				'unread_count' => self::$number,
				'has_warnings' => self::$bool,
				'has_errors' => self::$bool,
				'items' => [
					'type' => 'array',
					'items' => self::notification(),
				],
			],
		]);
	}

	public static function event(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => self::$id,
				'hostId' => self::$id,
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
				'date' => self::$date,
				'end_date' => self::$date,
				'visibility' => self::visibility(),
			],
			'required' => [
				'id',
				'hostId',
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
	public static function attendeeResponse(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'user' => self::user(),
				'event' => self::event(),
			],
			'required' => ['user', 'event'],
		];
	}

	public static function activity(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => ['type' => 'string', 'example' => 'hiking'],
				'name' => self::$text,
				'description' => self::$text,
				'types' => ['type' => 'array', 'items' => self::activityType()],
				'created_at' => self::$date,
				'updated_at' => self::$date,
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
		return ['type' => 'array', 'items' => self::activity()];
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
				'id' => self::$id,
				'prompt' => self::$text,
				'visibility' => self::userPrivacy(),
				'responses_count' => self::$number,
				'owner_id' => self::$id,
				'owner' => self::user(),
				'created_at' => self::$date,
				'updated_at' => self::$date,
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
		return ['type' => 'array', 'items' => self::prompt()];
	}

	public static function promptResponse(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'id' => self::$id,
				'prompt_id' => self::$id,
				'response' => ['type' => 'string', 'example' => 'Hello World', 'maxLength' => 700],
				'owner' => self::user(),
				'created_at' => self::$date,
				'updated_at' => self::$date,
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
				'prompt' => self::prompt(),
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
				'id' => self::$id,
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
				'color' => self::$number,
				'color_hex' => self::$hexCode,
				'author_id' => self::$id,
				'author' => self::user(),
				'created_at' => self::$date,
				'updated_at' => self::$date,
				'ocean' => self::oceanArticle(),
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
		return ['type' => 'array', 'items' => self::article()];
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
}
