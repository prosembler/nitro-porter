<?php

namespace Porter\Filter;

use Porter\Filter;

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
 */
class VanillaPhoto extends Filter
{
    public function __invoke(): mixed
    {
        $path = $this->value;

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
}
