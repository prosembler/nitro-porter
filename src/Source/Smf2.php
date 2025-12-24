<?php

/**
 * SMF2 exporter tool
 *
 * @author  John Crenshaw, for Priacta, Inc.
 */

namespace Porter\Source;

use Porter\Source;
use Porter\Migration;

class Smf2 extends Source
{
    public const SUPPORTED = [
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
            'Signatures' => 0,
            'Attachments' => 1,
            'Bookmarks' => 1,
        ]
    ];

    /**
     * @var array Required tables => columns
     */
    public array $sourceTables = array(
        'boards' => array(),
        'messages' => array(),
        'personal_messages' => array(),
        'pm_recipients' => array(),
        'categories' => array('id_cat', 'name', 'cat_order'),
        'membergroups' => array(),
        'members' => array('id_member', 'member_name', 'passwd', 'email_address', 'date_registered')
    );

    /**
     * Forum-specific export format.
     *
     * @param Migration $port
     */
    public function run(Migration $port): void
    {
        $this->users($port);
        $this->roles($port);
        $this->categories($port);
        $this->discussions($port);
        $this->comments($port);
        $this->attachments($port);
        $this->conversations($port);
    }

    public function decodeNumericEntity(string $text): array|false|string|null
    {
        if (function_exists('mb_decode_numericentity')) {
            $convmap = array(0x0, 0x2FFFF, 0, 0xFFFF);
            return mb_decode_numericentity($text, $convmap, 'UTF-8');
        } else {
            return $text;
        }
    }

    /**
     * Determine mime type from file name
     *
     * @param  string $fileName File name (Can be full path or file name only)
     * @return null|string Mime type if it could be determined or null.
     */
    public function getMimeTypeFromFileName($fileName): ?string
    {
        $mimeType = null;

        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        if ($extension) {
            $mimeType = MimeTypeFromExtension('.' . strtolower($extension));
        }

        return $mimeType;
    }

    /**
     * Filter used by $Media_Map to replace value for ThumbPath and ThumbWidth when the file is not an image.
     *
     * @access public
     * @param  string $value Current value
     * @param  string $field Current field
     * @param  array  $row   Contents of the current record.
     * @return string|null Return the supplied value if the record's file is an image. Return null otherwise
     *@see    Migration::writeTableToFile
     *
     */
    public function filterThumbnailData($value, $field, $row): ?string
    {
        $mimeType = $this->getMimeTypeFromFileName($row['Path']);
        if ($mimeType && strpos($mimeType, 'image/') === 0) {
            return $value;
        }
        return null;
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $user_Map = array(
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
        );
        $port->export(
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

    /**
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        $role_Map = array(
            'id_group' => 'RoleID',
            'group_name' => 'Name'
        );
        $port->export('Role', "select * from :_membergroups", $role_Map);

        // UserRoles
        $userRole_Map = array(
            'id_member' => 'UserID',
            'id_group' => 'RoleID'
        );
        $port->export('UserRole', "select * from :_members", $userRole_Map);
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
        $category_Map = array(
            'Name' => array('Column' => 'Name', 'Filter' => array($this, 'decodeNumericEntity')),
        );

        $port->export(
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

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        $discussion_Map = array(
            'id_topic' => 'DiscussionID',
            'subject' => array('Column' => 'Name', 'Filter' => array($this, 'decodeNumericEntity')),
            //,'Filter'=>'bb2html'),
            'body' => array('Column' => 'Body'),
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
        );
        $port->export(
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

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        $comment_Map = array(
            'id_msg' => 'CommentID',
            'id_topic' => 'DiscussionID',
            'Format' => 'Format',
            'body' => array('Column' => 'Body'), //,'Filter'=>'bb2html'),
            'id_member' => 'InsertUserID',
            'DateInserted' => 'DateInserted'
        );
        $port->export(
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

    /**
     * @param Migration $port
     */
    protected function attachments(Migration $port): void
    {
        $media_Map = array(
            'ID_ATTACH' => 'MediaID',
            'id_msg' => 'ForeignID',
            'size' => 'Size',
            'height' => 'ImageHeight',
            'width' => 'ImageWidth',
            'extract_mimetype' => array(
                'Column' => 'Type',
                'Filter' => function ($value, $field, $row) {
                    return $this->getMimeTypeFromFileName($row['Path']);
                }
            ),
            'thumb_path' => array('Column' => 'ThumbPath', 'Filter' => array($this, 'filterThumbnailData')),
            'thumb_width' => array('Column' => 'ThumbWidth', 'Filter' => array($this, 'filterThumbnailData')),
        );
        $port->export(
            'Media',
            "select a.*,
                    concat('attachments/', a.filename) as Path,
                    IF(b.filename is not null, concat('attachments/', b.filename), null) as thumb_path,
                    null as extract_mimetype,
                    b.width as thumb_width,
                    if(t.id_topic is null, 'Comment', 'Discussion') as ForeignTable
                from :_attachments a
                    left join :_attachments b on b.ID_ATTACH = a.ID_THUMB
                    left join :_topics t on a.id_msg = t.id_first_msg
                where a.attachment_type = 0
                    and a.id_msg > 0",
            $media_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function conversations(Migration $port): void
    {
        $conversation_Map = array(
            'id_pm_head' => 'ConversationID',
            'subject' => 'Subject',
            'id_member_from' => 'InsertUserID',
            'unixmsgtime' => 'DateInserted',
        );
        $port->export(
            'Conversation',
            "select pm.*,
                    from_unixtime(pm.msgtime) as unixmsgtime
                from :_personal_messages pm",
            $conversation_Map
        );

        $convMsg_Map = array(
            'id_pm' => 'MessageID',
            'id_pm_head' => 'ConversationID',
            'body' => 'Body',
            'format' => 'Format',
            'id_member_from' => 'InsertUserID',
            'unixmsgtime' => 'DateInserted',
        );
        $port->export(
            'ConversationMessage',
            "select pm.*,
                    from_unixtime(pm.msgtime) as unixmsgtime ,
                    'BBCode' as format
                from :_personal_messages pm",
            $convMsg_Map
        );

        $userConv_Map = array(
            'id_member2' => 'UserId',
            'id_pm_head' => 'ConversationID',
            'deleted2' => 'Deleted'
        );
        $port->export(
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
