<?php

namespace Drupal\Tests\mantle2\E2E;

use Drupal\mantle2\Controller\ReportsController;
use Drupal\mantle2\Custom\AccountType;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

class ReportsControllerTest extends E2ETestBase
{
	private function controller(): ReportsController
	{
		return ReportsController::create($this->container);
	}

	private function admin(): UserInterface
	{
		return $this->createUser([
			'field_account_type' => (string) array_search(
				AccountType::ADMINISTRATOR,
				AccountType::cases(),
				true,
			),
		]);
	}

	private function reportUserBody(UserInterface $target, string $reason = 'spam'): string
	{
		return json_encode([
			'content_type' => 'user',
			'content_id' => (string) $target->id(),
			'reason' => $reason,
		]);
	}

	#[Test]
	#[TestDox('POST /v2/reports validates body before hitting cloud')]
	#[Group('mantle2/reports')]
	public function createValidation(): void
	{
		$c = $this->controller();

		$this->assertSame(
			Response::HTTP_BAD_REQUEST,
			$c
				->createReport($this->request('POST', '/v2/reports', [], 'not json'))
				->getStatusCode(),
		);
		$this->assertSame(
			Response::HTTP_BAD_REQUEST,
			$c
				->createReport(
					$this->request(
						'POST',
						'/v2/reports',
						[],
						json_encode(['content_type' => 'nope']),
					),
				)
				->getStatusCode(),
		);
		$this->assertSame(
			Response::HTTP_BAD_REQUEST,
			$c
				->createReport(
					$this->request(
						'POST',
						'/v2/reports',
						[],
						json_encode(['content_type' => 'user']),
					),
				)
				->getStatusCode(),
		);
		$badReason = $c->createReport(
			$this->request(
				'POST',
				'/v2/reports',
				[],
				json_encode(['content_type' => 'user', 'content_id' => '1', 'reason' => 'nope']),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $badReason->getStatusCode());

		$missingParent = $c->createReport(
			$this->request(
				'POST',
				'/v2/reports',
				[],
				json_encode([
					'content_type' => 'prompt_response',
					'content_id' => '1',
					'reason' => 'spam',
				]),
			),
		);
		$this->assertSame(Response::HTTP_BAD_REQUEST, $missingParent->getStatusCode());
	}

	#[Test]
	#[TestDox('POST /v2/reports 404s when the reported content does not exist')]
	#[Group('mantle2/reports')]
	public function createUnknownContent(): void
	{
		$response = $this->controller()->createReport(
			$this->request(
				'POST',
				'/v2/reports',
				[],
				json_encode([
					'content_type' => 'user',
					'content_id' => '999999',
					'reason' => 'spam',
				]),
			),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
	}

	#[Test]
	#[TestDox('POST /v2/reports stores a report for existing content via cloud')]
	#[Group('mantle2/reports')]
	public function createSucceeds(): void
	{
		$target = $this->createUser();
		$response = $this->controller()->createReport(
			$this->request('POST', '/v2/reports', [], $this->reportUserBody($target)),
		);
		$this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());

		$body = $this->decode($response);
		$this->assertArrayHasKey('report', $body);
		$this->assertArrayHasKey('deduped', $body);
		$this->assertNotEmpty($body['report']['id']);
	}

	#[Test]
	#[TestDox('GET /v2/reports lists reports for admins and forbids everyone else')]
	#[Group('mantle2/reports')]
	public function listGating(): void
	{
		$normal = $this->controller()->listReports(
			$this->authRequest($this->createUser(), 'GET', '/v2/reports'),
		);
		$this->assertSame(Response::HTTP_FORBIDDEN, $normal->getStatusCode());

		$admin = $this->controller()->listReports(
			$this->authRequest($this->admin(), 'GET', '/v2/reports?limit=10'),
		);
		$this->assertSame(Response::HTTP_OK, $admin->getStatusCode());
		$body = $this->decode($admin);
		$this->assertArrayHasKey('reports', $body);
		$this->assertSame(1, $body['page']);
		$this->assertSame(10, $body['limit']);
	}

	#[Test]
	#[TestDox('GET /v2/reports/{id} 404s for an unknown id')]
	#[Group('mantle2/reports')]
	public function getUnknownReport(): void
	{
		$response = $this->controller()->getReport(
			bin2hex(random_bytes(16)),
			$this->authRequest($this->admin(), 'GET', '/v2/reports/x'),
		);
		$this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
	}

	#[Test]
	#[TestDox('PATCH /v2/reports/{id} dismiss transitions a report and reports no enforcement')]
	#[Group('mantle2/reports')]
	public function patchDismiss(): void
	{
		$target = $this->createUser();
		$created = $this->decode(
			$this->controller()->createReport(
				$this->request('POST', '/v2/reports', [], $this->reportUserBody($target)),
			),
		);
		$this->assertArrayHasKey('report', $created, 'report create returned no report');
		$reportId = $created['report']['id'] ?? null;
		$this->assertNotEmpty($reportId, 'report create returned no id to patch');

		$response = $this->controller()->patchReport(
			$reportId,
			$this->authRequest(
				$this->admin(),
				'PATCH',
				'/v2/reports/' . $reportId,
				[],
				json_encode(['action' => 'dismiss', 'notes' => 'e2e dismissal']),
			),
		);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertSame('none', $body['enforced_action']);
		$this->assertSame('dismissed', $body['status']);
	}
}
