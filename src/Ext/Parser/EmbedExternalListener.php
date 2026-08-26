<?php

namespace Porter\Ext\Parser;

use nadar\quill\BlockListener;

/**
 * Add convenience logic for "embed-external" block listeners.
 */
abstract class EmbedExternalListener extends BlockListener
{
    /**
     * Detect a type of embed-external block.
     *
     * @param array $jsonInsert See insertJsonKey().
     * @param array $types One of 'link', 'quote', or 'image'.
     * @return bool Whether to process the block.
     */
    public function isEmbedExternal(array $jsonInsert, array $types): bool
    {
        // Verify basic info structure.
        if (!isset($jsonInsert['data'])) {
            return false;
        }

        // Check data.type
        if (isset($jsonInsert['data']['type']) && in_array($jsonInsert['data']['type'], $types, true)) {
            return true;
        }

        // Check data.embedType
        if (isset($jsonInsert['data']['embedType']) && in_array($jsonInsert['data']['embedType'], $types, true)) {
            return true;
        }

        return false;
    }
}
