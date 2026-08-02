<?php

namespace Drupal\Tests\mantle2\Unit;

use Drupal\mantle2\Service\UsersHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Address hygiene, which is the cheapest place to stop a bounce.
 *
 * A live send burst measured a 35.6% bounce rate and the provider warned that sending would be
 * paused, which would have taken password resets down with it. Half of those failures were relay
 * addresses whose sending domain was unregistered; the other half were relay aliases the owner had
 * torn down, retried forever because nothing recorded a permanent failure.
 */
class EmailDeliverabilityTest extends TestCase
{
	public static function rejectedProvider(): array
	{
		return [
			'reserved .test tld' => ['someone@mybox.test'],
			'reserved .invalid tld' => ['someone@mybox.invalid'],
			'reserved localhost' => ['someone@localhost'],
			'reserved .local' => ['someone@printer.local'],
			'disposable mailinator' => ['someone@mailinator.com'],
			'disposable guerrillamail' => ['someone@guerrillamail.com'],
			'disposable 10minutemail' => ['someone@10minutemail.com'],
			'disposable yopmail' => ['someone@yopmail.com'],
			'disposable temp-mail' => ['someone@temp-mail.org'],
			'role noreply' => ['noreply@earth-app.com'],
			'role no-reply' => ['no-reply@earth-app.com'],
			'role postmaster' => ['postmaster@earth-app.com'],
			'role abuse' => ['abuse@earth-app.com'],
			'not an address' => ['not-an-email'],
			'no domain' => ['someone@'],
		];
	}

	#[Test]
	#[TestDox('Signup rejects $email')]
	#[Group('mantle2/util')]
	#[DataProvider('rejectedProvider')]
	public function testUnusableAddressesAreRejected(string $email): void
	{
		$this->assertFalse(
			UsersHelper::isAcceptableEmail($email),
			"$email can never receive mail and must not reach the send queue.",
		);
	}

	public static function relayProvider(): array
	{
		return [
			'apple private relay' => ['abc123xyz@privaterelay.appleid.com'],
			'firefox relay' => ['abc123@mozmail.com'],
			'duckduckgo' => ['abc123@duck.com'],
			'simplelogin' => ['abc123@simplelogin.io'],
			'aleeas' => ['abc123@aleeas.com'],
			'anonaddy' => ['abc123@anonaddy.me'],
			'addy.io' => ['abc123@addy.io'],
		];
	}

	/**
	 * The regression this file exists for.
	 *
	 * Blocking privaterelay.appleid.com looks like a fix for the bounce rate and is actually an
	 * account lockout: every Sign in with Apple user's verification and password-reset mail goes
	 * there. The fix is registering the sending domain with Apple, never blocking the recipient.
	 */
	#[Test]
	#[TestDox('Relay address $email is accepted, never blocked')]
	#[Group('mantle2/util')]
	#[DataProvider('relayProvider')]
	public function testRelayAddressesAreAccepted(string $email): void
	{
		$this->assertTrue(
			UsersHelper::isAcceptableEmail($email),
			"$email belongs to a real user; blocking it locks them out of their own account.",
		);
		$this->assertTrue(
			UsersHelper::isRelayEmail($email),
			"$email should be recognised as a relay so a bounce reads as a config alarm.",
		);
	}

	public static function acceptedProvider(): array
	{
		return [
			'gmail' => ['someone@gmail.com'],
			'icloud' => ['someone@icloud.com'],
			'own domain' => ['gregory@earth-app.com'],
			'subdomain' => ['someone@mail.example.co.uk'],
			'plus addressing' => ['someone+tag@gmail.com'],
			// the documentation domain stays usable on purpose; see RESERVED_EMAIL_DOMAINS
			'documentation domain' => ['someone@example.com'],
		];
	}

	#[Test]
	#[TestDox('Ordinary address $email is accepted')]
	#[Group('mantle2/util')]
	#[DataProvider('acceptedProvider')]
	public function testOrdinaryAddressesAreAccepted(string $email): void
	{
		$this->assertTrue(UsersHelper::isAcceptableEmail($email));
		$this->assertFalse(UsersHelper::isRelayEmail($email));
	}

	#[Test]
	#[TestDox('emailDomain lowercases and trims, and is empty without an @')]
	#[Group('mantle2/util')]
	public function testEmailDomainNormalisation(): void
	{
		$this->assertSame('gmail.com', UsersHelper::emailDomain('Someone@GMAIL.com'));
		$this->assertSame('gmail.com', UsersHelper::emailDomain('someone@ gmail.com '));
		$this->assertSame('', UsersHelper::emailDomain('someone'));

		// an address with two @ signs takes the last one, matching how MTAs split it
		$this->assertSame('real.com', UsersHelper::emailDomain('a@b@real.com'));
	}

	#[Test]
	#[TestDox('The relay list never overlaps the disposable or reserved lists')]
	#[Group('mantle2/util')]
	public function testRelayListDoesNotOverlapBlockedLists(): void
	{
		// an overlap here would silently reject real users, which is the failure mode this whole
		// file guards against
		$this->assertSame(
			[],
			array_intersect(
				UsersHelper::RELAY_EMAIL_DOMAINS,
				UsersHelper::DISPOSABLE_EMAIL_DOMAINS,
			),
		);
		$this->assertSame(
			[],
			array_intersect(UsersHelper::RELAY_EMAIL_DOMAINS, UsersHelper::RESERVED_EMAIL_DOMAINS),
		);
	}
}
