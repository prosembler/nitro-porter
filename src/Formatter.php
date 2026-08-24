<?php

namespace Porter;

use nadar\quill\Lexer as Quill;
use Porter\Bundle\Vanilla as Vanilla;
use s9e\TextFormatter\Bundles\Fatdown as Markdown;
use s9e\TextFormatter\Bundles\Forum as BBCode;

class Formatter
{
    /** @var ?Formatter Singleton storage. */
    private static ?Formatter $instance = null;

    /** @var array Some formatting requires UserIDs to be accessible. */
    protected array $userMap = [];

    /**
     * @var array|string[] Variants of Deleted User marker in Vanilla.
     * @todo Add more multilingual variations of 'deleted user'.
     */
    public const array DELETED_USERNAMES = [
        '[Deleted User]',
        '[DeletedUser]',
        '-Deleted-User-',
        '[Slettet bruker]', // Norwegian
        '[Utilisateur supprimé]', // French
    ];

    public const array URL_CHARACTERS = [
        '–' => '-',
        '—' => '-',
        'À' => 'A',
        'Á' => 'A',
        'Â' => 'A',
        'Ã' => 'A',
        'Ä' => 'A',
        'Å' => 'A',
        'Ā' => 'A',
        'Ą' => 'A',
        'Ă' => 'A',
        'Æ' => 'Ae',
        'Ç' => 'C',
        'Ć' => 'C',
        'Č' => 'C',
        'Ĉ' => 'C',
        'Ċ' => 'C',
        'Ď' => 'D',
        'Đ' => 'D',
        'Ð' => 'D',
        'È' => 'E',
        'É' => 'E',
        'Ê' => 'E',
        'Ë' => 'E',
        'Ē' => 'E',
        'Ě' => 'E',
        'Ĕ' => 'E',
        'Ė' => 'E',
        'Ĝ' => 'G',
        'Ğ' => 'G',
        'Ġ' => 'G',
        'Ģ' => 'G',
        'Ĥ' => 'H',
        'Ħ' => 'H',
        'Ì' => 'I',
        'Í' => 'I',
        'Î' => 'I',
        'Ï' => 'I',
        'Ī' => 'I',
        'Ĩ' => 'I',
        'Ĭ' => 'I',
        'Į' => 'I',
        'İ' => 'I',
        'Ĳ' => 'IJ',
        'Ĵ' => 'J',
        'Ķ' => 'K',
        'Ł' => 'K',
        'Ľ' => 'K',
        'Ĺ' => 'K',
        'Ļ' => 'K',
        'Ŀ' => 'K',
        'Ñ' => 'N',
        'Ń' => 'N',
        'Ň' => 'N',
        'Ņ' => 'N',
        'Ŋ' => 'N',
        'Ò' => 'O',
        'Ó' => 'O',
        'Ô' => 'O',
        'Õ' => 'O',
        'Ö' => 'Oe',
        'Ō' => 'O',
        'Ő' => 'O',
        'Ŏ' => 'O',
        'Œ' => 'OE',
        'Ŕ' => 'R',
        'Ŗ' => 'R',
        'Ś' => 'S',
        'Š' => 'S',
        'Ş' => 'S',
        'Ŝ' => 'S',
        'Ť' => 'T',
        'Ţ' => 'T',
        'Ŧ' => 'T',
        'Ù' => 'U',
        'Ú' => 'U',
        'Û' => 'U',
        'Ū' => 'U',
        'Ü' => 'Ue',
        'Ů' => 'U',
        'Ű' => 'U',
        'Ŭ' => 'U',
        'Ũ' => 'U',
        'Ų' => 'U',
        'Ŵ' => 'W',
        'Ý' => 'Y',
        'Ŷ' => 'Y',
        'Ÿ' => 'Y',
        'Ź' => 'Z',
        'Ž' => 'Z',
        'Ż' => 'Z',
        'Þ' => 'T',
        'à' => 'a',
        'á' => 'a',
        'â' => 'a',
        'ã' => 'a',
        'ä' => 'ae',
        'å' => 'a',
        'ā' => 'a',
        'ą' => 'a',
        'ă' => 'a',
        'æ' => 'ae',
        'ç' => 'c',
        'ć' => 'c',
        'č' => 'c',
        'ĉ' => 'c',
        'ċ' => 'c',
        'ď' => 'd',
        'đ' => 'd',
        'ð' => 'd',
        'è' => 'e',
        'é' => 'e',
        'ê' => 'e',
        'ë' => 'e',
        'ē' => 'e',
        'ę' => 'e',
        'ě' => 'e',
        'ĕ' => 'e',
        'ė' => 'e',
        'ƒ' => 'f',
        'ĝ' => 'g',
        'ğ' => 'g',
        'ġ' => 'g',
        'ģ' => 'g',
        'ĥ' => 'h',
        'ħ' => 'h',
        'ì' => 'i',
        'í' => 'i',
        'î' => 'i',
        'ï' => 'i',
        'ī' => 'i',
        'ĩ' => 'i',
        'ĭ' => 'i',
        'į' => 'i',
        'ı' => 'i',
        'ĳ' => 'ij',
        'ĵ' => 'j',
        'ķ' => 'k',
        'ĸ' => 'k',
        'ł' => 'l',
        'ľ' => 'l',
        'ĺ' => 'l',
        'ļ' => 'l',
        'ŀ' => 'l',
        'ñ' => 'n',
        'ń' => 'n',
        'ň' => 'n',
        'ņ' => 'n',
        'ŉ' => 'n',
        'ŋ' => 'n',
        'ò' => 'o',
        'ó' => 'o',
        'ô' => 'o',
        'õ' => 'o',
        'ö' => 'oe',
        'ø' => 'o',
        'ō' => 'o',
        'ő' => 'o',
        'ŏ' => 'o',
        'œ' => 'oe',
        'ŕ' => 'r',
        'ř' => 'r',
        'ŗ' => 'r',
        'š' => 's',
        'ù' => 'u',
        'ú' => 'u',
        'û' => 'u',
        'ū' => 'u',
        'ü' => 'ue',
        'ů' => 'u',
        'ű' => 'u',
        'ŭ' => 'u',
        'ũ' => 'u',
        'ų' => 'u',
        'ŵ' => 'w',
        'ý' => 'y',
        'ÿ' => 'y',
        'ŷ' => 'y',
        'ž' => 'z',
        'ż' => 'z',
        'ź' => 'z',
        'þ' => 't',
        'ß' => 'ss',
        'ſ' => 'ss',
        'А' => 'A',
        'Б' => 'B',
        'В' => 'V',
        'Г' => 'G',
        'Д' => 'D',
        'Е' => 'E',
        'Ё' => 'YO',
        'Ж' => 'ZH',
        'З' => 'Z',
        'Й' => 'Y',
        'К' => 'K',
        'Л' => 'L',
        'М' => 'M',
        'Н' => 'N',
        'О' => 'O',
        'П' => 'P',
        'Р' => 'R',
        'С' => 'S',
        'ș' => 's',
        'ț' => 't',
        'Ț' => 'T',
        'Т' => 'T',
        'У' => 'U',
        'Ф' => 'F',
        'Х' => 'H',
        'Ц' => 'C',
        'Ч' => 'CH',
        'Ш' => 'SH',
        'Щ' => 'SCH',
        'Ъ' => '',
        'Ы' => 'Y',
        'Ь' => '',
        'Э' => 'E',
        'Ю' => 'YU',
        'Я' => 'YA',
        'а' => 'a',
        'б' => 'b',
        'в' => 'v',
        'г' => 'g',
        'д' => 'd',
        'е' => 'e',
        'ё' => 'yo',
        'ж' => 'zh',
        'з' => 'z',
        'и' => 'i',
        'й' => 'y',
        'к' => 'k',
        'л' => 'l',
        'м' => 'm',
        'н' => 'n',
        'о' => 'o',
        'п' => 'p',
        'р' => 'r',
        'с' => 's',
        'т' => 't',
        'у' => 'u',
        'ф' => 'f',
        'х' => 'h',
        'ц' => 'c',
        'ч' => 'ch',
        'ш' => 'sh',
        'щ' => 'sch',
        'ъ' => '',
        'ы' => 'y',
        'ь' => '',
        'э' => 'e',
        'ю' => 'yu',
        'я' => 'ya',
    ];

    /**
     * Singleton accessor.
     *
     * @return self Formatter
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check for '[Deleted User]' (and variants) as username and replace.
     *
     */
    public function deletedNameDuplicates(?string $name, ?int $userID): string
    {
        if (in_array($name, self::DELETED_USERNAMES)) {
            $name = 'deleted_user_' . $userID;
        }
        return $name;
    }

    /**
     * Create an array of `strtolower(name)` => ID for doing lookups later.
     *
     * @todo This strategy likely won't scale past 100K users. 18K users @ +8mb memory use.
     */
    public function buildUserMap(Target $target): void
    {
        $userMap = $target->dbOutput()
            ->table('users')
            ->get(['id', 'username']);

        $users = [];
        foreach ($userMap as $user) {
            if (!$user->username) {
                Log::comment('UserID ' . $user->id . ' has a blank name.');
                continue;
            }

            // Use the first found ID for each name in case of duplicates.
            if (!isset($users[strtolower($user->username)])) {
                $users[strtolower($user->username)] = $user->id;
            }
        }

        // Record memory usage from user map.
        Log::comment('Mentions map memory usage at ' . Log::formatBytes(memory_get_usage()));

        $this->userMap = $users;
    }

    /**
     * Creates URL codes containing only lowercase Roman letters, digits, and hyphens.
     *
     * Adapted from Vanilla Forums Gdn_Format::Url()
     */
    public function toUrl(?string $str): string
    {
        // Preliminary decoding
        $str = strip_tags(html_entity_decode($str, ENT_COMPAT, 'UTF-8'));
        $str = strtr($str, self::URL_CHARACTERS);
        $str = preg_replace('`[\']`', '', $str);

        // Test for Unicode PCRE support
        // On non-UTF8 systems this will result in a blank string.
        $unicodeSupport = (preg_replace('`[\pP]`u', '', 'P') != '');

        // Convert punctuation, symbols, and spaces to hyphens
        if ($unicodeSupport) {
            $str = preg_replace('`[\pP\pS\s]`u', '-', $str);
        } else {
            $str = preg_replace('`[\s_[^\w\d]]`', '-', $str);
        }

        // Lowercase, no trailing or repeat hyphens
        $str = preg_replace('`-+`', '-', strtolower($str));
        $str = trim($str, '-');

        return rawurlencode($str);
    }

    /**
     * Render content to HTML, for targets that store rendered markup rather than TextFormatter XML.
     */
    public function toHtml(?string $format, ?string $text): string
    {
        if ($text === null) {
            return '';
        }
        switch (strtolower((string)$format)) {
            case 'html':
            case 'wysiwyg':
            case 'raw': // Unfiltered, so these could break.
                return (string)self::fixIllegalTags(self::closeTags($text));
            case 'markdown':
                return Markdown::render(Markdown::parse($text));
            case 'bbcode':
                return BBCode::render(BBCode::parse($text));
            case 'rich': // Quill
                return self::dequill($text);
            case 'text':
            case 'textex':
            default:
                // Escape first: the value is literal text, so any markup in it is not markup.
                return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
    }

    /**
     * Put content in TextFormatter-compatible format.
     */
    public function toTextFormatter(?string $format, ?string $text): string
    {
        if ($text === null) {
            return '';
        }
        switch (strtolower($format)) {
            case 'html':
            case 'wysiwyg':
            case 'raw': // Unfiltered — these could break.
                // Custom bundle to enable more HTML elements than Markdown.
                $text = self::closeTags($text);
                $text = self::fixIllegalTags($text);
                return $this->fixRawMentions(Vanilla::parse($text));
            case 'markdown':
                // Markdown bundle allows some (filtered) HTML.
                return $this->fixRawMentions(Markdown::parse($text));
            case 'bbcode':
                return $this->fixRawMentions(BBCode::parse($text));
            case 'rich': // Quill
                return self::wrap('r', self::dequill($text));
            case 'text':
            case 'textex':
            default:
                // Use of nl2br() here is needed for Vanilla PMs (which have no `Format`).
                // May require more refined detection for other cases but too many breaks is safer than too few.
                return self::wrap('t', $this->fixRawMentions(nl2br($text)));
        }
    }

    /**
     * s9e/textformatter loses its mind about unclosed HTML tags.
     */
    public static function closeTags(?string $text): ?string
    {
        if (empty($text)) {
            return $text;
        }

        // <br>
        $text = str_replace('<br>', '<br/>', $text);
        // <img src="">
        return preg_replace('#<img ([^>]+[^/])>#', '<img $1 />', $text);
    }

    /**
     * s9e/textformatter loses its mind about illegal span tags wrapping a div.
     *
     * This is a very tricky hack, so it needs to be rather narrow and therefore won't fix every scenario.
     * Class-less spans directly wrapping a div are the only condition we'll address for now.
     */
    public static function fixIllegalTags(?string $text): ?string
    {
        // <span><div ... </div></span>
        return preg_replace('#<span><div ([^>]+[^/])>(.+)</div></span>#U', '<div $1>$2</div>', $text);
    }

    /**
     * 'Rich' format in Vanilla is actually Quill WYSIWYG Delta.
     *
     * Vanilla stored invalid JSON — the "ops" array without its wrapper.
     * @see https://quilljs.com/docs/delta/
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
