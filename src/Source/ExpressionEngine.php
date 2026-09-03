<?php

/**
 * Expression Engine exporter tool
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Source;

class ExpressionEngine extends Source
{
    public const array INFO = [
        'name' => 'Expression Engine Discussion Forum',
        'defaultTablePrefix' => 'forum_',
        'charsetTable' => 'topics',
    ];

    public function conversations(): void
    {
        $this->exportConversationTemps();

        // Conversation.
        $conversation_Map = [
            'message_id' => 'ConversationID',
            'title2' => 'Subject',
            'sender_id' => 'InsertUserID',
            'message_date' => 'DateInserted',
        ];
        $filters = [
            'message_date' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Conversation',
            "select pm.*, g.title as title2
                from forum_message_data pm
                join z_pmgroup g on g.group_id = pm.message_id;",
            $conversation_Map,
            $filters
        );

        // User Conversation.
        $userConversation_Map = [
            'group_id' => 'ConversationID',
            'userid' => 'UserID',
        ];
        $this->export(
            'UserConversation',
            "select g.group_id, t.userid
                from z_pmto t
                join z_pmgroup g on g.group_id = t.message_id;",
            $userConversation_Map
        );

        // Conversation Message.
        $message_Map = [
            'group_id' => 'ConversationID',
            'message_id' => 'MessageID',
            'message_body' => 'Body',
            'message_date' => 'DateInserted',
            'sender_id' => 'InsertUserID',
            'Format=BBCode',
        ];
        $this->export(
            'ConversationMessage',
            "select pm.*, pm2.group_id
                from forum_message_data pm
                join z_pmtext pm2 on pm.message_id = pm2.message_id",
            $message_Map,
            $filters
        );
    }

    /**
     * Create temporary tables for private message conversion.
     */
    public function exportConversationTemps(): void
    {
        $this->dbInput()->unprepared('DROP TABLE IF EXISTS z_pmto;');
        $this->dbInput()->unprepared('CREATE TABLE z_pmto (message_id INT UNSIGNED, userid INT UNSIGNED, deleted TINYINT(1),
            PRIMARY KEY(message_id, userid));');
        $this->dbInput()->unprepared("insert ignore z_pmto (message_id, userid, deleted)
            select message_id, recipient_id, case when message_deleted = 'y' then 1 else 0 end as `deleted`
            from forum_message_copies;");

        $this->dbInput()->unprepared("UPDATE forum_message_data
            SET message_recipients = replace(message_recipients, '|', ',');");
        $this->dbInput()->unprepared("UPDATE forum_message_data
            SET message_cc = replace(message_cc, '|', ',');");

        $this->dbInput()->unprepared('insert ignore z_pmto (message_id, userid)
          select message_id, sender_id
          from forum_message_data;');
        $this->dbInput()->unprepared("insert ignore z_pmto (message_id, userid)
            select  message_id, u.member_id
            from forum_message_data m
            join forum_members u on  FIND_IN_SET(u.member_id, m.message_cc) > 0
            where m.message_cc <> '';");
        $this->dbInput()->unprepared("insert ignore z_pmto (message_id, userid)
            select message_id, u.member_id
            from forum_message_data m
            join forum_members u on  FIND_IN_SET(u.member_id, m.message_cc) > 0
            where m.message_cc <> '';");

        $this->dbInput()->unprepared("DROP TABLE IF EXISTS z_pmto2;");
        $this->dbInput()->unprepared("CREATE TABLE z_pmto2 (message_id INT UNSIGNED, userids VARCHAR(250),
            PRIMARY KEY (message_id));");
        $this->dbInput()->unprepared("insert z_pmto2 (message_id, userids)
            select message_id, group_concat(userid order by userid)
            from z_pmto t
            group by t.message_id;");

        $this->dbInput()->unprepared("DROP TABLE IF EXISTS z_pmtext;");
        $this->dbInput()->unprepared("CREATE TABLE z_pmtext (message_id INT UNSIGNED, title VARCHAR(250), title2 VARCHAR(250),
            userids VARCHAR(250), group_id INT UNSIGNED);");
        $this->dbInput()->unprepared("insert z_pmtext (message_id, title, title2)
            select message_id, message_subject,
                case when message_subject like 'Re: %' then trim(substring(message_subject, 4))
                    else message_subject end as title2
            from forum_message_data;");
        $this->dbInput()->unprepared("CREATE INDEX z_idx_pmtext ON z_pmtext (message_id);");
        $this->dbInput()->unprepared("UPDATE z_pmtext pm
            JOIN z_pmto2 t ON pm.message_id = t.message_id
            SET pm.userids = t.userids;");

        $this->dbInput()->unprepared("DROP TABLE IF EXISTS z_pmgroup;");
        $this->dbInput()->unprepared("CREATE TABLE z_pmgroup (group_id INT UNSIGNED, title VARCHAR(250), userids VARCHAR(250));");
        $this->dbInput()->unprepared("insert z_pmgroup (group_id, title, userids)
            select min(pm.message_id), pm.title2, t2.userids
            from z_pmtext pm
            join z_pmto2 t2 on pm.message_id = t2.message_id
            group by pm.title2, t2.userids;");
        $this->dbInput()->unprepared("CREATE INDEX z_idx_pmgroup ON z_pmgroup (title, userids);");
        $this->dbInput()->unprepared("CREATE INDEX z_idx_pmgroup2 ON z_pmgroup (group_id);");
        $this->dbInput()->unprepared("UPDATE z_pmtext pm
            JOIN z_pmgroup g ON pm.title2 = g.title AND pm.userids = g.userids
            SET pm.group_id = g.group_id;");
    }

    protected function users(): void
    {
        $map = [
            'member_id' => 'UserID',
            'username' => 'Username',
            'screen_name' => 'Name',
            'Password2' => 'Password',
            'email' => 'Email',
            'ipaddress' => 'InsertIPAddress',
            'join_date' => 'DateInserted',
            'last_activity' => 'DateLastActive',
            //'timezone' => 'HourOffset',
            'location' => 'Location',
            'HashMethod=django',
        ];
        $filters = [
            'screen_name' => \Porter\Filter\DecodeHtml::class,
            'join_date' => \Porter\Filter\UnixtimeToDate::class,
            'last_activity' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'User',
            "SELECT u.*,
                    concat('sha1$$', password) AS Password2,
                    CASE WHEN bday_y > 1900 THEN concat(bday_y, '-', bday_m, '-', bday_d) ELSE NULL END AS DateOfBirth,
                    from_unixtime(join_date) AS DateFirstVisit,
                    CASE WHEN avatar_filename = '' THEN NULL ELSE concat('imported/', avatar_filename) END AS Photo
                 FROM forum_members u",
            $map,
            $filters
        );
    }

    protected function roles(): void
    {
        $role_Map = [
            'group_id' => 'RoleID',
            'group_title' => 'Name',
            'group_description' => 'Description',
        ];
        $this->export('Role', "SELECT * FROM forum_member_groups", $role_Map);

        // User Role.
        $userRole_Map = [
            'member_id' => 'UserID',
            'group_id' => 'RoleID',
        ];
        $this->export('UserRole', "SELECT * FROM forum_members u", $userRole_Map);
    }

    protected function signatures(): void
    {
        $map = [
            'member_id' => 'UserID',
            'signature' => 'Value',
            'Name=Plugin.Signatures.Sig',
        ];
        $this->export('UserMeta', "SELECT member_id, signature FROM forum_members WHERE signature <> ''", $map);
    }

    protected function categories(): void
    {
        $category_Map = [
            'forum_id' => 'CategoryID',
            'forum_name' => 'Name',
            'forum_description' => 'Description',
            'forum_parent' => 'ParentCategoryID',
            'forum_order' => 'Sort',
        ];
        $this->export('Category', "SELECT * FROM forum_forums", $category_Map);
    }

    protected function discussions(): void
    {
        $map = [
            'topic_id' => 'DiscussionID',
            'forum_id' => 'CategoryID',
            'author_id' => 'InsertUserID',
            'title' => 'Name',
            'ip_address' => 'InsertIPAddress',
            'body' => 'Body',
            'body2' => 'Format',
            'topic_date' => 'DateInserted',
            'topic_edit_date' => 'DateUpdated',
            'topic_edit_author' => 'UpdateUserID',
        ];
        $filters = [
            'title' => \Porter\Filter\DecodeHtml::class,
            'body' => \Porter\Filter\AngleToSquareBrackets::class,
            'body2' => \Porter\Filter\BodyToFormat::class,
            'topic_date' => \Porter\Filter\UnixtimeToDate::class,
            'topic_edit_date' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Discussion',
            "SELECT t.*, t.body AS body2
                    CASE WHEN announcement = 'y' THEN 1 WHEN sticky = 'y' THEN 2 ELSE 0 END AS Announce,
                    CASE WHEN status = 'c' THEN 1 ELSE 0 END AS Closed
                FROM forum_forum_topics t",
            $map,
            $filters
        );
    }

    protected function comments(): void
    {
        $map = [
            'post_id' => 'CommentID',
            'topic_id' => 'DiscussionID',
            'author_id' => 'InsertUserID',
            'ip_address' => 'InsertIPAddress',
            'body' => 'Body',
            'body2' => 'Format',
            'post_date' => 'DateInserted',
            'post_edit_date' => 'DateUpdated',
            'post_edit_author' => 'UpdateUserID',
            'Format=Html',
        ];
        $filters = [
            'body' => \Porter\Filter\AngleToSquareBrackets::class,
            'body2' => \Porter\Filter\BodyToFormat::class,
            'post_date' => \Porter\Filter\UnixtimeToDate::class,
            'post_edit_date' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export('Comment', "SELECT p.*, p.body AS body2 FROM forum_forum_posts p", $map, $filters);
    }

    protected function attachments(): void
    {
        $map = [
            'filename' => 'Name',
            'extension' => 'Type',
            'thumb_path' => 'ThumbPath',
            'thumb_width' => 'ThumbWidth',
            'filesize' => 'Size',
            'member_id' => 'InsertUserID',
            'attachment_date' => 'DateInserted',
            'filehash' => 'FileHash',
            'thumb_width=128',
        ];
        $filters = [
            'extension' => \Porter\Filter\ExtToMime::class,
            'thumb_path' => \Porter\Filter\NullIfNotImage::class,
            'thumb_width' => \Porter\Filter\NullIfNotImage::class,
            'attachment_date' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Media',
            "SELECT a.*,
                concat('imported/', filename) AS Path,
                concat('imported/', filename) as thumb_path,
                extension as Ext,
                CASE WHEN post_id > 0 THEN post_id ELSE topic_id END AS ForeignID,
                CASE WHEN post_id > 0 THEN 'comment' ELSE 'discussion' END AS ForeignTable
                FROM forum_forum_attachments a",
            $map,
            $filters
        );
    }
}
