<?php

namespace Porter\Filter;

use Porter\Filter;

/** Requires selecting discussion/comment ID as 'FilterPostID'. */
class Base64ToFile extends Filter
{
    public function __invoke(): mixed
    {
        static $imageCount = 1;
        $postId = $this->row['FilterPostID'];
        preg_replace_callback(
            "~\"data:image/png;base64,(.*?)\"~",
            function ($matches) use ($postId, &$imageCount) {
                $filename = "{$postId}_{$imageCount}.png";
                $imageCount++;
                if (!empty($matches[1])) {
                    file_put_contents('/uploads/' . $filename, base64_decode($matches[1]));
                }
                return "\"/uploads/$filename\"";
            },
            $this->value
        );
        return $this->value;
    }
}
