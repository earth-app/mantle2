<?php

namespace Drupal\Tests\mantle2\Integration\Service;

use Drupal\mantle2\Service\CampaignHelper;
use Drupal\Tests\mantle2\Integration\IntegrationTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class CampaignHelperTest extends IntegrationTestBase
{
	private function verifiedActiveUser(array $extra = []): UserInterface
	{
		$user = $this->createUser(
			$extra + [
				'field_email_verified' => true,
				'field_first_name' => 'Ada',
				'field_last_name' => 'Lovelace',
			],
		);
		// mark as recently active so inactiveFilter is false
		$user->setLastLoginTime(\Drupal::time()->getCurrentTime());
		$user->save();
		return $user;
	}

	// Retrieval

	#[Test]
	#[TestDox('getCampaigns loads and decodes every campaign from the yml with required keys')]
	#[Group('mantle2/campaigns')]
	public function getCampaigns(): void
	{
		$campaigns = CampaignHelper::getCampaigns();
		$this->assertNotEmpty($campaigns);

		$ids = array_column($campaigns, 'id');
		$this->assertContains('verify_email', $ids);
		$this->assertContains('welcome_back', $ids);
		$this->assertContains('insights', $ids);

		foreach ($campaigns as $campaign) {
			$this->assertArrayHasKey('id', $campaign);
			$this->assertArrayHasKey('interval', $campaign);
			$this->assertArrayHasKey('body', $campaign);
			$this->assertIsInt($campaign['interval']);
		}
	}

	#[Test]
	#[TestDox('getCampaign resolves by id, by numeric index, and returns null for unknown keys')]
	#[Group('mantle2/campaigns')]
	public function getCampaign(): void
	{
		$byId = CampaignHelper::getCampaign('verify_email');
		$this->assertNotNull($byId);
		$this->assertSame('verify_email', $byId['id']);
		$this->assertSame('Verify Your Email Address', $byId['title']);
		$this->assertSame('unverifiedFilter', $byId['filter']);
		$this->assertFalse($byId['unsubscribable']);

		$byIndex = CampaignHelper::getCampaign(0);
		$this->assertSame('verify_email', $byIndex['id']);

		$this->assertNull(CampaignHelper::getCampaign('does_not_exist'));
	}

	// Filters

	#[Test]
	#[TestDox('verifiedFilter and unverifiedFilter split users on their email-verified flag')]
	#[Group('mantle2/campaigns')]
	public function verifiedFilters(): void
	{
		$verified = $this->verifiedActiveUser();
		$unverified = $this->verifiedActiveUser(['field_email_verified' => false]);

		$this->assertTrue(CampaignHelper::verifiedFilter($verified));
		$this->assertFalse(CampaignHelper::verifiedFilter($unverified));

		// unverifiedFilter also excludes inactive users, so use active ones here
		$this->assertTrue(CampaignHelper::unverifiedFilter($unverified));
		$this->assertFalse(CampaignHelper::unverifiedFilter($verified));
	}

	#[Test]
	#[TestDox('inactiveFilter flags never-logged-in and stale users, activeFilter is its inverse')]
	#[Group('mantle2/campaigns')]
	public function inactiveFilter(): void
	{
		$neverLoggedIn = $this->createUser();
		$this->assertTrue(CampaignHelper::inactiveFilter($neverLoggedIn));
		$this->assertFalse(CampaignHelper::activeFilter($neverLoggedIn));

		$stale = $this->createUser();
		$stale->setLastLoginTime(strtotime('-3 weeks'));
		$stale->save();
		$this->assertTrue(CampaignHelper::inactiveFilter($stale));

		$recent = $this->createUser();
		$recent->setLastLoginTime(\Drupal::time()->getCurrentTime());
		$recent->save();
		$this->assertFalse(CampaignHelper::inactiveFilter($recent));
		$this->assertTrue(CampaignHelper::activeFilter($recent));
	}

	#[Test]
	#[TestDox('activeVerifiedFilter requires both recent activity and a verified email')]
	#[Group('mantle2/campaigns')]
	public function activeVerifiedFilter(): void
	{
		$activeVerified = $this->verifiedActiveUser();
		$this->assertTrue(CampaignHelper::activeVerifiedFilter($activeVerified));

		$activeUnverified = $this->verifiedActiveUser(['field_email_verified' => false]);
		$this->assertFalse(CampaignHelper::activeVerifiedFilter($activeUnverified));

		$staleVerified = $this->verifiedActiveUser();
		$staleVerified->setLastLoginTime(strtotime('-3 weeks'));
		$staleVerified->save();
		$this->assertFalse(CampaignHelper::activeVerifiedFilter($staleVerified));
	}

	// Placeholders

	#[Test]
	#[TestDox('runPlaceholders substitutes the local user placeholders in a template')]
	#[Group('mantle2/campaigns')]
	public function runPlaceholdersUserFields(): void
	{
		$user = $this->verifiedActiveUser();
		$text =
			'Hi {user.first_name} {user.last_name} (@{user.username}), id {user.id}, mail {user.email}';
		$result = CampaignHelper::runPlaceholders($text, $user);

		$this->assertStringContainsString('Ada Lovelace', $result);
		$this->assertStringContainsString('@' . $user->getAccountName(), $result);
		$this->assertStringContainsString('id ' . $user->id(), $result);
		$this->assertStringContainsString($user->getEmail(), $result);
		$this->assertStringNotContainsString('{user.', $result);
	}

	#[Test]
	#[TestDox('runPlaceholders falls back to the username when the display name is empty')]
	#[Group('mantle2/campaigns')]
	public function runPlaceholdersIdentifierFallback(): void
	{
		$user = $this->createUser(['field_email_verified' => true]);
		$user->setLastLoginTime(\Drupal::time()->getCurrentTime());
		$user->save();

		$result = CampaignHelper::runPlaceholders('{user.identifier}', $user);
		$this->assertSame('@' . $user->getAccountName(), $result);
	}

	#[Test]
	#[TestDox('processCampaign expands user placeholders in the verify_email title and body')]
	#[Group('mantle2/campaigns')]
	public function processCampaignSubstitutesBody(): void
	{
		$user = $this->verifiedActiveUser();
		$campaign = CampaignHelper::getCampaign('verify_email');

		$processed = CampaignHelper::processCampaign($campaign, $user);

		$this->assertSame($campaign['title'], $processed['title']);
		$this->assertStringContainsString('@' . $user->getAccountName(), $processed['body']);
		$this->assertStringNotContainsString('{user.username}', $processed['body']);
		// unrelated keys pass through untouched
		$this->assertSame($campaign['interval'], $processed['interval']);
	}
}
