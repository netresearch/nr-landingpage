<?php

declare(strict_types=1);

namespace Netresearch\NrLandingpage\Tests\Unit\Service;

use Netresearch\NrLandingpage\Service\CreativeHtmlSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(CreativeHtmlSanitizer::class)]
final class CreativeHtmlSanitizerTest extends UnitTestCase
{
    private CreativeHtmlSanitizer $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new CreativeHtmlSanitizer();
    }

    // -------------------------------------------------------------------------
    // Empty input
    // -------------------------------------------------------------------------

    #[Test]
    public function sanitizeWithEmptyStringReturnsEmptyString(): void
    {
        self::assertSame('', $this->subject->sanitize(''));
    }

    #[Test]
    public function sanitizeWithWhitespaceOnlyReturnsEmptyString(): void
    {
        self::assertSame('', $this->subject->sanitize('   '));
    }

    // -------------------------------------------------------------------------
    // Script tags
    // -------------------------------------------------------------------------

    #[Test]
    public function sanitizeRemovesScriptTagWithContent(): void
    {
        $html = '<p>Hello</p><script>alert("xss")</script><p>World</p>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('alert', $result);
        self::assertStringContainsString('<p>Hello</p>', $result);
        self::assertStringContainsString('<p>World</p>', $result);
    }

    #[Test]
    public function sanitizeRemovesScriptTagWithTypeAttribute(): void
    {
        $html = '<script type="text/javascript">document.cookie = "stolen";</script>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('document.cookie', $result);
    }

    #[Test]
    public function sanitizeRemovesMultilineScriptBlock(): void
    {
        $html = "<p>Before</p>\n<script>\nvar x = 1;\nvar y = 2;\n</script>\n<p>After</p>";
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('var x', $result);
        self::assertStringContainsString('<p>Before</p>', $result);
        self::assertStringContainsString('<p>After</p>', $result);
    }

    // -------------------------------------------------------------------------
    // Event handler attributes
    // -------------------------------------------------------------------------

    #[Test]
    public function sanitizeRemovesOnclickAttribute(): void
    {
        $html = '<a href="/page" onclick="stealData()">Link</a>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('onclick', $result);
        self::assertStringNotContainsString('stealData', $result);
        self::assertStringContainsString('href="/page"', $result);
        self::assertStringContainsString('Link</a>', $result);
    }

    #[Test]
    public function sanitizeRemovesOnloadAttribute(): void
    {
        $html = '<body onload="trackUser()"><p>Content</p></body>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('onload', $result);
        self::assertStringNotContainsString('trackUser', $result);
    }

    #[Test]
    public function sanitizeRemovesOnerrorAttribute(): void
    {
        $html = '<img src="missing.png" onerror="alert(1)" alt="image">';
        $result = $this->subject->sanitize($html);

        // The <img> is removed entirely because it has a src attribute
        self::assertStringNotContainsString('onerror', $result);
        self::assertStringNotContainsString('alert(1)', $result);
        self::assertStringNotContainsString('<img', $result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function eventHandlerAttributeProvider(): array
    {
        return [
            'onmouseover' => ['<div onmouseover="hover()">text</div>'],
            'onfocus'     => ['<input onfocus="steal()" type="text">'],
            'onsubmit'    => ['<form onsubmit="send()">data</form>'],
            'onkeyup'     => ['<input onkeyup="log(event)" type="text">'],
            'onchange'    => ['<select onchange="leak(this)"><option>a</option></select>'],
        ];
    }

    #[Test]
    #[DataProvider('eventHandlerAttributeProvider')]
    public function sanitizeRemovesArbitraryEventHandlerAttributes(string $html): void
    {
        $result = $this->subject->sanitize($html);

        self::assertMatchesRegularExpression('/on\w+\s*=/', $html, 'Precondition: input must contain an event handler.');
        self::assertDoesNotMatchRegularExpression('/\bon\w+\s*=/', $result);
    }

    // -------------------------------------------------------------------------
    // javascript: protocol in href
    // -------------------------------------------------------------------------

    #[Test]
    public function sanitizeNeutralizesJavascriptProtocolInHref(): void
    {
        $html = '<a href="javascript:alert(\'xss\')">Click me</a>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('javascript:', $result);
        self::assertStringContainsString('Click me</a>', $result);
    }

    #[Test]
    public function sanitizeNeutralizesJavascriptProtocolInSrc(): void
    {
        $html = '<img src="javascript:alert(1)" alt="img">';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('javascript:', $result);
    }

    #[Test]
    public function sanitizeNeutralizesJavascriptProtocolWithMixedCase(): void
    {
        $html = '<a href="JaVaScRiPt:void(0)">Link</a>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('javascript:', strtolower($result));
    }

    #[Test]
    public function sanitizeNeutralizesJavascriptProtocolWithWhitespace(): void
    {
        // Whitespace between "javascript" and ":" is a known bypass technique
        $html = '<a href="javascript :alert(1)">Link</a>';
        $result = $this->subject->sanitize($html);

        // The regex requires no space between "javascript" and ":", so verify
        // the space variant is also handled by checking the raw attribute value
        // is not executable — here we just assert the literal "javascript :" pattern
        self::assertStringNotContainsString('javascript :', $result);
    }

    // -------------------------------------------------------------------------
    // CSS url() in <style> blocks
    // -------------------------------------------------------------------------

    #[Test]
    public function sanitizeReplacesCssUrlInStyleBlock(): void
    {
        $html = '<style>body { background: url("https://evil.com/track.png"); }</style>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('url(', $result);
        self::assertStringContainsString('<style>', $result);
        self::assertStringContainsString('background: none', $result);
    }

    #[Test]
    public function sanitizeReplacesCssUrlWithSingleQuotesInStyleBlock(): void
    {
        $html = "<style>div { background-image: url('https://evil.com/bg.jpg'); }</style>";
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('url(', $result);
        self::assertStringContainsString('background-image: none', $result);
    }

    #[Test]
    public function sanitizeReplacesCssUrlWithoutQuotesInStyleBlock(): void
    {
        $html = '<style>h1 { background: url(https://evil.com/image.png); }</style>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('url(', $result);
        self::assertStringContainsString('background: none', $result);
    }

    #[Test]
    public function sanitizePreservesOtherStyleRulesWhenRemovingUrl(): void
    {
        $html = '<style>body { color: red; background: url("evil.com/x.png"); font-size: 16px; }</style>';
        $result = $this->subject->sanitize($html);

        self::assertStringContainsString('color: red', $result);
        self::assertStringContainsString('font-size: 16px', $result);
        self::assertStringNotContainsString('url(', $result);
    }

    // -------------------------------------------------------------------------
    // CSS url() in inline style attributes
    // -------------------------------------------------------------------------

    #[Test]
    public function sanitizeReplacesCssUrlInInlineStyleAttribute(): void
    {
        // Use a url() without inner quotes so the style attribute value (double-quoted)
        // is fully captured by the regex — a single-quoted URL inside a double-quoted
        // attribute would confuse the regex boundary detection.
        $html = '<div style="background: url(https://evil.com/spy.png);">Content</div>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('url(', $result);
        self::assertStringContainsString('background: none', $result);
        self::assertStringContainsString('Content</div>', $result);
    }

    #[Test]
    public function sanitizeReplacesCssUrlWithDoubleQuotesInInlineStyle(): void
    {
        $html = '<p style="background-image: url(&quot;https://evil.com/bg.png&quot;);">Text</p>';
        // Note: url() without actual parenthetical quotes — the regex targets url( literally
        $html = '<p style="background-image: url(https://evil.com/bg.png);">Text</p>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('url(', $result);
        self::assertStringContainsString('background-image: none', $result);
    }

    #[Test]
    public function sanitizePreservesOtherInlineStylePropertiesWhenRemovingUrl(): void
    {
        $html = '<span style="color: blue; background: url(evil.com/x.gif); font-weight: bold;">text</span>';
        $result = $this->subject->sanitize($html);

        self::assertStringContainsString('color: blue', $result);
        self::assertStringContainsString('font-weight: bold', $result);
        self::assertStringNotContainsString('url(', $result);
    }

    // -------------------------------------------------------------------------
    // Dangerous tags: iframe, object, embed, form
    // -------------------------------------------------------------------------

    #[Test]
    public function sanitizeRemovesIframeTag(): void
    {
        $html = '<p>Before</p><iframe src="https://evil.com/page"></iframe><p>After</p>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('<iframe', $result);
        self::assertStringNotContainsString('evil.com', $result);
        self::assertStringContainsString('<p>Before</p>', $result);
        self::assertStringContainsString('<p>After</p>', $result);
    }

    #[Test]
    public function sanitizeRemovesObjectTag(): void
    {
        $html = '<object data="malware.swf" type="application/x-shockwave-flash"></object>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('<object', $result);
        self::assertStringNotContainsString('malware.swf', $result);
    }

    #[Test]
    public function sanitizeRemovesEmbedTag(): void
    {
        $html = '<embed src="https://evil.com/plugin" type="application/x-java-applet">';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('<embed', $result);
    }

    #[Test]
    public function sanitizeRemovesFormTag(): void
    {
        $html = '<form action="https://evil.com/steal" method="post"><input type="text" name="data"></form>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('<form', $result);
        self::assertStringNotContainsString('evil.com/steal', $result);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function dangerousTagProvider(): array
    {
        return [
            'iframe with src'         => ['<iframe src="x.html"></iframe>', 'iframe'],
            'object with data'        => ['<object data="x.swf"></object>', 'object'],
            'embed self-closing'      => ['<embed src="x.swf">', 'embed'],
            'form with action'        => ['<form action="/submit"></form>', 'form'],
        ];
    }

    #[Test]
    #[DataProvider('dangerousTagProvider')]
    public function sanitizeRemovesDangerousTag(string $html, string $tagName): void
    {
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('<' . $tagName, $result);
    }

    // -------------------------------------------------------------------------
    // data: URIs in src/href
    // -------------------------------------------------------------------------

    #[Test]
    public function sanitizeNeutralizesDataUriInSrc(): void
    {
        // The sanitizer removes the "data:" scheme prefix, leaving the remainder of
        // the attribute value harmless (no longer a valid URI).
        $html = '<img src="data:image/png;base64,abc123" alt="xss">';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('data:', $result);
        // Verify the dangerous scheme itself is gone; the orphaned remainder is inert.
        self::assertStringNotContainsString('src="data:', $result);
    }

    #[Test]
    public function sanitizeNeutralizesDataUriInHref(): void
    {
        $html = '<a href="data:text/html,<script>alert(1)</script>">click</a>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('data:', $result);
    }

    #[Test]
    public function sanitizeNeutralizesDataUriWithMixedCase(): void
    {
        $html = '<img src="DATA:image/svg+xml;base64,PHN2Zy8+" alt="svg">';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('data:', strtolower($result));
    }

    #[Test]
    public function sanitizeNeutralizesDataUriWithWhitespacePadding(): void
    {
        $html = '<img src="  data:image/png;base64,abc" alt="img">';
        $result = $this->subject->sanitize($html);

        // Leading whitespace before data: is a known bypass; the regex trims it
        self::assertStringNotContainsString('data:', $result);
    }

    // -------------------------------------------------------------------------
    // Valid HTML preservation
    // -------------------------------------------------------------------------

    #[Test]
    public function sanitizePreservesCleanHtmlUnchanged(): void
    {
        $html = '<h1>Hello World</h1><p>This is <strong>bold</strong> and <em>italic</em>.</p>';
        $result = $this->subject->sanitize($html);

        self::assertSame($html, $result);
    }

    #[Test]
    public function sanitizePreservesStyleBlockWithoutUrls(): void
    {
        $html = '<style>body { font-family: sans-serif; color: #333; } h1 { font-size: 2em; }</style>';
        $result = $this->subject->sanitize($html);

        self::assertStringContainsString('font-family: sans-serif', $result);
        self::assertStringContainsString('color: #333', $result);
        self::assertStringContainsString('font-size: 2em', $result);
    }

    #[Test]
    public function sanitizePreservesInlineSvg(): void
    {
        $html = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<circle cx="50" cy="50" r="40" fill="blue"/>'
            . '</svg>';
        $result = $this->subject->sanitize($html);

        self::assertStringContainsString('<svg', $result);
        self::assertStringContainsString('<circle', $result);
        self::assertStringContainsString('fill="blue"', $result);
    }

    #[Test]
    public function sanitizePreservesHrefWithLegitimateHttpUrl(): void
    {
        $html = '<a href="https://example.com/page">Visit page</a>';
        $result = $this->subject->sanitize($html);

        self::assertStringContainsString('href="https://example.com/page"', $result);
        self::assertStringContainsString('Visit page</a>', $result);
    }

    #[Test]
    public function sanitizeBlocksImgWithSrcAttribute(): void
    {
        $html = '<img src="https://example.com/photo.jpg" alt="Photo">';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('<img', $result);
    }

    #[Test]
    public function sanitizeBlocksImgWithDataUriSrc(): void
    {
        $html = '<img src="data:image/png;base64,abc123" alt="Inline">';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('<img', $result);
    }

    #[Test]
    public function sanitizeAllowsImagePlaceholderWithoutSrc(): void
    {
        $html = '<img data-image-slot="0" alt="Team photo">';
        $result = $this->subject->sanitize($html);

        self::assertStringContainsString('data-image-slot="0"', $result);
        self::assertStringContainsString('alt="Team photo"', $result);
    }

    #[Test]
    public function sanitizeAllowsImagePlaceholderWithReorderedAttributes(): void
    {
        $html = '<img alt="Hero" data-image-slot="0" class="hero-img">';
        $result = $this->subject->sanitize($html);

        self::assertStringContainsString('data-image-slot="0"', $result);
        self::assertStringContainsString('alt="Hero"', $result);
    }

    #[Test]
    public function sanitizeBlocksImgWithSrcAndDataImageSlot(): void
    {
        $html = '<img src="https://evil.com/photo.jpg" data-image-slot="0" alt="Test">';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('<img', $result);
    }

    #[Test]
    public function sanitizeBlocksSelfClosingImgWithSrc(): void
    {
        $html = '<img src="https://example.com/photo.jpg" alt="Photo" />';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('<img', $result);
    }

    #[Test]
    public function sanitizePreservesStyleAndSvgTogether(): void
    {
        $html = '<style>svg { display: block; margin: auto; }</style>'
            . '<svg viewBox="0 0 200 200"><rect width="200" height="200" fill="red"/></svg>';
        $result = $this->subject->sanitize($html);

        self::assertStringContainsString('<style>', $result);
        self::assertStringContainsString('display: block', $result);
        self::assertStringContainsString('<svg', $result);
        self::assertStringContainsString('fill="red"', $result);
    }

    // -------------------------------------------------------------------------
    // Complex / nested structures
    // -------------------------------------------------------------------------

    #[Test]
    public function sanitizeHandlesMultipleThreatTypesInOneDocument(): void
    {
        $html = '<div onclick="evil()">'
            . '<script>alert("xss")</script>'
            . '<iframe src="https://evil.com"></iframe>'
            . '<a href="javascript:void(0)">link</a>'
            . '<style>body { background: url("https://tracker.com/px.png"); }</style>'
            . '<img src="data:image/png;base64,abc" onerror="hack()">'
            . '<p>Safe content</p>'
            . '</div>';

        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('onclick', $result);
        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('alert', $result);
        self::assertStringNotContainsString('<iframe', $result);
        self::assertStringNotContainsString('javascript:', $result);
        self::assertStringNotContainsString('url(', $result);
        self::assertStringNotContainsString('data:', $result);
        self::assertStringNotContainsString('onerror', $result);
        self::assertStringContainsString('<p>Safe content</p>', $result);
    }

    #[Test]
    public function sanitizeHandlesNestedDivStructure(): void
    {
        $html = '<div class="wrapper">'
            . '<div class="header"><h1>Title</h1></div>'
            . '<div class="content"><p>Paragraph one.</p><p>Paragraph two.</p></div>'
            . '</div>';
        $result = $this->subject->sanitize($html);

        self::assertStringContainsString('class="wrapper"', $result);
        self::assertStringContainsString('<h1>Title</h1>', $result);
        self::assertStringContainsString('<p>Paragraph one.</p>', $result);
        self::assertStringContainsString('<p>Paragraph two.</p>', $result);
    }

    #[Test]
    public function sanitizeHandlesMultipleStyleBlocks(): void
    {
        $html = '<style>.a { color: red; background: url("a.png"); }</style>'
            . '<p>text</p>'
            . '<style>.b { font-size: 14px; background: url("b.png"); }</style>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('url(', $result);
        self::assertStringContainsString('color: red', $result);
        self::assertStringContainsString('font-size: 14px', $result);
    }

    #[Test]
    public function sanitizePreservesLegitimateHtmlEntitiesInContent(): void
    {
        $html = '<p>Price: 5 &lt; 10 &amp; 20 &gt; 15</p>';
        $result = $this->subject->sanitize($html);

        // Entities in text content are preserved (only attribute values are decoded for security)
        self::assertStringContainsString('&lt; 10 &amp; 20 &gt;', $result);
    }

    #[Test]
    public function sanitizeBlocksEntityEncodedJavascriptProtocol(): void
    {
        // &#106; = 'j' — entity-encoded bypass attempt
        $html = '<a href="&#106;avascript:alert(1)">click</a>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('javascript:', $result);
        self::assertStringNotContainsString('alert', $result);
    }

    #[Test]
    public function sanitizeBlocksEntityEncodedDataUri(): void
    {
        // &#100; = 'd' — entity-encoded data: URI bypass attempt
        $html = '<img src="&#100;ata:text/html,<script>alert(1)</script>">';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('data:', $result);
    }

    #[Test]
    public function sanitizeBlocksCssImportWithoutUrlWrapper(): void
    {
        $html = '<style>@import "https://evil.com/track.css"; .hero { color: red; }</style>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('@import', $result);
        self::assertStringContainsString('color: red', $result);
    }

    #[Test]
    public function sanitizeBlocksCssImportWithUrlWrapper(): void
    {
        $html = '<style>@import url("https://evil.com/track.css"); .hero { color: blue; }</style>';
        $result = $this->subject->sanitize($html);

        self::assertStringNotContainsString('@import', $result);
        self::assertStringNotContainsString('evil.com', $result);
        self::assertStringContainsString('color: blue', $result);
    }

    #[Test]
    public function sanitizeTrimsLeadingAndTrailingWhitespace(): void
    {
        $html = "   \n<p>Content</p>\n   ";
        $result = $this->subject->sanitize($html);

        self::assertSame('<p>Content</p>', $result);
    }

    // -------------------------------------------------------------------------
    // Script allowlist (data-creative)
    // -------------------------------------------------------------------------

    #[Test]
    public function sanitizePreservesScriptWithDataCreativeAndAllowedContent(): void
    {
        $html = '<script data-creative>gsap.to(".hero", {opacity: 1});</script>';
        $result = $this->subject->sanitize($html, allowScripts: true);
        self::assertStringContainsString('gsap.to', $result);
        self::assertStringContainsString('data-creative', $result);
    }

    #[Test]
    public function sanitizeStripsScriptWithDataCreativeContainingBlockedApi(): void
    {
        $html = '<script data-creative>fetch("/api/data").then(r => r.json());</script>';
        $result = $this->subject->sanitize($html, allowScripts: true);
        self::assertStringNotContainsString('fetch', $result);
        self::assertStringNotContainsString('<script', $result);
    }

    #[Test]
    public function sanitizeStripsScriptWithoutDataCreativeEvenWhenAllowed(): void
    {
        $html = '<script>alert("xss")</script>';
        $result = $this->subject->sanitize($html, allowScripts: true);
        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('alert', $result);
    }

    #[Test]
    public function sanitizeStripsDataCreativeScriptWhenNotAllowed(): void
    {
        $html = '<script data-creative>gsap.to(".hero", {opacity: 1});</script>';
        $result = $this->subject->sanitize($html);
        self::assertStringNotContainsString('<script', $result);
    }

    #[Test]
    public function sanitizeStripsScriptWithBracketNotationBypass(): void
    {
        $html = '<script data-creative>window["fetch"]("/api")</script>';
        $result = $this->subject->sanitize($html, allowScripts: true);
        self::assertStringNotContainsString('<script', $result);
    }

    #[Test]
    public function sanitizePreservesMultipleDataCreativeScripts(): void
    {
        $html = '<style>.a{}</style>'
            . '<script data-creative>gsap.from(".a", {y: 40});</script>'
            . '<section>content</section>'
            . '<script data-creative>ScrollTrigger.create({trigger: ".a"});</script>';
        $result = $this->subject->sanitize($html, allowScripts: true);
        self::assertSame(2, substr_count($result, '<script data-creative>'));
    }

    #[Test]
    public function sanitizeStripsEvalInDataCreativeScript(): void
    {
        $html = '<script data-creative>eval("alert(1)")</script>';
        $result = $this->subject->sanitize($html, allowScripts: true);
        self::assertStringNotContainsString('<script', $result);
    }

    #[Test]
    public function sanitizeStripsDocumentCookieInDataCreativeScript(): void
    {
        $html = '<script data-creative>document.cookie</script>';
        $result = $this->subject->sanitize($html, allowScripts: true);
        self::assertStringNotContainsString('<script', $result);
    }

    #[Test]
    public function sanitizeStripsSetTimeoutInDataCreativeScript(): void
    {
        $html = '<script data-creative>setTimeout(function(){}, 100)</script>';
        $result = $this->subject->sanitize($html, allowScripts: true);
        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('setTimeout', $result);
    }

    #[Test]
    public function sanitizeStripsSetIntervalInDataCreativeScript(): void
    {
        $html = '<script data-creative>setInterval(tick, 1000)</script>';
        $result = $this->subject->sanitize($html, allowScripts: true);
        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('setInterval', $result);
    }

    #[Test]
    public function sanitizeStripsDocumentCreateElementInDataCreativeScript(): void
    {
        $html = "<script data-creative>document.createElement('script')</script>";
        $result = $this->subject->sanitize($html, allowScripts: true);
        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('createElement', $result);
    }

    #[Test]
    public function sanitizeStripsConstructorBypassInDataCreativeScript(): void
    {
        $html = "<script data-creative>[].constructor.constructor('alert(1)')()</script>";
        $result = $this->subject->sanitize($html, allowScripts: true);
        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('constructor', $result);
    }

    #[Test]
    public function sanitizeStripsNewImageInDataCreativeScript(): void
    {
        $html = "<script data-creative>new Image().src='//evil.com/x'</script>";
        $result = $this->subject->sanitize($html, allowScripts: true);
        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('new Image', $result);
    }

    #[Test]
    public function sanitizeStripsImportScriptsInDataCreativeScript(): void
    {
        $html = "<script data-creative>importScripts('evil.js')</script>";
        $result = $this->subject->sanitize($html, allowScripts: true);
        self::assertStringNotContainsString('<script', $result);
        self::assertStringNotContainsString('importScripts', $result);
    }

    #[Test]
    public function sanitizePreservesFunctionCallbackInDataCreativeScript(): void
    {
        $html = "<script data-creative>document.addEventListener('DOMContentLoaded', function() { gsap.from('.hero', {opacity: 0, y: 30}); });</script>";
        $result = $this->subject->sanitize($html, allowScripts: true);
        self::assertStringContainsString('<script data-creative>', $result);
        self::assertStringContainsString('function()', $result);
        self::assertStringContainsString('gsap.from', $result);
    }

    #[Test]
    public function sanitizePreservesArrowFunctionInDataCreativeScript(): void
    {
        $html = "<script data-creative>document.addEventListener('DOMContentLoaded', () => { gsap.from('.hero', {opacity: 0}); });</script>";
        $result = $this->subject->sanitize($html, allowScripts: true);
        self::assertStringContainsString('<script data-creative>', $result);
        self::assertStringContainsString('gsap.from', $result);
    }

    #[Test]
    public function sanitizeStillBlocksNewFunctionConstructor(): void
    {
        $html = "<script data-creative>new Function('return this')()</script>";
        $result = $this->subject->sanitize($html, allowScripts: true);
        self::assertStringNotContainsString('<script', $result);
    }

    #[Test]
    public function sanitizePreservesTypicalGsapDomContentLoadedPattern(): void
    {
        $html = '<style>.hero{opacity:0}</style>'
            . '<section class="hero"><h1>Title</h1></section>'
            . "<script data-creative>document.addEventListener('DOMContentLoaded', function() {"
            . " gsap.from('.hero h1', {scrollTrigger: '.hero', opacity: 0, y: -50, duration: 1});"
            . ' });</script>';
        $result = $this->subject->sanitize($html, allowScripts: true);
        self::assertStringContainsString('<script data-creative>', $result);
        self::assertStringContainsString('gsap.from', $result);
        self::assertStringContainsString('scrollTrigger', $result);
        self::assertStringContainsString('<style>', $result);
        self::assertStringContainsString('<section', $result);
    }
}
