<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\ApiKeyScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ApiKeyScopeTest extends TestCase
{
	public static function constantProvider(): array
	{
		return [
			'user:read' => [ApiKeyScope::USER_READ, 'user:read'],
			'user:read:profile' => [ApiKeyScope::USER_READ_PROFILE, 'user:read:profile'],
			'user:read:email' => [ApiKeyScope::USER_READ_EMAIL, 'user:read:email'],
			'user:read:private' => [ApiKeyScope::USER_READ_PRIVATE, 'user:read:private'],
			'user:read:oauth' => [ApiKeyScope::USER_READ_OAUTH, 'user:read:oauth'],
			'user:edit' => [ApiKeyScope::USER_EDIT, 'user:edit'],
			'user:edit:bio' => [ApiKeyScope::USER_EDIT_BIO, 'user:edit:bio'],
			'user:edit:name' => [ApiKeyScope::USER_EDIT_NAME, 'user:edit:name'],
			'user:edit:email' => [ApiKeyScope::USER_EDIT_EMAIL, 'user:edit:email'],
			'user:edit:privacy' => [ApiKeyScope::USER_EDIT_PRIVACY, 'user:edit:privacy'],
			'user:edit:visibility' => [ApiKeyScope::USER_EDIT_VISIBILITY, 'user:edit:visibility'],
			'user:edit:photo' => [ApiKeyScope::USER_EDIT_PHOTO, 'user:edit:photo'],
			'user:edit:cosmetic' => [ApiKeyScope::USER_EDIT_COSMETIC, 'user:edit:cosmetic'],
			'user:edit:subscription' => [
				ApiKeyScope::USER_EDIT_SUBSCRIPTION,
				'user:edit:subscription',
			],
			'users:read' => [ApiKeyScope::USERS_READ, 'users:read'],
			'users:read:list' => [ApiKeyScope::USERS_READ_LIST, 'users:read:list'],
			'users:read:profile' => [ApiKeyScope::USERS_READ_PROFILE, 'users:read:profile'],
			'users:read:photo' => [ApiKeyScope::USERS_READ_PHOTO, 'users:read:photo'],
			'friends:read' => [ApiKeyScope::FRIENDS_READ, 'friends:read'],
			'friends:write' => [ApiKeyScope::FRIENDS_WRITE, 'friends:write'],
			'friends:write:add' => [ApiKeyScope::FRIENDS_WRITE_ADD, 'friends:write:add'],
			'friends:write:remove' => [ApiKeyScope::FRIENDS_WRITE_REMOVE, 'friends:write:remove'],
			'circle:read' => [ApiKeyScope::CIRCLE_READ, 'circle:read'],
			'circle:write' => [ApiKeyScope::CIRCLE_WRITE, 'circle:write'],
			'circle:write:add' => [ApiKeyScope::CIRCLE_WRITE_ADD, 'circle:write:add'],
			'circle:write:remove' => [ApiKeyScope::CIRCLE_WRITE_REMOVE, 'circle:write:remove'],
			'activities:read' => [ApiKeyScope::ACTIVITIES_READ, 'activities:read'],
			'activities:write' => [ApiKeyScope::ACTIVITIES_WRITE, 'activities:write'],
			'activities:write:self' => [
				ApiKeyScope::ACTIVITIES_WRITE_SELF,
				'activities:write:self',
			],
			'activities:write:catalog' => [
				ApiKeyScope::ACTIVITIES_WRITE_CATALOG,
				'activities:write:catalog',
			],
			'events:read' => [ApiKeyScope::EVENTS_READ, 'events:read'],
			'events:write' => [ApiKeyScope::EVENTS_WRITE, 'events:write'],
			'events:write:create' => [ApiKeyScope::EVENTS_WRITE_CREATE, 'events:write:create'],
			'events:write:update' => [ApiKeyScope::EVENTS_WRITE_UPDATE, 'events:write:update'],
			'events:write:delete' => [ApiKeyScope::EVENTS_WRITE_DELETE, 'events:write:delete'],
			'events:write:rsvp' => [ApiKeyScope::EVENTS_WRITE_RSVP, 'events:write:rsvp'],
			'events:write:images' => [ApiKeyScope::EVENTS_WRITE_IMAGES, 'events:write:images'],
			'prompts:read' => [ApiKeyScope::PROMPTS_READ, 'prompts:read'],
			'prompts:write' => [ApiKeyScope::PROMPTS_WRITE, 'prompts:write'],
			'prompts:write:create' => [ApiKeyScope::PROMPTS_WRITE_CREATE, 'prompts:write:create'],
			'prompts:write:update' => [ApiKeyScope::PROMPTS_WRITE_UPDATE, 'prompts:write:update'],
			'prompts:write:delete' => [ApiKeyScope::PROMPTS_WRITE_DELETE, 'prompts:write:delete'],
			'prompts:write:respond' => [
				ApiKeyScope::PROMPTS_WRITE_RESPOND,
				'prompts:write:respond',
			],
			'articles:read' => [ApiKeyScope::ARTICLES_READ, 'articles:read'],
			'articles:write' => [ApiKeyScope::ARTICLES_WRITE, 'articles:write'],
			'articles:write:create' => [
				ApiKeyScope::ARTICLES_WRITE_CREATE,
				'articles:write:create',
			],
			'articles:write:update' => [
				ApiKeyScope::ARTICLES_WRITE_UPDATE,
				'articles:write:update',
			],
			'articles:write:delete' => [
				ApiKeyScope::ARTICLES_WRITE_DELETE,
				'articles:write:delete',
			],
			'articles:write:quiz' => [ApiKeyScope::ARTICLES_WRITE_QUIZ, 'articles:write:quiz'],
			'quests:read' => [ApiKeyScope::QUESTS_READ, 'quests:read'],
			'quests:write' => [ApiKeyScope::QUESTS_WRITE, 'quests:write'],
			'badges:read' => [ApiKeyScope::BADGES_READ, 'badges:read'],
			'badges:write:mastery' => [ApiKeyScope::BADGES_WRITE_MASTERY, 'badges:write:mastery'],
			'points:read' => [ApiKeyScope::POINTS_READ, 'points:read'],
			'cosmetics:read' => [ApiKeyScope::COSMETICS_READ, 'cosmetics:read'],
			'notifications:read' => [ApiKeyScope::NOTIFICATIONS_READ, 'notifications:read'],
			'notifications:write' => [ApiKeyScope::NOTIFICATIONS_WRITE, 'notifications:write'],
		];
	}

	#[Test]
	#[TestDox('Constant $_dataName holds its colon-delimited scope string')]
	#[Group('mantle2/custom')]
	#[DataProvider('constantProvider')]
	public function testConstants(string $constant, string $expected): void
	{
		$this->assertSame($expected, $constant);
	}

	#[Test]
	#[TestDox('hierarchy is a non-empty tree of description nodes with optional children')]
	#[Group('mantle2/custom')]
	public function testHierarchyShape(): void
	{
		$tree = ApiKeyScope::hierarchy();
		$this->assertNotEmpty($tree);

		$walk = function (array $nodes) use (&$walk): void {
			foreach ($nodes as $name => $node) {
				$this->assertIsString($name);
				$this->assertArrayHasKey('description', $node);
				$this->assertIsString($node['description']);
				$this->assertNotSame('', $node['description']);
				if (isset($node['children'])) {
					$this->assertIsArray($node['children']);
					$this->assertNotEmpty($node['children']);
					$walk($node['children']);
				}
			}
		};
		$walk($tree);
	}

	#[Test]
	#[TestDox('hierarchy roots are exactly the top-level scope families')]
	#[Group('mantle2/custom')]
	public function testHierarchyRoots(): void
	{
		$roots = array_keys(ApiKeyScope::hierarchy());
		$this->assertSame(
			[
				ApiKeyScope::USER_READ,
				ApiKeyScope::USER_EDIT,
				ApiKeyScope::USERS_READ,
				ApiKeyScope::FRIENDS_READ,
				ApiKeyScope::FRIENDS_WRITE,
				ApiKeyScope::CIRCLE_READ,
				ApiKeyScope::CIRCLE_WRITE,
				ApiKeyScope::ACTIVITIES_READ,
				ApiKeyScope::ACTIVITIES_WRITE,
				ApiKeyScope::EVENTS_READ,
				ApiKeyScope::EVENTS_WRITE,
				ApiKeyScope::PROMPTS_READ,
				ApiKeyScope::PROMPTS_WRITE,
				ApiKeyScope::ARTICLES_READ,
				ApiKeyScope::ARTICLES_WRITE,
				ApiKeyScope::QUESTS_READ,
				ApiKeyScope::QUESTS_WRITE,
				ApiKeyScope::BADGES_READ,
				ApiKeyScope::BADGES_WRITE_MASTERY,
				ApiKeyScope::POINTS_READ,
				ApiKeyScope::COSMETICS_READ,
				ApiKeyScope::NOTIFICATIONS_READ,
				ApiKeyScope::NOTIFICATIONS_WRITE,
			],
			$roots,
		);
	}

	#[Test]
	#[TestDox('all() flattens every parent and child scope in pre-order')]
	#[Group('mantle2/custom')]
	public function testAll(): void
	{
		$all = ApiKeyScope::all();

		$this->assertContains(ApiKeyScope::USER_READ, $all);
		$this->assertContains(ApiKeyScope::USER_READ_PROFILE, $all);
		$this->assertContains(ApiKeyScope::EVENTS_WRITE_IMAGES, $all);
		$this->assertContains(ApiKeyScope::NOTIFICATIONS_WRITE, $all);

		// parents precede their children (pre-order walk)
		$this->assertLessThan(
			array_search(ApiKeyScope::USER_READ_PROFILE, $all, true),
			array_search(ApiKeyScope::USER_READ, $all, true),
		);

		// no duplicates
		$this->assertSame(array_values(array_unique($all)), $all);

		// count is stable: every hierarchy node appears once
		$count = 0;
		$walk = function (array $nodes) use (&$walk, &$count): void {
			foreach ($nodes as $node) {
				$count++;
				if (!empty($node['children'])) {
					$walk($node['children']);
				}
			}
		};
		$walk(ApiKeyScope::hierarchy());
		$this->assertCount($count, $all);
	}

	#[Test]
	#[TestDox('leaves() returns only childless scopes and excludes parents')]
	#[Group('mantle2/custom')]
	public function testLeaves(): void
	{
		$leaves = ApiKeyScope::leaves();

		// USER_READ has children so is not a leaf; its children are
		$this->assertNotContains(ApiKeyScope::USER_READ, $leaves);
		$this->assertContains(ApiKeyScope::USER_READ_PROFILE, $leaves);
		$this->assertNotContains(ApiKeyScope::USER_EDIT, $leaves);
		$this->assertContains(ApiKeyScope::USER_EDIT_EMAIL, $leaves);

		// childless roots are themselves leaves
		$this->assertContains(ApiKeyScope::FRIENDS_READ, $leaves);
		$this->assertContains(ApiKeyScope::QUESTS_READ, $leaves);
		$this->assertContains(ApiKeyScope::QUESTS_WRITE, $leaves);
		$this->assertContains(ApiKeyScope::BADGES_WRITE_MASTERY, $leaves);

		// every leaf is a valid scope with no children in the tree
		foreach ($leaves as $leaf) {
			$this->assertTrue(ApiKeyScope::isValid($leaf));
		}

		// leaves is a strict subset of all
		$this->assertEmpty(array_diff($leaves, ApiKeyScope::all()));
		$this->assertLessThan(count(ApiKeyScope::all()), count($leaves));
	}

	public static function expandProvider(): array
	{
		return [
			'parent expands to its leaf children' => [
				[ApiKeyScope::USER_READ],
				[
					ApiKeyScope::USER_READ_EMAIL,
					ApiKeyScope::USER_READ_OAUTH,
					ApiKeyScope::USER_READ_PRIVATE,
					ApiKeyScope::USER_READ_PROFILE,
				],
			],
			'leaf scope expands to itself' => [
				[ApiKeyScope::USER_READ_EMAIL],
				[ApiKeyScope::USER_READ_EMAIL],
			],
			'childless root expands to itself' => [
				[ApiKeyScope::FRIENDS_READ],
				[ApiKeyScope::FRIENDS_READ],
			],
			'unknown scope is dropped silently' => [['not:a:scope'], []],
			'result is sorted and unique' => [
				[ApiKeyScope::FRIENDS_WRITE, ApiKeyScope::FRIENDS_WRITE_ADD],
				[ApiKeyScope::FRIENDS_WRITE_ADD, ApiKeyScope::FRIENDS_WRITE_REMOVE],
			],
			'empty input yields empty output' => [[], []],
		];
	}

	#[Test]
	#[TestDox('expand($_dataName) resolves granted scopes to sorted unique leaves')]
	#[Group('mantle2/custom')]
	#[DataProvider('expandProvider')]
	public function testExpand(array $granted, array $expected): void
	{
		$this->assertSame($expected, ApiKeyScope::expand($granted));
	}

	#[Test]
	#[TestDox('expand of a parent equals the leaf set beneath it')]
	#[Group('mantle2/custom')]
	public function testExpandParentEqualsLeafSet(): void
	{
		$expanded = ApiKeyScope::expand([ApiKeyScope::EVENTS_WRITE]);
		$this->assertSame(
			[
				ApiKeyScope::EVENTS_WRITE_CREATE,
				ApiKeyScope::EVENTS_WRITE_DELETE,
				ApiKeyScope::EVENTS_WRITE_IMAGES,
				ApiKeyScope::EVENTS_WRITE_RSVP,
				ApiKeyScope::EVENTS_WRITE_UPDATE,
			],
			$expanded,
		);
		foreach ($expanded as $leaf) {
			$this->assertContains($leaf, ApiKeyScope::leaves());
		}
	}

	public static function satisfiesTrueProvider(): array
	{
		return [
			'exact match' => [[ApiKeyScope::USER_EDIT_EMAIL], ApiKeyScope::USER_EDIT_EMAIL],
			'direct parent grant' => [[ApiKeyScope::USER_EDIT], ApiKeyScope::USER_EDIT_EMAIL],
			'top parent grant covers grandchild' => [['user'], ApiKeyScope::USER_EDIT_EMAIL],
			'parent grant covers itself' => [[ApiKeyScope::USER_EDIT], ApiKeyScope::USER_EDIT],
			'events parent covers rsvp' => [
				[ApiKeyScope::EVENTS_WRITE],
				ApiKeyScope::EVENTS_WRITE_RSVP,
			],
		];
	}

	#[Test]
	#[TestDox('satisfies($_dataName) is true via exact or implicit-parent grant')]
	#[Group('mantle2/custom')]
	#[DataProvider('satisfiesTrueProvider')]
	public function testSatisfiesTrue(array $granted, string $required): void
	{
		$this->assertTrue(ApiKeyScope::satisfies($granted, $required));
	}

	public static function satisfiesFalseProvider(): array
	{
		return [
			'sibling does not satisfy' => [[ApiKeyScope::USER_READ], ApiKeyScope::USER_EDIT_EMAIL],
			'child does not satisfy parent' => [
				[ApiKeyScope::USER_EDIT_EMAIL],
				ApiKeyScope::USER_EDIT,
			],
			'unrelated scope' => [[ApiKeyScope::EVENTS_READ], ApiKeyScope::PROMPTS_READ],
			'empty grants satisfy nothing' => [[], ApiKeyScope::USER_READ],
			'user family does not cross into users family' => [
				[ApiKeyScope::USER_READ],
				ApiKeyScope::USERS_READ_PROFILE,
			],
		];
	}

	#[Test]
	#[TestDox('satisfies($_dataName) is false when neither exact nor ancestor is granted')]
	#[Group('mantle2/custom')]
	#[DataProvider('satisfiesFalseProvider')]
	public function testSatisfiesFalse(array $granted, string $required): void
	{
		$this->assertFalse(ApiKeyScope::satisfies($granted, $required));
	}

	#[Test]
	#[TestDox('satisfies walks the full colon chain up to the root segment')]
	#[Group('mantle2/custom')]
	public function testSatisfiesWalksFullChain(): void
	{
		// top-level segment 'events' covers a three-level required scope
		$this->assertTrue(ApiKeyScope::satisfies(['events'], ApiKeyScope::EVENTS_WRITE_IMAGES));
		// but a single segment never matches an unrelated family
		$this->assertFalse(ApiKeyScope::satisfies(['events'], ApiKeyScope::ACTIVITIES_WRITE_SELF));
	}

	#[Test]
	#[TestDox('isValid is true for every scope in all() and false for unknowns')]
	#[Group('mantle2/custom')]
	public function testIsValid(): void
	{
		foreach (ApiKeyScope::all() as $scope) {
			$this->assertTrue(ApiKeyScope::isValid($scope), "expected valid: $scope");
		}

		$this->assertFalse(ApiKeyScope::isValid('user'));
		$this->assertFalse(ApiKeyScope::isValid('not:a:scope'));
		$this->assertFalse(ApiKeyScope::isValid(''));
		$this->assertFalse(ApiKeyScope::isValid('USER:READ'));
	}
}
