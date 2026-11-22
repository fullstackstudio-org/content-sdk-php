<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Unit;

use FullStackStudio\Content\StrictHtml;
use PHPUnit\Framework\TestCase;

/** The strict DOM allowlist for event descriptions: what survives, and that scripts, handlers and unsafe links stay inert. */
final class StrictHtmlTest extends TestCase
{
    public function testTheAllowlistSurvivesWithoutAttributes(): void
    {
        $html = '<h2 class="x">Kop</h2><p id="p" style="color:red">Tekst <strong>vet</strong> <em>schuin</em> <u>onder</u> <s>door</s><br>regel</p>'
            . '<ul><li>een</li></ul><ol><li>twee</li></ol><h3>drie</h3><h4>vier</h4><blockquote>citaat</blockquote>';

        self::assertSame('<h2>Kop</h2><p>Tekst <strong>vet</strong> <em>schuin</em> <u>onder</u> <s>door</s><br>regel</p><ul><li>een</li></ul><ol><li>twee</li></ol><h3>drie</h3><h4>vier</h4><blockquote>citaat</blockquote>', StrictHtml::sanitize($html));
    }

    public function testScriptsHandlersAndEmbeddedContentAreDropped(): void
    {
        $out = StrictHtml::sanitize('<p onmouseover="x()">a</p><script>alert(1)</script><img src=x onerror=alert(2)><svg onload=alert(3)><script>alert(4)</script></svg>'
            . '<iframe srcdoc="<script>alert(5)</script>"></iframe><style>*{}</style><template><script>alert(6)</script></template><noscript><p>n</p></noscript>'
            . '<math><mi xlink:href="javascript:alert(7)">m</mi></math><form action="https://evil.test"><input name=a></form><!-- <script>c</script> -->');

        self::assertSame('<p>a</p>', $out);
    }

    public function testOnlyHttpHttpsAndMailtoLinksKeepTheirHref(): void
    {
        self::assertSame('<a href="https://example.nl/a?b=1&amp;c=2" rel="noopener nofollow">ok</a>', StrictHtml::sanitize('<a href="https://example.nl/a?b=1&amp;c=2" target="_blank" rel="opener">ok</a>'));
        self::assertSame('<a href="mailto:info@example.nl" rel="noopener nofollow">mail</a>', StrictHtml::sanitize('<a href="mailto:info@example.nl">mail</a>'));

        foreach (['javascript:alert(1)', ' JavaScript:alert(1)', "java\tscript:alert(1)", 'jav&#x09;ascript:alert(1)', '&#106;avascript:alert(1)', 'data:text/html,<script>alert(1)</script>', 'vbscript:x', '//evil.test', '/relatief', 'https:evil', ''] as $href) {
            self::assertSame('klik', StrictHtml::sanitize('<a href="' . $href . '">klik</a>'), $href);
        }
    }

    public function testUnknownElementsAreUnwrappedAndTextIsEscaped(): void
    {
        self::assertSame('<p>een <strong>b</strong> <em>i</em> span &lt;script&gt;</p>', StrictHtml::sanitize('<div><p>een <b>b</b> <i>i</i> <span class="s">span</span> &lt;script&gt;</p></div>'));
        self::assertSame('', StrictHtml::sanitize(null));
        self::assertSame('', StrictHtml::sanitize("  \n"));
        self::assertSame('<p>Café € ü</p>', StrictHtml::sanitize('<p>Café € ü</p>'), 'UTF-8 stays intact');
    }
}
