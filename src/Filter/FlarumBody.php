<?php

namespace Porter\Filter;

use League\HTMLToMarkdown\HtmlConverter;
use Porter\Filter;

/**
 * @see Migration::filterData()
 * @see \Porter\Target\Flarum::comments()
 */
class FlarumBody extends Filter
{
    private static ?HtmlConverter $htmlConverter = null;

    public function __invoke(): mixed
    {
        $format = $this->row['Format'] ?? 'Text'; // Apparently null 'Format' values are possible.
        if (!is_string($this->value)) {
            return \Porter\Formatter::instance()->toTextFormatter($format, $this->value);
        }

        $hasHtml = preg_match('~</?[a-z][a-z0-9]*(?:\s[^<>]*)?\s*/?>~i', $this->value) === 1;
        $codeBlocks = [];
        $this->value = $this->replaceWoltLabCodeBlocks($this->value, $codeBlocks);
        $this->value = $this->replaceWoltLabQuotes($this->value);
        $this->value = $this->replaceWoltLabSpoilers($this->value);

        if ($hasHtml) {
            self::$htmlConverter ??= new HtmlConverter();
            $this->value = self::$htmlConverter->convert($this->value);
            $format = 'Markdown';
        }

        $this->value = $this->escapeIncompleteFtpSchemes($this->value);
        $formatter = \Porter\Formatter::instance();
        $this->value = $formatter->toTextFormatter($format, $this->value);
        foreach ($codeBlocks as $placeholder => $code) {
            $codeTag = $code['language'] === '' ? '[code]' : '[code=' . $code['language'] . ']';
            $codeContent = $codeTag . $code['content'] . '[/code]';
            $parsedCode = $formatter->toTextFormatter('BBCode', $codeContent);
            $this->value = str_replace($placeholder, $parsedCode, $this->value);
        }

        return $this->value;
    }

    private function replaceWoltLabCodeBlocks(string $value, array &$codeBlocks): string
    {
        $result = preg_replace_callback(
            '~<woltlab-metacode\b(?<attributes>[^>]*)>(?<content>.*?)</woltlab-metacode\s*>~is',
            static function (array $match) use (&$codeBlocks): string {
                $language = self::getAttribute($match['attributes'], 'data-name') ?? '';
                if (!preg_match('/^[a-z0-9_+-]+$/i', $language)) {
                    $language = '';
                }

                $content = preg_replace('~<br\s*/?>~i', "\n", $match['content']);
                $content = html_entity_decode(
                    $content ?? $match['content'],
                    ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                    'UTF-8'
                );
                $placeholder = 'NITROPORTERMETACODE' . count($codeBlocks) . 'BLOCK';
                $codeBlocks[$placeholder] = [
                    'language' => $language,
                    'content' => $content,
                ];
                return $placeholder;
            },
            $value
        );
        if ($result === null) {
            throw new \RuntimeException('Failed to convert WoltLab metacode blocks.');
        }
        return $result;
    }

    private function escapeIncompleteFtpSchemes(string $value): string
    {
        $parts = preg_split('/(\r\n|\n|\r)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new \RuntimeException('Failed to split converted post content.');
        }

        $inCodeBlock = false;
        $fenceLength = 0;
        for ($index = 0; $index < count($parts); $index += 2) {
            $line = $parts[$index];
            if (!$inCodeBlock) {
                $escaped = preg_replace_callback(
                    '~\bftp:(?!//)~i',
                    static fn(array $match): string => substr($match[0], 0, -1) . '&#58;',
                    $line
                );
                if ($escaped === null) {
                    throw new \RuntimeException('Failed to escape incomplete FTP links.');
                }
                $line = $escaped;
            }

            if (preg_match('/^\s*(`{3,})/', $line, $match)) {
                $foundFenceLength = strlen($match[1]);
                if (!$inCodeBlock) {
                    $inCodeBlock = true;
                    $fenceLength = $foundFenceLength;
                } elseif ($foundFenceLength >= $fenceLength) {
                    $inCodeBlock = false;
                }
            }
            $parts[$index] = $line;
        }

        return implode('', $parts);
    }

    private function replaceWoltLabQuotes(string $value): string
    {
        $value = preg_replace_callback(
            '~<woltlab-quote\b(?<attributes>[^>]*)>~i',
            static function (array $match): string {
                $author = trim(self::getAttribute($match['attributes'], 'data-author') ?? '');
                if ($author === '') {
                    return '<blockquote>';
                }

                $author = htmlspecialchars($author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $link = self::getAttribute($match['attributes'], 'data-link');
                if ($link !== null && preg_match('~^(?:https?://|/(?!/))~i', $link)) {
                    $author = '<a href="' . htmlspecialchars(
                        $link,
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8'
                    ) . '">' . $author . '</a>';
                }

                return '<blockquote><p><strong>Zitat von ' . $author . ':</strong></p>';
            },
            $value
        );
        if ($value === null) {
            throw new \RuntimeException('Failed to convert WoltLab quotes.');
        }

        $result = preg_replace('~</woltlab-quote\s*>~i', '</blockquote>', $value);
        if ($result === null) {
            throw new \RuntimeException('Failed to close converted WoltLab quotes.');
        }
        return $result;
    }

    private function replaceWoltLabSpoilers(string $value): string
    {
        $value = preg_replace_callback(
            '~<woltlab-spoiler\b(?<attributes>[^>]*)>~i',
            static function (array $match): string {
                $label = trim(self::getAttribute($match['attributes'], 'data-label') ?? '');
                $title = $label === '' ? 'Spoiler' : 'Spoiler: ' . $label;
                return '<blockquote><p><strong>'
                    . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</strong></p>';
            },
            $value
        );
        if ($value === null) {
            throw new \RuntimeException('Failed to convert WoltLab spoilers.');
        }

        $result = preg_replace('~</woltlab-spoiler\s*>~i', '</blockquote>', $value);
        if ($result === null) {
            throw new \RuntimeException('Failed to close converted WoltLab spoilers.');
        }
        return $result;
    }

    private static function getAttribute(string $attributes, string $name): ?string
    {
        $matched = preg_match(
            '~(?:^|\s)' . preg_quote($name, '~') . '\s*=\s*(["\'])(.*?)\1~is',
            $attributes,
            $match
        );
        if ($matched !== 1) {
            return null;
        }
        return html_entity_decode($match[2], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
