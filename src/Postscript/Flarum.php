<?php

namespace Porter\Postscript;

use Porter\ConnectionManager;
use Porter\Log;
use Porter\Migration;
use Porter\Parser\Flarum\QuoteEmbed;
use Porter\Postscript;

class Flarum extends Postscript
{
    /** @var string[] Database structure for the table post_mentions_user. */
    public const array DB_STRUCTURE_POST_MENTIONS_USER = [
        'post_id' => 'int',
        'mentions_user_id' => 'int',
    ];

    /** @var string[] Database structure for the table post_mentions_post. */
    public const array DB_STRUCTURE_POST_MENTIONS_POST = [
        'post_id' => 'int',
        'mentions_post_id' => 'int',
    ];

    /**
     * Main process.
     *
     * In test runs, 1/3 of the total migration time was from numberPosts and buildPostMentions.
     * They take about as long as migrating all posts (comments) in the first place.
     *
     * @param Migration $port
     */
    public function run(Migration $port): void
    {
        $this->buildUserMentions($port);
        $this->numberPosts($port);
        $this->buildPostMentions($port); // Must be AFTER `numberPosts()`
        $this->setLastRead($port);
        $this->addDefaultGroups($port);
        $this->addDefaultBadgeCategory($port);
        $this->promoteAdmin($port);
        $this->resetAccessTokens($port);
    }

    /**
     * Find mentions in posts and record to database table.
     */
    protected function buildUserMentions(Migration $port): void
    {
        // Start timer.
        $start = microtime(true);
        $rows = 0;

        // Prepare mentions table.
        $port->outputStorage()->prepare('post_mentions_user', self::DB_STRUCTURE_POST_MENTIONS_USER);
        $port->ignoreOutputDuplicates('post_mentions_user'); // Primary key forbids more than 1 record per user/post.

        // Get output post data.
        $posts = $port->postQB()
            ->from('posts')
            ->select(['id', 'discussion_id', 'content']);
        $memory = memory_get_usage();

        // Find & record mentions in batches.
        foreach ($posts->cursor() as $post) {
            // Find converted mentions and connect to userID.
            $mentions = [];
            preg_match_all(
                '/<USERMENTION .*id="(?<userids>[0-9]*)".*\/USERMENTION>/U',
                $post->content,
                $mentions
            );
            // There can be multiple userids per post.
            foreach ($mentions['userids'] as $userid) {
                $port->outputStorage()->stream([
                    'post_id' => $post->id,
                    'mentions_user_id' => (int)$userid
                ], self::DB_STRUCTURE_POST_MENTIONS_USER, false);
                $rows++;
            }
        }

        // Insert remaining mentions.
        $port->outputStorage()->stream([], [], true);

        // Report.
        Log::storage('build', 'mentions_user', microtime(true) - $start, $rows, $memory);
    }

    /**
     * Calculate post numbers for imported posts.
     *
     * Numbers are sequentially incremented chronologically per discussion, not an ID.
     * The `posts.number` field is `1` for the OP and sequentially increments by `created_at` order.
     * The `discussions.post_number_index` is the NEXT number to set for `posts.number`.
     * That means it should be set to the current post count +1.
     *
     * @param Migration $port
     */
    protected function numberPosts(Migration $port): void
    {
        // Start timer.
        $start = microtime(true);
        $rows = 0;
        Log::comment("Building 'post number' info for discussions...");

        // Get discussion id list (avoiding empty discussions) from output.
        $posts = $port->postQB()->from('posts')
            ->distinct()
            ->get('discussion_id');
        $memory = memory_get_usage();

        foreach ($posts as $post) {
            // Update posts with their number, per discussion.
            $prx = $port->dbPostscript()->getTablePrefix();
            $port->dbPostscript()->statement("set @num := 0");
            $count = $port->dbPostscript()->affectingStatement("update `" . "{$prx}posts`
                    set `number` = (@num := @num + 1)
                    where `discussion_id` = " . $post->discussion_id . "
                    order by `created_at` asc");
            $rows += $count;

            // Set discussions.post_number_index
            $port->dbPostscript()->table('discussions')
                ->where('id', '=', $post->discussion_id)
                ->update(['post_number_index' => ($count + 1)]);
        }

        // Report.
        Log::storage('build', 'discussions.post_number_index', microtime(true) - $start, $rows, $memory);
    }

    /**
     * Find mentions in posts and record to database table.
     *
     * @see QuoteEmbed — '<POSTMENTION discussionid="" displayname="{author}" id="{postid}" number="">'
     */
    protected function buildPostMentions(Migration $port): void
    {
        // Start timer.
        $start = microtime(true);
        $rows = 0;
        $failures = 0;

        // Prepare mentions table.
        $port->outputStorage()->prepare('post_mentions_post', self::DB_STRUCTURE_POST_MENTIONS_POST);
        $port->ignoreOutputDuplicates('post_mentions_post'); // Primary key forbids more than 1 record per user/post.

        // Create an OP lookup array.
        // @todo This may fall down around 200K discussions.
        $posts = $port->postQB()
            ->from('posts')
            ->where('number', '=', 1)
            ->get(['id', 'discussion_id'])
            ->toArray();
        $discussions = array_combine(array_column($posts, 'discussion_id'), array_column($posts, 'id'));

        // Get post data.
        $posts = $port->postQB()
            ->from('posts')
            ->select(['id', 'discussion_id', 'content']);
        $memory = memory_get_usage();

        // Find & record mentions in batches.
        foreach ($posts->cursor() as $post) {
            // Find converted mentions and connect to userID.
            $mentions = [];
            preg_match_all(
                '/<POSTMENTION discussionid="(?<discussionids>[0-9]*)".* id="(?<postids>[0-9]*)".*\/POSTMENTION>/U',
                $post->content,
                $mentions
            );

            // There can be multiple mentioned postids per post.
            foreach (array_filter($mentions['postids']) as $postid) {
                // Repair the post.
                if (!$this->repairPostMention($port, $post->id, $post->content, (int)$postid, 'post')) {
                    $failures++;
                }

                // Record post mentions.
                $port->outputStorage()->stream([
                    'post_id' => $post->id,
                    'mentions_post_id' => (int)$postid
                ], self::DB_STRUCTURE_POST_MENTIONS_POST, false);
                $rows++;
            }

            // There can also be multiple mentioned discussionids per post.
            foreach (array_filter($mentions['discussionids']) as $discussionid) {
                // Repair the post.
                if (!$this->repairPostMention($port, $post->id, $post->content, (int)$discussionid, 'discussion')) {
                    $failures++;
                }

                // Record post mentions.
                $port->outputStorage()->stream([
                    'post_id' => $post->id,
                    'mentions_post_id' => (int)$discussions[$discussionid] // Use the OP lookup
                ], self::DB_STRUCTURE_POST_MENTIONS_POST, false);
                $rows++;
            }
        }

        // Insert remaining mentions.
        $port->outputStorage()->stream([], [], true);

        // Log failures.
        if ($failures) {
            Log::comment('Failed to find ' . $failures . ' quoted posts (perhaps deleted).');
        }

        // Report.
        Log::storage('build', 'mentions_post', microtime(true) - $start, $rows, $memory);
    }

    /**
     * Fix incomplete mention markup.
     *
     * This adds considerable overheard to the migration.
     *
     * @param Migration $port
     * @param int $postid Post being updated.
     * @param string $content Content being updated.
     * @param int $quoteID The post referenced in the content.
     * @param string $quoteType One of 'post' or 'discussion'.
     * @return bool Whether the post mention was repaired.
     * @see QuoteEmbed — '<POSTMENTION discussionid="" displayname="{author}" id="{postid}" number="">'
     */
    protected function repairPostMention(Migration $port, int $postid, string $content, int $quoteID, string $quoteType)
    {
        // Prep a secondary connection for updating markup (main one will be running unbuffered query).
        $db = $port->dbPostscript();

        // Get missing quote info.
        $quoteQuery = $db->table('posts');
        if ($quoteType === 'post') {
            $quoteQuery->where('id', '=', $quoteID);
        } else { // 'discussion'
            $quoteQuery->where('discussion_id', '=', $quoteID)
                ->where('number', '=', 1);
        }
        $quotedPost = $quoteQuery->get(['id', 'discussion_id', 'number'])->first();

        // Abort if no quoted post was found.
        if (!is_object($quotedPost)) {
            //$ex->comment("Failed to find mentioned " . $quoteType . " id: " . $quoteID);
            return false;
        }

        // Swap it into the mention markup.
        // Only one of these will match and it's easier than a logic gate.
        $body = str_replace(
            '<POSTMENTION discussionid="" displayname="" id="' . $quoteID . '" number=""',
            '<POSTMENTION discussionid="' . $quotedPost->discussion_id .
            '" displayname="" id="' . $quoteID .
            '" number="' . $quotedPost->number . '"',
            $content
        );
        $body = str_replace(
            '<POSTMENTION discussionid="' . $quoteID . '" displayname="" id="" number=""',
            '<POSTMENTION discussionid="' . $quoteID .
            '" displayname="" id="' . $quotedPost->id .
            '" number="' . $quotedPost->number . '"',
            $content
        );

        // Update the post in the database.
        $db->table('posts')
            ->where('id', '=', $postid)
            ->update(['content' => $body]);

        return true;
    }

    /**
     * Flarum won't even show your bookmarks without last_read_post_number being populated. What a diva!
     *
     * @param Migration $port
     */
    protected function setLastRead(Migration $port): void
    {
        // Verify table exists.
        if (! $port->hasOutputSchema('discussion_user')) {
            return;
        }

        // Start timer.
        $start = microtime(true);
        $rows = 0;
        Log::comment("Building 'last read' info for user bookmarks...");

        // Calculate & set discussion_user.last_read_post_number.
        $bookmarks = $port->postQB()
            ->from('discussion_user', 'du')
            ->select(['du.user_id', 'du.discussion_id'])
            ->selectRaw('max(number) as last_number')
            ->join(
                'posts',
                'posts.discussion_id',
                '=',
                'du.discussion_id',
                'left'
            )
            ->groupBy(['du.user_id', 'du.discussion_id'])
            ->get();
        $memory = memory_get_usage(); // @todo This is a memory bottleneck — can it be streamed?
        foreach ($bookmarks as $post) {
            $count = $port->dbPostscript()->affectingStatement("update `discussion_user`
                set last_read_post_number = " . (int)$post->last_number . "
                where user_id = " . $post->user_id . "
                    and discussion_id = " . $post->discussion_id);
            $rows += $count;
        }

        // Report.
        Log::storage(
            'build',
            'discussion_user.last_read_post_number',
            microtime(true) - $start,
            $rows,
            $memory
        );
    }

    /**
     * Recreate the default groups (1 = Admins, 2 = Guests, 3 = Members).
     *
     * @param Migration $port
     */
    protected function addDefaultGroups(Migration $port): void
    {
        $port->dbPostscript()->table('groups')
            ->insertOrIgnore([
                ['id' => 1, 'name_singular' => 'Admin', 'name_plural' => 'Admins', 'is_hidden' => 0],
                ['id' => 2, 'name_singular' => 'Guest', 'name_plural' => 'Guests', 'is_hidden' => 0],
                ['id' => 3, 'name_singular' => 'Member', 'name_plural' => 'Members', 'is_hidden' => 0],
                // Not strictly necessary, just safer because Mod-level permissions may be in `group_user` already.
                ['id' => 4, 'name_singular' => 'Mod', 'name_plural' => 'Mods', 'is_hidden' => 0],
            ]);
    }

    /**
     * Add the default badge category.
     *
     * Badges are automatically added to badge_category_id = 1 during import.
     *
     * @param Migration $port
     */
    protected function addDefaultBadgeCategory(Migration $port): void
    {
        if ($port->hasOutputSchema('badge_category')) {
            $port->dbPostscript()
                ->table('badge_category')
                ->insertOrIgnore(['id' => 1, 'name' => 'Imported Badges', 'created_at' => date('Y-m-d h:m:s')]);
            Log::comment('Added  badge category "Imported Badges".');
        }
    }

    /**
     * Promote the superadmin to the Flarum admin role.
     *
     * @param Migration $port
     */
    protected function promoteAdmin(Migration $port): void
    {
        // Find the Vanilla superadmin (User.Admin = 1) and make them an Admin.
        $result = $port->targetQB()
            ->from('User')
            ->where('Admin', '>', 0)
            ->first();

        if (isset($result->UserID, $result->Name, $result->Email)) {
            // Add the admin.
            $port->dbPostscript()
                ->table('group_user')
                ->insert(['group_id' => 1, 'user_id' => $result->UserID]);

            // Report promotion.
            Log::comment('Promoted to Admin: ' . $result->Name . ' (' . $result->Email . ')');
        } else {
            // Report failure.
            Log::comment('No user found to promote to Admin. (Searching for Admin=1 flag on PORT_User.)');
        }
    }

    /**
     * Empty access tokens for a fresh forum.
     *
     * @param Migration $port
     */
    protected function resetAccessTokens(Migration $port): void
    {
        $port->dbPostscript()->table('access_tokens')->truncate();
    }
}
