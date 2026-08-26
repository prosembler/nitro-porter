<?php

namespace Porter\Ext\Parser\Flarum;

use nadar\quill\InlineListener;
use nadar\quill\Line;

class Mention extends InlineListener
{
    /**
     * Convert a Quill (Vanilla) mention to a Flarum mention.
     */
    public function process(Line $line)
    {
        $mention = $line->insertJsonKey('mention');
        if ($mention) {
            // USERMENTION just linked to the user like Vanilla.
            // POSTMENTION requires `id` (of the post), `number` (position on page), and `discussionid`.
            // Requires entry in `post_mentions_user` or `post_mentions_post` or '[deleted]' will render.
            $replacement = '<USERMENTION displayname="' .
                $mention['name'] . '" id="' . $mention['userID'] . '">@"' .
                $mention['name'] . '"</USERMENTION>';
            $this->updateInput($line, $replacement);
        }
    }
}
