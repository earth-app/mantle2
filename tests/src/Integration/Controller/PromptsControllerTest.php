<?php

namespace Drupal\Tests\mantle2\Integration\Controller;

use Drupal\mantle2\Controller\PromptsController;
use Drupal\mantle2\Custom\AccountType;
use Drupal\mantle2\Custom\Prompt;
use Drupal\mantle2\Custom\Visibility;
use Drupal\mantle2\Service\PromptsHelper;
use Drupal\node\Entity\Node;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

class PromptsControllerTest extends IntegrationTestBase
{
	protected bool $installContentTypes = true;

	protected function setUp(): void
	{
		parent::setUp();
		// comments and node save require these tables (base class omits them)

		// responders need the 'post comments' permission
		$role = Role::load(RoleInterface::AUTHENTICATED_ID);
		if (!$role) {
			$role = Role::create([
				'id' => RoleInterface::AUTHENTICATED_ID,
				'label' => 'Authenticated',
			]);
		}
		$role->grantPermission('post comments');
		$role->save();
	}

	private function controller(): PromptsController
	{
		return PromptsController::create($this->container);
	}

	private function verifiedUser(array $values = []): UserInterface
	{
		return $this->createUser(['field_email_verified' => true] + $values);
	}

	private function admin(): UserInterface
	{
		return $this->createUser([
			'field_email_verified' => true,
			'field_account_type' => (string) array_search(
				AccountType::ADMINISTRATOR,
				AccountType::cases(),
				true,
			),
		]);
	}

	// seeds a prompt without an author to skip the CloudHelper notification (create success is E2E)
	private function seedPrompt(
		UserInterface $owner,
		string $text = 'This is a seeded prompt body',
		Visibility $visibility = Visibility::PUBLIC,
	): Node {
		$obj = new Prompt(0, $text, (int) $owner->id(), $visibility);
		// pass the owner as author so node uid matches field_owner_id, as production does
		return PromptsHelper::createPrompt($obj, $owner);
	}

	#[Test]
	#[
		TestDox(
			'POST /v2/prompts validates auth, email, private accounts, body, and length before create',
		),
	]
	#[Group('mantle2/prompts')]
	public function create_(): void
	{
		$anon = $this->controller()->createPrompt($this->request('POST', '/v2/prompts', [], '{}'));
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$unverified = $this->createUser();
		$emailGate = $this->controller()->createPrompt(
			$this->authRequest(
				$unverified,
				'POST',
				'/v2/prompts',
				[],
				'{"prompt":"a valid prompt","visibility":"PUBLIC"}',
			),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $emailGate->getStatusCode());

		$private = $this->verifiedUser([
			'field_visibility' => (string) array_search(
				Visibility::PRIVATE,
				Visibility::cases(),
				true,
			),
		]);
		$privBlocked = $this->controller()->createPrompt(
			$this->authRequest(
				$private,
				'POST',
				'/v2/prompts',
				[],
				'{"prompt":"a valid prompt body","visibility":"PUBLIC"}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $privBlocked->getStatusCode());
		$this->assertStringContainsString(
			'Private accounts',
			$this->decode($privBlocked)['message'],
		);

		$user = $this->verifiedUser();
		$badFields = $this->controller()->createPrompt(
			$this->authRequest($user, 'POST', '/v2/prompts', [], '{"prompt":123}'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badFields->getStatusCode());

		$tooShort = $this->controller()->createPrompt(
			$this->authRequest(
				$user,
				'POST',
				'/v2/prompts',
				[],
				'{"prompt":"short","visibility":"PUBLIC"}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $tooShort->getStatusCode());
		$this->assertStringContainsString('between length', $this->decode($tooShort)['message']);
	}

	#[Test]
	#[TestDox('GET /v2/prompts paginates and enforces visibility per requester')]
	#[Group('mantle2/prompts')]
	public function list(): void
	{
		$owner = $this->verifiedUser();
		$this->seedPrompt($owner, 'A public prompt body', Visibility::PUBLIC);
		$this->seedPrompt($owner, 'An unlisted prompt body', Visibility::UNLISTED);
		$this->seedPrompt($owner, 'A private prompt body', Visibility::PRIVATE);

		$anon = $this->controller()->prompts($this->request('GET', '/v2/prompts'));
		$this->assertSame(Response::HTTP_OK, $anon->getStatusCode());
		$anonBody = $this->decode($anon);
		$this->assertSame(1, $anonBody['total']);
		$this->assertSame(25, $anonBody['limit']);

		$loggedIn = $this->controller()->prompts(
			$this->authRequest($this->verifiedUser(), 'GET', '/v2/prompts'),
		);
		$loggedInBody = $this->decode($loggedIn);
		$this->assertSame(2, $loggedInBody['total']);

		$asOwner = $this->controller()->prompts($this->authRequest($owner, 'GET', '/v2/prompts'));
		$this->assertSame(3, $this->decode($asOwner)['total']);

		$badLimit = $this->controller()->prompts($this->request('GET', '/v2/prompts?limit=0'));
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badLimit->getStatusCode());
	}

	#[Test]
	#[
		TestDox(
			'GET /v2/prompts/:id returns a visible prompt and 404s for hidden or missing prompts',
		),
	]
	#[Group('mantle2/prompts')]
	public function get(): void
	{
		$owner = $this->verifiedUser();
		$public = $this->seedPrompt($owner, 'A public prompt body', Visibility::PUBLIC);
		$private = $this->seedPrompt($owner, 'A private prompt body', Visibility::PRIVATE);

		$ok = $this->controller()->getPrompt((int) $public->id(), $this->request('GET', '/'));
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame('A public prompt body', $body['prompt']);
		$this->assertArrayHasKey('owner', $body);
		$this->assertArrayHasKey('responses_count', $body);

		$missing = $this->controller()->getPrompt(999999, $this->request('GET', '/'));
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$hidden = $this->controller()->getPrompt((int) $private->id(), $this->request('GET', '/'));
		$this->assertSame(Response::HTTP_NOT_FOUND, $hidden->getStatusCode());

		$asOwner = $this->controller()->getPrompt(
			(int) $private->id(),
			$this->authRequest($owner, 'GET', '/'),
		);
		$this->assertSame(Response::HTTP_OK, $asOwner->getStatusCode());
	}

	#[Test]
	#[TestDox('PATCH /v2/prompts/:id updates for owner, rejects non-owners, and 404s when missing')]
	#[Group('mantle2/prompts')]
	public function patch(): void
	{
		$owner = $this->verifiedUser();
		$node = $this->seedPrompt($owner, 'The original prompt body', Visibility::PUBLIC);

		$missing = $this->controller()->updatePrompt(
			999999,
			$this->authRequest($owner, 'PATCH', '/', [], '{"prompt":"x"}'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$anon = $this->controller()->updatePrompt(
			(int) $node->id(),
			$this->request('PATCH', '/', [], '{"prompt":"x"}'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$other = $this->controller()->updatePrompt(
			(int) $node->id(),
			$this->authRequest(
				$this->verifiedUser(),
				'PATCH',
				'/',
				[],
				'{"visibility":"UNLISTED"}',
			),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $other->getStatusCode());

		$noChange = $this->controller()->updatePrompt(
			(int) $node->id(),
			$this->authRequest($owner, 'PATCH', '/', [], '{}'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $noChange->getStatusCode());

		$ok = $this->controller()->updatePrompt(
			(int) $node->id(),
			$this->authRequest(
				$owner,
				'PATCH',
				'/',
				[],
				'{"prompt":"The rewritten prompt body","visibility":"UNLISTED"}',
			),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame('The rewritten prompt body', $body['prompt']);
		$this->assertSame('UNLISTED', $body['visibility']);

		$reloaded = PromptsHelper::nodeToPrompt(Node::load($node->id()));
		$this->assertSame('The rewritten prompt body', $reloaded->getPrompt());
		$this->assertSame(Visibility::UNLISTED, $reloaded->getVisibility());
	}

	#[Test]
	#[
		TestDox(
			'DELETE /v2/prompts/:id removes for owner, rejects non-owners, and 404s when missing',
		),
	]
	#[Group('mantle2/prompts')]
	public function delete(): void
	{
		$owner = $this->verifiedUser();
		$node = $this->seedPrompt($owner);

		$missing = $this->controller()->deletePrompt(
			999999,
			$this->authRequest($owner, 'DELETE', '/'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$other = $this->controller()->deletePrompt(
			(int) $node->id(),
			$this->authRequest($this->verifiedUser(), 'DELETE', '/'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $other->getStatusCode());

		$ok = $this->controller()->deletePrompt(
			(int) $node->id(),
			$this->authRequest($owner, 'DELETE', '/'),
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $ok->getStatusCode());
		$this->assertNull(Node::load($node->id()));
	}

	#[Test]
	#[
		TestDox(
			'POST /v2/prompts/:id/responses creates a response with auth, email, and content validation',
		),
	]
	#[Group('mantle2/prompts')]
	public function createResponse(): void
	{
		$owner = $this->verifiedUser();
		$node = $this->seedPrompt($owner);

		$anon = $this->controller()->createPromptResponse(
			(int) $node->id(),
			$this->request('POST', '/', [], '{"content":"hi"}'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$unverified = $this->createUser();
		$emailGate = $this->controller()->createPromptResponse(
			(int) $node->id(),
			$this->authRequest($unverified, 'POST', '/', [], '{"content":"hi"}'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $emailGate->getStatusCode());

		$responder = $this->verifiedUser();
		$badContent = $this->controller()->createPromptResponse(
			(int) $node->id(),
			$this->authRequest($responder, 'POST', '/', [], '{"content":"   "}'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badContent->getStatusCode());

		$notFound = $this->controller()->createPromptResponse(
			999999,
			$this->authRequest($responder, 'POST', '/', [], '{"content":"hello there"}'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $notFound->getStatusCode());

		$ok = $this->controller()->createPromptResponse(
			(int) $node->id(),
			$this->authRequest($responder, 'POST', '/', [], '{"content":"My genuine response"}'),
		);
		$this->assertSame(Response::HTTP_CREATED, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame('My genuine response', $body['response']);
		$this->assertArrayHasKey('owner', $body);
		$this->assertSame(1, PromptsHelper::getCommentsCount($node));
	}

	#[Test]
	#[
		TestDox(
			'GET /v2/prompts/:id/responses lists responses and 404s for hidden or missing prompts',
		),
	]
	#[Group('mantle2/prompts')]
	public function listResponses(): void
	{
		$owner = $this->verifiedUser();
		$responder = $this->verifiedUser();
		$node = $this->seedPrompt($owner);
		PromptsHelper::addComment($responder, $node, 'first response');
		PromptsHelper::addComment($responder, $node, 'second response');

		$ok = $this->controller()->getPromptResponses(
			(int) $node->id(),
			$this->request('GET', '/'),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$body = $this->decode($ok);
		$this->assertSame(2, $body['total']);
		$this->assertCount(2, $body['items']);
		$this->assertArrayHasKey('created_at', $body['items'][0]);

		$missing = $this->controller()->getPromptResponses(999999, $this->request('GET', '/'));
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$private = $this->seedPrompt($owner, 'A private prompt body', Visibility::PRIVATE);
		$hidden = $this->controller()->getPromptResponses(
			(int) $private->id(),
			$this->request('GET', '/'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $hidden->getStatusCode());
	}

	#[Test]
	#[
		TestDox(
			'GET /v2/prompts/:id/responses/:response returns a response and 404s when mismatched',
		),
	]
	#[Group('mantle2/prompts')]
	public function getResponse(): void
	{
		$owner = $this->verifiedUser();
		$responder = $this->verifiedUser();
		$node = $this->seedPrompt($owner);
		$comment = PromptsHelper::addComment($responder, $node, 'a response to fetch');

		$ok = $this->controller()->getPromptResponse(
			(int) $node->id(),
			(int) $comment->id(),
			$this->request('GET', '/'),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertSame('a response to fetch', $this->decode($ok)['response']);

		$missing = $this->controller()->getPromptResponse(
			(int) $node->id(),
			999999,
			$this->request('GET', '/'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());

		$otherPrompt = $this->seedPrompt($owner, 'A second prompt body');
		$mismatch = $this->controller()->getPromptResponse(
			(int) $otherPrompt->id(),
			(int) $comment->id(),
			$this->request('GET', '/'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $mismatch->getStatusCode());
	}

	#[Test]
	#[TestDox('PATCH /v2/prompts/:id/responses/:response updates for owner and rejects non-owners')]
	#[Group('mantle2/prompts')]
	public function patchResponse(): void
	{
		$owner = $this->verifiedUser();
		$responder = $this->verifiedUser();
		$node = $this->seedPrompt($owner);
		$comment = PromptsHelper::addComment($responder, $node, 'the original response');

		$anon = $this->controller()->updatePromptResponse(
			(int) $node->id(),
			(int) $comment->id(),
			$this->request('PATCH', '/', [], '{"content":"x"}'),
		);
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$other = $this->controller()->updatePromptResponse(
			(int) $node->id(),
			(int) $comment->id(),
			$this->authRequest($this->verifiedUser(), 'PATCH', '/', [], '{"content":"hijack"}'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $other->getStatusCode());

		$ok = $this->controller()->updatePromptResponse(
			(int) $node->id(),
			(int) $comment->id(),
			$this->authRequest($responder, 'PATCH', '/', [], '{"content":"the edited response"}'),
		);
		$this->assertSame(Response::HTTP_OK, $ok->getStatusCode());
		$this->assertSame('the edited response', $this->decode($ok)['response']);
	}

	#[Test]
	#[
		TestDox(
			'DELETE /v2/prompts/:id/responses/:response removes for owner and rejects non-owners',
		),
	]
	#[Group('mantle2/prompts')]
	public function deleteResponse(): void
	{
		$owner = $this->verifiedUser();
		$responder = $this->verifiedUser();
		$node = $this->seedPrompt($owner);
		$comment = PromptsHelper::addComment($responder, $node, 'a response to delete');

		$other = $this->controller()->deletePromptResponse(
			(int) $node->id(),
			(int) $comment->id(),
			$this->authRequest($this->verifiedUser(), 'DELETE', '/'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $other->getStatusCode());

		$ok = $this->controller()->deletePromptResponse(
			(int) $node->id(),
			(int) $comment->id(),
			$this->authRequest($responder, 'DELETE', '/'),
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $ok->getStatusCode());
		$this->assertSame(0, PromptsHelper::getCommentsCount($node));
	}

	#[Test]
	#[TestDox('POST /v2/prompts/check_expired requires an admin')]
	#[Group('mantle2/prompts')]
	public function checkExpired(): void
	{
		$anon = $this->controller()->checkExpiredPrompts($this->request('POST', '/'));
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $anon->getStatusCode());

		$normal = $this->controller()->checkExpiredPrompts(
			$this->authRequest($this->verifiedUser(), 'POST', '/'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $normal->getStatusCode());

		$ok = $this->controller()->checkExpiredPrompts(
			$this->authRequest($this->admin(), 'POST', '/'),
		);
		$this->assertSame(Response::HTTP_NO_CONTENT, $ok->getStatusCode());
	}

	#[Test]
	#[TestDox('GET /v2/prompts supports rand sort, search, author filter, and rejects bad author')]
	#[Group('mantle2/prompts')]
	public function listBranches(): void
	{
		$owner = $this->verifiedUser();
		$this->seedPrompt($owner, 'A searchable alpha prompt', Visibility::PUBLIC);
		$this->seedPrompt($owner, 'A different beta prompt', Visibility::PUBLIC);

		$rand = $this->controller()->prompts($this->request('GET', '/v2/prompts?sort=rand'));
		$this->assertSame(Response::HTTP_OK, $rand->getStatusCode());
		$this->assertSame(2, $this->decode($rand)['total']);

		$randSearch = $this->controller()->prompts(
			$this->request('GET', '/v2/prompts?sort=rand&search=alpha'),
		);
		$this->assertSame(1, $this->decode($randSearch)['total']);

		$randAuthor = $this->controller()->prompts(
			$this->request('GET', '/v2/prompts?sort=rand&author=' . $owner->id()),
		);
		$this->assertSame(2, $this->decode($randAuthor)['total']);

		$search = $this->controller()->prompts($this->request('GET', '/v2/prompts?search=beta'));
		$this->assertSame(1, $this->decode($search)['total']);

		$byAuthor = $this->controller()->prompts(
			$this->request('GET', '/v2/prompts?author=' . $owner->id()),
		);
		$this->assertSame(2, $this->decode($byAuthor)['total']);

		$badAuthor = $this->controller()->prompts(
			$this->request('GET', '/v2/prompts?author=999999'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badAuthor->getStatusCode());

		// admin sees all visibilities
		$this->seedPrompt($owner, 'A private admin-visible prompt', Visibility::PRIVATE);
		$asAdmin = $this->controller()->prompts(
			$this->authRequest($this->admin(), 'GET', '/v2/prompts?sort=rand'),
		);
		$this->assertSame(3, $this->decode($asAdmin)['total']);
	}

	#[Test]
	#[TestDox('GET /v2/prompts/random validates count/author and enforces visibility')]
	#[Group('mantle2/prompts')]
	public function random(): void
	{
		$owner = $this->verifiedUser();

		$empty = $this->controller()->randomPrompt($this->request('GET', '/v2/prompts/random'));
		$this->assertSame(Response::HTTP_NOT_FOUND, $empty->getStatusCode());

		$this->seedPrompt($owner, 'A public random prompt', Visibility::PUBLIC);
		$this->seedPrompt($owner, 'A private random prompt', Visibility::PRIVATE);

		$badCount = $this->controller()->randomPrompt(
			$this->request('GET', '/v2/prompts/random?count=99'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badCount->getStatusCode());

		$badAuthor = $this->controller()->randomPrompt(
			$this->request('GET', '/v2/prompts/random?author=-1'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badAuthor->getStatusCode());

		// anon only sees the public one
		$anon = $this->controller()->randomPrompt(
			$this->request('GET', '/v2/prompts/random?count=10'),
		);
		$this->assertSame(Response::HTTP_OK, $anon->getStatusCode());
		$this->assertCount(1, $this->decode($anon));

		// owner sees both (public + own private)
		$asOwner = $this->controller()->randomPrompt(
			$this->authRequest($owner, 'GET', '/v2/prompts/random?count=10'),
		);
		$this->assertCount(2, $this->decode($asOwner));

		// the author filter matches on node uid; seeded prompts are owned by $owner
		$byAuthor = $this->controller()->randomPrompt(
			$this->request('GET', '/v2/prompts/random?count=10&author=' . $owner->id()),
		);
		$this->assertSame(Response::HTTP_OK, $byAuthor->getStatusCode());

		// a uid that owns none (root) yields a 404 (author branch still exercised)
		$noneForAuthor = $this->controller()->randomPrompt(
			$this->request('GET', '/v2/prompts/random?count=10&author=1'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $noneForAuthor->getStatusCode());
	}

	#[Test]
	#[TestDox('PATCH /v2/prompts/:id censors flagged bodies and rejects them without censor')]
	#[Group('mantle2/prompts')]
	public function patchFlagged(): void
	{
		$owner = $this->verifiedUser();
		$node = $this->seedPrompt($owner, 'A clean prompt body here', Visibility::PUBLIC);

		$rejected = $this->controller()->updatePrompt(
			(int) $node->id(),
			$this->authRequest($owner, 'PATCH', '/', [], '{"prompt":"this is fucking bad"}'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $rejected->getStatusCode());
		$this->assertStringContainsString('inappropriate', $this->decode($rejected)['message']);

		$censored = $this->controller()->updatePrompt(
			(int) $node->id(),
			$this->authRequest(
				$owner,
				'PATCH',
				'/',
				[],
				'{"prompt":"this is fucking fine now","censor":true}',
			),
		);
		$this->assertSame(Response::HTTP_OK, $censored->getStatusCode());
		$this->assertStringContainsString('****', $this->decode($censored)['prompt']);

		$badCensor = $this->controller()->updatePrompt(
			(int) $node->id(),
			$this->authRequest(
				$owner,
				'PATCH',
				'/',
				[],
				'{"prompt":"another body","censor":"yes"}',
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badCensor->getStatusCode());
	}

	#[Test]
	#[TestDox('PATCH /v2/prompts/:id/responses/:response censors flagged content')]
	#[Group('mantle2/prompts')]
	public function patchResponseFlagged(): void
	{
		$owner = $this->verifiedUser();
		$responder = $this->verifiedUser();
		$node = $this->seedPrompt($owner);
		$comment = PromptsHelper::addComment($responder, $node, 'the original response');

		$rejected = $this->controller()->updatePromptResponse(
			(int) $node->id(),
			(int) $comment->id(),
			$this->authRequest($responder, 'PATCH', '/', [], '{"content":"you piece of shit"}'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $rejected->getStatusCode());

		$censored = $this->controller()->updatePromptResponse(
			(int) $node->id(),
			(int) $comment->id(),
			$this->authRequest(
				$responder,
				'PATCH',
				'/',
				[],
				'{"content":"this shit right here","censor":true}',
			),
		);
		$this->assertSame(Response::HTTP_OK, $censored->getStatusCode());
		$this->assertStringContainsString('****', $this->decode($censored)['response']);

		$empty = $this->controller()->updatePromptResponse(
			(int) $node->id(),
			(int) $comment->id(),
			$this->authRequest($responder, 'PATCH', '/', [], '{"content":"   "}'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $empty->getStatusCode());
	}

	#[Test]
	#[TestDox('GET/PATCH/DELETE response endpoints 404 on hidden prompts and mismatched ids')]
	#[Group('mantle2/prompts')]
	public function responseVisibilityGuards(): void
	{
		$owner = $this->verifiedUser();
		$private = $this->seedPrompt($owner, 'A private prompt body', Visibility::PRIVATE);
		$comment = PromptsHelper::addComment($owner, $private, 'owner response');

		// anon cannot see a private prompt's responses
		$hiddenGet = $this->controller()->getPromptResponse(
			(int) $private->id(),
			(int) $comment->id(),
			$this->request('GET', '/'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $hiddenGet->getStatusCode());

		$hiddenPatch = $this->controller()->updatePromptResponse(
			(int) $private->id(),
			(int) $comment->id(),
			$this->authRequest($this->verifiedUser(), 'PATCH', '/', [], '{"content":"x"}'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $hiddenPatch->getStatusCode());

		$hiddenDelete = $this->controller()->deletePromptResponse(
			(int) $private->id(),
			(int) $comment->id(),
			$this->authRequest($this->verifiedUser(), 'DELETE', '/'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $hiddenDelete->getStatusCode());

		// mismatched response id under a visible prompt
		$public = $this->seedPrompt($owner, 'A public prompt body', Visibility::PUBLIC);
		$mismatch = $this->controller()->deletePromptResponse(
			(int) $public->id(),
			(int) $comment->id(),
			$this->authRequest($owner, 'DELETE', '/'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $mismatch->getStatusCode());
	}

	#[Test]
	#[TestDox('POST responses 404s a hidden prompt and rejects invalid JSON')]
	#[Group('mantle2/prompts')]
	public function createResponseGuards(): void
	{
		$owner = $this->verifiedUser();
		$private = $this->seedPrompt($owner, 'A private prompt body', Visibility::PRIVATE);
		$responder = $this->verifiedUser();

		$hidden = $this->controller()->createPromptResponse(
			(int) $private->id(),
			$this->authRequest($responder, 'POST', '/', [], '{"content":"hi there"}'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $hidden->getStatusCode());

		$public = $this->seedPrompt($owner, 'A public prompt body', Visibility::PUBLIC);
		$badJson = $this->controller()->createPromptResponse(
			(int) $public->id(),
			$this->authRequest($responder, 'POST', '/', [], '{bad json'),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badJson->getStatusCode());
	}
}
