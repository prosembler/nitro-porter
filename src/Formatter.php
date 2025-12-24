<?php

namespace Porter;

use nadar\quill\Lexer as Quill;
use s9e\TextFormatter\Bundles\Fatdown as Markdown;
use s9e\TextFormatter\Bundles\Forum as BBCode;
use Porter\Bundle\Vanilla as Vanilla;

class Formatter
{
    /** @var ?Formatter Singleton storage. */
    private static ?Formatter $instance = null;

    /** @var array Some formatting requires UserIDs to be accessible. */
    protected array $userMap = [];

    /**
     * Singleton accessor.
     *
     * @return self Formatter
     */
    public static function instance(?Migration $ex = null): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
            self::$instance->setup($ex);
        }
        return self::$instance;
    }

    /**
     * @return void
     */
    public function setup(Migration $port)
    {
        $this->userMap = $this->buildUserMap($port);
    }

    /**
     * Create an array of `strtolower(name)` => ID for doing lookups later.
     *
     * @todo This strategy likely won't scale past 100K users. 18K users @ +8mb memory use.
     *
     * @param Migration $port
     * @return array
     */
    public function buildUserMap(Migration $port): array
    {
        $userMap = $port->dbOutput()
            ->table('users')
            ->get(['id', 'username']);

        $users = [];
        foreach ($userMap as $user) {
            // Use the first found ID for each name in case of duplicates.
            if (!isset($users[strtolower($user->username)])) {
                $users[strtolower($user->username)] = $user->id;
            }
        }

        // Record memory usage from user map.
        Log::comment('Mentions map memory usage at ' . formatBytes(memory_get_usage()));

        return $users;
    }

    /**
     * Put content in TextFormatter-compatible format.
     *
     * @param ?string $format
     * @param ?string $text
     * @return string
     */
    public function toTextFormatter(?string $format, ?string $text): string
    {
        switch ($format) {
            case 'Html':
            case 'Wysiwyg':
            case 'Raw': // Unfiltered — these could break.
                // Custom bundle to enable more HTML elements than Markdown.
                $text = self::closeTags($text);
                $text = self::fixIllegalTags($text);
                return $this->fixRawMentions(Vanilla::parse($text));
            case 'Markdown':
                // Markdown bundle allows some (filtered) HTML.
                return $this->fixRawMentions(Markdown::parse($text));
            case 'BBCode':
                return $this->fixRawMentions(BBCode::parse($text));
            case 'Rich': // Quill
                return self::wrap('r', self::dequill($text));
            case 'Text':
            case 'TextEx':
            default:
                // Use of nl2br() here is needed for Vanilla PMs (which have no `Format`).
                // May require more refined detection for other cases but too many breaks is safer than too few.
                return self::wrap('t', $this->fixRawMentions(nl2br($text)));
        }
    }

    /**
     * s9e/textformatter loses its mind about unclosed HTML tags.
     *
     * @param ?string $text
     * @return ?string
     */
    public static function closeTags(?string $text): ?string
    {
        if (empty($text)) {
            return $text;
        }

        // <br>
        $text = str_replace('<br>', '<br/>', $text);
        // <img src="">
        $text = preg_replace('#<img ([^>]+[^/])>#', '<img $1 />', $text);

        return $text;
    }

    /**
     * s9e/textformatter loses its mind about illegal span tags wrapping a div.
     *
     * This is a very tricky hack, so it needs to be rather narrow and therefore won't fix every scenario.
     * Class-less spans directly wrapping a div are the only condition we'll address for now.
     *
     * @param ?string $text
     * @return ?string
     */
    public static function fixIllegalTags(?string $text): ?string
    {
        // <span><div ... </div></span>
        $text = preg_replace('#<span><div ([^>]+[^/])>(.+)</div></span>#U', '<div $1>$2</div>', $text);

        return $text;
    }

    /**
     * 'Rich' format in Vanilla is actually Quill WYSIWYG Delta.
     *
     * Vanilla stored invalid JSON — the "ops" array without its wrapper.
     *
     * @see https://quilljs.com/docs/delta/
     *
     * @param string $text
     * @return string
     */
    public static function dequill(string $text): string
    {
        // Fix invalid Quill Delta.
        $text = self::fixQuillHeaders($text);

        // Quill data is invalid, but check first in case they fix it in a future version.
        if (false === json_validate($text)) {
            // Fix the JSON.
            $text = '{"ops":' . $text . '}';

            // Re-check we have valid JSON now and give up if we don't.
            if (false === json_validate($text)) {
                Log::comment(json_last_error_msg() . PHP_EOL);
                return '';
            }
        }

        // Use the Quill renderer.
        $lexer = new Quill($text);

        // Custom mention handler.
        $lexer->registerListener(new Parser\Flarum\Mention());

        // Custom emoji handler.
        $lexer->registerListener(new Parser\Emoji());

        // Custom image embed handler for `embed-external`.
        $lexer->registerListener(new Parser\Flarum\ImageEmbed());

        // Custom link handler for `embed-external`.
        $lexer->registerListener(new Parser\Flarum\LinkEmbed());

        // Custom Spotify link handler for `embed-external`.
        $lexer->registerListener(new Parser\Flarum\SpotifyEmbed());

        // Custom quote handler for `embed-external`.
        $lexer->registerListener(new Parser\Flarum\QuoteEmbed());

        return $lexer->render();
    }

    /**
     * Vanilla appears to use a customized 'header' element in Quill Deltas that breaks parsers.
     *
     * @todo Replace this with an overridden listener.
     * @todo example call: `$lexer->overwriteListener(new Heading, new \Porter\Parser\Heading());`
     * @todo example class: `class Heading extends \nadar\quill\listener\Heading`
     *
     * @param string $text
     * @return string
     */
    public static function fixQuillHeaders(string $text): string
    {
        // Avoid regex if we can.
        if (strstr($text, '{"header"') === false) {
            return $text;
        }

        // Remove array of attributes under `header` and simply give the numeric level instead.
        // ex: {"header":{"level":1,"ref":""}},
        return preg_replace('/{"header":{"level":([1-6]),"ref":"\w*"}}/', '{"header":$1}', $text);
    }

    /**
     * Replace basic Vanilla mentions with tag-based Flarum mentions.
     *
     * @param ?string $text
     * @return string
     */
    public function fixRawMentions(?string $text): string
    {
        // Allow empty content.
        if (is_null($text)) {
            return '';
        }

        // Find unconverted mentions and associate userID.
        $mentions = $this->findRawMentions($text);
        foreach ($mentions as $mention) {
            // Remove the optional double quote if present & guarantee we have a userid.
            $slug = strtolower(trim($mention, "\""));
            if (!isset($this->userMap[$slug])) {
                continue; // Username wasn't in the map, abort.
            }

            // Do the content substitution per found mention.
            $newMention = '<USERMENTION id="' . $this->userMap[$slug] . '">@' . $mention . '</USERMENTION>';
            $text = str_replace('@' . $mention, $newMention, $text);
        }

        return $text;
    }

    /**
     * Find valid Vanilla mentions in a post's content.
     *
     * Results may be wrapped in double quotes if the original was.
     *
     * @param string $content
     * @return array
     */
    protected function findRawMentions(string $content): array
    {
        $mentions = [];
        preg_match_all(
            // Mentions start with '@' and may be quoted or not.
            // Valid username rules apply unless it's quoted, in which case ANY character is allowed.
            // Mentions are bounded by whitespace, non-dash/underscore punctuation, OR the start/end of content.
            '/(?:^|[\s\r\n])@(([\p{N}\p{L}\p{M}\p{Pc}\p{Pd}]+)(?=[\s\r\n\p{Po}\p{Ps}\p{Pe}]+|$)|(".+"))/Uu',
            $content,
            $mentions
        );
        return $mentions[1];
    }

    /**
     * Wraps text in an XML tag.
     *
     * s9e\TextFormatter requires a `<t>` wrap for plain text and `<r>` for HTML ('rich').
     *
     * @param string $char
     * @param string $text
     * @return string
     */
    public static function wrap(string $char, string $text): string
    {
        return '<' . $char . '>' . $text . '</' . $char . '>';
    }

    public static function unwrap(string $text): string
    {
        return substr($text, 3, -4);
    }
}
