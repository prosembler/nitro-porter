<?php

/**
 * Invision Powerboard 3.x or earlier exporter tool.
 *
 * To export avatars, provide ?db-avatars=1&avatars-source=/path/to/avatars
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Source;
use Porter\Migration;

class IpBoard3 extends Source
{
    public const SUPPORTED = [
        'name' => 'IP.Board 3',
        'defaultTablePrefix' => 'ibf_',
        'charsetTable' => 'posts',
        'passwordHashMethod' => 'ipb',
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
            'Signatures' => 1,
            'Attachments' => 1,
            'Bookmarks' => 0,
            'Badges' => 0,
            'UserNotes' => 0,
            'Ranks' => 0,
            'Groups' => 0,
            'Tags' => 1,
        ]
    ];

    /**
     * Export avatars into vanilla-compatibles names
     */
    public function doAvatars(Migration $port): void
    {
        // Source table
        $sourceTable = 'profile_portal'; //$this->param('users-source', 'profile_portal');

        // Check source folder
        $sourceFolder = ''; //$this->param('avatars-source');
        if (!is_dir($sourceFolder)) {
            trigger_error("Source avatar folder '{$sourceFolder}' does not exist.");
        }

        // Set up a target folder
        $targetFolder = combinePaths(array($sourceFolder, 'ipb'));
        if (!is_dir($sourceFolder)) {
            @$made = mkdir($targetFolder, 0777, true);
            if (!$made) {
                trigger_error("Target avatar folder '{$targetFolder}' could not be created.");
            }
        }

        switch ($sourceTable) {
            case 'profile_portal':
                $userList = $port->query(
                    "select
                        pp_member_id as member_id,
                        pp_main_photo as main_photo,
                        pp_thumb_photo as thumb_photo,
                        coalesce(pp_main_photo,pp_thumb_photo,0) as photo
                    from :_profile_portal
                    where length(coalesce(pp_main_photo,pp_thumb_photo,0)) > 3
                    order by pp_member_id asc"
                );
                break;

            case 'member_extra':
            default:
                $userList = $port->query(
                    "select
                        id as member_id,
                        avatar_location as photo
                    from :_member_extra
                    where
                        length(avatar_location) > 3 and
                        avatar_location <> 'noavatar'
                    order by id asc"
                );
                break;
        }

        $processed = 0;
        $skipped = 0;
        $completed = 0;
        $errors = array();
        while ($row = $userList->nextResultRow()) {
            $processed++;
            $error = false;
            $userID = $row['member_id'];

            // Determine target paths and name
            $photo = trim($row['photo']);
            $photo = preg_replace('`^upload:`', '', $photo);
            if (preg_match('`^https?:`i', $photo)) {
                $skipped++;
                continue;
            }

            $photoFileName = basename($photo);
            $photoPath = dirname($photo);
            $photoFolder = combinePaths(array($targetFolder, $photoPath));
            @mkdir($photoFolder, 0777, true);

            $photoSrc = combinePaths(array($sourceFolder, $photo));
            if (!file_exists($photoSrc)) {
                $errors[] = "Missing file: {$photoSrc}";
                continue;
            }

            $mainPhoto = trim($row['main_photo'] ?? null);
            $thumbPhoto = trim($row['thumb_photo'] ?? null);

            // Main Photo
            if (!$mainPhoto) {
                $mainPhoto = $photo;
            }
            $mainSrc = combinePaths(array($sourceFolder, $mainPhoto));
            $mainDest = combinePaths(array($photoFolder, "p" . $photoFileName));
            $copied = @copy($mainSrc, $mainDest);
            if (!$copied) {
                $error |= true;
                $errors[] = "! failed to copy main photo '{$mainSrc}' for user {$userID} (-> {$mainDest}).";
            }

            $thumbSrc = combinePaths(array($sourceFolder, $mainPhoto));
            $thumbDest = combinePaths(array($photoFolder, "n" . $photoFileName));
            $copied = @copy($thumbSrc, $thumbDest);
            if (!$copied) {
                $error |= true;
                $errors[] = "! failed to copy thumbnail '{$thumbSrc}' for user {$userID} (-> {$thumbDest}).";
            }

            if (!$error) {
                $completed++;
            }

            if (!($processed % 100)) {
                echo " - processed {$processed}\n";
            }
        }

        $nErrors = sizeof($errors);
        if ($nErrors) {
            echo "{$nErrors} errors:\n";
            foreach ($errors as $error) {
                echo "{$error}\n";
            }
        }

        echo "Completed: {$completed}\n";
        echo "Skipped: {$skipped}\n";
    }

    /**
     * @param Migration $port
     */
    public function run(Migration $port): void
    {
        // Export avatars
        //if ($this->param('avatars')) {
            //$this->doAvatars($ex);
        //}

        if ($port->hasInputSchema('members', 'member_id') === true) {
            $memberID = 'member_id';
        } else {
            $memberID = 'id';
        }

        $this->users($memberID, $port);
        $this->roles($port, $memberID);
        $this->userMeta($port);

        $this->categories($port);
        $this->discussions($port);
        $this->tags($port);
        $this->comments($port);
        $this->attachments($port);

        if ($port->hasInputSchema('message_topic_user_map')) {
            $this->conversations($port); // v3
        } else {
            $this->conversationsV2($port); // v2
        }
    }

    /**
     * @param Migration $port
     */
    protected function conversationsV2(Migration $port): void
    {
        $sql = <<<EOT
            create table tmp_to (
               id int,
               userid int,
               primary key (id, userid)
            );
            truncate table tmp_to;
            insert ignore tmp_to (
               id,
               userid
            )
            select
               mt_id,
               mt_from_id
            from :_message_topics;

            insert ignore tmp_to (
               id,
               userid
            )
            select
               mt_id,
               mt_to_id
            from :_message_topics;

            create table tmp_to2 (
               id int primary key,
               userids varchar(255)
            );
            truncate table tmp_to2;
            insert tmp_to2 (
               id,
               userids
            )
            select
               id,
               group_concat(userid order by userid)
            from tmp_to
            group by id;

            create table tmp_conversation (
               id int primary key,
               title varchar(255),
               title2 varchar(255),
               userids varchar(255),
               groupid int
            );
            replace tmp_conversation (
               id,
               title,
               title2,
               userids
            )
            select
               mt_id,
               mt_title,
               mt_title,
               t2.userids
            from :_message_topics t
            join tmp_to2 t2
               on t.mt_id = t2.id;

            update tmp_conversation
            set title2 = trim(right(title2, length(title2) - 3))
            where title2 like 'Re:%';

            update tmp_conversation
            set title2 = trim(right(title2, length(title2) - 5))
            where title2 like 'Sent:%';

            update tmp_conversation
            set title2 = trim(right(title2, length(title2) - 3))
            where title2 like 'Re:%';

            update tmp_conversation
            set title2 = trim(right(title2, length(title2) - 5))
            where title2 like 'Sent:%';

            update tmp_conversation
            set title2 = trim(right(title2, length(title2) - 3))
            where title2 like 'Re:%';

            update tmp_conversation
            set title2 = trim(right(title2, length(title2) - 5))
            where title2 like 'Sent:%';

            update tmp_conversation
            set title2 = trim(right(title2, length(title2) - 3))
            where title2 like 'Re:%';

            update tmp_conversation
            set title2 = trim(right(title2, length(title2) - 5))
            where title2 like 'Sent:%';

            update tmp_conversation
            set title2 = trim(right(title2, length(title2) - 3))
            where title2 like 'Re:%';

            update tmp_conversation
            set title2 = trim(right(title2, length(title2) - 5))
            where title2 like 'Sent:%';

            create table tmp_group (
               title2 varchar(255),
               userids varchar(255),
               groupid int,
               primary key (title2, userids)
            );
            replace tmp_group (
               title2,
               userids,
               groupid
            )
            select
               title2,
               userids,
               min(id)
            from tmp_conversation
            group by title2, userids;

            create index tidx_group on tmp_group(title2, userids);
            create index tidx_conversation on tmp_conversation(title2, userids);

            update tmp_conversation c
            join tmp_group g
               on c.title2 = g.title2 and c.userids = g.userids
            set c.groupid = g.groupid;
EOT;

        $port->dbInput()->unprepared($sql);

        // Conversations.
        $conversation_Map = array(
            'groupid' => 'ConversationID',
            'title2' => 'Subject',
            'mt_date' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'mt_from_id' => 'InsertUserID'
        );
        $sql = "select mt.*,
                tc.title2,
                tc.groupid
            from :_message_topics mt
            join tmp_conversation tc
                on mt.mt_id = tc.id";
        $this->clearFilters('Conversation', $conversation_Map, $sql);
        $port->export('Conversation', $sql, $conversation_Map);

        // Conversation Message.
        $conversationMessage_Map = array(
            'msg_id' => 'MessageID',
            'groupid' => 'ConversationID',
            'msg_date' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'msg_post' => 'Body',
            'Format' => 'Format',
            'msg_author_id' => 'InsertUserID',
            'msg_ip_address' => 'InsertIPAddress'
        );
        $sql = "select tx.*,
                tc.title2,
                tc.groupid,
                'IPB' as Format
            from :_message_text tx
            join :_message_topics mt
                on mt.mt_msg_id = tx.msg_id
            join tmp_conversation tc
                on mt.mt_id = tc.id";
        $this->clearFilters('ConversationMessage', $conversationMessage_Map, $sql);
        $port->export('ConversationMessage', $sql, $conversationMessage_Map);

        // User Conversation.
        $userConversation_Map = array(
            'userid' => 'UserID',
            'groupid' => 'ConversationID'
        );
        $sql = "select distinct
                g.groupid,
                t.userid
            from tmp_to t
            join tmp_group g
                on g.groupid = t.id";
        $port->export('UserConversation', $sql, $userConversation_Map);

        $port->dbInput()->unprepared(
            "drop table tmp_conversation;
            drop table tmp_to;
            drop table tmp_to2;
            drop table tmp_group;"
        );
    }

    /**
     * @param Migration $port
     */
    protected function conversations(Migration $port): void
    {
        // Conversations.
        $conversation_Map = array(
            'mt_id' => 'ConversationID',
            'mt_date' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'mt_title' => 'Subject',
            'mt_starter_id' => 'InsertUserID'
        );
        $sql = "select * from :_message_topics where mt_is_deleted = 0";
        $this->clearFilters('Conversation', $conversation_Map, $sql);
        $port->export('Conversation', $sql, $conversation_Map);

        // Conversation Message.
        $conversationMessage_Map = array(
            'msg_id' => 'MessageID',
            'msg_topic_id' => 'ConversationID',
            'msg_date' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'msg_post' => 'Body',
            'Format' => 'Format',
            'msg_author_id' => 'InsertUserID',
            'msg_ip_address' => 'InsertIPAddress'
        );
        $sql = "select m.*, 'IPB' as Format from :_message_posts m";
        $this->clearFilters('ConversationMessage', $conversationMessage_Map, $sql);
        $port->export('ConversationMessage', $sql, $conversationMessage_Map);

        // User Conversation.
        $userConversation_Map = array(
            'map_user_id' => 'UserID',
            'map_topic_id' => 'ConversationID',
            'Deleted' => 'Deleted'
        );
        $sql = "select t.*,
            !map_user_active as Deleted
            from :_message_topic_user_map t";
        $port->export('UserConversation', $sql, $userConversation_Map);
    }

    /**
     * @param string $table
     * @param array $map
     * @param string $sql
     */
    public function clearFilters($table, &$map, &$sql): void
    {
        $PK = false;
        $selects = array();

        foreach ($map as $column => $info) {
            if (!$PK) {
                $PK = $column;
            }

            if (!is_array($info) || !isset($info['Filter'])) {
                continue;
            }


            $filter = $info['Filter'];
            if (isset($info['SourceColumn'])) {
                $source = $info['SourceColumn'];
            } else {
                $source = $column;
            }

            if (!is_array($filter)) {
                switch ($filter) {
                    case 'HTMLDecoder':
                        //$this->ex->HTMLDecoderDb($table, $column, $PK);
                        unset($map[$column]['Filter']);
                        break;
                    case 'timestampToDate':
                        $selects[] = "from_unixtime($source) as {$column}_Date";

                        unset($map[$column]);
                        $map[$column . '_Date'] = $info['Column'];
                        break;
                }
            }
        }

        if (count($selects) > 0) {
            $statement = implode(', ', $selects);
            $sql = str_replace('from ', ", $statement\nfrom ", $sql);
        }
    }

    /**
     * Filter used by $Media_Map to replace value for ThumbPath and ThumbWidth when the file is not an image.
     *
     * @param  string $value Current value
     * @param  string $field Current field
     * @param  array  $row   Contents of the current record.
     * @return string|null Return the supplied value if the record's file is an image. Return null otherwise
     *@see    Migration::writeTableToFile
     *
     */
    public function filterThumbnailData($value, $field, $row): ?string
    {
        if (strpos(strtolower($row['atype_mimetype']), 'image/') === 0) {
            return $value;
        } else {
            return null;
        }
    }

    /**
     * @param string $memberID
     * @param Migration $port
     */
    protected function users(string $memberID, Migration $port): void
    {
        $user_Map = array(
            $memberID => 'UserID',
            'members_display_name' => array('Column' => 'Name', 'Filter' => 'HtmlDecoder'),
            'email' => 'Email',
            'joined' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'firstvisit' => array(
                'Column' => 'DateFirstVisit',
                'SourceColumn' => 'joined',
                'Filter' => 'timestampToDate'
            ),
            'ip_address' => 'InsertIPAddress',
            'time_offset' => 'HourOffset',
            'last_activity' => array('Column' => 'DateLastActive', 'Filter' => 'timestampToDate'),
            'member_banned' => 'Banned',
            'Photo' => 'Photo',
            'title' => 'Title',
            'location' => 'Location'
        );

        $from = '';
        if ($port->hasInputSchema('members', 'members_pass_hash') === true) {
            $select = ",concat(m.members_pass_hash, '$', m.members_pass_salt) as Password";
        } else {
            $select = ",concat(mc.converge_pass_hash, '$', mc.converge_pass_salt) as Password";
            $from = "left join :_members_converge mc
            on m.$memberID = mc.converge_id";
        }

        if ($port->hasInputSchema('members', 'hide_email') === true) {
            $showEmail = '!hide_email';
        } else {
            $showEmail = '0';
        }

        $cdn = ''; // @todo CDN support

        if ($port->hasInputSchema('member_extra') === true) {
            $sql = "select m.*,
                m.joined as firstvisit,
                'ipb' as HashMethod,
                 $showEmail as ShowEmail,
                case when x.avatar_location in ('noavatar', '') then null
                    when x.avatar_location like 'upload:%'
                        then concat('{$cdn}ipb/', right(x.avatar_location, length(x.avatar_location) - 7))
                    when x.avatar_type = 'upload' then concat('{$cdn}ipb/', x.avatar_location)
                    when x.avatar_type = 'url' then x.avatar_location
                    when x.avatar_type = 'local' then concat('{$cdn}style_avatars/', x.avatar_location)
                    else null
                end as Photo,
                x.location
                $select
            from :_members m
            left join :_member_extra x
                on m.$memberID = x.id
                $from";
        } else {
            $sql = "select m.*,
                joined as firstvisit,
                'ipb' as HashMethod,
                 $showEmail as ShowEmail,
                case when length(p.pp_main_photo) <= 3 or p.pp_main_photo is null then null
                    when p.pp_main_photo like '%//%' then p.pp_main_photo
                    else concat('{$cdn}ipb/', p.pp_main_photo)
                end as Photo
                $select
                from :_members m
                left join :_profile_portal p
                    on m.$memberID = p.pp_member_id
                $from";
        }
        $this->clearFilters('members', $user_Map, $sql);
        $port->export('User', $sql, $user_Map);
    }

    /**
     * @param Migration $port
     * @param string $memberID
     */
    protected function roles(Migration $port, string $memberID): void
    {
        $role_Map = array(
            'g_id' => 'RoleID',
            'g_title' => 'Name'
        );
        $port->export('Role', "select * from :_groups", $role_Map);

        // User Role.
        if ($port->hasInputSchema('members', 'member_group_id') === true) {
            $groupID = 'member_group_id';
        } else {
            $groupID = 'mgroup';
        }

        $userRole_Map = array(
            $memberID => 'UserID',
            $groupID => 'RoleID'
        );

        $sql = "
         select
            m.$memberID, m.$groupID
         from :_members m";

        if ($port->hasInputSchema('members', 'mgroup_others')) {
            $sql .= "
            union all
            select m.$memberID, g.g_id
            from :_members m
            join :_groups g
               on find_in_set(g.g_id, m.mgroup_others)";
        }

        $port->export('UserRole', $sql, $userRole_Map);
    }

    /**
     * @param Migration $port
     */
    protected function userMeta(Migration $port): void
    {
        $userMeta_Map = array(
            'UserID' => 'UserID',
            'Name' => 'Name',
            'Value' => 'Value'
        );

        if ($port->hasInputSchema('profile_portal', 'signature') === true) {
            $sql = "
         select
            pp_member_id as UserID,
            'Plugin.Signatures.Sig' as Name,
            signature as Value
         from :_profile_portal
         where length(signature) > 1
         union all
         select
            pp_member_id as UserID,
            'Plugin.Signatures.Format' as Name,
            'IPB' as Value
         from :_profile_portal
         where length(signature) > 1
               ";
        } elseif ($port->hasInputSchema('member_extra', array('id', 'signature')) === true) {
            $sql = "
         select
            id as UserID,
            'Plugin.Signatures.Sig' as Name,
            signature as Value
         from :_member_extra
         where length(signature) > 1
         union all
         select
            id as UserID,
            'Plugin.Signatures.Format' as Name,
            'IPB' as Value
         from :_member_extra
         where length(signature) > 1";
        } else {
            $sql = false;
        }
        if ($sql) {
            $port->export('UserMeta', $sql, $userMeta_Map);
        }
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
        $category_Map = array(
            'id' => 'CategoryID',
            'name' => array('Column' => 'Name', 'Filter' => 'HtmlDecoder'),
            'name_seo' => 'UrlCode',
            'description' => 'Description',
            'parent_id' => 'ParentCategoryID',
            'position' => 'Sort'
        );
        $port->export('Category', "select * from :_forums", $category_Map);
    }

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        $descriptionSQL = 'p.post';
        $hasTopicDescription = ($port->hasInputSchema('topics', array('description')) === true);
        if ($hasTopicDescription || $port->hasInputSchema('posts', array('description')) === true) {
            $description = ($hasTopicDescription) ? 't.description' : 'p.description';
            $descriptionSQL = "case
                when $description <> '' and p.post is not null
                    then concat('<div class=\"IPBDescription\">', $description, '</div>', p.post)
                when $description <> '' then $description
                else p.post
            end";
        }
        $discussion_Map = array(
            'tid' => 'DiscussionID',
            'title' => 'Name',
            'description' => array('Column' => 'SubName', 'Type' => 'varchar(255)'),
            'forum_id' => 'CategoryID',
            'starter_id' => 'InsertUserID',
            'start_date' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'ip_address' => 'InsertIPAddress',
            'edit_time' => array('Column' => 'DateUpdated', 'Filter' => 'timestampToDate'),
            //          'last_post' => array('Column' => 'DateLastPost', 'Filter' => array($ex, 'timestampToDate')),
            'posts' => 'CountComments',
            'views' => 'CountViews',
            'pinned' => 'Announce',
            'post' => 'Body',
            'closed' => 'Closed'
        );
        $sql = "select t.*,
            $descriptionSQL as post,
            case when t.state = 'closed' then 1 else 0 end as closed,
            'BBCode' as Format,
            p.ip_address,
            p.edit_time
        from :_topics t
        left join :_posts p
            on t.topic_firstpost = p.pid";
        $this->clearFilters('topics', $discussion_Map, $sql);
        $port->export('Discussion', $sql, $discussion_Map);
    }

    /**
     * @param Migration $port
     */
    protected function tags(Migration $port): void
    {
        $port->query("DROP TABLE IF EXISTS `z_tag` ");
        $port->query(
            "CREATE TABLE `z_tag` (
                `TagID` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `FullName` varchar(50) DEFAULT NULL,
                PRIMARY KEY (`TagID`),
                UNIQUE KEY `FullName` (`FullName`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;"
        );
        $port->query("insert into z_tag (FullName) (select distinct t.tag_text as FullName from :_core_tags t)");

        $tagDiscussion_Map = array(
            'tag_added' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
        );
        $sql = "select TagID, '0' as CategoryID, tag_meta_id as DiscussionID, t.tag_added
            from :_core_tags t
            left join z_tag zt
                on t.tag_text = zt.FullName";
        $port->export('TagDiscussion', $sql, $tagDiscussion_Map);

        $tag_Map = array(
            'FullName' => 'FullName',
            'FullNameToName' => array('Column' => 'Name', 'Filter' => 'formatUrl')
        );
        $sql = "select TagID, FullName, FullName as FullNameToName from z_tag zt";
        $port->export('Tag', $sql, $tag_Map);
    }

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        $comment_Map = array(
            'pid' => 'CommentID',
            'topic_id' => 'DiscussionID',
            'author_id' => 'InsertUserID',
            'ip_address' => 'InsertIPAddress',
            'post_date' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'edit_time' => array('Column' => 'DateUpdated', 'Filter' => 'timestampToDate'),
            'post' => 'Body'
        );
        $sql = "select p.*,
                'BBCode' as Format
            from :_posts p
            join :_topics t
                on p.topic_id = t.tid
            where p.pid between {from} and {to}
                and p.pid <> t.topic_firstpost";
        $this->clearFilters('Comment', $comment_Map, $sql);
        $port->export('Comment', $sql, $comment_Map);
    }

    /**
     * @param Migration $port
     */
    protected function attachments(Migration $port): void
    {
        $media_Map = array(
            'attach_id' => 'MediaID',
            'atype_mimetype' => 'Type',
            'attach_file' => 'Name',
            'attach_path' => 'Path',
            'attach_date' => array('Column' => 'DateInserted', 'Filter' => 'timestampToDate'),
            'thumb_path' => array('Column' => 'ThumbPath', 'Filter' => array($this, 'filterThumbnailData')),
            'thumb_width' => array('Column' => 'ThumbWidth', 'Filter' => array($this, 'filterThumbnailData')),
            'attach_member_id' => 'InsertUserID',
            'attach_filesize' => 'Size',
            'ForeignID' => 'ForeignID',
            'ForeignTable' => 'ForeignTable',
            'img_width' => 'ImageWidth',
            'img_height' => 'ImageHeight'
        );
        $sql = "select a.*,
               concat('~cf/ipb/', a.attach_location) as attach_path,
               concat('~cf/ipb/', a.attach_location) as thumb_path,
               128 as thumb_width,
               ty.atype_mimetype,
               case when p.pid = t.topic_firstpost then 'discussion' else 'comment' end as ForeignTable,
               case when p.pid = t.topic_firstpost then t.tid else p.pid end as ForeignID,
               case a.attach_img_width when 0 then a.attach_thumb_width else a.attach_img_width end as img_width,
               case a.attach_img_height when 0 then a.attach_thumb_height else a.attach_img_height end as img_height
            from :_attachments a
            join :_posts p
               on a.attach_rel_id = p.pid and a.attach_rel_module = 'post'
            join :_topics t
               on t.tid = p.topic_id
            left join :_attachments_type ty
               on a.attach_ext = ty.atype_extension";
        $this->clearFilters('Media', $media_Map, $sql);
        $port->export('Media', $sql, $media_Map);
    }
}
