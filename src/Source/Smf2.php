<?php

/**
 * SMF2 exporter tool
 *
 * @author  John Crenshaw, for Priacta, Inc.
 */

namespace Porter\Source;

use Porter\Source;

class Smf2 extends Source
{
    public const array SUPPORTED = [
        'name' => 'Simple Machines 2',
        'defaultTablePrefix' => 'smf_',
        'charsetTable' => 'messages',
        'passwordHashMethod' => 'Django',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 0,
            'Roles' => 1,
            'Avatars' => 0,
            'PrivateMessages' => 1,
            'Attachments' => 1,
            'Bookmarks' => 1,
        ]
    ];

    public array $sourceTables = [
        'boards' => [],
        'messages' => [],
        'personal_messages' => [],
        'pm_recipients' => [],
        'categories' => ['id_cat', 'name', 'cat_order'],
        'membergroups' => [],
        'members' => ['id_member', 'member_name', 'passwd', 'email_address', 'date_registered']
    ];

    public function decodeNumericEntity(string $text): array|false|string|null
    {
        if (function_exists('mb_decode_numericentity')) {
            $convmap = [0x0, 0x2FFFF, 0, 0xFFFF];
            return mb_decode_numericentity($text, $convmap, 'UTF-8');
        } else {
            return $text;
        }
    }

    /**
     * Filter used by $Media_Map to replace value for ThumbPath and ThumbWidth when the file is not an image.
     */
    public function filterThumbnailData(mixed $value, string $field, array $row): ?string
    {
        $extension = pathinfo($row['Path'], PATHINFO_EXTENSION);
        $images = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp'];
        if (in_array(strtolower($extension), $images)) {
            return $value;
        }
        return null;
    }

    protected function users(): void
    {
        $user_Map = [
            'id_member' => 'UserID',
            'member_name' => 'Name',
            'password' => 'Password',
            'email_address' => 'Email',
            'DateInserted' => 'DateInserted',
            'timeOffset' => 'HourOffset',
            'posts' => 'CountComments',
            //'avatar'=>'Photo',
            'Photo' => 'Photo',
            'birthdate' => 'DateOfBirth',
            'DateFirstVisit' => 'DateFirstVisit',
            'DateLastActive' => 'DateLastActive',
            'DateUpdated' => 'DateUpdated'
        ];
        $this->export(
            'User',
            " select m.*,
                    from_unixtime(date_registered) as DateInserted,
                    from_unixtime(date_registered) as DateFirstVisit,
                    from_unixtime(last_login) as DateLastActive,
                    from_unixtime(last_login) as DateUpdated,
                    concat('sha1$', lower(member_name), '$', passwd) as `password`,
                    if(m.avatar <> '', m.avatar, concat('attachments/', a.filename)) as Photo
                from :_members m
                left join :_attachments a on a.id_member = m.id_member ",
            $user_Map
        );
    }

    protected function roles(): void
    {
        $role_Map = [
            'id_group' => 'RoleID',
            'group_name' => 'Name'
        ];
        $this->export('Role', "select * from :_membergroups", $role_Map);

        // UserRoles
        $userRole_Map = [
            'id_member' => 'UserID',
            'id_group' => 'RoleID'
        ];
        $this->export('UserRole', "select * from :_members", $userRole_Map);
    }

    protected function categories(): void
    {
        $category_Map = [
            'Name' => ['Column' => 'Name', 'Filter' => [$this, 'decodeNumericEntity']],
        ];
        $this->export(
            'Category',
            "select
                    (`id_cat` + 1000000) as `CategoryID`,
                    `name` as `Name`,
                    '' as `Description`,
                    null as `ParentCategoryID`,
                    `cat_order` as `Sort`
                from :_categories
                union
                select
                    b.`id_board` as `CategoryID`,
                    b.`name` as `Name`,
                    b.`description` as `Description`,
                    (CASE WHEN b.`id_parent` = 0 THEN (`id_cat` + 1000000) ELSE `id_parent` END) as `ParentCategoryID`,
                    b.`board_order` as `Sort`
                from :_boards b",
            $category_Map
        );
    }

    protected function discussions(): void
    {
        $discussion_Map = [
            'id_topic' => 'DiscussionID',
            'subject' => ['Column' => 'Name', 'Filter' => [$this, 'decodeNumericEntity']],
            //,'Filter'=>'bb2html'),
            'body' => ['Column' => 'Body'],
            //,'Filter'=>'bb2html'),
            'Format' => 'Format',
            'id_board' => 'CategoryID',
            'DateInserted' => 'DateInserted',
            'DateUpdated' => 'DateUpdated',
            'id_member' => 'InsertUserID',
            'DateLastComment' => 'DateLastComment',
            'UpdateUserID' => 'UpdateUserID',
            'locked' => 'Closed',
            'isSticky' => 'Announce',
            'CountComments' => 'CountComments',
            'numViews' => 'CountViews',
            'LastCommentUserID' => 'LastCommentUserID',
            'id_last_msg' => 'LastCommentID'
        ];
        $this->export(
            'Discussion',
            "select t.*,
                    (t.num_replies + 1) as CountComments,
                    m.subject,
                    m.body,
                    from_unixtime(m.poster_time) as DateInserted,
                    from_unixtime(m.modified_time) as DateUpdated,
                    m.id_member,
                    from_unixtime(m_end.poster_time) AS DateLastComment,
                    m_end.id_member AS UpdateUserID,
                    m_end.id_member AS LastCommentUserID,
                    'BBCode' as Format
                from :_topics t
                join :_messages as m on t.id_first_msg = m.id_msg
                join :_messages as m_end on t.id_last_msg = m_end.id_msg
                -- where t.spam = 0 AND m.spam = 0;",
            $discussion_Map
        );
    }

    protected function comments(): void
    {
        $comment_Map = [
            'id_msg' => 'CommentID',
            'id_topic' => 'DiscussionID',
            'Format' => 'Format',
            'body' => ['Column' => 'Body'], //,'Filter'=>'bb2html'),
            'id_member' => 'InsertUserID',
            'DateInserted' => 'DateInserted'
        ];
        $this->export(
            'Comment',
            "select m.*,
                    from_unixtime(m.poster_time) AS DateInserted,
                    'BBCode' as Format
                from :_messages m
                join :_topics t on m.id_topic = t.id_topic
                where m.id_msg <> t.id_first_msg;",
            $comment_Map
        );
    }

    protected function attachments(): void
    {
        $media_Map = [
            'ID_ATTACH' => 'MediaID',
            'id_msg' => 'ForeignID',
            'size' => 'Size',
            'height' => 'ImageHeight',
            'width' => 'ImageWidth',
            'thumb_path' => ['Column' => 'ThumbPath', 'Filter' => [$this, 'filterThumbnailData']],
            'thumb_width' => ['Column' => 'ThumbWidth', 'Filter' => [$this, 'filterThumbnailData']],
        ];
        $filters = [
           'Type' => 'ExtToMime',
        ];
        $this->export(
            'Media',
            "select a.*,
                    concat('attachments/', a.filename) as Path,
                    IF(b.filename is not null, concat('attachments/', b.filename), null) as thumb_path,
                    a.filename as Type,
                    b.width as thumb_width,
                    if(t.id_topic is null, 'Comment', 'Discussion') as ForeignTable
                from :_attachments a
                    left join :_attachments b on b.ID_ATTACH = a.ID_THUMB
                    left join :_topics t on a.id_msg = t.id_first_msg
                where a.attachment_type = 0
                    and a.id_msg > 0",
            $media_Map,
            $filters
        );
    }

    protected function conversations(): void
    {
        $conversation_Map = [
            'id_pm_head' => 'ConversationID',
            'subject' => 'Subject',
            'id_member_from' => 'InsertUserID',
            'unixmsgtime' => 'DateInserted',
        ];
        $this->export(
            'Conversation',
            "select pm.*,
                    from_unixtime(pm.msgtime) as unixmsgtime
                from :_personal_messages pm",
            $conversation_Map
        );

        $convMsg_Map = [
            'id_pm' => 'MessageID',
            'id_pm_head' => 'ConversationID',
            'body' => 'Body',
            'format' => 'Format',
            'id_member_from' => 'InsertUserID',
            'unixmsgtime' => 'DateInserted',
        ];
        $this->export(
            'ConversationMessage',
            "select pm.*,
                    from_unixtime(pm.msgtime) as unixmsgtime ,
                    'BBCode' as format
                from :_personal_messages pm",
            $convMsg_Map
        );

        $userConv_Map = [
            'id_member2' => 'UserId',
            'id_pm_head' => 'ConversationID',
            'deleted2' => 'Deleted'
        ];
        $this->export(
            'UserConversation',
            "(select
                    pm.id_member_from as id_member2,
                    pm.id_pm_head,
                    pm.deleted_by_sender as deleted2
                from :_personal_messages pm )
            UNION ALL
            (select
                    pmr.id_member as id_member2,
                    pm.id_pm_head,
                    pmr.deleted as deleted2
                from :_personal_messages pm join :_pm_recipients pmr on pmr.id_pm = pm.id_pm)",
            $userConv_Map
        );
    }
}
