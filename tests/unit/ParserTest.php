<?php

use nadar\quill\Lexer as Quill;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Porter\Ext\Bundle\Vanilla as Vanilla;
use Porter\Ext\Parser\Flarum\ImageEmbed;
use Porter\Filter\FlarumBody;
use Porter\Formatter;
use Porter\Target\Flarum;
use s9e\TextFormatter\Bundles\Fatdown as Markdown;

final class ParserTest extends TestCase
{
    /**
     * @covers ImageEmbed::process
     */
    public function testQuillCanParseExternalEmbed(): void
    {
        $stored = '[{"insert":{"embed-external":{"data":{"url":' .
            '"https:\/\/example.com\/uploads\/779\/8C8NUCDYD6ZW.png","name":"auto-draft-6.png","type":"image",' .
            '"size":92198,"width":500,"height":545,"mediaID":34100,"dateInserted":"2020-01-11T03:21:13+00:00",' .
            '"insertUserID":4708,"foreignType":"embed","foreignID":4708,"format":null,"bodyRaw":null},' .
            '"loaderData":{"type":"image"}}}},{"insert":"\n"}]'; // post id 953302
        $stored = '{"ops":' . $stored . '}'; // Fix the JSON.
        $lexer = new Quill($stored);
        $lexer->registerListener(new \Porter\Ext\Parser\Flarum\ImageEmbed());
        $result = $lexer->render();
        $expected = '<UPL-IMAGE-PREVIEW url="https://example.com/uploads/779/8C8NUCDYD6ZW.png">' .
            '[upl-image-preview url=https://example.com/uploads/779/8C8NUCDYD6ZW.png]</UPL-IMAGE-PREVIEW>';
        $this->assertStringContainsString($expected, $result);
    }

    /**
     * @covers Formatter::toTextFormatter
     */
    #[IgnoreDeprecations]
    public function testFatdownCanParseHtml(): void
    {
        $stored = '<b>Avant </b>de poster dans le forum la présentation est <b><u>obligatoire</u> </b>ici :&nbsp;
            <a rel="nofollow" href="https://routeur4g.fr/discussions/categories/pr%C3%A9sentation">Présentation</a>
            &nbsp;avec dans le titre le pseudo.<br><span>Cela permet d\'en savoir plus sur votre matériel/lieu de
            vie/niveau technique. Pas besoin de faire un roman mais expliquez pourquoi vous en êtes venu à la 4G nous
            permet de vous donner de bons conseils.&nbsp;<br></span>';
        $stored = Markdown::parse($stored);
        $result = Markdown::render($stored);
        $expected = '<p><b>Avant </b>de poster dans le forum la présentation est <b><u>obligatoire</u> </b>ici : 
            <a href="https://routeur4g.fr/discussions/categories/pr%C3%A9sentation">Présentation</a>
             avec dans le titre le pseudo.<br><span>Cela permet d’en savoir plus sur votre matériel/lieu de
            vie/niveau technique. Pas besoin de faire un roman mais expliquez pourquoi vous en êtes venu à la 4G nous
            permet de vous donner de bons conseils. <br></span></p>';
        $this->assertStringContainsString($expected, $result);
    }

    /**
     * @dataProvider getParseRenderTests()
     */
    public function testParseRender(string $text, string $expected): void
    {
        $xml = Vanilla::parse($text);
        $html = Vanilla::render($xml);

        $this->assertEquals($expected, $html);
    }

    public static function getParseRenderTests(): array
    {
        return [
            [   // Allow headings
                '<h1>Title</h1>Text <h2>subheading</h2>Text 2',
                '<h1>Title</h1><p>Text</p> <h2>subheading</h2><p>Text 2</p>'
            ],
            [   // Allow divs
                'Text<div class="someClass">content</div>Text 2',
                '<p>Text</p><div class="someClass"><p>content</p></div><p>Text 2</p>',
            ],
        ];
    }

    /**
     * @covers \Porter\Formatter::closeTags
     */
    public function testTagClose(): void
    {
        $stored = 'text <img alt="" src="https://routeur4g.fr/discussions/uploads/editor/bf/ytfu9hvmqp3u.jpg">';
        $result = Formatter::closeTags($stored);
        $expected = 'text <img alt="" src="https://routeur4g.fr/discussions/uploads/editor/bf/ytfu9hvmqp3u.jpg" />';
        $this->assertEquals($expected, $result);
    }

    /**
     * @covers \Porter\Formatter::fixIllegalTags
     */
    public function testFixIllegalTags(): void
    {
        $stored = '<span><div class="post-text-align-center"><br><br>&nbsp;Administrateur<br>version </div></span>';
        $result = Formatter::fixIllegalTags($stored);
        $expected = '<div class="post-text-align-center"><br><br>&nbsp;Administrateur<br>version </div>';
        $this->assertEquals($expected, $result);
    }

    /**
     * @dataProvider getToHtmlTests()
     * @covers \Porter\Formatter::toHtml
     */
    public function testToHtml(?string $format, ?string $text, string $expected): void
    {
        $this->assertEquals($expected, Formatter::instance()->toHtml($format, $text));
    }

    public static function getToHtmlTests(): array
    {
        return [
            [   // BBCode is rendered, not left as markup.
                'BBCode',
                'Hello [b]world[/b]',
                'Hello <b>world</b>',
            ],
            [   // Format matching is case-insensitive.
                'bbcode',
                'Hello [b]world[/b]',
                'Hello <b>world</b>',
            ],
            [   // Markdown is rendered to HTML.
                'Markdown',
                'Hello **world**',
                '<p>Hello <strong>world</strong></p>',
            ],
            [   // Plain text is literal, so its markup gets escaped.
                'Text',
                "<b>not bold</b>\nsecond line",
                "&lt;b&gt;not bold&lt;/b&gt;<br />\nsecond line",
            ],
            [   // An unknown format falls through to text.
                null,
                'Hello',
                'Hello',
            ],
            [   // Null content is allowed.
                'Html',
                null,
                '',
            ],
        ];
    }

    public function testFlarumBodyParsesHtmlEvenWhenSourceFormatIsBbcode(): void
    {
        $body = (new FlarumBody(
            '<p><strong>HTML text</strong></p>',
            'Body',
            ['Format' => 'BBCode'],
        ))();

        $this->assertStringContainsString('<p>', $body);
        $this->assertStringContainsString('<STRONG>', $body);
        $this->assertStringNotContainsString('&lt;p&gt;', $body);
    }

    public function testFlarumBodyConvertsWoltLabMetacodeToFortranCodeBlock(): void
    {
        $body = (new FlarumBody(
            '<woltlab-metacode data-attributes="" data-name="fortran">'
                . 'MsgBox(0, &quot;Title&quot;, &lt;value&gt;)'
                . '</woltlab-metacode>',
            'Body',
            ['Format' => 'BBCode'],
        ))();

        $this->assertStringContainsString('<CODE', strtoupper($body));
        $this->assertStringContainsString('fortran', strtolower($body));
        $this->assertStringContainsString('MsgBox(0, "Title", &lt;value&gt;)', $body);
        $this->assertStringNotContainsString('woltlab-metacode', $body);
    }

    public function testFlarumBodyConvertsWoltLabQuoteWithAuthor(): void
    {
        $body = (new FlarumBody(
            '<woltlab-quote data-author="Example &amp; User" data-link="https://example.com/thread/1-test/">'
                . '<p>Quoted text</p></woltlab-quote>',
            'Body',
            ['Format' => 'BBCode'],
        ))();
        $rendered = Markdown::render($body);

        $this->assertStringContainsString('<blockquote>', $rendered);
        $this->assertStringContainsString('Zitat von', $rendered);
        $this->assertStringContainsString('Example &amp; User', $rendered);
        $this->assertStringContainsString('Quoted text', $rendered);
        $this->assertStringNotContainsString('woltlab-quote', $rendered);
    }

    public function testFlarumBodyPreservesWoltLabSpoilerAsLabeledQuote(): void
    {
        $body = (new FlarumBody(
            '<woltlab-spoiler data-label="Details"><p>Hidden text</p></woltlab-spoiler>',
            'Body',
            ['Format' => 'BBCode'],
        ))();
        $rendered = Markdown::render($body);

        $this->assertStringContainsString('<blockquote>', $rendered);
        $this->assertStringContainsString('Spoiler: Details', $rendered);
        $this->assertStringContainsString('Hidden text', $rendered);
        $this->assertStringNotContainsString('woltlab-spoiler', $rendered);
    }

    public function testFlarumBodyAvoidsAutolinkWarningForIncompleteFtpScheme(): void
    {
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            $body = (new FlarumBody('The value ftp: is incomplete.', 'Body', ['Format' => 'Text']))();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertStringContainsString('ftp&#58;', $body);
    }

    public function testWoltLabDiscussionLinksBecomeFlarumRoutes(): void
    {
        $replaceLinks = new ReflectionMethod(Flarum::class, 'replaceDiscussionLinks');
        $body = '<a href="https://example.com/thread/88563-old-title/">Link</a> '
            . '[url=https://example.com/thread/88516-another-title/]Other[/url] '
            . 'https://example.com/thread/99999-not-imported/';

        $result = $replaceLinks->invoke(null, $body, [
            88563 => '/d/88563-actual-flarum-title',
            88516 => '/d/88516-another-flarum-title',
        ]);

        $this->assertStringContainsString('href="/d/88563-actual-flarum-title"', $result);
        $this->assertStringContainsString('[url=/d/88516-another-flarum-title]', $result);
        $this->assertStringContainsString('https://example.com/thread/99999-not-imported/', $result);
    }
}
