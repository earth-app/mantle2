<?php

namespace Drupal\Tests\mantle2\Unit;

use Drupal\mantle2\Service\HTMLFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class HTMLFactoryUnitTest extends TestCase
{
	private HTMLFactory $htmlFactory;

	protected function setUp(): void
	{
		parent::setUp();
		$this->htmlFactory = new HTMLFactory();
	}

	#[Test]
	#[TestDox('Test simple text conversion to HTML paragraph')]
	#[Group('mantle2/html')]
	public function testSimpleTextConversion()
	{
		$text = 'This is a simple text.';
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString(
			'<p style="margin: 0 0 16px 0; line-height: 1.5;">This is a simple text.</p>',
			$html,
		);
		$this->assertStringContainsString('<div style="font-family: Arial, sans-serif;', $html);
	}

	#[Test]
	#[TestDox('Test bold text formatting with **text**')]
	#[Group('mantle2/html')]
	public function testBoldTextFormatting()
	{
		$text = 'This is **bold text** in a sentence.';
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString(
			'<strong style="font-weight: bold;">bold text</strong>',
			$html,
		);
		$this->assertStringContainsString('This is', $html);
		$this->assertStringContainsString('in a sentence.', $html);
	}

	#[Test]
	#[TestDox('Test italic text formatting with *text*')]
	#[Group('mantle2/html')]
	public function testItalicTextFormatting()
	{
		$text = 'This is *italic text* in a sentence.';
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString(
			'<em style="font-style: italic;">italic text</em>',
			$html,
		);
	}

	#[Test]
	#[TestDox('Test multiple bold texts in one line')]
	#[Group('mantle2/html')]
	public function testMultipleBoldTexts()
	{
		$text = 'This has **first bold** and **second bold** text.';
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString(
			'<strong style="font-weight: bold;">first bold</strong>',
			$html,
		);
		$this->assertStringContainsString(
			'<strong style="font-weight: bold;">second bold</strong>',
			$html,
		);
	}

	#[Test]
	#[TestDox('Test link conversion with [text](url) syntax')]
	#[Group('mantle2/html')]
	public function testLinkFormatting()
	{
		$text = 'Visit [Google](https://google.com) for search.';
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString(
			'<a href="https://google.com" style="color: #007bff; text-decoration: none;">Google</a>',
			$html,
		);
	}

	#[Test]
	#[TestDox('Test plain URL auto-linking')]
	#[Group('mantle2/html')]
	public function testPlainUrlLinking()
	{
		$text = 'Visit https://example.com for more info.';
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString('<a href="https://example.com"', $html);
		$this->assertStringContainsString(
			'style="color: #007bff; text-decoration: none;">https://example.com</a>',
			$html,
		);
	}

	#[Test]
	#[TestDox('Test unordered list with - prefix')]
	#[Group('mantle2/html')]
	public function testUnorderedList()
	{
		$text = "Items:\n- First item\n- Second item\n- Third item";
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString(
			'<ul style="margin: 0 0 16px 0; padding-left: 20px;">',
			$html,
		);
		$this->assertStringContainsString('<li style="margin: 4px 0;">First item</li>', $html);
		$this->assertStringContainsString('<li style="margin: 4px 0;">Second item</li>', $html);
		$this->assertStringContainsString('<li style="margin: 4px 0;">Third item</li>', $html);
		$this->assertStringContainsString('</ul>', $html);
	}

	#[Test]
	#[TestDox('Test unordered list with bold items')]
	#[Group('mantle2/html')]
	public function testUnorderedListWithBoldItems()
	{
		$text = "Items:\n- **Bold item**\n- Regular item\n- **Another bold**";
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString(
			'<li style="margin: 4px 0;"><strong style="font-weight: bold;">Bold item</strong></li>',
			$html,
		);
		$this->assertStringContainsString('<li style="margin: 4px 0;">Regular item</li>', $html);
		$this->assertStringContainsString(
			'<li style="margin: 4px 0;"><strong style="font-weight: bold;">Another bold</strong></li>',
			$html,
		);
	}

	#[Test]
	#[TestDox('Test multiple paragraphs with empty lines')]
	#[Group('mantle2/html')]
	public function testMultipleParagraphs()
	{
		$text = "First paragraph.\n\nSecond paragraph.\n\nThird paragraph.";
		$html = $this->htmlFactory->toHtml($text);

		// Should have 3 paragraphs
		$this->assertEquals(
			3,
			substr_count($html, '<p style="margin: 0 0 16px 0; line-height: 1.5;">'),
		);
		$this->assertStringContainsString('First paragraph.', $html);
		$this->assertStringContainsString('Second paragraph.', $html);
		$this->assertStringContainsString('Third paragraph.', $html);
	}

	#[Test]
	#[TestDox('Test mixed formatting in single line')]
	#[Group('mantle2/html')]
	public function testMixedFormatting()
	{
		$text = 'This has **bold**, *italic*, and [a link](https://example.com) together.';
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString(
			'<strong style="font-weight: bold;">bold</strong>',
			$html,
		);
		$this->assertStringContainsString('<em style="font-style: italic;">italic</em>', $html);
		$this->assertStringContainsString(
			'<a href="https://example.com" style="color: #007bff; text-decoration: none;">a link</a>',
			$html,
		);
	}

	#[Test]
	#[TestDox('Test line ending normalization')]
	#[Group('mantle2/html')]
	public function testLineEndingNormalization()
	{
		$textWithCRLF = "Line 1\r\nLine 2\r\nLine 3";
		$textWithCR = "Line 1\rLine 2\rLine 3";
		$textWithLF = "Line 1\nLine 2\nLine 3";

		$htmlCRLF = $this->htmlFactory->toHtml($textWithCRLF);
		$htmlCR = $this->htmlFactory->toHtml($textWithCR);
		$htmlLF = $this->htmlFactory->toHtml($textWithLF);

		// All should produce the same result
		$this->assertStringContainsString('Line 1', $htmlCRLF);
		$this->assertStringContainsString('Line 2', $htmlCRLF);
		$this->assertStringContainsString('Line 3', $htmlCRLF);

		// Verify all three produce paragraphs
		$this->assertEquals(
			3,
			substr_count($htmlCRLF, '<p style="margin: 0 0 16px 0; line-height: 1.5;">'),
		);
		$this->assertEquals(
			3,
			substr_count($htmlCR, '<p style="margin: 0 0 16px 0; line-height: 1.5;">'),
		);
		$this->assertEquals(
			3,
			substr_count($htmlLF, '<p style="margin: 0 0 16px 0; line-height: 1.5;">'),
		);
	}

	#[Test]
	#[TestDox('Test HTML escaping for XSS prevention')]
	#[Group('mantle2/html')]
	public function testHtmlEscaping()
	{
		$text = 'This has <script>alert("xss")</script> malicious code.';
		$html = $this->htmlFactory->toHtml($text);

		// Script tags should be escaped
		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('&lt;script&gt;', $html);
		$this->assertStringContainsString('&lt;/script&gt;', $html);
	}

	#[Test]
	#[TestDox('Test email branding footer is included')]
	#[Group('mantle2/html')]
	public function testEmailBrandingIncluded()
	{
		$text = 'Simple text.';
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString('Thank you for using The Earth App!', $html);
		$this->assertStringContainsString('support@earth-app.com', $html);
		$this->assertStringContainsString('https://cdn.earth-app.com/earth-app.png', $html);
		$this->assertStringContainsString('The Earth App. All rights reserved.', $html);
		$this->assertStringContainsString(date('Y'), $html);
	}

	#[Test]
	#[TestDox('Test email container wrapper is applied')]
	#[Group('mantle2/html')]
	public function testEmailContainerWrapper()
	{
		$text = 'Test content.';
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringStartsWith(
			'<div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5; color: #333;">',
			$html,
		);
	}

	#[Test]
	#[TestDox('Test empty string handling')]
	#[Group('mantle2/html')]
	public function testEmptyString()
	{
		$html = $this->htmlFactory->toHtml('');

		// Should still have container and branding
		$this->assertStringContainsString('<div style="font-family: Arial, sans-serif;', $html);
		$this->assertStringContainsString('Thank you for using The Earth App!', $html);
	}

	#[Test]
	#[TestDox('Test whitespace trimming')]
	#[Group('mantle2/html')]
	public function testWhitespaceTrimming()
	{
		$text = "   \n\n  Test text with whitespace.  \n\n   ";
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString('Test text with whitespace.', $html);
		// Should only have one paragraph
		$this->assertEquals(
			1,
			substr_count($html, '<p style="margin: 0 0 16px 0; line-height: 1.5;">'),
		);
	}

	#[Test]
	#[TestDox('Test list closes properly before paragraph')]
	#[Group('mantle2/html')]
	public function testListClosesBeforeParagraph()
	{
		$text = "List:\n- Item 1\n- Item 2\n\nRegular paragraph after list.";
		$html = $this->htmlFactory->toHtml($text);

		// Find positions to verify order
		$ulPos = strpos($html, '<ul style=');
		$ulClosePos = strpos($html, '</ul>');
		$paragraphPos = strpos($html, 'Regular paragraph');

		$this->assertNotFalse($ulPos);
		$this->assertNotFalse($ulClosePos);
		$this->assertNotFalse($paragraphPos);
		$this->assertLessThan($ulClosePos, $ulPos);
		$this->assertLessThan($paragraphPos, $ulClosePos);
	}

	#[Test]
	#[TestDox('Test email verification code format from mantle2.module')]
	#[Group('mantle2/html')]
	#[Group('mantle2/integration')]
	public function testEmailVerificationFormat()
	{
		$verificationCode = 'ABC123';
		$text = "Your email verification code is: **{$verificationCode}**\n\nThis code will expire in 15 minutes.\nIf you did not request this verification, please ignore this email.";
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString(
			'<strong style="font-weight: bold;">ABC123</strong>',
			$html,
		);
		$this->assertStringContainsString('This code will expire in 15 minutes.', $html);
		$this->assertStringContainsString(
			'If you did not request this verification, please ignore this email.',
			$html,
		);
	}

	#[Test]
	#[TestDox('Test new login notification format from mantle2.module')]
	#[Group('mantle2/html')]
	#[Group('mantle2/integration')]
	public function testNewLoginNotificationFormat()
	{
		$text =
			"Your account was just used to log in from a new device or location.\n\n**Details:**\n- Time: 2025-10-23 10:00:00\n- IP: 192.168.1.1\n- User Agent: Mozilla/5.0\n\nIf this was you, you can safely ignore this email.";
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString(
			'<strong style="font-weight: bold;">Details:</strong>',
			$html,
		);
		$this->assertStringContainsString(
			'<li style="margin: 4px 0;">Time: 2025-10-23 10:00:00</li>',
			$html,
		);
		$this->assertStringContainsString('<li style="margin: 4px 0;">IP: 192.168.1.1</li>', $html);
		$this->assertStringContainsString(
			'<li style="margin: 4px 0;">User Agent: Mozilla/5.0</li>',
			$html,
		);
	}

	#[Test]
	#[TestDox('Test password reset format with link from mantle2.module')]
	#[Group('mantle2/html')]
	#[Group('mantle2/integration')]
	public function testPasswordResetFormat()
	{
		$resetLink = 'https://earth-app.com/reset?token=abc123';
		$text = "We received a request to reset your password for The Earth App.\n\nClick the link below to reset your password:\n{$resetLink}\n\nThis link will expire in 1 hour.";
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString('We received a request to reset your password', $html);
		$this->assertStringContainsString(
			'<a href="https://earth-app.com/reset?token=abc123"',
			$html,
		);
		$this->assertStringContainsString('This link will expire in 1 hour.', $html);
	}

	#[Test]
	#[TestDox('Test email change notification with bold emails')]
	#[Group('mantle2/html')]
	#[Group('mantle2/integration')]
	public function testEmailChangeNotificationFormat()
	{
		$oldEmail = 'old@example.com';
		$newEmail = 'new@example.com';
		$text = "Your email address was just requested to be changed.\n\nOld Email: **{$oldEmail}**\n\nNew Email: **{$newEmail}**\n\nIf you did not perform this action, please contact support immediately.";
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString(
			'<strong style="font-weight: bold;">old@example.com</strong>',
			$html,
		);
		$this->assertStringContainsString(
			'<strong style="font-weight: bold;">new@example.com</strong>',
			$html,
		);
		$this->assertStringContainsString('If you did not perform this action', $html);
	}

	#[Test]
	#[TestDox('Test special characters in text are escaped')]
	#[Group('mantle2/html')]
	public function testSpecialCharacterEscaping()
	{
		$text = 'Price is less than < 100 & greater than > 50.';
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString('&lt;', $html);
		$this->assertStringContainsString('&gt;', $html);
		$this->assertStringContainsString('&amp;', $html);
	}

	#[Test]
	#[TestDox('Test quotes are escaped properly')]
	#[Group('mantle2/html')]
	public function testQuoteEscaping()
	{
		$text = 'He said "Hello" and she said \'Hi\'.';
		$html = $this->htmlFactory->toHtml($text);

		// Quotes should be escaped
		$this->assertStringContainsString('&quot;', $html);
		// Single quotes are escaped as &apos; or &#039; depending on context
		$this->assertTrue(
			str_contains($html, '&apos;') || str_contains($html, '&#039;'),
			'Single quote should be escaped',
		);
	}

	#[Test]
	#[TestDox('Test formatted tags are preserved after escaping')]
	#[Group('mantle2/html')]
	public function testFormattedTagsPreservedAfterEscaping()
	{
		$text = '**Bold text** with <script>alert("xss")</script> and *italic*.';
		$html = $this->htmlFactory->toHtml($text);

		// Our formatting should work
		$this->assertStringContainsString(
			'<strong style="font-weight: bold;">Bold text</strong>',
			$html,
		);
		$this->assertStringContainsString('<em style="font-style: italic;">italic</em>', $html);

		// But malicious HTML should be escaped
		$this->assertStringContainsString('&lt;script&gt;', $html);
		$this->assertStringNotContainsString('<script>', $html);
	}

	#[Test]
	#[TestDox('Test multiple URLs in same paragraph')]
	#[Group('mantle2/html')]
	public function testMultipleUrls()
	{
		$text = 'Visit https://google.com or https://bing.com for search.';
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString('<a href="https://google.com"', $html);
		$this->assertStringContainsString('<a href="https://bing.com"', $html);
	}

	#[Test]
	#[TestDox('Test HTTP and HTTPS URLs both work')]
	#[Group('mantle2/html')]
	public function testHttpAndHttpsUrls()
	{
		$text = 'Visit http://example.com and https://secure.com';
		$html = $this->htmlFactory->toHtml($text);

		$this->assertStringContainsString('<a href="http://example.com"', $html);
		$this->assertStringContainsString('<a href="https://secure.com"', $html);
	}

	#[Test]
	#[TestDox('Test link with bold text inside')]
	#[Group('mantle2/html')]
	public function testLinkWithBoldText()
	{
		$text = '[**Bold Link**](https://example.com)';
		$html = $this->htmlFactory->toHtml($text);

		// The link should contain the bold text
		$this->assertStringContainsString('<a href="https://example.com"', $html);
		// Note: The bold is inside the link text
		$this->assertStringContainsString('Bold Link', $html);
	}

	#[Test]
	#[TestDox('Test consecutive empty lines are handled')]
	#[Group('mantle2/html')]
	public function testConsecutiveEmptyLines()
	{
		$text = "Line 1\n\n\n\n\nLine 2";
		$html = $this->htmlFactory->toHtml($text);

		// Should have exactly 2 paragraphs despite multiple empty lines
		$this->assertEquals(
			2,
			substr_count($html, '<p style="margin: 0 0 16px 0; line-height: 1.5;">'),
		);
	}

	#[Test]
	#[TestDox('Test branding HTML uses separate paragraphs')]
	#[Group('mantle2/html')]
	#[Group('mantle2/branding')]
	public function testBrandingHasSeparateParagraphs()
	{
		$text = 'Test content.';
		$html = $this->htmlFactory->toHtml($text);

		// Each branding element should be in its own paragraph tag
		$this->assertStringContainsString(
			'<p style="margin: 0 0 8px 0;">Thank you for using The Earth App!</p>',
			$html,
		);
		$this->assertStringContainsString(
			'<p style="margin: 0 0 8px 0;">If you have any questions',
			$html,
		);
		$this->assertStringContainsString('<p style="margin: 8px 0 0 0;">&copy;', $html);
		$this->assertStringContainsString(
			'<p style="margin: 8px 0 0 0;">This email was sent from a notification-only address',
			$html,
		);
	}

	#[Test]
	#[TestDox('Test branding section has correct wrapper div')]
	#[Group('mantle2/html')]
	#[Group('mantle2/branding')]
	public function testBrandingWrapperDiv()
	{
		$text = 'Test content.';
		$html = $this->htmlFactory->toHtml($text);

		// Branding should be in its own div with correct styling
		$this->assertStringContainsString(
			'<div style="margin-top: 32px; font-size: 10px; color: #999;">',
			$html,
		);

		// Check that branding div closes properly
		$brandingStartPos = strpos($html, 'margin-top: 32px; font-size: 10px; color: #999;');
		$this->assertNotFalse($brandingStartPos);

		// Verify logo has display:block style
		$this->assertStringContainsString(
			'<img src="https://cdn.earth-app.com/earth-app.png" alt="The Earth App Logo" style="height: 24px; margin-top: 8px; display: block;">',
			$html,
		);
	}

	#[Test]
	#[TestDox('Test content wrapper and branding are separate divs')]
	#[Group('mantle2/html')]
	#[Group('mantle2/branding')]
	public function testContentAndBrandingAreSeparate()
	{
		$text = 'Main content here.';
		$html = $this->htmlFactory->toHtml($text);

		// Content wrapper should close before branding starts
		$contentWrapperPos = strpos($html, 'font-family: Arial, sans-serif');
		$contentClosePos = strpos($html, '</div>', $contentWrapperPos);
		$brandingPos = strpos($html, 'margin-top: 32px; font-size: 10px');

		$this->assertNotFalse($contentWrapperPos);
		$this->assertNotFalse($contentClosePos);
		$this->assertNotFalse($brandingPos);
		$this->assertLessThan($brandingPos, $contentClosePos);
	}

	#[Test]
	#[TestDox('Test multiple paragraphs with mixed formatting')]
	#[Group('mantle2/html')]
	public function testMultipleParagraphsWithMixedFormatting()
	{
		$text =
			"First paragraph with **bold** text.\n\nSecond paragraph with *italic* text.\n\nThird paragraph with [a link](https://example.com).";
		$html = $this->htmlFactory->toHtml($text);

		// Should have exactly 3 paragraphs
		$this->assertEquals(
			3,
			substr_count($html, '<p style="margin: 0 0 16px 0; line-height: 1.5;">'),
		);

		// Check all formatting is preserved
		$this->assertStringContainsString(
			'<strong style="font-weight: bold;">bold</strong>',
			$html,
		);
		$this->assertStringContainsString('<em style="font-style: italic;">italic</em>', $html);
		$this->assertStringContainsString('<a href="https://example.com"', $html);
	}

	#[Test]
	#[TestDox('Test paragraph with list then paragraph')]
	#[Group('mantle2/html')]
	public function testParagraphListParagraphStructure()
	{
		$text = "Intro paragraph.\n\n- List item 1\n- List item 2\n\nClosing paragraph.";
		$html = $this->htmlFactory->toHtml($text);

		// Should have 2 paragraphs and 1 list
		$this->assertEquals(
			2,
			substr_count($html, '<p style="margin: 0 0 16px 0; line-height: 1.5;">'),
		);
		$this->assertEquals(1, substr_count($html, '<ul style='));
		$this->assertEquals(2, substr_count($html, '<li style='));

		// Verify order
		$introPos = strpos($html, 'Intro paragraph');
		$listPos = strpos($html, '<ul style=');
		$closingPos = strpos($html, 'Closing paragraph');

		$this->assertLessThan($listPos, $introPos);
		$this->assertLessThan($closingPos, $listPos);
	}

	#[Test]
	#[TestDox('Test complex multi-paragraph email with all features')]
	#[Group('mantle2/html')]
	#[Group('mantle2/integration')]
	public function testComplexMultiParagraphEmail()
	{
		$text =
			"Hello **User**,\n\nYour account has been updated with the following changes:\n\n- Password changed\n- Email verified\n- Profile updated\n\nIf you have questions, visit https://help.example.com or contact support.\n\nThank you!";
		$html = $this->htmlFactory->toHtml($text);

		// Should have 4 paragraphs (greeting, intro, outro, thanks)
		$this->assertEquals(
			4,
			substr_count($html, '<p style="margin: 0 0 16px 0; line-height: 1.5;">'),
		);

		// Should have 1 list with 3 items
		$this->assertEquals(1, substr_count($html, '<ul style='));
		$this->assertEquals(3, substr_count($html, '<li style='));

		// Check formatting
		$this->assertStringContainsString(
			'<strong style="font-weight: bold;">User</strong>',
			$html,
		);
		$this->assertStringContainsString('<a href="https://help.example.com"', $html);

		// Check branding is included
		$this->assertStringContainsString('Thank you for using The Earth App!', $html);
	}

	#[Test]
	#[TestDox('Test newline handling creates separate paragraphs')]
	#[Group('mantle2/html')]
	public function testNewlineCreatesSeparateParagraphs()
	{
		$text = "Line 1\nLine 2\nLine 3";
		$html = $this->htmlFactory->toHtml($text);

		// Each line should be its own paragraph
		$this->assertEquals(
			3,
			substr_count($html, '<p style="margin: 0 0 16px 0; line-height: 1.5;">'),
		);
		$this->assertStringContainsString(
			'<p style="margin: 0 0 16px 0; line-height: 1.5;">Line 1</p>',
			$html,
		);
		$this->assertStringContainsString(
			'<p style="margin: 0 0 16px 0; line-height: 1.5;">Line 2</p>',
			$html,
		);
		$this->assertStringContainsString(
			'<p style="margin: 0 0 16px 0; line-height: 1.5;">Line 3</p>',
			$html,
		);
	}

	#[Test]
	#[TestDox('Test single newlines vs double newlines both create paragraphs')]
	#[Group('mantle2/html')]
	public function testSingleVsDoubleNewlines()
	{
		$textSingle = "Para 1\nPara 2\nPara 3";
		$textDouble = "Para 1\n\nPara 2\n\nPara 3";

		$htmlSingle = $this->htmlFactory->toHtml($textSingle);
		$htmlDouble = $this->htmlFactory->toHtml($textDouble);

		// Both should create 3 paragraphs
		$this->assertEquals(
			3,
			substr_count($htmlSingle, '<p style="margin: 0 0 16px 0; line-height: 1.5;">'),
		);
		$this->assertEquals(
			3,
			substr_count($htmlDouble, '<p style="margin: 0 0 16px 0; line-height: 1.5;">'),
		);
	}

	#[Test]
	#[TestDox('Test mixed bold and italic in multiple paragraphs')]
	#[Group('mantle2/html')]
	public function testMixedFormattingMultipleParagraphs()
	{
		$text =
			"**Bold** in first paragraph.\n*italic* in second paragraph.\n**Bold** and *italic* together.";
		$html = $this->htmlFactory->toHtml($text);

		// Should have 3 paragraphs
		$this->assertEquals(
			3,
			substr_count($html, '<p style="margin: 0 0 16px 0; line-height: 1.5;">'),
		);

		// Count formatting elements
		$this->assertEquals(
			2,
			substr_count($html, '<strong style="font-weight: bold;">Bold</strong>'),
		);
		$this->assertEquals(2, substr_count($html, '<em style="font-style: italic;">italic</em>'));
	}

	#[Test]
	#[TestDox('Test list items with URLs and formatting')]
	#[Group('mantle2/html')]
	public function testListItemsWithUrlsAndFormatting()
	{
		$text =
			"Features:\n- **Bold feature** at https://example.com/feature1\n- *Italic feature* at https://example.com/feature2\n- Normal feature";
		$html = $this->htmlFactory->toHtml($text);

		// Should have 1 paragraph + 1 list
		$this->assertEquals(
			1,
			substr_count($html, '<p style="margin: 0 0 16px 0; line-height: 1.5;">'),
		);
		$this->assertEquals(1, substr_count($html, '<ul style='));
		$this->assertEquals(3, substr_count($html, '<li style='));

		// Check formatting in list items
		$this->assertStringContainsString(
			'<li style="margin: 4px 0;"><strong style="font-weight: bold;">Bold feature</strong>',
			$html,
		);
		$this->assertStringContainsString(
			'<li style="margin: 4px 0;"><em style="font-style: italic;">Italic feature</em>',
			$html,
		);
		$this->assertStringContainsString('<a href="https://example.com/feature1"', $html);
		$this->assertStringContainsString('<a href="https://example.com/feature2"', $html);
	}

	#[Test]
	#[TestDox('Test actual new login email format matches expected structure')]
	#[Group('mantle2/html')]
	#[Group('mantle2/integration')]
	public function testActualNewLoginEmailStructure()
	{
		$time = '2025-10-26T01:12:39+00:00';
		$ip = '172.69.17.12';
		$additionalIps = '(none)';
		$userAgent =
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:144.0) Gecko/20100101 Firefox/144.0';
		$referer = 'No Referrer';

		$text = "Your account was just used to log in from a new device or location.\n\n**Details:**\n- Time: {$time}\n- IP: {$ip} ({$additionalIps})\n- User Agent: {$userAgent}\n- Referrer: {$referer}\n\nIf this was you, you can safely ignore this email.\nIf you did not log in, please reset your password immediately to secure your account.";

		$html = $this->htmlFactory->toHtml($text);

		// Should have intro paragraph, Details paragraph, list, and 2 closing paragraphs
		$this->assertGreaterThanOrEqual(
			3,
			substr_count($html, '<p style="margin: 0 0 16px 0; line-height: 1.5;">'),
		);

		// Should have list with 4 items
		$this->assertEquals(1, substr_count($html, '<ul style='));
		$this->assertEquals(4, substr_count($html, '<li style='));

		// Verify all details are present and formatted
		$this->assertStringContainsString(
			'<strong style="font-weight: bold;">Details:</strong>',
			$html,
		);
		$this->assertStringContainsString("Time: {$time}", $html);
		$this->assertStringContainsString("IP: {$ip}", $html);
		$this->assertStringContainsString("User Agent: {$userAgent}", $html);
		$this->assertStringContainsString("Referrer: {$referer}", $html);

		// Verify closing messages are in separate paragraphs
		$this->assertStringContainsString(
			'If this was you, you can safely ignore this email.',
			$html,
		);
		$this->assertStringContainsString(
			'If you did not log in, please reset your password immediately to secure your account.',
			$html,
		);

		// Verify branding is present
		$this->assertStringContainsString('Thank you for using The Earth App!', $html);
	}

	#[Test]
	#[TestDox('Test branding does not contain literal newlines or extra whitespace')]
	#[Group('mantle2/html')]
	#[Group('mantle2/branding')]
	public function testBrandingHasNoLiteralWhitespace()
	{
		$text = 'Test content.';
		$html = $this->htmlFactory->toHtml($text);

		// Extract branding section
		$brandingStart = strpos($html, 'margin-top: 32px; font-size: 10px; color: #999;');
		$brandingSection = substr($html, $brandingStart);

		// Should not contain excessive whitespace between tags (outside of content)
		// Check that paragraph tags are properly closed without newlines in between
		$this->assertStringNotContainsString("</p>\n\n\t\t\t<p", $brandingSection);
		$this->assertStringNotContainsString("</p>\n\t\t\t<p", $brandingSection);

		// All text content should be within proper HTML tags
		$this->assertStringContainsString(
			'<p style="margin: 0 0 8px 0;">Thank you for using The Earth App!</p>',
			$html,
		);
	}

	#[Test]
	#[TestDox('Test email with all formatting types across multiple paragraphs')]
	#[Group('mantle2/html')]
	public function testAllFormattingTypesMultipleParagraphs()
	{
		$text =
			"**Welcome** to our service!\n\nHere are your *important* details:\n- Account: **premium**\n- Status: *active*\n- URL: https://account.example.com\n\nVisit [our help center](https://help.example.com) for more info.\n\nThank you!";
		$html = $this->htmlFactory->toHtml($text);

		// Verify paragraph count (4 paragraphs + 1 list)
		$paragraphCount = substr_count($html, '<p style="margin: 0 0 16px 0; line-height: 1.5;">');
		$this->assertGreaterThanOrEqual(4, $paragraphCount);

		// Verify all formatting types exist
		$this->assertStringContainsString('<strong style="font-weight: bold;">', $html);
		$this->assertStringContainsString('<em style="font-style: italic;">', $html);
		$this->assertStringContainsString('<a href="https://account.example.com"', $html);
		$this->assertStringContainsString('<a href="https://help.example.com"', $html);
		$this->assertStringContainsString('<ul style=', $html);
		$this->assertStringContainsString('<li style=', $html);

		// Verify structure: wrapper div, content, branding
		$this->assertStringStartsWith(
			'<div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5; color: #333;">',
			$html,
		);
		$this->assertStringContainsString('Thank you for using The Earth App!', $html);
	}
}
