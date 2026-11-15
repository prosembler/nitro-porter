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
        if (is_string($this->value) && preg_match('~</?[a-z][a-z0-9]*(?:\s[^<>]*)?\s*/?>~i', $this->value)) {
            self::$htmlConverter ??= new HtmlConverter();
            $this->value = self::$htmlConverter->convert($this->value);
            $format = 'Markdown';
        }
        return \Porter\Formatter::instance()->toTextFormatter($format, $this->value);
    }
}
