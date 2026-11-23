<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace SdpPortal\Component\ServiceDeskPlus\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Api\Redactor;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Approvals\AttachmentPreview;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Identity\EmailNormalizer;
use SdpPortal\Component\ServiceDeskPlus\Administrator\Support\HtmlText;
use SdpPortal\Component\ServiceDeskPlus\Site\View\Ui;

final class TextHelpersTest extends TestCase
{
    public function testHtmlFromServiceDeskPlusBecomesPlainText(): void
    {
        self::assertSame("Hello\nWorld", HtmlText::toPlain('<p>Hello</p><script>alert(1)</script><div>World</div>'));
        self::assertSame('• One', HtmlText::toPlain('<ul><li>One</li></ul>'));
        // Entities are decoded: the result is text, which every template escapes again.
        self::assertSame('<b>', HtmlText::toPlain('&lt;b&gt;'));
    }

    public function testTextTypedByUsersIsEscapedBeforeItIsSentAsHtml(): void
    {
        self::assertSame("&lt;img src=x onerror=alert(1)&gt;<br>\nline 2", HtmlText::fromPlain("<img src=x onerror=alert(1)>\nline 2"));
    }

    public function testBadgesEscapeTheText(): void
    {
        self::assertSame('<span class="sdp-badge sdp-tone-muted">&lt;b&gt;&quot;x&quot;</span>', Ui::badge('<b>"x"'));
        self::assertSame('<span class="sdp-badge sdp-tone-red">Rejected</span>', Ui::badge('Rejected'));
        self::assertSame('—', Ui::badge('  '));
    }

    public function testRedactorRemovesKeysAndMarkup(): void
    {
        $text = Redactor::scrub('<b>Bad</b> authtoken: ABC-123 and technician_key=XYZ and secret S3CR3T', ['S3CR3T']);

        self::assertSame('Bad authtoken: [redacted] and technician_key=[redacted] and secret [redacted]', $text);
        self::assertSame(301, mb_strlen(Redactor::scrub(str_repeat('a', 500))));
    }

    public function testEmailsAreComparedExactlyAfterTrimAndLowercase(): void
    {
        self::assertSame('a.b+tag@example.com', EmailNormalizer::normalize('  A.B+tag@Example.COM '));
        self::assertTrue(EmailNormalizer::isUsable('a@example.com'));
        self::assertFalse(EmailNormalizer::isUsable('not-an-email'));
        self::assertFalse(EmailNormalizer::isUsable('a@b@c'));
        self::assertFalse(EmailNormalizer::isUsable(''));
    }

    public function testOnlyImagesAndPdfCanBePreviewed(): void
    {
        self::assertSame('pdf', AttachmentPreview::kind('Quote.PDF'));
        self::assertSame('image', AttachmentPreview::kind('photo.webp'));
        self::assertSame('', AttachmentPreview::kind('drawing.svg'));
        self::assertSame('', AttachmentPreview::kind('page.html'));
        self::assertSame('', AttachmentPreview::kind('pdf'));
    }
}
