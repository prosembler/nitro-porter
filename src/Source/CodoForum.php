<?php

/**
 * Codoforum exporter tool. Tested with CodoForum v3.7.
 *
 * @author  Hans Adema
 */

namespace Porter\Source;

use Porter\Source;

class CodoForum extends Source
{
    public const SUPPORTED = [
        'name' => 'CodoForum',
        'defaultTablePrefix' => 'codo_',
        'charsetTable' => 'posts',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 0,
            'Roles' => 1,
            'Avatars' => 0,
            'PrivateMessages' => 0,
            'Signatures' => 1,
        ]
    ];

    /**
     * @var array Required tables => columns
     */
    public array $sourceTables = array(
        'users' => array('id', 'username', 'mail', 'user_status', 'pass', 'signature'),
        'roles' => array('rid', 'rname'),
        'user_roles' => array('uid', 'rid'),
        'categories' => array('cat_id', 'cat_name'),
        'topics' => array('topic_id', 'cat_id', 'uid', 'title'),
        'posts' => array('post_id', 'topic_id', 'uid', 'imessage'),
    );

    /**
     * Main export process.
     *
     */
    public function run(): void
    {
        $this->users();
        $this->roles();
        $this->userMeta();
        $this->categories();
        $this->discussions();
        $this->comments();
    }

    /**
     */
    protected function users(): void
    {
        $this->export(
            'User',
            "select
                u.id as UserID,
                u.username as Name,
                u.mail as Email,
                u.user_status as Verified,
                u.pass as Password,
                'Vanilla' as HashMethod,
                from_unixtime(u.created) as DateFirstVisit
            from :_users u"
        );
    }

    /**
     */
    protected function roles(): void
    {
        $this->export(
            'Role',
            "select
                    r.rid as RolesID,
                    r.rname as Name
                from :_roles r"
        );

        // User Role.
        $this->export(
            'UserRole',
            "select
                    ur.uid as UserID,
                    ur.rid as RoleID
                from :_user_roles ur
                where ur.is_primary = 1"
        );
    }

    /**
     */
    protected function userMeta(): void
    {
        $this->export(
            'UserMeta',
            "select
                    u.id as UserID,
                    'Plugin.Signatures.Sig' as Name,
                    u.signature as Value
                from :_users u
                where u.signature != '' and u.signature is not null"
        );
    }

    /**
     */
    protected function categories(): void
    {
        $this->export(
            'Category',
            "select
                    c.cat_id as CategoryID,
                    c.cat_name as Name
                from :_categories c"
        );
    }

    /**
     */
    protected function discussions(): void
    {
        $this->export(
            'Discussion',
            "select
                t.topic_id as DiscussionID,
                t.cat_id as CategoryID,
                t.uid as InsertUserID,
                t.title as Name,
                from_unixtime(t.topic_created) as DateInserted,
                from_unixtime(t.last_post_time) as DateLastComment
            from :_topics t"
        );
    }

    /**
     */
    protected function comments(): void
    {
        $this->export(
            'Comment',
            "select
                    p.post_id as CommentID,
                    p.topic_id as DiscussionID,
                    p.uid as InsertUserID,
                    p.imessage as Body,
                    'Markdown' as Format,
                    from_unixtime(p.post_created) as DateInserted
                from :_posts p"
        );
    }
}
