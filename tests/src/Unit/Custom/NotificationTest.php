<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\Notification;
use Drupal\mantle2\Service\GeneralHelper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class NotificationTest extends TestCase
{
	private function make(array $overrides = []): Notification
	{
		$d = array_merge(
			[
				'id' => 'ntf_1',
				'userId' => '42',
				'title' => 'Welcome',
				'message' => "Summary\nFull body text",
				'timestamp' => 1717000000,
				'link' => 'https://earth-app.com/x',
				'type' => 'info',
				'source' => 'system',
				'isRead' => false,
			],
			$overrides,
		);

		return new Notification(
			$d['id'],
			$d['userId'],
			$d['title'],
			$d['message'],
			$d['timestamp'],
			$d['link'],
			$d['type'],
			$d['source'],
			$d['isRead'],
		);
	}

	#[Test]
	#[TestDox('Every getter returns its constructor argument')]
	#[Group('mantle2/custom')]
	public function testGetters(): void
	{
		$n = $this->make();
		$this->assertSame('ntf_1', $n->getId());
		$this->assertSame('42', $n->getUserId());
		$this->assertSame('Welcome', $n->getTitle());
		$this->assertSame("Summary\nFull body text", $n->getMessage());
		$this->assertSame(1717000000, $n->getTimestamp());
		$this->assertSame('https://earth-app.com/x', $n->getLink());
		$this->assertSame('info', $n->getType());
		$this->assertSame('system', $n->getSource());
		$this->assertFalse($n->isRead());
	}

	#[Test]
	#[TestDox('Optional constructor args default to null link, info type, system source, unread')]
	#[Group('mantle2/custom')]
	public function testDefaults(): void
	{
		$n = new Notification('id', 'u', 't', 'm', 100);
		$this->assertNull($n->getLink());
		$this->assertSame('info', $n->getType());
		$this->assertSame('system', $n->getSource());
		$this->assertFalse($n->isRead());
	}

	#[Test]
	#[TestDox('Setters mutate title, message, link, and read state')]
	#[Group('mantle2/custom')]
	public function testSetters(): void
	{
		$n = $this->make();

		$n->setTitle('New Title');
		$this->assertSame('New Title', $n->getTitle());

		$n->setMessage('changed');
		$this->assertSame('changed', $n->getMessage());

		$n->setLink(null);
		$this->assertNull($n->getLink());
		$n->setLink('https://x.test');
		$this->assertSame('https://x.test', $n->getLink());

		$n->setRead();
		$this->assertTrue($n->isRead());
		$n->setRead(false);
		$this->assertFalse($n->isRead());
	}

	#[Test]
	#[
		TestDox(
			'jsonSerialize emits canonical keys with formatted user id and read/created_at mapping',
		),
	]
	#[Group('mantle2/custom')]
	public function testJsonSerialize(): void
	{
		$json = $this->make(['isRead' => true])->jsonSerialize();

		$this->assertSame(
			['id', 'title', 'user_id', 'message', 'link', 'type', 'source', 'read', 'created_at'],
			array_keys($json),
		);
		$this->assertSame('ntf_1', $json['id']);
		$this->assertSame('Welcome', $json['title']);
		$this->assertSame(GeneralHelper::formatId('42'), $json['user_id']);
		$this->assertSame("Summary\nFull body text", $json['message']);
		$this->assertSame('https://earth-app.com/x', $json['link']);
		$this->assertSame('info', $json['type']);
		$this->assertSame('system', $json['source']);
		$this->assertTrue($json['read']);
		$this->assertSame(1717000000, $json['created_at']);
	}

	#[Test]
	#[TestDox('jsonSerialize preserves a null link')]
	#[Group('mantle2/custom')]
	public function testJsonSerializeNullLink(): void
	{
		$json = new Notification('id', 'u', 't', 'm', 100)->jsonSerialize();
		$this->assertNull($json['link']);
		$this->assertFalse($json['read']);
	}
}
