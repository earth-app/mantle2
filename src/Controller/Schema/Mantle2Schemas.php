<?php /** @noinspection PhpUnused */

namespace Drupal\mantle2\Controller\Schema;

use Drupal\mantle2\Custom\ActivityType;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Custom\EventType;
use Drupal\mantle2\Custom\Privacy;

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
							'error' => [
								'type' => 'string',
								'example' => $description,
							],
							'code' => [
								'type' => 'number',
								'example' => 409,
							],
						],
						'required' => ['error', 'code'],
					],
				],
			],
		];
	}

	// Root Types (schemas)

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
	];

	public static array $paginatedParams = [
		'type' => 'object',
		'properties' => [
			'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
			'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 250, 'default' => 25],
			'search' => ['type' => 'string', 'maxLength' => 40, 'default' => ''],
		],
		'required' => ['page', 'limit', 'search'],
	];

	// String Types
	public static array $text = ['type' => 'string', 'example' => 'Hello World'];
	public static function text($maxLength = 100, $minLength = 1): array
	{
		return [
			'type' => 'string',
			'minLength' => $minLength,
			'maxLength' => $maxLength,
			'example' => str_repeat('a', mt_rand($minLength, $maxLength)),
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
		'pattern' => "^[a-zA-Z0-9!@#$%^&*()_+={}\[\]:;\"'<>.,?\/\\|-]+$",
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
		'example' => '#ffd700',
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
			'properties' => [
				'username' => self::$username,
				'password' => self::$password,
				'email' => self::$email,
				'first_name' => self::text(50),
				'last_name' => self::text(50),
			],
			'required' => ['username', 'password'],
		];
	}

	public static function userUpdate(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'username' => self::$username,
				'email' => self::$email,
				'first_name' => self::text(50),
				'last_name' => self::text(50),
				'address' => self::text(),
				'bio' => self::text(500),
				'country' => self::text(2),
				'phone_number' => self::$number,
				'visibility' => self::visibility(),
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
				'abstract' => self::$text,
				'content' => self::$text,
				'theme_color' => ['type' => 'string'],
				'keywords' => ['type' => 'array', 'items' => self::$text],
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
				'title' => ['type' => 'string', 'example' => 'Hello World', 'maxLength' => 48],
				'description' => [
					'type' => 'string',
					'example' => 'Hello World',
					'maxLength' => 512,
				],
				'tags' => [
					'type' => 'array',
					'items' => ['type' => 'string', 'example' => 'Hello World', 'maxLength' => 30],
					'maxItems' => 10,
					'default' => [],
				],
				'content' => [
					'type' => 'string',
					'example' => 'Hello World',
					'minLength' => 50,
					'maxLength' => 10000,
				],
				'color' => self::$hexCode,
				'ocean' => self::oceanArticle(),
			],
			'required' => ['title', 'description', 'tags', 'content'],
		];
	}

	public static function articleUpdate(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'title' => ['type' => 'string', 'example' => 'Hello World', 'maxLength' => 48],
				'description' => [
					'type' => 'string',
					'example' => 'Hello World',
					'maxLength' => 512,
				],
				'tags' => [
					'type' => 'array',
					'items' => ['type' => 'string', 'example' => 'Hello World', 'maxLength' => 30],
					'maxItems' => 10,
					'default' => [],
				],
				'content' => [
					'type' => 'string',
					'example' => 'Hello World',
					'minLength' => 50,
					'maxLength' => 10000,
				],
				'color' => self::$hexCode,
			],
		];
	}

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
						'field_privacy' => self::userFieldPrivacy(),
					],
				],
				'activities' => self::activitiesList(),
				'friends' => self::idArray(),
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
				'created_at' => self::$date,
				'updated_at' => self::$date,
			],
			'required' => ['id', 'prompt', 'visibility', 'created_at'],
		];
	}
	public static function prompts(): array
	{
		return self::paginated(self::prompt());
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
				'article_id' => ['type' => 'string', 'example' => 'article123'],
				'title' => ['type' => 'string', 'example' => 'Hello World', 'maxLength' => 48],
				'summary' => ['type' => 'string', 'example' => 'Hello World', 'maxLength' => 512],
				'tags' => [
					'type' => 'array',
					'items' => ['type' => 'string', 'example' => 'Hello World', 'maxLength' => 30],
					'maxItems' => 10,
					'default' => [],
				],
				'content' => [
					'type' => 'string',
					'example' => 'Hello World',
					'minLength' => 50,
					'maxLength' => 10000,
				],
				'created_at' => self::$date,
				'updated_at' => self::$date,
				'ocean' => self::oceanArticle(),
			],
			'required' => ['id', 'article_id', 'title', 'summary', 'tags', 'content', 'created_at'],
		];
	}
}
