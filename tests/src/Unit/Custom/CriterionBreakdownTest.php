<?php

namespace Drupal\Tests\mantle2\Unit\Custom;

use Drupal\mantle2\Custom\CriterionBreakdown;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class CriterionBreakdownTest extends TestCase
{
	#[Test]
	#[TestDox('Constructor stores public fields')]
	#[Group('mantle2/custom')]
	public function testConstruct(): void
	{
		$c = new CriterionBreakdown('creativity', 0.8, 0.9, 0.15);
		$this->assertSame('creativity', $c->id);
		$this->assertSame(0.8, $c->similarity);
		$this->assertSame(0.9, $c->normalized);
		$this->assertSame(0.15, $c->weight);
	}

	#[Test]
	#[TestDox('jsonSerialize returns id, similarity, normalized, weight')]
	#[Group('mantle2/custom')]
	public function testJsonSerialize(): void
	{
		$json = new CriterionBreakdown('c', -1.0, 0.0, 1.0)->jsonSerialize();
		$this->assertSame(['id', 'similarity', 'normalized', 'weight'], array_keys($json));
		$this->assertSame('c', $json['id']);
		$this->assertSame(-1.0, $json['similarity']);
		$this->assertSame(0.0, $json['normalized']);
		$this->assertSame(1.0, $json['weight']);
	}
}
