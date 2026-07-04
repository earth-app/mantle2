<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\Article;
use Drupal\mantle2\Service\GeneralHelper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ArticleTest extends TestCase
{
	private function make(): Article
	{
		return new Article(
			123,
			'Title',
			'Description',
			['a', 'b'],
			'Body content',
			456,
			0xff5733,
			1717000000,
			1717000500,
			['url' => 'https://x'],
		);
	}

	#[Test]
	#[TestDox('Constructor stores fields and getters return them')]
	#[Group('mantle2/custom')]
	public function testGetters(): void
	{
		$a = $this->make();
		$this->assertSame(123, $a->getId());
		$this->assertSame('Title', $a->getTitle());
		$this->assertSame('Description', $a->getDescription());
		$this->assertSame(['a', 'b'], $a->getTags());
		$this->assertSame('Body content', $a->getContent());
		$this->assertSame(456, $a->getAuthorId());
		$this->assertSame(0xff5733, $a->getColor());
		$this->assertSame(1717000000, $a->getCreatedAt());
		$this->assertSame(1717000500, $a->getUpdatedAt());
		$this->assertSame(['url' => 'https://x'], $a->getOcean());
	}

	#[Test]
	#[TestDox('jsonSerialize formats ids, derives color_hex, and preserves timestamps')]
	#[Group('mantle2/custom')]
	public function testJsonSerialize(): void
	{
		$json = $this->make()->jsonSerialize();

		$this->assertSame(
			[
				'id',
				'title',
				'description',
				'tags',
				'content',
				'color',
				'color_hex',
				'author_id',
				'created_at',
				'updated_at',
				'ocean',
			],
			array_keys($json),
		);
		$this->assertSame(GeneralHelper::formatId(123), $json['id']);
		$this->assertSame(GeneralHelper::formatId(456), $json['author_id']);
		$this->assertSame(0xff5733, $json['color']);
		$this->assertSame('#FF5733', $json['color_hex']);
		$this->assertSame(1717000000, $json['created_at']);
		$this->assertSame(1717000500, $json['updated_at']);
		$this->assertSame(['url' => 'https://x'], $json['ocean']);
	}
}
