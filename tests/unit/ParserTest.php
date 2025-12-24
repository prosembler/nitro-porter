<?php

use nadar\quill\Lexer as Quill;
use PHPUnit\Framework\TestCase;
use Porter\Bundle\Vanilla as Vanilla;
use Porter\Formatter;
use Porter\Parser\Flarum\ImageEmbed;
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
        $lexer->registerListener(new \Porter\Parser\Flarum\ImageEmbed());
        $result = $lexer->render();
        $expected = '<UPL-IMAGE-PREVIEW url="https://example.com/uploads/779/8C8NUCDYD6ZW.png">' .
            '[upl-image-preview url=https://example.com/uploads/779/8C8NUCDYD6ZW.png]</UPL-IMAGE-PREVIEW>';
        $this->assertStringContainsString($expected, $result);
    }

    /**
     * @covers Formatter::toTextFormatter
     */
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

    public function getParseRenderTests(): array
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
}
