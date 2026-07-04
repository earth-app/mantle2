<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\mantle2\Custom\Prompt;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\PromptsHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class PromptsHelperTest extends IntegrationTestBase
{
	protected function setUp(): void
	{
		parent::setUp();
		// comments and node save require these tables (base class omits them)
	}

	private function makePrompt(
		UserInterface $owner,
		string $text = 'This is a valid prompt body',
		Visibility $visibility = Visibility::PUBLIC,
	): \Drupal\node\Entity\Node {
		// createPrompt with an author triggers CloudHelper via addNotification (E2E);
		// pass null author to exercise the local persistence path only
		$obj = new Prompt(0, $text, (int) $owner->id(), $visibility);
		return PromptsHelper::createPrompt($obj, null);
	}

	#region Prompt CRUD

	#[Test]
	#[TestDox('createPrompt persists a prompt node mapped back through nodeToPrompt')]
	#[Group('mantle2/prompts')]
	public function create_(): void
	{
		$owner = $this->createUser();
		$node = $this->makePrompt($owner, 'A brand new prompt body');

		$this->assertSame('prompt', $node->getType());
		$this->assertSame((int) $owner->id(), (int) $node->get('field_owner_id')->value);

		$prompt = PromptsHelper::nodeToPrompt($node);
		$this->assertSame('A brand new prompt body', $prompt->getPrompt());
		$this->assertSame((int) $owner->id(), $prompt->getOwnerId());
		$this->assertSame(Visibility::PUBLIC, $prompt->getVisibility());
	}

	#[Test]
	#[TestDox('loadPromptNode returns a prompt only for prompt-type nodes')]
	#[Group('mantle2/prompts')]
	public function load(): void
	{
		$owner = $this->createUser();
		$node = $this->makePrompt($owner);

		$this->assertInstanceOf(Prompt::class, PromptsHelper::loadPromptNode((int) $node->id()));
		$this->assertNull(PromptsHelper::loadPromptNode(999999));

		$activity = \Drupal\node\Entity\Node::create(['type' => 'activity', 'title' => 't']);
		$activity->save();
		$this->assertNull(PromptsHelper::loadPromptNode((int) $activity->id()));
	}

	#[Test]
	#[TestDox('updatePrompt rewrites body and visibility and rejects non-prompt nodes')]
	#[Group('mantle2/prompts')]
	public function update(): void
	{
		$owner = $this->createUser();
		$node = $this->makePrompt($owner, 'Original prompt body');

		$prompt = PromptsHelper::nodeToPrompt($node);
		$prompt->setPrompt('Rewritten prompt body');
		$prompt->setVisibility(Visibility::UNLISTED);
		PromptsHelper::updatePrompt($node, $prompt);

		$reloaded = PromptsHelper::nodeToPrompt($node);
		$this->assertSame('Rewritten prompt body', $reloaded->getPrompt());
		$this->assertSame(Visibility::UNLISTED, $reloaded->getVisibility());

		$activity = \Drupal\node\Entity\Node::create(['type' => 'activity', 'title' => 't']);
		$activity->save();
		$this->expectException(\InvalidArgumentException::class);
		PromptsHelper::updatePrompt($activity, $prompt);
	}

	#[Test]
	#[TestDox('getPrompts and getPromptsCount are scoped to the owner')]
	#[Group('mantle2/prompts')]
	public function ownerScoping(): void
	{
		$owner = $this->createUser();
		$other = $this->createUser();

		$this->makePrompt($owner, 'Owner prompt one');
		$this->makePrompt($owner, 'Owner prompt two');
		$this->makePrompt($other, 'Other prompt one');

		$this->assertSame(2, PromptsHelper::getPromptsCount($owner));
		$this->assertSame(1, PromptsHelper::getPromptsCount($other));
		$this->assertCount(2, PromptsHelper::getPrompts($owner));
	}

	public static function visibilityProvider(): array
	{
		return [
			'public visible to anon' => [Visibility::PUBLIC, false, true],
			'unlisted hidden from anon' => [Visibility::UNLISTED, false, false],
			'unlisted visible to user' => [Visibility::UNLISTED, true, true],
			'private hidden from non-owner' => [Visibility::PRIVATE, true, false],
		];
	}

	#[Test]
	#[TestDox('isVisible enforces visibility rules per requester')]
	#[Group('mantle2/prompts')]
	#[DataProvider('visibilityProvider')]
	public function visibility(Visibility $visibility, bool $withUser, bool $expected): void
	{
		$owner = $this->createUser();
		$node = $this->makePrompt($owner, 'A visibility test prompt', $visibility);
		$prompt = PromptsHelper::nodeToPrompt($node);

		$requester = $withUser ? $this->createUser() : null;
		$this->assertSame($expected, PromptsHelper::isVisible($prompt, $requester));

		// owner always sees a private prompt
		if ($visibility === Visibility::PRIVATE) {
			$this->assertTrue(PromptsHelper::isVisible($prompt, $owner));
		}
	}

	#endregion

	#region Response CRUD

	#[Test]
	#[TestDox('addComment creates a response, counted and mapped through entityToPromptResponse')]
	#[Group('mantle2/prompts')]
	public function responseCrud(): void
	{
		$owner = $this->createUser();
		$responder = $this->createUser();
		$node = $this->makePrompt($owner);

		$this->assertSame(0, PromptsHelper::getCommentsCount($node));

		$comment = PromptsHelper::addComment($responder, $node, 'My first response');
		$this->assertSame(1, PromptsHelper::getCommentsCount($node));

		$response = PromptsHelper::entityToPromptResponse($comment);
		$this->assertSame('My first response', $response->getResponse());
		$this->assertSame((int) $node->id(), $response->getPromptId());
		$this->assertSame((int) $responder->id(), $response->getOwnerId());

		PromptsHelper::addComment($responder, $node, 'A second response');
		$responses = PromptsHelper::getResponses($node);
		$this->assertCount(2, $responses);
		$this->assertContainsOnlyInstancesOf(
			\Drupal\mantle2\Custom\PromptResponse::class,
			$responses,
		);
	}

	#[Test]
	#[TestDox('hasResponded tracks whether a user has answered a prompt')]
	#[Group('mantle2/prompts')]
	public function tracking(): void
	{
		$owner = $this->createUser();
		$responder = $this->createUser();
		$node = $this->makePrompt($owner);

		$this->assertFalse(PromptsHelper::hasResponded($responder, $node));
		PromptsHelper::addComment($responder, $node, 'Responded now');
		$this->assertTrue(PromptsHelper::hasResponded($responder, $node));
		$this->assertFalse(PromptsHelper::hasResponded($owner, $node));
	}

	#[Test]
	#[TestDox('getComments paginates and searches responses')]
	#[Group('mantle2/prompts')]
	public function commentSearch(): void
	{
		$owner = $this->createUser();
		$responder = $this->createUser();
		$node = $this->makePrompt($owner);

		PromptsHelper::addComment($responder, $node, 'alpha response');
		PromptsHelper::addComment($responder, $node, 'beta response');
		PromptsHelper::addComment($responder, $node, 'gamma text');

		$this->assertCount(3, PromptsHelper::getComments($node));
		$this->assertSame(1, PromptsHelper::getCommentsCount($node, 'alpha'));

		$page1 = PromptsHelper::getComments($node, 1, 2);
		$this->assertCount(2, $page1);
	}

	#endregion

	#region Serialization

	#[Test]
	#[TestDox('serializePrompt and serializePromptResponse expose owner and counts')]
	#[Group('mantle2/prompts')]
	public function serialization(): void
	{
		$owner = $this->createUser();
		$responder = $this->createUser();
		$node = $this->makePrompt($owner, 'Serialize this prompt');
		$prompt = PromptsHelper::nodeToPrompt($node);

		$comment = PromptsHelper::addComment($responder, $node, 'A response to serialize');

		$serialized = PromptsHelper::serializePrompt($prompt, $node, $responder);
		$this->assertSame('Serialize this prompt', $serialized['prompt']);
		$this->assertSame(1, $serialized['responses_count']);
		$this->assertTrue($serialized['has_responded']);
		$this->assertArrayHasKey('owner', $serialized);
		$this->assertArrayHasKey('created_at', $serialized);

		// a non-responder shows has_responded false, and anon shows null
		$this->assertFalse(PromptsHelper::serializePrompt($prompt, $node, $owner)['has_responded']);
		$this->assertNull(PromptsHelper::serializePrompt($prompt, $node, null)['has_responded']);

		$response = PromptsHelper::entityToPromptResponse($comment);
		$sr = PromptsHelper::serializePromptResponse($response, $owner);
		$this->assertSame('A response to serialize', $sr['response']);
		$this->assertArrayHasKey('owner', $sr);
	}

	#endregion
}
