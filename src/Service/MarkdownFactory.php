<?php

namespace Drupal\mantle2\Service;

use League\CommonMark\CommonMarkConverter;

class MarkdownFactory
{
	protected $converter;

	public function __construct()
	{
		$config = [
			'html_input' => 'strip',
			'allow_unsafe_links' => false,
		];
		$this->converter = new CommonMarkConverter($config);
	}

	public function toHtml(string $markdown): string
	{
		return $this->converter->convert($markdown)->getContent();
	}
}
