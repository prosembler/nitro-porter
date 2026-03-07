<?php

/**
 * Filter functions for passing thru values during export.
 */

/**
 * Available filter for Migration.
 *
 * @see Migration::filterData()
 * @see \Porter\Target\Flarum::comments()
 *
 * @param ?string $value
 * @param string $column
 * @param array $row
 * @return string
 */
function filterFlarumContent(?string $value, string $column, array $row): string
{
    $format = $row['Format'] ?? 'Text'; // Apparently null 'Format' values are possible.
    return \Porter\Formatter::instance()->toTextFormatter($format, $value);
}

/**
 * De-duplicate deleted usernames.
 *
 * Check for '[Deleted User]' (and variants) as username and replace.
 * Violates a unique key constraint on the target db's username field.
 *
 * @param string|null $value
 * @param string $column
 * @param array $row
 * @return string|null
 */
function fixDuplicateDeletedNames(?string $value, string $column, array $row): ?string
{
    // @todo Add multilingual variations of 'deleted user'.
    $duplicates = [
        '[Deleted User]',
        '[DeletedUser]',
        '-Deleted-User-',
        '[Slettet bruker]', // Norwegian
        '[Utilisateur supprimé]', // French
    ];
    if (in_array($value, $duplicates)) {
        $value = 'deleted_user_' . $row['UserID'];
    }
    return $value;
}

/**
 * @param mixed $value
 * @param string $column
 * @param array $row
 * @return string
 */
function fixNullEmails(mixed $value, string $column, array $row): string
{
    if (empty($value)) {
        $value = 'blank_email_' . $row['UserID'];
    }
    return $value;
}

/**
 * @param mixed $value
 * @param string $column
 * @param array $row
 * @return string
 */
function createDiscussionSlugs(mixed $value, string $column, array $row): string
{
    return $value; // @todo Create a slug
}

/**
 * Don't allow zero-equivalent dates.
 *
 * @param string $value
 * @return string
 */
function forceDate(string $value): string
{
    if (!$value || preg_match('`0000-00-00`', $value)) {
        return gmdate('Y-m-d H:i:s');
    }

    return $value;
}

/**
 * Only allow IPv4 addresses to pass.
 *
 * @param string $ip
 * @return string|null Valid IPv4 address or nuthin'.
 */
function forceIP4(string $ip): ?string
{
    $ip = '';
    if (preg_match('`(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})`', $ip, $m)) {
        $ip = $m[1];
    }

    return $ip ?: null;
}

/**
 * Creates URL codes containing only lowercase Roman letters, digits, and hyphens.
 * Converted from Gdn_Format::Url
 *
 * @param string $str A string to be formatted.
 * @return string
 */
function formatUrl(string $str): string
{
    $urlTranslations = array(
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
        'я' => 'ya'
    );

    // Preliminary decoding
    $str = strip_tags(html_entity_decode($str, ENT_COMPAT, 'UTF-8'));
    $str = strtr($str, $urlTranslations);
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
 * Decode the HTML out of a value.
 */
function HTMLDecoder(string $value): string
{
    // Uses default flags as of PHP 8.1.
    $encoding = defined('PORTER_INPUT_ENCODING') ? PORTER_INPUT_ENCODING : 'UTF-8';
    return html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, $encoding);
}

/**
 * Inverse int value.
 *
 * @param mixed $value
 * @return int
 */
function notFilter(mixed $value): int
{
    return (int)(!$value);
}

/**
 * Convert empty values to zero.
 *
 * Useful for 'not null' columns with default=0.
 *
 * @param mixed $value
 * @return mixed Original $value or zero if empty.
 */
function emptyToZero(mixed $value): mixed
{
    return empty($value) ? 0 : $value;
}

/**
 * Convert a timestamp to MySQL date format.
 *
 * Do this in MySQL with FROM_UNIXTIME() instead whenever possible.
 *
 * @param mixed $value
 * @return ?string
 */
function timestampToDate(mixed $value): ?string
{
    if ($value == null) {
        return null;
    } else {
        return gmdate('Y-m-d H:i:s', $value);
    }
}

/**
 * Wrapper for long2ip that nulls 'non-digit' values.
 *
 * @param mixed $value
 * @return ?string
 */
function long2ipf(mixed $value): ?string
{
    return (!empty($value) && ctype_digit($value)) ? long2ip($value) : null;
}

/**
 * Convert 'y/n' to boolean.
 *
 * @param mixed $value
 * @return int
 */
function YNBool(mixed $value): int
{
    if ($value == 'y') {
        return 1;
    } else {
        return 0;
    }
}

/**
 * Guess the Format of the Body.
 *
 * @param mixed $value
 * @return string
 */
function guessFormat(mixed $value): string
{
    if (strpos($value, '[') !== false) {
        return 'BBCode';
    } elseif (str_contains($value, '<')) {
        return 'Html';
    } else {
        return 'BBCode';
    }
}

/**
 * Derive mimetype from file extension.
 *
 * @param string $value
 * @return string
 */
function mimeTypeFromExtension(string $value): string
{
    $value = pathinfo($value, PATHINFO_EXTENSION);
    switch ($value) {
        case 'png':
        case 'jpg':
        case 'jpeg':
        case 'gif':
        case 'bmp':
        case 'webp':
            return 'image/' . $value;
        case 'zip':
        case 'doc':
        case 'docx':
        case 'pdf':
        case 'xls':
        case 'swf':
            return 'application/' . $value;
        case 'txt':
        case 'htm':
        case 'html':
            return 'text/' . $value;
        case 'mov':
        case 'avi':
            return 'video/' . $value;
    }
    return '';
}

/**
 * Change square brackets to braces.
 *
 * @param mixed $value
 * @return mixed
 */
function cleanBodyBrackets(mixed $value): mixed
{
    if (strpos($value, '[') !== false) {
        $result = str_replace(array('<', '>'), array('[', ']'), $value);
        return $result;
    }
    return $value;
}

/**
 * @param string $text
 * @return string
 */
function bbPressTrim(string $text): string
{
    return rtrim(bb_Code_Trick_Reverse($text));
}

/**
 * @param string $text
 * @return string
 */
function bb_Code_Trick_Reverse(string $text): string
{
    $text = preg_replace_callback("!(<pre><code>|<code>)(.*?)(</code></pre>|</code>)!s", 'bb_decodeit', $text);
    $text = str_replace(array('<p>', '<br />'), '', $text);
    $text = str_replace('</p>', "\n", $text);
    $text = str_replace('<coded_br />', '<br />', $text);
    $text = str_replace('<coded_p>', '<p>', $text);
    $text = str_replace('</coded_p>', '</p>', $text);

    return $text;
}

/**
 * @param array $matches
 * @return string
 */
function bb_Decodeit(array $matches): string
{
    $text = $matches[2];
    $trans_table = array_flip(get_html_translation_table(HTML_ENTITIES));
    $text = strtr($text, $trans_table);
    $text = str_replace('<br />', '<coded_br />', $text);
    $text = str_replace('<p>', '<coded_p>', $text);
    $text = str_replace('</p>', '</coded_p>', $text);
    $text = str_replace(array('&#38;', '&amp;'), '&', $text);
    $text = str_replace('&#39;', "'", $text);
    if ('<pre><code>' == $matches[1]) {
        $text = "\n$text\n";
    }

    return "`$text`";
}

/**
 * Convert Vanilla's avatar filenames to match the filesystem.
 *
 * In Vanilla's database, avatars are relative paths, e.g. `userpics/396/YGIC427MJADQ.jpg`
 * In the filesystem, filenames are prepended with 'n' (thumbnail - small) or 'p' (profile - fullsize)
 * Since we are exporting, assume db values should use the fullsize image's actual filename.
 *
 * This is a rare case of the intermediary PORTER database necessarily being out of sync with Vanilla's
 * due to this one-way data transformation being required.
 * Running the export from Vanilla to Vanilla will therefore likely cause avatars to break.
 *
 * Removes everything from `$path` after final '/', then re-appends the filename
 * with 'p' now prepended (if it didn't already start with 'p' to prevent compounding it).
 *
 * @param string $path
 * @return string
 */
function vanillaPhoto(string $path): string
{
    // Skip processing for blank entries.
    if (empty($path)) {
        return $path;
    }

    // Vanilla can have URLs in the Photo field.
    if (strrpos($path, 'http') === 0) {
        return $path;
    }

    // Vanilla Cloud CDN was used & you'll need a bespoke script to fix avatars.
    if (strrpos($path, 'static:') === 0) {
        return $path;
    }

    $filename = basename($path);
    // Only convert Vanilla-processed avatars (skip previous imports)
    // OR vBulletin-imported avatars (which got special post-processing in Vanilla, along with SM2 imports)
    // @see Vanilla\FileUtils::generateUniqueUploadPath()
    // @see \vBulletinImportModel::processAvatars()
    if (
        preg_match('/[A-Z0-9]{12}\.(jpg|jpeg|gif|png)/', $filename) !== 1
        && preg_match('/avatar[0-9_]+\.(jpg|jpeg|gif)/', $filename) !== 1
    ) {
        return $path;
    }
    // Don't recursively add 'p' to filenames.
    if (strrpos($filename, 'p') !== 0) {
        $filename = 'p' . $filename;
    }
    return substr($path, 0, strrpos($path, '/') + 1) . $filename;
}
