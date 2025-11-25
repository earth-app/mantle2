<?php

namespace Drupal\mantle2\Service;

class HTMLFactory
{
	/**
	 * Convert text with basic formatting to HTML
	 *
	 * @param string $text The text to convert
	 * @param bool $includeUnsubscribe Whether to include an unsubscribe link
	 * @param string|null $unsubscribeUrl The unsubscribe URL to use
	 * @return string The HTML output
	 */
	public function toHtml(
		string $text,
		bool $includeUnsubscribe = false,
		?string $unsubscribeUrl = null,
	): string {
		// Normalize line endings and trim whitespace
		$text = trim($text);
		$text = str_replace(["\r\n", "\r"], "\n", $text);

		// Convert text to HTML
		$html = $this->convertToHtml($text);

		// Wrap for email rendering
		$html = $this->wrapEmailContent($html, $includeUnsubscribe, $unsubscribeUrl);

		// Return full HTML document for email clients
		return $this->createHtmlDocument($html);
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
		$emptyLineCount = 0;

		foreach ($lines as $line) {
			$trimmedLine = trim($line);

			// Track empty lines but don't skip them yet
			if (empty($trimmedLine)) {
				$emptyLineCount++;
				if ($inList) {
					$html .= '</ul>';
					$inList = false;
				}
				continue;
			}

			// Reset empty line counter
			$emptyLineCount = 0;

			// Handle headers (lines starting with #)
			if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmedLine, $matches)) {
				if ($inList) {
					$html .= '</ul>';
					$inList = false;
				}
				$level = strlen($matches[1]); // Number of # symbols
				$headerText = $this->formatInlineElements($matches[2]);
				$html .= $this->formatHeader($level, $headerText);
				continue;
			}

			// Handle unordered lists (lines starting with -)
			if (preg_match('/^-\s+(.+)$/', $trimmedLine, $matches)) {
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
				$this->formatInlineElements($trimmedLine) .
				'</p>';
		}

		// Close any open list
		if ($inList) {
			$html .= '</ul>';
		}

		return $html;
	}

	/**
	 * Format header elements with appropriate styling
	 */
	private function formatHeader(int $level, string $text): string
	{
		$sizes = [
			1 => '28px',
			2 => '24px',
			3 => '20px',
			4 => '18px',
			5 => '16px',
			6 => '14px',
		];

		$margins = [
			1 => '0 0 24px 0',
			2 => '0 0 20px 0',
			3 => '0 0 16px 0',
			4 => '0 0 14px 0',
			5 => '0 0 12px 0',
			6 => '0 0 10px 0',
		];

		$fontSize = $sizes[$level] ?? $sizes[6];
		$margin = $margins[$level] ?? $margins[6];

		return "<h{$level} style=\"margin: {$margin}; font-size: {$fontSize}; font-weight: bold; color: #222;\">{$text}</h{$level}>";
	}

	/**
	 * Format inline elements like bold, links, etc.
	 */
	private function formatInlineElements(string $text): string
	{
		// First, escape HTML to prevent XSS
		$text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);

		// Process markdown links [text](url) which may contain nested formatting
		// This must happen before bold/italic conversion to properly handle [**text**](url)
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^\)]+)\)/',
			function ($matches) {
				$linkText = $matches[1];
				$url = $matches[2];

				// Process bold within link text: **text** -> <strong>text</strong>
				$linkText = preg_replace(
					'/\*\*(.+?)\*\*/',
					'<strong style="font-weight: bold;">$1</strong>',
					$linkText,
				);

				// Process italic within link text: *text* -> <em>text</em>
				$linkText = preg_replace(
					'/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/',
					'<em style="font-style: italic;">$1</em>',
					$linkText,
				);

				return '<a href="' .
					$url .
					'" style="color: #007bff; text-decoration: none;">' .
					$linkText .
					'</a>';
			},
			$text,
		);

		// Convert plain URLs to links, but skip URLs already in anchor tags
		$text = preg_replace(
			'/(?<!href=")(?<!">)(https?:\/\/[^\s<]+)(?!<\/a>)/',
			'<a href="$1" style="color: #007bff; text-decoration: none;">$1</a>',
			$text,
		);

		// Convert **text** to <strong>text</strong> (for non-link text)
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
	private function wrapEmailContent(
		string $html,
		bool $includeUnsubscribe = false,
		?string $unsubscribeUrl = null,
	): string {
		// Wrap in email-safe container and include branding details
		$wrapper =
			'<div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5; color: #333;">';
		$wrapper .= $html;
		$wrapper .= '</div>';
		$wrapper .= $this->getBrandingHtml($includeUnsubscribe, $unsubscribeUrl);

		return $wrapper;
	}

	private function getBrandingHtml(
		bool $includeUnsubscribe = false,
		?string $unsubscribeUrl = null,
	): string {
		$branding =
			'<div style="margin-top: 32px; padding-top: 32px; border-top: 1px solid #e0e0e0; font-size: 12px; color: #666;">';
		$branding .= '<p style="margin: 0 0 8px 0;">Thank you for using The Earth App!</p>';
		$branding .=
			'<p style="margin: 0 0 8px 0;">If you have any questions, feel free to <a href="mailto:support@earth-app.com" style="color: #007bff; text-decoration: none;">contact our support team</a>.</p>';

		if ($includeUnsubscribe && $unsubscribeUrl) {
			$branding .=
				'<p style="margin: 0 0 8px 0;"><a href="' .
				htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8') .
				'" style="color: #007bff; text-decoration: none;">Unsubscribe from these emails</a></p>';
		}

		$branding .=
			'<img src="https://cdn.earth-app.com/earth-app.png" alt="The Earth App Logo" style="width: auto; height: 32px; margin: 16px 0; display: block;">';
		$branding .=
			'<p style="margin: 8px 0 0 0; font-size: 11px; color: #999;">&copy; ' .
			date('Y') .
			' The Earth App. All rights reserved.</p>';
		$branding .=
			'<p style="margin: 8px 0 0 0; font-size: 11px; color: #999;">This email was sent from a notification-only address that cannot accept incoming email. Please do not reply to this message.</p>';
		$branding .= '</div>';

		return $branding;
	}

	private function createHtmlDocument(string $content): string
	{
		$html = '<!DOCTYPE html>';
		$html .= '<html lang="en">';
		$html .= '<head>';
		$html .= '<meta charset="UTF-8">';
		$html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
		$html .= '<title>The Earth App</title>';
		$html .= '</head>';
		$html .= '<body style="margin: 0; padding: 0; background-color: #f5f5f5;">';
		$html .= '<table role="presentation" style="width: 100%; border-collapse: collapse;">';
		$html .= '<tr>';
		$html .= '<td align="center" style="padding: 40px 0;">';
		$html .=
			'<table role="presentation" style="max-width: 600px; width: 100%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
		$html .= '<tr>';
		$html .= '<td style="padding: 40px;">';
		$html .= $content;
		$html .= '</td>';
		$html .= '</tr>';
		$html .= '</table>';
		$html .= '</td>';
		$html .= '</tr>';
		$html .= '</table>';
		$html .= '</body>';
		$html .= '</html>';

		return $html;
	}
}
