<?php

namespace Drupal\mantle2\Service;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

class MarkdownFactory
{
	protected $converter;

	public function __construct()
	{
		$config = [
			'html_input' => 'strip',
			'allow_unsafe_links' => false,
		];

		$environment = new Environment($config);
		$environment->addExtension(new CommonMarkCoreExtension());
		$environment->addExtension(new GithubFlavoredMarkdownExtension());

		$this->converter = new MarkdownConverter($environment);
	}

	public function toHtml(string $markdown): string
	{
		return $this->converter->convert($markdown)->getContent();
	}
}
