<?php

namespace Porter\Ext\Parser;

use nadar\quill\InlineListener;
use nadar\quill\Line;

class Emoji extends InlineListener
{
    /**
     * Convert a Quill (Vanilla) emoji.
     *
     * Handles: `{"emoji":{"emojiChar":"\u2764\ufe0f"}}`
     */
    public function process(Line $line)
    {
        $emoji = $line->insertJsonKey('emoji');
        if ($emoji && isset($emoji['emojiChar'])) {
            // A string like `\u2764` is a Unicode code point escaped for JSON.
            // We already have decoded JSON, so we can just leave the parsed string with no change.
            $this->updateInput($line, $emoji['emojiChar']);
        }
    }
}
