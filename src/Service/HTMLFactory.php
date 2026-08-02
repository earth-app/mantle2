<?php

namespace Drupal\mantle2\Service;

use Drupal;

class HTMLFactory
{
	public const ACCENT = '#2e7d32';
	private const CDN_HOST = 'cdn.earth-app.com';
	private const MAX_CTAS = 2;
	private const PREHEADER_LENGTH = 90;

	private const SOCIAL_LINKS = [
		'Instagram' => 'https://instagram.com/theearthapp',
		'X' => 'https://x.com/theearthapp',
		'GitHub' => 'https://github.com/earth-app',
	];

	/**
	 * Convert text with basic formatting to HTML
	 *
	 * @param string $text The text to convert
	 * @param bool $includeUnsubscribe Whether to include an unsubscribe link
	 * @param string|null $unsubscribeUrl The unsubscribe URL to use
	 * @param array $options preheader, cta, title, accent, utm, footer_links
	 * @return string The HTML output
	 */
	public function toHtml(
		string $text,
		bool $includeUnsubscribe = false,
		?string $unsubscribeUrl = null,
		array $options = [],
	): string {
		// Normalize line endings and trim whitespace
		$text = trim($text);
		$text = str_replace(["\r\n", "\r"], "\n", $text);

		$accent = $this->sanitizeColor($options['accent'] ?? self::ACCENT);
		$utm = is_array($options['utm'] ?? null) ? $options['utm'] : [];
		$ctas = $this->normalizeCtas($options['cta'] ?? null);

		// Convert text to HTML
		$html = $this->convertToHtml($text, $accent, $utm);

		foreach (array_slice($ctas, 0, self::MAX_CTAS) as $cta) {
			$html .= $this->getCtaHtml($cta, $accent, $utm);
		}

		// Wrap for email rendering
		$html = $this->wrapEmailContent(
			$html,
			$includeUnsubscribe,
			$unsubscribeUrl,
			$options['footer_links'] ?? true,
			$utm,
		);

		$preheader = $options['preheader'] ?? $this->derivePreheader($text);

		// Return full HTML document for email clients
		return $this->createHtmlDocument(
			$html,
			$options['title'] ?? 'The Earth App',
			$accent,
			$preheader,
		);
	}

	/**
	 * Strip markdown down to readable text; also feeds the inbox preview line.
	 */
	public function toPlainText(string $markdown): string
	{
		$text = str_replace(["\r\n", "\r"], "\n", trim($markdown));
		$text = preg_replace('/^#{1,6}\s+/m', '', $text);
		$text = preg_replace('/^\s*[-*]\s+/m', '- ', $text);
		$text = preg_replace('/^\s*>\s?/m', '', $text);
		$text = preg_replace('/^\s*```.*$/m', '', $text);
		$text = preg_replace('/^\s*---+\s*$/m', '', $text);
		$text = preg_replace('/\[\[([^\]]+)\]\]\(([^)]+)\)/', '$1 <$2>', $text);
		// linked image keeps the target, not the image src
		$text = preg_replace('/\[!\[([^\]]*)\]\(([^)]+)\)\]\(([^)]+)\)/', '$1 <$3>', $text);
		$text = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '$1', $text);
		$text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1 <$2>', $text);
		$text = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text);
		$text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '$1', $text);

		return trim(preg_replace("/\n{3,}/", "\n\n", $text));
	}

	/**
	 * Convert text with formatting markers to HTML
	 */
	private function convertToHtml(
		string $text,
		string $accent = self::ACCENT,
		array $utm = [],
	): string {
		// Split into lines for processing
		$lines = explode("\n", $text);
		$html = '';
		// block state lives in an array so closeBlocks() can own the transitions
		$open = ['ul' => false, 'ol' => false, 'quote' => false];
		$inCode = false;
		$codeBuffer = [];
		$tableBuffer = [];
		$emptyLineCount = 0;

		foreach ($lines as $line) {
			$trimmedLine = trim($line);

			// fenced code blocks swallow everything until the closing fence
			if (str_starts_with($trimmedLine, '```')) {
				if ($inCode) {
					$html .= $this->renderCode($codeBuffer);
					$codeBuffer = [];
					$inCode = false;
				} else {
					$html .= $this->closeBlocks($open);
					$html .= $this->flushTable($tableBuffer, $accent, $utm);
					$inCode = true;
				}
				continue;
			}
			if ($inCode) {
				$codeBuffer[] = $line;
				continue;
			}

			if (str_contains($trimmedLine, '|') && preg_match('/^\|.*\|$/', $trimmedLine)) {
				$html .= $this->closeBlocks($open);
				$tableBuffer[] = $trimmedLine;
				continue;
			}
			$html .= $this->flushTable($tableBuffer, $accent, $utm);

			// Track empty lines but don't skip them yet
			if (empty($trimmedLine)) {
				$emptyLineCount++;
				$html .= $this->closeBlocks($open);
				continue;
			}

			// Reset empty line counter
			$emptyLineCount = 0;

			if (preg_match('/^-{3,}$/', $trimmedLine)) {
				$html .= $this->closeBlocks($open);
				$html .=
					'<hr style="border: none; border-top: 1px solid #e0e0e0; margin: 24px 0;">';
				continue;
			}

			// button syntax so campaign bodies can emit a CTA with no code change
			if (preg_match('/^\[\[([^\]]+)\]\]\(([^)]+)\)$/', $trimmedLine, $matches)) {
				$html .= $this->closeBlocks($open);
				$html .= $this->getCtaHtml(
					['label' => $matches[1], 'url' => $matches[2]],
					$accent,
					$utm,
				);
				continue;
			}

			// linked image; must precede the bare-image check and cannot go through
			// formatInlineElements, whose link pattern will not span the nested ]
			if (preg_match('/^\[!\[([^\]]*)\]\(([^)]+)\)\]\(([^)]+)\)$/', $trimmedLine, $matches)) {
				$html .= $this->closeBlocks($open);
				$html .= $this->renderImage($matches[1], $matches[2], $matches[3], $utm);
				continue;
			}

			if (preg_match('/^!\[([^\]]*)\]\(([^)]+)\)$/', $trimmedLine, $matches)) {
				$html .= $this->closeBlocks($open);
				$html .= $this->renderImage($matches[1], $matches[2]);
				continue;
			}

			// Handle headers (lines starting with #)
			if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmedLine, $matches)) {
				$html .= $this->closeBlocks($open);
				$level = strlen($matches[1]); // Number of # symbols
				$headerText = $this->formatInlineElements($matches[2], $utm);
				$html .= $this->formatHeader($level, $headerText);
				continue;
			}

			if (preg_match('/^>\s?(.*)$/', $trimmedLine, $matches)) {
				if ($open['ul'] || $open['ol']) {
					$html .= $this->closeBlocks($open);
				}
				if (!$open['quote']) {
					$html .=
						'<blockquote style="margin: 0 0 16px 0; padding: 8px 0 8px 16px; border-left: 3px solid ' .
						$accent .
						'; color: #555; font-style: italic;">';
					$open['quote'] = true;
				}
				$html .=
					'<p style="margin: 0 0 8px 0; line-height: 1.5;">' .
					$this->formatInlineElements($matches[1], $utm) .
					'</p>';
				continue;
			}

			if (preg_match('/^\d+\.\s+(.+)$/', $trimmedLine, $matches)) {
				if ($open['ul'] || $open['quote']) {
					$html .= $this->closeBlocks($open);
				}
				if (!$open['ol']) {
					$html .= '<ol style="margin: 0 0 16px 0; padding-left: 20px;">';
					$open['ol'] = true;
				}
				$html .=
					'<li style="margin: 4px 0;">' .
					$this->formatInlineElements($matches[1], $utm) .
					'</li>';
				continue;
			}

			// Handle unordered lists (lines starting with -)
			if (preg_match('/^-\s+(.+)$/', $trimmedLine, $matches)) {
				if ($open['ol'] || $open['quote']) {
					$html .= $this->closeBlocks($open);
				}
				if (!$open['ul']) {
					$html .= '<ul style="margin: 0 0 16px 0; padding-left: 20px;">';
					$open['ul'] = true;
				}
				$html .=
					'<li style="margin: 4px 0;">' .
					$this->formatInlineElements($matches[1], $utm) .
					'</li>';
				continue;
			}

			// Close list if we were in one
			$html .= $this->closeBlocks($open);

			// Handle regular paragraphs
			$html .=
				'<p style="margin: 0 0 16px 0; line-height: 1.5;">' .
				$this->formatInlineElements($trimmedLine, $utm) .
				'</p>';
		}

		if ($inCode && $codeBuffer) {
			$html .= $this->renderCode($codeBuffer);
		}
		$html .= $this->flushTable($tableBuffer, $accent, $utm);
		$html .= $this->closeBlocks($open);

		return $html;
	}

	/**
	 * @param array{ul: bool, ol: bool, quote: bool} $open
	 */
	private function closeBlocks(array &$open): string
	{
		$html = '';
		if ($open['ul']) {
			$html .= '</ul>';
			$open['ul'] = false;
		}
		if ($open['ol']) {
			$html .= '</ol>';
			$open['ol'] = false;
		}
		if ($open['quote']) {
			$html .= '</blockquote>';
			$open['quote'] = false;
		}

		return $html;
	}

	private function flushTable(array &$rows, string $accent, array $utm): string
	{
		if (!$rows) {
			return '';
		}

		$html = $this->renderTable($rows, $accent, $utm);
		$rows = [];

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
	private function formatInlineElements(string $text, array $utm = []): string
	{
		// First, escape HTML to prevent XSS
		$text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);

		// Process markdown links [text](url) which may contain nested formatting
		// This must happen before bold/italic conversion to properly handle [**text**](url)
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^\)]+)\)/',
			function ($matches) use ($utm) {
				$linkText = $matches[1];
				$url = $this->sanitizeUrl($matches[2], $utm);

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

				// an unsafe scheme degrades to plain text rather than rendering an anchor
				if ($url === null) {
					return $linkText;
				}

				return '<a href="' .
					$url .
					'" style="color: #007bff; text-decoration: none;">' .
					$linkText .
					'</a>';
			},
			$text,
		);

		// Convert plain URLs to links, but skip URLs already in anchor tags
		$text = preg_replace_callback(
			'/(?<!href=")(?<!">)(https?:\/\/[^\s<]+)(?!<\/a>)/',
			function ($matches) use ($utm) {
				$url = $this->sanitizeUrl($matches[1], $utm);
				if ($url === null) {
					return $matches[1];
				}

				return '<a href="' .
					$url .
					'" style="color: #007bff; text-decoration: none;">' .
					$matches[1] .
					'</a>';
			},
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
		bool $footerLinks = true,
		array $utm = [],
	): string {
		// Wrap in email-safe container and include branding details
		$wrapper =
			'<div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5; color: #333;">';
		$wrapper .= $html;
		$wrapper .= '</div>';
		$wrapper .= $this->getFooterHtml($includeUnsubscribe, $unsubscribeUrl, $footerLinks, $utm);

		return $wrapper;
	}

	private function getFooterHtml(
		bool $includeUnsubscribe = false,
		?string $unsubscribeUrl = null,
		bool $footerLinks = true,
		array $utm = [],
	): string {
		$branding =
			'<div style="margin-top: 32px; padding-top: 32px; border-top: 1px solid #e0e0e0; font-size: 12px; color: #666;">';
		$branding .= '<p style="margin: 0 0 8px 0;">Thank you for using The Earth App!</p>';
		$branding .=
			'<p style="margin: 0 0 8px 0;">If you have any questions, feel free to <a href="mailto:support@earth-app.com" style="color: #007bff; text-decoration: none;">contact our support team</a>.</p>';

		if ($footerLinks) {
			$links = [];
			foreach (self::SOCIAL_LINKS as $label => $url) {
				$href = $this->sanitizeUrl($url, $utm);
				if ($href !== null) {
					$links[] =
						'<a href="' .
						$href .
						'" style="color: #007bff; text-decoration: none;">' .
						$label .
						'</a>';
				}
			}
			if ($links) {
				$branding .=
					'<p style="margin: 0 0 8px 0;">' .
					implode(' &nbsp;&middot;&nbsp; ', $links) .
					'</p>';
			}

			$appHref = $this->sanitizeUrl('https://app.earth-app.com', $utm);
			if ($appHref !== null) {
				$branding .=
					'<p style="margin: 0 0 8px 0;"><a href="' .
					$appHref .
					'" style="color: #007bff; text-decoration: none;">Open The Earth App</a></p>';
			}

			// promotional, so it is gated on $footerLinks; transactional mail passes false
			$storeHref = $this->sanitizeUrl('https://earth-app.com/ios', $utm);
			if ($storeHref !== null) {
				$branding .=
					'<p style="margin: 0 0 8px 0;"><a href="' .
					$storeHref .
					'" style="color: #007bff; text-decoration: none;">Get The Earth App for iOS</a></p>';
			}
		}

		if ($includeUnsubscribe && $unsubscribeUrl) {
			// never UTM-tag this; it may be the one-click List-Unsubscribe-Post endpoint
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
		// commercial email legally requires a postal address; it is read from settings and
		// omitted entirely when unset, because a wrong address is worse than none
		$postalAddress = self::postalAddress();
		if (is_string($postalAddress) && trim($postalAddress) !== '') {
			$branding .=
				'<p style="margin: 4px 0 0 0; font-size: 11px; color: #999;">' .
				htmlspecialchars(trim($postalAddress), ENT_QUOTES, 'UTF-8') .
				'</p>';
		}
		$branding .=
			'<p style="margin: 8px 0 0 0; font-size: 11px; color: #999;">This email was sent from a notification-only address that cannot accept incoming email. Please do not reply to this message.</p>';
		$branding .= '</div>';

		return $branding;
	}

	private static function postalAddress(): ?string
	{
		try {
			if (!Drupal::hasContainer()) {
				return null;
			}

			$value = Drupal::service('settings')->get('mantle2.postal_address');
			return is_string($value) ? $value : null;
		} catch (\Throwable) {
			return null;
		}
	}

	private function getPreheaderHtml(string $text): string
	{
		if ($text === '') {
			return '';
		}

		$escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

		// the zero-width spacer is mandatory; without it Gmail appends body text to the preview
		return '<div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">' .
			$escaped .
			'</div>' .
			'<div style="display: none; max-height: 0; overflow: hidden;">' .
			str_repeat('&#847;&zwnj;&nbsp;', 30) .
			'</div>';
	}

	private function getHeaderHtml(string $accent): string
	{
		return '<tr><td style="background-color: ' .
			$accent .
			'; padding: 20px 32px;">' .
			'<img src="https://cdn.earth-app.com/earth-app.png" alt="The Earth App" style="height: 28px; width: auto; display: block;">' .
			'</td></tr>';
	}

	/**
	 * Padded-anchor button; renders acceptably everywhere without VML.
	 */
	private function getCtaHtml(array $cta, string $accent, array $utm = []): string
	{
		$url = $this->sanitizeUrl($cta['url'] ?? '', $utm);
		$label = trim((string) ($cta['label'] ?? ''));
		if ($url === null || $label === '') {
			return '';
		}

		return '<table role="presentation" style="margin: 24px 0; border-collapse: collapse;"><tr>' .
			'<td align="center" bgcolor="' .
			$accent .
			'" style="border-radius: 8px;">' .
			'<a href="' .
			$url .
			'" style="display: inline-block; padding: 14px 28px; font-family: Arial, sans-serif; font-size: 15px; font-weight: bold; color: #ffffff; text-decoration: none; border-radius: 8px;">' .
			htmlspecialchars($label, ENT_QUOTES, 'UTF-8') .
			'</a></td></tr></table>';
	}

	private function renderCode(array $lines): string
	{
		$code = htmlspecialchars(implode("\n", $lines), ENT_QUOTES, 'UTF-8');

		return '<pre style="margin: 0 0 16px 0; padding: 12px; background-color: #f6f8fa; border-radius: 6px; font-family: Consolas, Monaco, monospace; font-size: 13px; overflow-x: auto;">' .
			$code .
			'</pre>';
	}

	private function renderImage(
		string $alt,
		string $url,
		?string $href = null,
		array $utm = [],
	): string {
		$safeAlt = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');

		// only our own CDN; blocks third-party tracking pixels in campaign bodies
		$host = parse_url($url, PHP_URL_HOST);
		if (!is_string($host) || strtolower($host) !== self::CDN_HOST) {
			return '<p style="margin: 0 0 16px 0; line-height: 1.5;">' . $safeAlt . '</p>';
		}

		$img =
			'<img src="' .
			htmlspecialchars($url, ENT_QUOTES, 'UTF-8') .
			'" alt="' .
			$safeAlt .
			'" style="max-width: 100%; height: auto; display: block; margin: 0 0 16px 0; border-radius: 6px;">';

		// an unsafe target keeps the image rather than dropping the whole block
		$target = $href === null ? null : $this->sanitizeUrl($href, $utm);
		if ($target === null) {
			return $img;
		}

		return '<a href="' . $target . '" style="text-decoration: none;">' . $img . '</a>';
	}

	private function renderTable(array $rows, string $accent, array $utm): string
	{
		$parsed = [];
		foreach ($rows as $row) {
			// a |---|---| separator row only marks the header boundary
			if (preg_match('/^\|[\s:\-|]+\|$/', $row)) {
				continue;
			}
			$cells = array_map('trim', explode('|', trim($row, '|')));
			$parsed[] = $cells;
		}

		if (!$parsed) {
			return '';
		}

		$header = array_shift($parsed);
		$html =
			'<table role="presentation" style="width: 100%; border-collapse: collapse; margin: 0 0 16px 0; font-size: 13px;"><thead><tr>';
		foreach ($header as $cell) {
			$html .=
				'<th style="text-align: left; padding: 8px; border-bottom: 2px solid ' .
				$accent .
				'; color: #222;">' .
				$this->formatInlineElements($cell, $utm) .
				'</th>';
		}
		$html .= '</tr></thead><tbody>';

		foreach ($parsed as $row) {
			$html .= '<tr>';
			foreach ($row as $cell) {
				$html .=
					'<td style="padding: 8px; border-bottom: 1px solid #e0e0e0;">' .
					$this->formatInlineElements($cell, $utm) .
					'</td>';
			}
			$html .= '</tr>';
		}

		return $html . '</tbody></table>';
	}

	/**
	 * @return string|null the escaped href, or null when the scheme is not safe
	 */
	private function sanitizeUrl(string $url, array $utm = []): ?string
	{
		$url = trim($url);
		if ($url === '') {
			return null;
		}

		$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
		if (!in_array($scheme, ['http', 'https', 'mailto'], true)) {
			return null;
		}

		if ($scheme !== 'mailto') {
			$url = $this->appendUtm($url, $utm);
		}

		return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
	}

	private function appendUtm(string $url, array $utm): string
	{
		if (!$utm || !$this->isEarthAppUrl($url)) {
			return $url;
		}

		$query = (string) parse_url($url, PHP_URL_QUERY);
		if (str_contains($query, 'utm_source=')) {
			return $url;
		}

		$params = [];
		foreach (['source', 'medium', 'campaign', 'content'] as $key) {
			if (!empty($utm[$key])) {
				$params['utm_' . $key] = (string) $utm[$key];
			}
		}
		if (!$params) {
			return $url;
		}

		return $url . ($query === '' ? '?' : '&') . http_build_query($params);
	}

	private function isEarthAppUrl(string $url): bool
	{
		$host = strtolower((string) parse_url($url, PHP_URL_HOST));

		return $host === 'earth-app.com' || str_ends_with($host, '.earth-app.com');
	}

	private function normalizeCtas(mixed $cta): array
	{
		if (!is_array($cta) || !$cta) {
			return [];
		}

		// accept either a single {label,url} or a list of them
		if (isset($cta['url'])) {
			return [$cta];
		}

		return array_values(
			array_filter($cta, fn($entry) => is_array($entry) && isset($entry['url'])),
		);
	}

	private function derivePreheader(string $markdown): string
	{
		$plain = $this->toPlainText($markdown);
		// an inbox preview should read as prose; drop the bare targets toPlainText keeps
		$plain = preg_replace('/\s*<[^>\s]+>/', '', $plain);
		$plain = trim(preg_replace('/\s+/', ' ', $plain));
		if ($plain === '') {
			return '';
		}

		if (strlen($plain) <= self::PREHEADER_LENGTH) {
			return $plain;
		}

		return rtrim(substr($plain, 0, self::PREHEADER_LENGTH)) . '...';
	}

	private function sanitizeColor(mixed $color): string
	{
		return is_string($color) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)
			? $color
			: self::ACCENT;
	}

	private function createHtmlDocument(
		string $content,
		string $title = 'The Earth App',
		string $accent = self::ACCENT,
		string $preheader = '',
	): string {
		$html = '<!DOCTYPE html>';
		$html .= '<html lang="en">';
		$html .= '<head>';
		$html .= '<meta charset="UTF-8">';
		$html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
		$html .= '<meta name="color-scheme" content="light">';
		$html .= '<meta name="supported-color-schemes" content="light">';
		$html .= '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
		$html .= '</head>';
		$html .= '<body style="margin: 0; padding: 0; background-color: #f5f5f5;">';
		$html .= $this->getPreheaderHtml($preheader);
		$html .= '<table role="presentation" style="width: 100%; border-collapse: collapse;">';
		$html .= '<tr>';
		$html .= '<td align="center" style="padding: 40px 0;">';
		$html .=
			'<table role="presentation" style="max-width: 600px; width: 100%; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow: hidden;">';
		$html .= $this->getHeaderHtml($accent);
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
