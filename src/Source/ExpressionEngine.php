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
    public const SUPPORTED = [
        'name' => 'Expression Engine Discussion Forum',
        'defaultTablePrefix' => 'forum_',
        'charsetTable' => 'topics',
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
            'Signatures' => 1,
            'Attachments' => 1,
            'Bookmarks' => 1,
        ]
    ];

    /**
     *
     */
    public function run(): void
    {
        $this->conversations();
        $this->users();
        $this->roles();
        $this->signatures();
        $this->categories();
        $this->discussions();
        $this->comments();
        $this->attachments();
    }

    /**
     * Private message conversion.
     */
    public function conversations(): void
    {
        $this->exportConversationTemps();

        // Conversation.
        $conversation_Map = array(
            'message_id' => 'ConversationID',
            'title2' => array('Column' => 'Subject', 'Type' => 'varchar(255)'),
            'sender_id' => 'InsertUserID',
            'message_date' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
        );
        $this->export(
            'Conversation',
            "SELECT pm.*, g.title AS title2
                FROM forum_message_data pm
                JOIN z_pmgroup g
                ON g.group_id = pm.message_id;",
            $conversation_Map
        );

        // User Conversation.
        $userConversation_Map = array(
            'group_id' => 'ConversationID',
            'userid' => 'UserID'
        );
        $this->export(
            'UserConversation',
            "SELECT g.group_id, t.userid
                FROM z_pmto t
                JOIN z_pmgroup g
                ON g.group_id = t.message_id;",
            $userConversation_Map
        );

        // Conversation Message.
        $message_Map = array(
            'group_id' => 'ConversationID',
            'message_id' => 'MessageID',
            'message_body' => 'Body',
            'message_date' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'sender_id' => 'InsertUserID'
        );
        $this->export(
            'ConversationMessage',
            "SELECT pm.*, pm2.group_id,
                    'BBCode' AS Format
                FROM forum_message_data pm
                JOIN z_pmtext pm2
                ON pm.message_id = pm2.message_id",
            $message_Map
        );
    }

    /**
     * Create temporary tables for private message conversion.
     */
    public function exportConversationTemps(): void
    {
        $this->query('DROP TABLE IF EXISTS z_pmto;');
        $this->query(
            'CREATE TABLE z_pmto (
            message_id INT UNSIGNED,
            userid INT UNSIGNED,
            deleted TINYINT(1),
            PRIMARY KEY(message_id, userid)
            );'
        );
        $this->query(
            "insert ignore z_pmto (
                message_id,
                userid,
                deleted
            )
            select
                message_id,
                recipient_id,
                case when message_deleted = 'y' then 1 else 0 end as `deleted`
            from forum_message_copies;"
        );

        $this->query(
            "UPDATE forum_message_data
            SET message_recipients = replace(message_recipients, '|', ',');"
        );

        $this->query(
            "UPDATE forum_message_data
            SET message_cc = replace(message_cc, '|', ',');"
        );

        $this->query(
            'insert ignore z_pmto (
            message_id,
            userid
          )
          select
            message_id,
            sender_id
          from forum_message_data;'
        );

        $this->query(
            "insert ignore z_pmto (
                message_id,
                userid
            )
            select
                message_id,
                u.member_id
            from forum_message_data m
            join forum_members u
                on  FIND_IN_SET(u.member_id, m.message_cc) > 0
            where m.message_cc <> '';"
        );

        $this->query(
            "insert ignore z_pmto (
                message_id,
                userid
            )
            select
                message_id,
                u.member_id
            from forum_message_data m
            join forum_members u
                on  FIND_IN_SET(u.member_id, m.message_cc) > 0
            where m.message_cc <> '';"
        );

        $this->query("DROP TABLE IF EXISTS z_pmto2;");
        $this->query(
            "CREATE TABLE z_pmto2 (
            message_id INT UNSIGNED,
            userids VARCHAR(250),
            PRIMARY KEY (message_id)
            );"
        );
        $this->query(
            "insert z_pmto2 (
            message_id,
            userids
            )
            select
                message_id,
                group_concat(userid order by userid)
            from z_pmto t
            group by t.message_id;"
        );

        $this->query("DROP TABLE IF EXISTS z_pmtext;");
        $this->query(
            "CREATE TABLE z_pmtext (
            message_id INT UNSIGNED,
            title VARCHAR(250),
            title2 VARCHAR(250),
            userids VARCHAR(250),
            group_id INT UNSIGNED
            );"
        );
        $this->query(
            "insert z_pmtext (
            message_id,
            title,
            title2
            )
            select
                message_id,
                message_subject,
                case when message_subject like 'Re: %' then trim(substring(message_subject, 4))
                    else message_subject end as title2
            from forum_message_data;"
        );

        $this->query("CREATE INDEX z_idx_pmtext ON z_pmtext (message_id);");
        $this->query(
            "UPDATE z_pmtext pm
            JOIN z_pmto2 t
                ON pm.message_id = t.message_id
            SET pm.userids = t.userids;"
        );

        $this->query("DROP TABLE IF EXISTS z_pmgroup;");
        $this->query(
            "CREATE TABLE z_pmgroup (
            group_id INT UNSIGNED,
            title VARCHAR(250),
            userids VARCHAR(250)
            );"
        );
        $this->query(
            "insert z_pmgroup (
            group_id,
            title,
            userids
            )
            select
                min(pm.message_id),
                pm.title2,
                t2.userids
            from z_pmtext pm
            join z_pmto2 t2
                on pm.message_id = t2.message_id
            group by pm.title2, t2.userids;"
        );

        $this->query("CREATE INDEX z_idx_pmgroup ON z_pmgroup (title, userids);");
        $this->query("CREATE INDEX z_idx_pmgroup2 ON z_pmgroup (group_id);");

        $this->query(
            "UPDATE z_pmtext pm
            JOIN z_pmgroup g
                ON pm.title2 = g.title AND pm.userids = g.userids
            SET pm.group_id = g.group_id;"
        );
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
        if (strpos(mimeTypeFromExtension(strtolower($row['extension'])), 'image/') === 0) {
            return $value;
        }
        return null;
    }

    /**
     */
    protected function users(): void
    {
        $user_Map = array(
            'member_id' => 'UserID',
            'username' => array('Column' => 'Username', 'Type' => 'varchar(50)'),
            'screen_name' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
            'Password2' => 'Password',
            'email' => 'Email',
            'ipaddress' => 'InsertIPAddress',
            'join_date' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'last_activity' => array('Column' => 'DateLastActive', 'Filter' => 'timestampToDate'),
            //'timezone' => 'HourOffset',
            'location' => 'Location'
        );
        $this->export(
            'User',
            "SELECT u.*,
                    'django' AS HashMethod,
                    concat('sha1$$', password) AS Password2,
                    CASE WHEN bday_y > 1900 THEN concat(bday_y, '-', bday_m, '-', bday_d) ELSE NULL END AS DateOfBirth,
                    from_unixtime(join_date) AS DateFirstVisit,
                    ip_address AS LastIPAddress,
                    CASE WHEN avatar_filename = '' THEN NULL ELSE concat('imported/', avatar_filename) END AS Photo
                 FROM forum_members u",
            $user_Map
        );
    }

    /**
     */
    protected function roles(): void
    {
        $role_Map = array(
            'group_id' => 'RoleID',
            'group_title' => 'Name',
            'group_description' => 'Description'
        );
        $this->export(
            'Role',
            "SELECT * FROM forum_member_groups",
            $role_Map
        );

        // User Role.
        $userRole_Map = array(
            'member_id' => 'UserID',
            'group_id' => 'RoleID'
        );
        $this->export(
            'UserRole',
            "SELECT * FROM forum_members u",
            $userRole_Map
        );
    }

    /**
     */
    protected function signatures(): void
    {
        $this->export(
            'UserMeta',
            "SELECT
                    member_id AS UserID,
                    'Plugin.Signatures.Sig' AS Name,
                    signature AS Value
                FROM forum_members
                WHERE signature <> ''"
        );
    }

    /**
     */
    protected function categories(): void
    {
        $category_Map = array(
            'forum_id' => 'CategoryID',
            'forum_name' => 'Name',
            'forum_description' => 'Description',
            'forum_parent' => 'ParentCategoryID',
            'forum_order' => 'Sort'
        );
        $this->export(
            'Category',
            "SELECT * FROM forum_forums",
            $category_Map
        );
    }

    /**
     */
    protected function discussions(): void
    {
        $discussion_Map = array(
            'topic_id' => 'DiscussionID',
            'forum_id' => 'CategoryID',
            'author_id' => 'InsertUserID',
            'title' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
            'ip_address' => 'InsertIPAddress',
            'body' => array('Column' => 'Body', 'Filter' => 'cleanBodyBrackets'),
            'body2' => array('Column' => 'Format', 'Filter' => 'guessFormat'),
            'topic_date' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'topic_edit_date' => array('Column' => 'DateUpdated', 'Filter' => 'timestampToDate'),
            'topic_edit_author' => 'UpdateUserID'
        );
        $this->export(
            'Discussion',
            "SELECT t.*,
                    CASE WHEN announcement = 'y' THEN 1 WHEN sticky = 'y' THEN 2 ELSE 0 END AS Announce,
                    CASE WHEN status = 'c' THEN 1 ELSE 0 END AS Closed,
                    t.body AS body2
                FROM forum_forum_topics t",
            $discussion_Map
        );
    }

    /**
     */
    protected function comments(): void
    {
        $comment_Map = array(
            'post_id' => 'CommentID',
            'topic_id' => 'DiscussionID',
            'author_id' => 'InsertUserID',
            'ip_address' => 'InsertIPAddress',
            'body' => array('Column' => 'Body', 'Filter' => 'cleanBodyBrackets'),
            'body2' => array('Column' => 'Format', 'Filter' => 'guessFormat'),
            'post_date' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'post_edit_date' => array('Column' => 'DateUpdated', 'Filter' => 'timestampToDate'),
            'post_edit_author' => 'UpdateUserID'
        );
        $this->export(
            'Comment',
            "SELECT p.*,
                    'Html' AS Format,
                    p.body AS body2
                FROM forum_forum_posts p",
            $comment_Map
        );
    }

    /**
     */
    protected function attachments(): void
    {
        $media_Map = array(
            'filename' => 'Name',
            'extension' => array('Column' => 'Type', 'Filter' => 'mimeTypeFromExtension'),
            'thumb_path' => array('Column' => 'ThumbPath', 'Filter' => array($this, 'filterThumbnailData')),
            'thumb_width' => array('Column' => 'ThumbWidth', 'Filter' => array($this, 'filterThumbnailData')),
            'filesize' => 'Size',
            'member_id' => 'InsertUserID',
            'attachment_date' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'filehash' => array('Column' => 'FileHash', 'Type' => 'varchar(100)')
        );
        $this->export(
            'Media',
            "SELECT a.*,
                concat('imported/', filename) AS Path,
                concat('imported/', filename) as thumb_path,
                128 as thumb_width,
                CASE WHEN post_id > 0 THEN post_id ELSE topic_id END AS ForeignID,
                CASE WHEN post_id > 0 THEN 'comment' ELSE 'discussion' END AS ForeignTable
                FROM forum_forum_attachments a",
            $media_Map
        );
    }
}
