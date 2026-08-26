<?php

namespace Porter\Ext\Parser\Flarum;

use nadar\quill\Lexer;
use nadar\quill\Line;
use Porter\Ext\Parser\EmbedExternalListener;

/**
 * Convert a Quill (Vanilla) external embed of a Spotify link.
 *
 * @see https://s9etextformatter.readthedocs.io/Plugins/Autolink/Synopsis/
 */
class SpotifyEmbed extends EmbedExternalListener
{
    /**
     * {@inheritDoc}
     */
    public function process(Line $line)
    {
        $embed = $line->insertJsonKey('embed-external');
        // Type 'spotify' doesn't exist in 2022 but we'll check for it for forwards-compat.
        if ($embed && $this->isEmbedExternal($embed, ['link', 'spotify'])) {
            if (str_contains($embed['data']['url'], '//open.spotify.com/')) { // reverse logic required in LinkEmbed
                $matches = [];
                preg_match('/open.spotify.com\/([a-z]+\/[a-zA-Z0-9_-]+)/', $embed['data']['url'], $matches);
                $this->pick($line, ['url' => $embed['data']['url'], 'playlistid' => $matches[1] ?? '']);
                $line->setDone();
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function render(Lexer $lexer)
    {
        $this->wrapElement(
            ' <SPOTIFY id="{playlistid}"><URL url="{url}">{url}</URL></SPOTIFY>',
            ['url', 'playlistid']
        );
    }
}
