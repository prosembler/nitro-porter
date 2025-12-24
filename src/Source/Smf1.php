<?php

/**
 * SMF exporter tool
 *
 * @author  John Crenshaw, for Priacta, Inc.
 */

namespace Porter\Source;

use Porter\Source;
use Porter\Migration;

class Smf1 extends Source
{
    public const SUPPORTED = [
        'name' => 'Simple Machines 1',
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
            'Avatars' => 1,
            'PrivateMessages' => 1,
            'Signatures' => 0,
            'Attachments' => 1,
            'Bookmarks' => 0,
            'Badges' => 0,
            'UserNotes' => 0,
            'Ranks' => 0,
            'Groups' => 0,
            'Tags' => 0,
            'Reactions' => 0,
            'Articles' => 0,
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
        'categories' => array('ID_CAT', 'name', 'catOrder'),
        'membergroups' => array(),
        'members' => array('ID_MEMBER', 'memberName', 'passwd', 'emailAddress', 'dateRegistered')
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
        } else {
            return null;
        }
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $user_Map = array(
            'ID_MEMBER' => 'UserID',
            'memberName' => 'Name',
            'password' => 'Password',
            'emailAddress' => 'Email',
            'DateInserted' => 'DateInserted',
            'timeOffset' => 'HourOffset',
            'posts' => 'CountComments',
            //'avatar'=>'Photo',
            'birthdate' => 'DateOfBirth',
            'DateFirstVisit' => 'DateFirstVisit',
            'DateLastActive' => 'DateLastActive',
            'DateUpdated' => 'DateUpdated'
        );
        $port->export(
            'User',
            "select m.*,
                    from_unixtime(dateRegistered) as DateInserted,
                    from_unixtime(dateRegistered) as DateFirstVisit,
                    from_unixtime(lastLogin) as DateLastActive,
                    from_unixtime(lastLogin) as DateUpdated,
                    concat('sha1$', lower(memberName), '$', passwd) as `password`,
                    if(m.avatar <> '', m.avatar, concat('attachments/', a.filename)) as Photo
                from :_members m
                left join :_attachments a on a.ID_MEMBER = m.ID_MEMBER ",
            $user_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        $role_Map = array(
            'ID_GROUP' => 'RoleID',
            'groupName' => 'Name'
        );
        $port->export('Role', "select * from :_membergroups", $role_Map);

        // UserRoles
        $userRole_Map = array(
            'ID_MEMBER' => 'UserID',
            'ID_GROUP' => 'RoleID'
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
                    (`ID_CAT` + 1000000) as `CategoryID`,
                    `name` as `Name`,
                    '' as `Description`,
                    null as `ParentCategoryID`,
                    `catOrder` as `Sort`
                from :_categories
                union
                select
                    b.`ID_BOARD` as `CategoryID`,
                    b.`name` as `Name`,
                    b.`description` as `Description`,
                    (CASE WHEN b.`ID_PARENT` = 0 THEN (`ID_CAT` + 1000000) ELSE `ID_PARENT` END) as `ParentCategoryID`,
                    b.`boardOrder` as `Sort`
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
            'ID_TOPIC' => 'DiscussionID',
            'subject' => array('Column' => 'Name', 'Filter' => array($this, 'decodeNumericEntity')),
            //,'Filter'=>'bb2html'),
            'body' => array('Column' => 'Body'),
            //,'Filter'=>'bb2html'),
            'Format' => 'Format',
            'ID_BOARD' => 'CategoryID',
            'DateInserted' => 'DateInserted',
            'DateUpdated' => 'DateUpdated',
            'ID_MEMBER' => 'InsertUserID',
            'DateLastComment' => 'DateLastComment',
            'UpdateUserID' => 'UpdateUserID',
            'locked' => 'Closed',
            'isSticky' => 'Announce',
            'CountComments' => 'CountComments',
            'numViews' => 'CountViews',
            'LastCommentUserID' => 'LastCommentUserID',
            'ID_LAST_MSG' => 'LastCommentID'
        );
        $port->export(
            'Discussion',
            "select t.*,
                    (t.numReplies + 1) as CountComments,
                    m.subject,
                    m.body,
                    from_unixtime(m.posterTime) as DateInserted,
                    from_unixtime(m.modifiedTime) as DateUpdated,
                    m.ID_MEMBER,
                    from_unixtime(m_end.posterTime) AS DateLastComment,
                    m_end.ID_MEMBER AS UpdateUserID,
                    m_end.ID_MEMBER AS LastCommentUserID,
                    'BBCode' as Format
                from :_topics t
                join :_messages as m on t.ID_FIRST_MSG = m.ID_MSG
                join :_messages as m_end on t.ID_LAST_MSG = m_end.ID_MSG
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
            'ID_MSG' => 'CommentID',
            'ID_TOPIC' => 'DiscussionID',
            'Format' => 'Format',
            'body' => array('Column' => 'Body'), //,'Filter'=>'bb2html'),
            'ID_MEMBER' => 'InsertUserID',
            'DateInserted' => 'DateInserted'
        );
        $port->export(
            'Comment',
            "select m.*,
                    from_unixtime(m.posterTime) AS DateInserted,
                    'BBCode' as Format
                from :_messages m
                join :_topics t on m.ID_TOPIC = t.ID_TOPIC
                where m.ID_MSG <> t.ID_FIRST_MSG;",
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
            'ID_MSG' => 'ForeignID',
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
                    if(t.ID_TOPIC is null, 'Comment', 'Discussion') as ForeignTable
                from :_attachments a
                    left join :_attachments b on b.ID_ATTACH = a.ID_THUMB
                    left join :_topics t on a.ID_MSG = t.ID_FIRST_MSG
                where a.attachmentType = 0
                    and a.ID_MSG > 0",
            $media_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function conversations(Migration $port): void
    {
// Conversations need a bit more conversion so execute a series of queries for that.
        $port->query(
            'create table :_smfpmto (
                id int,
                to_id int,
                deleted tinyint,
                primary key(id, to_id)
            )'
        );
        $port->query(
            'insert :_smfpmto (
                id,
                to_id,
                deleted
            )
            select
                ID_PM,
                ID_MEMBER_FROM,
                deletedBySender
            from :_personal_messages'
        );
        $port->query(
            'insert ignore :_smfpmto (
                id,
                to_id,
                deleted
            )
            select
                ID_PM,
                ID_MEMBER,
                deleted
            from :_pm_recipients'
        );

        $port->query(
            'create table :_smfpmto2 (
                id int,
                to_ids varchar(255),
                primary key(id)
            )'
        );

        $port->query(
            'insert :_smfpmto2 (
                id,
                to_ids
            )
            select
                id,
                group_concat(to_id order by to_id
            )
            from :_smfpmto
            group by id'
        );

        $port->query(
            'create table :_smfpm (
                id int,
                group_id int,
                subject varchar(200),
                subject2 varchar(200),
                from_id int,
                to_ids varchar(255)
            )'
        );

        $port->query('create index :_idx_smfpm2 on :_smfpm (subject2, from_id)');
        $port->query('create index :_idx_smfpmg on :_smfpm (group_id)');

        $port->query(
            'insert :_smfpm (
                id,
                subject,
                subject2,
                from_id,
                to_ids
            )
            select
                ID_PM,
                subject,
                case when subject like \'Re: %\' then trim(substring(subject, 4)) else subject end as subject2,
                ID_MEMBER_FROM,
                to2.to_ids
            from :_personal_messages pm
            join :_smfpmto2 to2
                on pm.ID_PM = to2.id'
        );

        $port->query(
            'create table :_smfgroups (
              id int primary key,
              subject2 varchar(200),
              to_ids varchar(255)
            )'
        );

        $port->query(
            'insert :_smfgroups
            select
                min(id) as group_id, subject2, to_ids
            from :_smfpm
            group by subject2, to_ids'
        );

        $port->query('create index :_idx_smfgroups on :_smfgroups (subject2, to_ids)');

        $port->query(
            'update :_smfpm pm
                join :_smfgroups g
                    on pm.subject2 = g.subject2 and pm.to_ids = g.to_ids
                set pm.group_id = g.id'
        );

        // Conversation.
        $conv_Map = array(
            'id' => 'ConversationID',
            'from_id' => 'InsertUserID',
            'DateInserted' => 'DateInserted',
            'subject2' => array('Column' => 'Subject', 'Type' => 'varchar(255)')
        );
        $port->export(
            'Conversation',
            "select
                    pm.group_id,
                    pm.from_id,
                    pm.subject2,
                    from_unixtime(pm2.msgtime) as DateInserted
                from :_smfpm pm
                join :_personal_messages pm2
                    on pm.id = pm2.ID_PM
                where pm.id = pm.group_id",
            $conv_Map
        );

        // ConversationMessage.
        $convMessage_Map = array(
            'id' => 'MessageID',
            'group_id' => 'ConversationID',
            'DateInserted' => 'DateInserted',
            'from_id' => 'InsertUserID',
            'body' => array('Column' => 'Body')
        );
        $port->export(
            'ConversationMessage',
            "select
                pm.id,
                pm.group_id,
                from_unixtime(pm2.msgtime) as DateInserted,
                pm.from_id,
                'BBCode' as Format,
                case when pm.subject = pm.subject2 then concat(pm.subject, '\n\n', pm2.body) else pm2.body end as body
            from :_smfpm pm
            join :_personal_messages pm2
                on pm.id = pm2.ID_PM",
            $convMessage_Map
        );

        // UserConversation.
        $userConv_Map = array(
            'to_id' => 'UserID',
            'group_id' => 'ConversationID',
            'deleted' => 'Deleted'
        );
        $port->export(
            'UserConversation',
            "select
                    pm.group_id,
                    t.to_id,
                    t.deleted
                from :_smfpmto t
                join :_smfpm pm
                    on t.id = pm.group_id",
            $userConv_Map
        );

        $port->query('drop table :_smfpm');
        $port->query('drop table :_smfpmto');
        $port->query('drop table :_smfpmto2');
        $port->query('drop table :_smfgroups');
    }
}
