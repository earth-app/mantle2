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
			'max_nesting_level' => 10,
			'renderer' => [
				'block_separator' => "\n",
				'inner_separator' => "\n",
				'soft_break' => "<br>\n",
			],
		];

		$environment = new Environment($config);
		$environment->addExtension(new CommonMarkCoreExtension());
		$environment->addExtension(new GithubFlavoredMarkdownExtension());

		$this->converter = new MarkdownConverter($environment);
	}

	public function toHtml(string $markdown): string
	{
		// Normalize line endings and trim whitespace
		$markdown = trim($markdown);
		$markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

		// Convert markdown to HTML
		$html = $this->converter->convert($markdown)->getContent();

		// Ensure proper paragraph wrapping for email
		$html = $this->wrapEmailContent($html);

		return $html;
	}

	/**
	 * Wrap email content for better rendering in email clients
	 */
	private function wrapEmailContent(string $html): string
	{
		// Add basic email-safe styles
		$html = str_replace(
			['<p>', '<strong>', '<br>'],
			[
				'<p style="margin: 0 0 16px 0; line-height: 1.5;">',
				'<strong style="font-weight: bold;">',
				'<br>',
			],
			$html,
		);

		// Wrap in email-safe container
		return '<div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5; color: #333;">' .
			$html .
			'</div>';
	}
}
