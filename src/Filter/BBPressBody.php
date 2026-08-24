<?php

namespace Porter\Filter;

use Porter\Filter;

class BBPressBody extends Filter
{
    public function __invoke(): mixed
    {
        return rtrim($this->bbCodeTrickReverse($this->value));
    }

    /**
     * Fixes bbPress text formatting issues.
     * @see bbPressTrim()
     */
    protected function bbCodeTrickReverse(?string $text): string
    {
        $pattern = "!(<pre><code>|<code>)(.*?)(</code></pre>|</code>)!s";
        $text = preg_replace_callback($pattern, [$this, 'bbDecodeit'], $text);
        $text = str_replace(['<p>', '<br />'], '', $text);
        $text = str_replace('</p>', "\n", $text);
        $text = str_replace('<coded_br />', '<br />', $text);
        $text = str_replace('<coded_p>', '<p>', $text);
        return str_replace('</coded_p>', '</p>', $text);
    }

    /**
     * Callback for bbPressTrim filter.
     * @see bbCodeTrickReverse
     */
    protected function bbDecodeit(array $matches): string
    {
        $text = $matches[2];
        $trans_table = array_flip(get_html_translation_table(HTML_ENTITIES));
        $text = strtr($text, $trans_table);
        $text = str_replace('<br />', '<coded_br />', $text);
        $text = str_replace('<p>', '<coded_p>', $text);
        $text = str_replace('</p>', '</coded_p>', $text);
        $text = str_replace(['&#38;', '&amp;'], '&', $text);
        $text = str_replace('&#39;', "'", $text);
        if ('<pre><code>' == $matches[1]) {
            $text = "\n$text\n";
        }
        return "`$text`";
    }
}
