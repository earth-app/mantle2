<?php

namespace Drupal\mantle2\Service;

class HTMLFactory
{
	/**
	 * Convert text with basic formatting to HTML
	 *
	 * @param string $text The text to convert
	 * @return string The HTML output
	 */
	public function toHtml(string $text): string
	{
		// Normalize line endings and trim whitespace
		$text = trim($text);
		$text = str_replace(["\r\n", "\r"], "\n", $text);

		// Convert text to HTML
		$html = $this->convertToHtml($text);

		// Wrap for email rendering
		$html = $this->wrapEmailContent($html);

		return $html;
	}

	/**
	 * Convert text with formatting markers to HTML
	 */
	private function convertToHtml(string $text): string
	{
		// Split into lines for processing
		$lines = explode("\n", $text);
		$html = '';
		$inList = false;

		foreach ($lines as $line) {
			$line = trim($line);

			// Skip empty lines
			if (empty($line)) {
				if ($inList) {
					$html .= '</ul>';
					$inList = false;
				}
				continue;
			}

			// Handle unordered lists (lines starting with -)
			if (preg_match('/^-\s+(.+)$/', $line, $matches)) {
				if (!$inList) {
					$html .= '<ul style="margin: 0 0 16px 0; padding-left: 20px;">';
					$inList = true;
				}
				$html .=
					'<li style="margin: 4px 0;">' .
					$this->formatInlineElements($matches[1]) .
					'</li>';
				continue;
			}

			// Close list if we were in one
			if ($inList) {
				$html .= '</ul>';
				$inList = false;
			}

			// Handle regular paragraphs
			$html .=
				'<p style="margin: 0 0 16px 0; line-height: 1.5;">' .
				$this->formatInlineElements($line) .
				'</p>';
		}

		// Close any open list
		if ($inList) {
			$html .= '</ul>';
		}

		return $html;
	}

	/**
	 * Format inline elements like bold, links, etc.
	 */
	private function formatInlineElements(string $text): string
	{
		// First, escape HTML to prevent XSS
		$text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);

		// Convert links [text](url) to <a href="url">text</a>
		// Must happen before plain URL conversion to avoid double-conversion
		$text = preg_replace(
			'/\[([^\]]+)\]\(([^\)]+)\)/',
			'<a href="$2" style="color: #007bff; text-decoration: none;">$1</a>',
			$text,
		);

		// Convert plain URLs to links, but skip URLs already in anchor tags
		$text = preg_replace(
			'/(?<!href=")(?<!">)(https?:\/\/[^\s<]+)(?!<\/a>)/',
			'<a href="$1" style="color: #007bff; text-decoration: none;">$1</a>',
			$text,
		);

		// Convert **text** to <strong>text</strong>
		$text = preg_replace(
			'/\*\*(.+?)\*\*/',
			'<strong style="font-weight: bold;">$1</strong>',
			$text,
		);

		// Convert *text* to <em>text</em> (must happen after ** to avoid conflicts)
		$text = preg_replace(
			'/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/',
			'<em style="font-style: italic;">$1</em>',
			$text,
		);

		return $text;
	}

	/**
	 * Wrap email content for better rendering in email clients
	 */
	private function wrapEmailContent(string $html): string
	{
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
			<p>If you have any questions, feel free to <a href="mailto:support@earth-app.com" style="color: #007bff; text-decoration: none;">contact our support team</a>.</p>
			<img src="https://cdn.earth-app.com/earth-app.png" alt="The Earth App Logo" style="height: 24px; margin-top: 8px;">
			<p style="margin-top: 8px;">&copy; ' .
			date('Y') .
			' The Earth App. All rights reserved.</p>
			<p>This email was sent from a notification-only address that cannot accept incoming email. Please do not reply to this message.</p>
		</div>';
	}
}
