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
    public const array INFO = [
        'name' => 'Simple Machines 2',
        'defaultTablePrefix' => 'smf_',
        'charsetTable' => 'messages',
        'passwordHashMethod' => 'Django',
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

    protected function users(): void
    {
        $map = [
            'id_member' => 'UserID',
            'member_name' => 'Name',
            'password' => 'Password',
            'email_address' => 'Email',
            'date_registered' => 'DateInserted',
            'timeOffset' => 'HourOffset',
            'posts' => 'CountComments',
            //'avatar'=>'Photo',
            'Photo' => 'Photo',
            'birthdate' => 'DateOfBirth',
            'last_login' => 'DateLastActive',
        ];
        $filters = [
            'date_registered' => \Porter\Filter\UnixtimeToDate::class,
            'last_login' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'User',
            "select m.*,
                    concat('sha1$', lower(member_name), '$', passwd) as `password`,
                    if(m.avatar <> '', m.avatar, concat('attachments/', a.filename)) as Photo
                from :_members m
                left join :_attachments a on a.id_member = m.id_member ",
            $map,
            $filters
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
        $map = [
            'name' => 'Name',
        ];
        $filters = [
            'name' => \Porter\Filter\DecodeNumericEntity::class,
        ];
        $this->export(
            'Category',
            "select (`id_cat` + 1000000) as `CategoryID`,
                    name,
                    '' as `Description`,
                    null as `ParentCategoryID`,
                    `cat_order` as `Sort`
                from :_categories
                union
                select b.`id_board` as `CategoryID`,
                    b.name,
                    b.`description` as `Description`,
                    (CASE WHEN b.`id_parent` = 0 THEN (`id_cat` + 1000000) ELSE `id_parent` END) as `ParentCategoryID`,
                    b.`board_order` as `Sort`
                from :_boards b",
            $map,
            $filters
        );
    }

    protected function discussions(): void
    {
        $map = [
            'id_topic' => 'DiscussionID',
            'subject' => 'Name', //,'Filter'=>'bb2html'),
            'body' => 'Body',  //,'Filter'=>'bb2html'),
            'Format' => 'Format',
            'id_board' => 'CategoryID',
            'poster_time' => 'DateInserted',
            'modified_time' => 'DateUpdated',
            'id_member' => 'InsertUserID',
            'DateLastComment' => 'DateLastComment',
            'UpdateUserID' => 'UpdateUserID',
            'locked' => 'Closed',
            'isSticky' => 'Announce',
            'CountComments' => 'CountComments',
            'numViews' => 'CountViews',
            'LastCommentUserID' => 'LastCommentUserID',
            'id_last_msg' => 'LastCommentID',
            'Format=BBCode',
        ];
        $filters = [
            'subject' => \Porter\Filter\DecodeNumericEntity::class,
            'poster_time' => \Porter\Filter\UnixtimeToDate::class,
            'modified_time' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Discussion',
            "select t.*, (t.num_replies + 1) as CountComments, m.subject, m.body, m.id_member,
                    m.poster_time, m.modified_time,
                    from_unixtime(m_end.poster_time) AS DateLastComment,
                    m_end.id_member as UpdateUserID,
                    m_end.id_member as LastCommentUserID
                from :_topics t
                join :_messages as m on t.id_first_msg = m.id_msg
                join :_messages as m_end on t.id_last_msg = m_end.id_msg
                -- where t.spam = 0 AND m.spam = 0;",
            $map,
            $filters
        );
    }

    protected function comments(): void
    {
        $comment_Map = [
            'id_msg' => 'CommentID',
            'id_topic' => 'DiscussionID',
            'Format' => 'Format',
            'body' => 'Body', //,'Filter'=>'bb2html'),
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
        $map = [
            'ID_ATTACH' => 'MediaID',
            'id_msg' => 'ForeignID',
            'size' => 'Size',
            'height' => 'ImageHeight',
            'width' => 'ImageWidth',
            'filename' => 'Type',
            'thumb_path' => 'ThumbPath',
            'thumb_width' => 'ThumbWidth',
        ];
        $filters = [
            'filename' => \Porter\Filter\ExtToMime::class,
            'thumb_path' => \Porter\Filter\NullIfNotImage::class,
            'thumb_width' => \Porter\Filter\NullIfNotImage::class,
        ];
        $this->export(
            'Media',
            "select a.*, b.width as thumb_width,
                    concat('attachments/', a.filename) as Path,
                    IF(b.filename is not null, concat('attachments/', b.filename), null) as thumb_path,
                    if(t.id_topic is null, 'Comment', 'Discussion') as ForeignTable
                from :_attachments a
                left join :_attachments b on b.ID_ATTACH = a.ID_THUMB
                left join :_topics t on a.id_msg = t.id_first_msg
                where a.attachment_type = 0 and a.id_msg > 0",
            $map,
            $filters
        );
    }

    protected function conversations(): void
    {
        $map = [
            'id_pm_head' => 'ConversationID',
            'subject' => 'Subject',
            'id_member_from' => 'InsertUserID',
            'msgtime' => 'DateInserted',
        ];
        $filters = [
            'msgtime' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export('Conversation', "select * from :_personal_messages", $map, $filters);

        $map = [
            'id_pm' => 'MessageID',
            'id_pm_head' => 'ConversationID',
            'body' => 'Body',
            'id_member_from' => 'InsertUserID',
            'msgtime' => 'DateInserted',
            'Format=BBCode',
        ];
        $this->export('ConversationMessage', "select * from :_personal_messages", $map, $filters);

        $map = [
            'id_member2' => 'UserId',
            'id_pm_head' => 'ConversationID',
            'deleted2' => 'Deleted'
        ];
        $this->export(
            'UserConversation',
            "(select pm.id_member_from as id_member2,
                    pm.id_pm_head,
                    pm.deleted_by_sender as deleted2
                from :_personal_messages pm )
                UNION ALL
                (select pmr.id_member as id_member2,
                    pm.id_pm_head,
                    pmr.deleted as deleted2
                from :_personal_messages pm join :_pm_recipients pmr on pmr.id_pm = pm.id_pm)",
            $map
        );
    }
}
