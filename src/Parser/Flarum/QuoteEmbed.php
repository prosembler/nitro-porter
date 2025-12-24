<?php

namespace Porter\Parser\Flarum;

use nadar\quill\Lexer;
use nadar\quill\Line;
use Porter\Formatter;
use Porter\Parser\EmbedExternalListener;

/**
 * Convert a Quill (Vanilla) external embed of a quote.
 *
 * @see
 */
class QuoteEmbed extends EmbedExternalListener
{
    /**
     * {@inheritDoc}
     */
    public function process(Line $line)
    {
        $embed = $line->insertJsonKey('embed-external');
        if ($embed && $this->isEmbedExternal($embed, ['quote'])) {
            // Quotes can be nested / recursive in any format.
            // If the 'body' isn't null, just use that. Otherwise, we have to go deeper.
            $body = $embed['data']['body'] ?? $this->processRawQuote($embed);

            // Post was first stored in Vanilla Rich Editor as data.attributes.commentID,
            // then later as data.recordID (for recordType 'comment')
            $type = $embed['data']['recordType'] ?? '';
            if ($type === 'comment') {
                $postid = $embed['data']['recordID'] ?? '';
            } else {
                $postid = $embed['data']['attributes']['commentID'] ?? '';
            }
            // Likewise for Discussions: data.attributes.discussionID, later data.recordID for type 'discussion'.
            if ($type === 'discussion') {
                $discussionid = $embed['data']['recordID'] ?? '';
            } else {
                $discussionid = $embed['data']['attributes']['discussionID'] ?? '';
            }

            // Only one of 'discussionid' or 'postid' will end up being set.
            $data = [
                'url' => $embed['data']['url'] ?? '',
                'postid' => $postid,
                'discussionid' => $discussionid,
                'authorid' => $embed['data']['attributes']['insertUser']['userID'] ?? '',
                'author' => $embed['data']['attributes']['insertUser']['name'] ?? '',
                'body' => $body,
            ];

            $this->pick($line, $data);
            $line->setDone();
        }
    }

    /**
     * Recursively process embedded quotes that are Rich text.
     *
     * @param array $embed
     * @return string
     */
    public function processRawQuote(array $embed): string
    {
        $body = '';
        if (isset($embed['data']['attributes']['bodyRaw'])) {
            $text = json_encode($embed['data']['attributes']['bodyRaw']);
            $format = $embed['data']['attributes']['format'] ?? 'Rich';
            $body = Formatter::instance()->toTextFormatter($format, $text);
            $body = Formatter::unwrap($body); // Undo 'r' XML tag from parsing since we're mid-post.
        }
        return $body;
    }

    /**
     * {@inheritDoc}
     *
     * @see Flarum::buildPostMentions — This template is very sensitive.
     */
    public function render(Lexer $lexer)
    {
        // Carefully mimic the TextFormatter output for quote replies.
        // Omits 'p' tags that wrap <POSTMENTION> thru {body} normally because we don't know if the body has 'p' tags.
        $this->wrapElement('<QUOTE><i>&gt; </i>' .
            // Postscript will fill in blank attributes BUT THEY MUST BE IN THIS ORDER.
            '<POSTMENTION discussionid="{discussionid}" displayname="{author}" id="{postid}" number="">' .
            '@"{author}"#p{postid}</POSTMENTION> {body}</QUOTE>', ['author', 'postid', 'body', 'discussionid']);
    }
}
