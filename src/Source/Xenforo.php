<?php

/**
 * Xenforo source package.
 *
 * Avatar sizes from sample (Sep 2025, v2.3):
 *  data/avatars/s = 48x48 (96x96 res)
 *  data/avatars/m = 96x96 (96x96 res)
 *  data/avatars/l = 192x192 (96x96 res)
 *  data/avatars/h = 384x384 (mixed res)
 *
 * @author Lincoln Russell, code@lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Config;
use Porter\Source;
use Porter\Migration;
use Staudenmeir\LaravelCte\Query\Builder;

class Xenforo extends Source
{
    public const SUPPORTED = [
        'name' => 'Xenforo',
        'defaultTablePrefix' => 'xf_',
        'charsetTable' => 'post',
        'passwordHashMethod' => 'xenforo',
        'avatarsPrefix' => '',
        'avatarThumbPrefix' => '',
        'avatarPath' => 'data/avatars/h/',
        'avatarThumbPath' => 'data/avatars/m/',
        'attachmentPath' => 'internal_data/attachments/0/',
        'attachmentThumbPath' => 'data/attachments/0/',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 0, // @todo
            'Roles' => 1,
            'Avatars' => 1,
            'AvatarThumbnails' => 1,
            'PrivateMessages' => 1,
            'Signatures' => 1,
            'Attachments' => 1,
            'Bookmarks' => 0, // @todo
        ]
    ];

    /**
     * Forum-specific export format.
     *
     * @param Migration $port
     */
    public function run(Migration $port): void
    {
        $this->users($port);
        $this->roles($port);
        $this->signatures($port);

        $this->categories($port);
        $this->discussions($port);
        $this->comments($port);
        $this->conversations($port);
        $this->attachments($port);
    }

    /**
     * @param Migration $port
     */
    public function signatures(Migration $port): void
    {
        $sql = "select
                user_id as UserID,
                'Plugin.Signatures.Sig' as Name,
                signature as Value
            from :_user_profile
            where nullif(signature, '') is not null
            union
            select
                user_id,
                'Plugin.Signatures.Format',
                'BBCode'
            from :_user_profile
            where nullif(signature, '') is not null";
        $port->export('UserMeta', $sql);
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $map = [
            'user_id' => 'UserID',
            'username' => 'Name',
            'email' => 'Email',
            'custom_title' => 'Title',
            'register_date' => 'DateInserted',
            'last_activity' => 'DateLastActive',
            'is_admin' => 'Admin',
            'is_banned' => 'Banned',
            'password' => 'Password',
            'hash_method' => 'HashMethod',
            'avatar' => 'Photo',
            'avatarFullPath' => 'SourceAvatarFullPath',
            'avatarThumbFullPath' => 'SourceAvatarThumbFullPath',
        ];
        $filter = [
            'register_date' => 'timestampToDate',
            'last_activity' => 'timestampToDate',
        ];
        $prx = $port->dbInput()->getTablePrefix();
        $query = $port->sourceQB()->from('user', 'u')->select()
            ->selectRaw("'xenforo' as hash_method")
            ->selectRaw("data as password")
            ->selectRaw("case when avatar_date > 0
                then concat('/', {$prx}u.user_id div 1000, '/', {$prx}u.user_id, '.jpg')
                else null end as avatar")
            ->selectRaw("case when avatar_date > 0
                then concat('{$this->getPath('avatar', true)}', '/',
                    {$prx}u.user_id div 1000, '/', {$prx}u.user_id, '.jpg')
                else null end as avatarFullPath")
            ->selectRaw("case when avatar_date > 0
                then concat('{$this->getPath('avatarThumb', true)}', '/',
                    {$prx}u.user_id div 1000, '/', {$prx}u.user_id, '.jpg')
                else null end as avatarThumbFullPath")
            ->join('user_authenticate as ua', 'u.user_id', '=', 'ua.user_id');

        $port->export('User', $query, $map, $filter);
    }

    /**
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        $role_Map = array(
            'user_group_id' => 'RoleID',
            'title' => 'Name'
        );
        $port->export(
            'Role',
            "select * from :_user_group",
            $role_Map
        );

        // User Roles.
        $userRole_Map = array(
            'user_id' => 'UserID',
            'user_group_id' => 'RoleID'
        );

        $port->export(
            'UserRole',
            "select user_id, user_group_id
                from :_user
                union all
                select u.user_id, ua.user_group_id
                from :_user u
                join :_user_group ua
                    on find_in_set(ua.user_group_id, u.secondary_group_ids)",
            $userRole_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
        $category_Map = array(
            'node_id' => 'CategoryID',
            'title' => 'Name',
            'description' => 'Description',
            'parent_node_id' => array(
                'Column' => 'ParentCategoryID',
                'Filter' => function ($value) {
                    return $value ? $value : null;
                }
            ),
            'display_order' => 'Sort',
            'display_in_list' => array('Column' => 'HideAllDiscussions', 'Filter' => 'NotFilter')
        );
        $port->export(
            'Category',
            "select n.* from :_node n",
            $category_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        $discussion_Map = array(
            'thread_id' => 'DiscussionID',
            'node_id' => 'CategoryID',
            'title' => 'Name',
            'view_count' => 'CountViews',
            'user_id' => 'InsertUserID',
            'post_date' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'sticky' => 'Announce',
            'discussion_open' => array('Column' => 'Closed', 'Filter' => 'NotFilter'),
            'last_post_date' => array('Column' => 'DateLastComment', 'Filter' => 'timestampToDate'),
            'message' => 'Body',
            'format' => 'Format',
            'ip' => array('Column' => 'InsertIPAddress', 'Filter' => 'long2ipf')
        );
        $port->export(
            'Discussion',
            "select t.*,
                p.message,
                'BBCode' as format,
                ip.ip
            from :_thread t
            join :_post p
                on t.first_post_id = p.post_id
            left join :_ip ip
                on p.ip_id = ip.ip_id",
            $discussion_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        $comment_Map = array(
            'post_id' => 'CommentID',
            'thread_id' => 'DiscussionID',
            'user_id' => 'InsertUserID',
            'post_date' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'message' => 'Body',
            'format' => 'Format',
            'ip' => array('Column' => 'InsertIPAddress', 'Filter' => 'long2ipf')
        );
        $port->export(
            'Comment',
            "select p.*,
                'BBCode' as format,
                ip.ip
            from :_post p
            join :_thread t
                on p.thread_id = t.thread_id
            left join :_ip ip
                on p.ip_id = ip.ip_id
            where p.post_id <> t.first_post_id
                and message_state = 'visible'",
            $comment_Map
        );
    }

    /**
     * Export attachments.
     *
     * Real-world example set that consistently refers to the same upload (for real):
     *  URL example: `/attachments/7590ax-webp.227/`
     *  URL format: `'/attachments/' .
     *   str_replace('.', '-', '{attachment_data.filename}') . '.{attachment.attachment_id}'`
     *  Thumbnail path example: `/attachments/0/13-cbec5592e1d5cd9d2f783b4039c4ce6e.jpg`
     *  Thumbnail path format: '/attachments/0/{attachment_data.data_id}-{attachment_data.file_key}.jpg'
     *  Original path example: `/internal_data/attachments/0/13-cbec5592e1d5cd9d2f783b4039c4ce6e.data`
     *  Original path format: `'/internal_data/attachments/0/{attachment_data.data_id}-{attachment_data.file_key}.data'`
     *
     * Captured in late 2025 from Xenforo v2.3.6.
     *
     * Schema magic values: `attachment.content_type`: `post` | `conversation_message`
     *
     * Xenforo faithfully reimplemented vBulletin's worst ideas here, probably a misguided security effort.
     * Most other platforms don't jank filenames like this, so rebuild Path as {id}-{filename} to avoid conflicts.
     * @param Migration $port
     *@see self::attachmentsMap() for the `FileTransfer` data to complete the file renaming.
     *
     */
    protected function attachments(Migration $port): void
    {
        $map = [
            'attachment_id' => 'MediaID',
            'filename' => 'Name',
            'file_size' => 'Size',
            'user_id' => 'InsertUserID',
            'upload_date' => 'DateInserted',
            'width' => 'ImageWidth',
            'height' => 'ImageHeight',
        ];
        $filters = [
            'Type' => 'mimeTypeFromExtension',
        ];
        $prx = $port->dbInput()->getTablePrefix();

        $query = $port->sourceQB()
            ->from('attachment', 'a')
            ->join('attachment_data as ad', 'ad.data_id', '=', 'a.data_id')
            ->select(['a.attachment_id',
                'ad.filename', 'ad.file_size', 'ad.user_id', 'ad.width', 'ad.height',
                'ap.ForeignID', 'ap.ForeignTable',
            ])
            ->selectRaw("{$prx}ad.filename as Type")
            ->selectRaw("from_unixtime({$prx}ad.upload_date) as DateInserted")

            // Paths for platform relative to uploads root (flat, in this case).
            ->selectRaw("concat({$prx}a.data_id, '-', replace({$prx}ad.filename, ' ', '_')) as Path")
            ->selectRaw("concat({$prx}a.data_id, '-', replace({$prx}ad.filename, ' ', '_')) as ThumbPath")

            // Paths for FileTransfer.
            ->selectRaw("concat('{$this->getPath('attachment', true)}', '/',
                {$prx}ad.data_id, '-', {$prx}ad.file_key, '.data') as SourceFullPath")
            ->selectRaw("concat('{$this->getPath('attachmentThumb', true)}', '/',
                {$prx}ad.data_id, '-', {$prx}ad.file_key, '.data') as SourceThumbFullPath")

            // Build a CET of attached post data & join it.
            ->withExpression('ap', function (Builder $query) {
                $prx = $query->connection->getTablePrefix(); // @phpstan-ignore method.notFound
                $query->from('post', 'p')
                    ->select(['post_id'])
                    ->selectRaw("if({$prx}p.post_id = {$prx}t.first_post_id,
                        {$prx}t.thread_id, {$prx}p.post_id) as ForeignID")
                    ->selectRaw("if({$prx}p.post_id = {$prx}t.first_post_id, 'discussion', 'comment') as ForeignTable")
                    ->join('thread as t', "t.thread_id", '=', "p.thread_id")
                    ->where("p.message_state", '<>', "deleted");
            })
            ->join('ap', 'post_id', '=', 'a.content_id')
            ->where('a.content_type', '=', "post");

        $port->export('Media', $query, $map, $filters);
    }

    /**
     * @param Migration $port
     */
    protected function conversations(Migration $port): void
    {
        $conversation_Map = array(
            'conversation_id' => 'ConversationID',
            'title' => 'Subject',
            'user_id' => 'InsertUserID',
            'start_date' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate')
        );
        $port->export(
            'Conversation',
            "select *, substring(title, 1, 200) as title from :_conversation_master",
            $conversation_Map
        );

        $conversationMessage_Map = array(
            'message_id' => 'MessageID',
            'conversation_id' => 'ConversationID',
            'message_date' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'user_id' => 'InsertUserID',
            'message' => 'Body',
            'format' => 'Format',
            'ip' => array('Column' => 'InsertIPAddress', 'Filter' => 'long2ipf')
        );
        $port->export(
            'ConversationMessage',
            "select m.*,
                    'BBCode' as format,
                    ip.ip
                from :_conversation_message m
                left join :_ip ip
                    on m.ip_id = ip.ip_id",
            $conversationMessage_Map
        );

        $userConversation_Map = array(
            'conversation_id' => 'ConversationID',
            'user_id' => 'UserID',
            'Deleted' => 'Deleted'
        );
        $port->export(
            'UserConversation',
            "select
                    r.conversation_id,
                    user_id,
                    case when r.recipient_state = 'deleted' then 1 else 0 end as Deleted
                from :_conversation_recipient r
                union all
                select
                    cu.conversation_id,
                    cu.owner_user_id,
                    0
                from :_conversation_user cu",
            $userConversation_Map
        );
    }
}
