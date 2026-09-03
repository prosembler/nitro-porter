<?php

namespace Porter\Filter;

use Porter\Filter;

class ExtractColonDelimReactions extends Filter
{
    public function __invoke(): mixed
    {
        if ($this->value === '0:0') {
            return null;
        }
        $reactionArray = explode(':', $this->value);

        // @todo absolutely never directly edit serialization, this is so bad; build the array & serialize()
        $arraynum = 0;
        $up = '';
        $down = '';
        if ($reactionArray[0] > 0) {
            $arraynum++;
            $up = 's:2:"Up";s:' . strlen($reactionArray[0]) . ':"' . $reactionArray[0] . '";';
        }
        if ($reactionArray[1] > 0) {
            $arraynum++;
            $down = 's:4:"Down";s:' . strlen($reactionArray[1]) . ':"' . $reactionArray[1] . '";';
        }
        return 'a:1:{s:5:"React";a:' . $arraynum . ':{' . $up . $down . '}}';
    }
}
