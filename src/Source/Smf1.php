<?php

/**
 * SMF exporter tool
 *
 * @author  John Crenshaw, for Priacta, Inc.
 */

namespace Porter\Source;

use Porter\Source;

class Smf1 extends Source
{
    protected const array FLAGS = [
        'hasDiscussionBody' => true, // SMF stores the OP body in the discussion export.
    ];

    public const array INFO = [
        'name' => 'Simple Machines 1',
        'defaultTablePrefix' => 'smf_',
        'charsetTable' => 'messages',
        'passwordHashMethod' => 'Django',
    ];

    /**
     * @var array Required tables => columns
     */
    public array $sourceTables = [
        'boards' => [],
        'messages' => [],
        'personal_messages' => [],
        'pm_recipients' => [],
        'categories' => ['ID_CAT', 'name', 'catOrder'],
        'membergroups' => [],
        'members' => ['ID_MEMBER', 'memberName', 'passwd', 'emailAddress', 'dateRegistered']
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
        ];
        $this->export(
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

    protected function roles(): void
    {
        $role_Map = [
            'ID_GROUP' => 'RoleID',
            'groupName' => 'Name'
        ];
        $this->export('Role', "select * from :_membergroups", $role_Map);

        // UserRoles
        $userRole_Map = [
            'ID_MEMBER' => 'UserID',
            'ID_GROUP' => 'RoleID'
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

    protected function discussions(): void
    {
        $discussion_Map = [
            'ID_TOPIC' => 'DiscussionID',
            'subject' => ['Column' => 'Name', 'Filter' => [$this, 'decodeNumericEntity']],
            //,'Filter'=>'bb2html'),
            'body' => ['Column' => 'Body'],
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
        ];
        $this->export(
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

    protected function comments(): void
    {
        $comment_Map = [
            'ID_MSG' => 'CommentID',
            'ID_TOPIC' => 'DiscussionID',
            'Format' => 'Format',
            'body' => ['Column' => 'Body'], //,'Filter'=>'bb2html'),
            'ID_MEMBER' => 'InsertUserID',
            'DateInserted' => 'DateInserted'
        ];
        $this->export(
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

    protected function attachments(): void
    {
        $media_Map = [
            'ID_ATTACH' => 'MediaID',
            'ID_MSG' => 'ForeignID',
            'size' => 'Size',
            'height' => 'ImageHeight',
            'width' => 'ImageWidth',
            'thumb_path' => 'ThumbPath',
            'thumb_width' => 'ThumbWidth',
        ];
        $filters = [
            'Type' => 'ExtToMime',
            'thumb_path' => [$this, 'filterThumbnailData'],
            'thumb_width' => [$this, 'filterThumbnailData'],
        ];
        $this->export(
            'Media',
            "select a.*,
                    concat('attachments/', a.filename) as Path,
                    a.filename as Type,
                    IF(b.filename is not null, concat('attachments/', b.filename), null) as thumb_path,
                    b.width as thumb_width,
                    if(t.ID_TOPIC is null, 'Comment', 'Discussion') as ForeignTable
                from :_attachments a
                    left join :_attachments b on b.ID_ATTACH = a.ID_THUMB
                    left join :_topics t on a.ID_MSG = t.ID_FIRST_MSG
                where a.attachmentType = 0
                    and a.ID_MSG > 0",
            $media_Map,
            $filters
        );
    }

    protected function conversations(): void
    {
        // Conversations need a bit more conversion so execute a series of queries for that.
        $this->query(
            'create table :_smfpmto (
                id int,
                to_id int,
                deleted tinyint,
                primary key(id, to_id)
            )'
        );
        $this->query(
            'insert :_smfpmto (id, to_id, deleted)
            select ID_PM, ID_MEMBER_FROM, deletedBySender
            from :_personal_messages'
        );
        $this->query(
            'insert ignore :_smfpmto (id, to_id, deleted)
            select ID_PM, ID_MEMBER, deleted
            from :_pm_recipients'
        );

        $this->query(
            'create table :_smfpmto2 (
                id int,
                to_ids varchar(255),
                primary key(id)
            )'
        );
        $this->query(
            'insert :_smfpmto2 (id, to_ids)
            select id,  group_concat(to_id order by to_id)
            from :_smfpmto
            group by id'
        );

        $this->query(
            'create table :_smfpm (
                id int,
                group_id int,
                subject varchar(200),
                subject2 varchar(200),
                from_id int,
                to_ids varchar(255)
            )'
        );
        $this->query('create index :_idx_smfpm2 on :_smfpm (subject2, from_id)');
        $this->query('create index :_idx_smfpmg on :_smfpm (group_id)');
        $this->query(
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

        $this->query(
            'create table :_smfgroups (
              id int primary key,
              subject2 varchar(200),
              to_ids varchar(255)
            )'
        );

        $this->query(
            'insert :_smfgroups
            select min(id) as group_id, subject2, to_ids
            from :_smfpm
            group by subject2, to_ids'
        );

        $this->query('create index :_idx_smfgroups on :_smfgroups (subject2, to_ids)');

        $this->query(
            'update :_smfpm pm
                join :_smfgroups g
                    on pm.subject2 = g.subject2 and pm.to_ids = g.to_ids
                set pm.group_id = g.id'
        );

        // Conversation.
        $conv_Map = [
            'id' => 'ConversationID',
            'from_id' => 'InsertUserID',
            'DateInserted' => 'DateInserted',
            'subject2' => ['Column' => 'Subject', 'Type' => 'varchar(255)']
        ];
        $this->export(
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
        $convMessage_Map = [
            'id' => 'MessageID',
            'group_id' => 'ConversationID',
            'DateInserted' => 'DateInserted',
            'from_id' => 'InsertUserID',
            'body' => ['Column' => 'Body']
        ];
        $this->export(
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
        $userConv_Map = [
            'to_id' => 'UserID',
            'group_id' => 'ConversationID',
            'deleted' => 'Deleted'
        ];
        $this->export(
            'UserConversation',
            "select pm.group_id, t.to_id, t.deleted
                from :_smfpmto t
                join :_smfpm pm
                    on t.id = pm.group_id",
            $userConv_Map
        );

        $this->query('drop table :_smfpm');
        $this->query('drop table :_smfpmto');
        $this->query('drop table :_smfpmto2');
        $this->query('drop table :_smfgroups');
    }
}
