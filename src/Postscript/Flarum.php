<?php

namespace Porter\Postscript;

use Porter\Ext\Parser\Flarum\QuoteEmbed;
use Porter\Log;
use Porter\Postscript;
use Porter\StorageInfo;

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
     */
    public function run(): void
    {
        $this->numberPosts();
        $this->buildUserMentions();
        $this->buildPostMentions(); // Must be AFTER `numberPosts()`
        $this->setLastRead();
    }

    /**
     * Find mentions in posts and record to database table.
     */
    protected function buildUserMentions(): void
    {
        // Start timer.
        $start = microtime(true);

        // Prepare mentions table.
        $this->outputStorage()->prepare('post_mentions_user', self::DB_STRUCTURE_POST_MENTIONS_USER);
        $this->ignoreOutputDuplicates('post_mentions_user'); // Primary key forbids more than 1 record per user/post.

        // Get output post data.
        $posts = $this->postQB()
            ->from('posts')
            ->select(['id', 'discussion_id', 'content']);

        $info = new StorageInfo(
            name: 'post_mentions_user',
            memory: memory_get_usage(), // Posts all in memory.
            startTime: $start,
        );

        // Find & record mentions in batches.
        foreach ($posts->cursor() as $post) {
            if (empty($post->content)) {
                continue;
            }

            // Find converted mentions and connect to userID.
            $mentions = [];
            preg_match_all(
                '/<USERMENTION .*id="(?<userids>[0-9]*)".*\/USERMENTION>/U',
                $post->content,
                $mentions
            );
            // There can be multiple userids per post.
            foreach ($mentions['userids'] as $userid) {
                $info = $this->outputStorage()->stream([
                    'post_id' => $post->id,
                    'mentions_user_id' => (int)$userid
                ], $info);
            }
        }
        // Insert remaining mentions.
        $info = $this->outputStorage()->stream([], $info, true);

        // Report.
        Log::storage('build', $info);
    }

    /**
     * Calculate post numbers for imported posts.
     *
     * Numbers are sequentially incremented chronologically per discussion, not an ID.
     * The `posts.number` field is `1` for the OP and sequentially increments by `created_at` order.
     * The `discussions.post_number_index` is the NEXT number to set for `posts.number`.
     * That means it should be set to the current post count +1.
     */
    protected function numberPosts(): void
    {
        // Start timer.
        $start = microtime(true);
        Log::comment("Building 'post number' info for discussions...");

        // Get discussion id list (avoiding empty discussions) from output.
        $posts = $this->postQB()->from('posts')->distinct()->get('discussion_id');
        $memory = memory_get_usage();

        $rows = 0;
        foreach ($posts as $post) {
            // Update posts with their number, per discussion.
            $prx = $this->dbPostscript()->getTablePrefix();
            $this->dbPostscript()->statement("set @num := 0");
            $count = $this->dbPostscript()->affectingStatement("update `" . "{$prx}posts`
                    set `number` = (@num := @num + 1)
                    where `discussion_id` = " . $post->discussion_id . "
                    order by `created_at` asc");
            $rows += $count;

            // Set discussions.post_number_index
            $this->dbPostscript()->table('discussions')
                ->where('id', '=', $post->discussion_id)
                ->update(['post_number_index' => ($count + 1)]);
        }

        // Report.
        Log::storage('build', new StorageInfo(
            name: 'discussions.post_number_index',
            memory: $memory,
            rows: $rows,
            startTime: $start,
        ));
    }

    /**
     * Find mentions in posts and record to database table.
     *
     * @see QuoteEmbed — '<POSTMENTION discussionid="" displayname="{author}" id="{postid}" number="">'
     */
    protected function buildPostMentions(): void
    {
        // Start timer.
        $start = microtime(true);

        // Prepare mentions table.
        $this->outputStorage()->prepare('post_mentions_post', self::DB_STRUCTURE_POST_MENTIONS_POST);
        $this->ignoreOutputDuplicates('post_mentions_post'); // Primary key forbids more than 1 record per user/post.

        // Create an OP lookup array.
        // @todo This may fall down around 200K discussions.
        $posts = $this->postQB()
            ->from('posts')
            ->where('number', '=', 1)
            ->get(['id', 'discussion_id'])
            ->toArray();
        $discussions = array_combine(array_column($posts, 'discussion_id'), array_column($posts, 'id'));

        // Get post data.
        $posts = $this->postQB()
            ->from('posts')
            ->select(['id', 'discussion_id', 'content']);
        $memory = memory_get_usage();

        // Find & record mentions in batches.
        $failures = 0;
        $info = new StorageInfo();
        foreach ($posts->cursor() as $post) {
            if (empty($post->content)) {
                continue;
            }

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
                if (!$this->repairPostMention($post->id, $post->content, (int)$postid, 'post')) {
                    $failures++;
                }

                // Record post mentions.
                $info = $this->outputStorage()->stream([
                    'post_id' => $post->id,
                    'mentions_post_id' => (int)$postid
                ], $info);
            }

            // There can also be multiple mentioned discussionids per post.
            foreach (array_filter($mentions['discussionids']) as $discussionid) {
                // Repair the post.
                if (!$this->repairPostMention($post->id, $post->content, (int)$discussionid, 'discussion')) {
                    $failures++;
                }

                // Record post mentions.
                $info = $this->outputStorage()->stream([
                    'post_id' => $post->id,
                    'mentions_post_id' => (int)$discussions[$discussionid] // Use the OP lookup
                ], $info);
            }
        }
        // Insert remaining mentions.
        $info = $this->outputStorage()->stream([], $info, true);

        // Log failures.
        if ($failures) {
            Log::comment('Failed to find ' . $failures . ' quoted posts (perhaps deleted).');
        }

        // Report.
        Log::storage('build', new StorageInfo(
            name: 'mentions_post',
            memory: $memory,
            rows: $info->rows,
            startTime: $start,
        ));
    }

    /**
     * Fix incomplete mention markup.
     *
     * This adds considerable overheard to the migration.
     *
     * @param int $postid Post being updated.
     * @param string $content Content being updated.
     * @param int $quoteID The post referenced in the content.
     * @param string $quoteType One of 'post' or 'discussion'.
     * @return bool Whether the post mention was repaired.
     * @see QuoteEmbed — '<POSTMENTION discussionid="" displayname="{author}" id="{postid}" number="">'
     */
    private function repairPostMention(int $postid, string $content, int $quoteID, string $quoteType): bool
    {
        // Prep a secondary connection for updating markup (main one will be running unbuffered query).
        $db = $this->dbPostscript();

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
        $content = str_replace(
            '<POSTMENTION discussionid="" displayname="" id="' . $quoteID . '" number=""',
            '<POSTMENTION discussionid="' . $quotedPost->discussion_id .
            '" displayname="" id="' . $quoteID .
            '" number="' . $quotedPost->number . '"',
            $content
        );
        $content = str_replace(
            '<POSTMENTION discussionid="' . $quoteID . '" displayname="" id="" number=""',
            '<POSTMENTION discussionid="' . $quoteID .
            '" displayname="" id="' . $quotedPost->id .
            '" number="' . $quotedPost->number . '"',
            $content
        );

        // Update the post in the database.
        $db->table('posts')
            ->where('id', '=', $postid)
            ->update(['content' => $content]);

        return true;
    }

    /**
     * Flarum won't even show your bookmarks without last_read_post_number being populated. What a diva!
     */
    protected function setLastRead(): void
    {
        // Verify table exists.
        if (! $this->hasOutputSchema('discussion_user')) {
            return;
        }

        // Calculate & set discussion_user.last_read_post_number.
        Log::comment("Building 'last read' info for user bookmarks...");
        $bookmarks = $this->postQB()->from('discussion_user', 'du')
            ->select(['du.user_id', 'du.discussion_id'])
            ->selectRaw('max(number) as last_number')
            ->join('posts', 'posts.discussion_id', '=', 'du.discussion_id', 'left')
            ->groupBy(['du.user_id', 'du.discussion_id'])
            ->get();
        $start = microtime(true);
        $memory = memory_get_usage(); // @todo This is a memory bottleneck — can it be streamed?
        $px = $this->dbPostscript()->getTablePrefix();
        $rows = 0;
        foreach ($bookmarks as $post) {
            $count = $this->dbPostscript()->affectingStatement("update {$px}discussion_user
                set last_read_post_number = " . (int)$post->last_number . "
                where user_id = " . $post->user_id . " and discussion_id = " . $post->discussion_id);
            $rows += $count;
        }
        Log::storage('build', new StorageInfo(
            name: 'discussion_user.last_read_post_number',
            memory: $memory,
            rows: $rows,
            startTime: $start,
        ));
    }
}
