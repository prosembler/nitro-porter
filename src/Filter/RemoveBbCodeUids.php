<?php

namespace Porter\Filter;

use Porter\Filter;

/** Optional field bbcode_uid */
class RemoveBbCodeUids extends Filter
{
    public function __invoke(): mixed
    {
        if (empty($this->value)) {
            return null;
        }

        // Remove UIDs.
        if (!empty($this->row['bbcode_uid'])) {
            $UID = trim($this->row['bbcode_uid']);  // ex: '2zp03s9s';
            $this->value = preg_replace("`((?::[a-zA-Z])?:$UID)`", '', $this->value);
        }

        // Remove smilies.
        $this->value = preg_replace('#<!-- s(.*?) --><img src="\{SMILIES_PATH}/.*? /><!-- s -->#', '\1', $this->value);

        // Remove links.
        $regex = '`<!-- [a-z] --><a\s+class="[^"]+"\s+href="([^"]+)">([^<]+)</a><!-- [a-z] -->`';
        $this->value = preg_replace($regex, '[url=$1]$2[/url]', $this->value);

        // Allow mailto: links w/o a class.
        $regex = '`<!-- [a-z] --><a\s+href="mailto:([^"]+)">([^<]+)</a><!-- [a-z] -->`i';
        $this->value = preg_replace($regex, '[url=$1]$2[/url]', $this->value);

        // Fix encoded characters.
        $from = ['&quot;', '&#39;', '&#58;', 'Â', '&#46;', '&amp;'];
        $to = ['"', "'", ':', '', '.', '&'];
        return str_replace($from, $to, $this->value);
    }
}
