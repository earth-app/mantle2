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
				'block_separator' => "<br>\n",
				'inner_separator' => "<br>\n",
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

		// Wrap in email-safe container and include branding details
		return '<div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5; color: #333;">' .
			$html .
			'</div>' .
			$this->getBrandingHtml();
	}

	private function getBrandingHtml(): string
	{
		return '<div style="margin-top: 32px; font-size: 10px; color: #999;">
			<p>Thank you for using The Earth App!</p>
			<p>If you have any questions, feel free to <a href="mailto:support@earth-app.com">contact our support team</a>.</p>
			<img src="https://cdn.earth-app.com/earth-app.png" alt="The Earth App Logo" style="height: 24px; margin-top: 8px;">
			<p style="margin-top: 8px;">&copy; ' .
			date('Y') .
			' The Earth App. All rights reserved.</p>
			<p>This email was sent from a notification-only address that cannot accept incoming email. Please do not reply to this message.</p>
		</div>';
	}
}
