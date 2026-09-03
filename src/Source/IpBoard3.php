<?php

/**
 * Invision Powerboard 3.x or earlier exporter tool.
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Log;
use Porter\Source;

/**
 * Formatting issues?
 * @see https://github.com/prosembler/vanilla/tree/main/plugins/IPBFormatter
 */
class IpBoard3 extends Source
{
    public const array INFO = [
        'name' => 'IP.Board 3',
        'defaultTablePrefix' => 'ibf_',
        'charsetTable' => 'posts',
        'passwordHashMethod' => 'ipb',
    ];

    /**
     * Export avatars into vanilla-compatibles names
     */
    public function filemap(): void
    {
        // @todo map a target folder properly
        $sourceFolder = 'avatars';
        $targetFolder = self::combinePaths([$sourceFolder, 'ipb']);

        $userList = false;
        if ($this->hasInputSchema('profile_portal', ['pp_member_id', 'pp_main_photo', 'pp_thumb_photo'])) {
            $userList = $this->query("select
                    pp_member_id as member_id,
                    pp_main_photo as main_photo,
                    pp_thumb_photo as thumb_photo,
                    coalesce(pp_main_photo,pp_thumb_photo,0) as photo
                from :_profile_portal
                where length(coalesce(pp_main_photo,pp_thumb_photo,0)) > 3
                order by pp_member_id asc");
        } elseif ($this->hasInputSchema('member_extra', ['avatar_location'])) {
            $userList = $this->query("select id as member_id, avatar_location as photo
                from :_member_extra
                where length(avatar_location) > 3 and avatar_location <> 'noavatar'
                order by id asc");
        }

        $processed = 0;
        $skipped = 0;
        $completed = 0;
        $errors = [];
        if (!$userList) {
            return;
        }
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
            $photoFolder = self::combinePaths([$targetFolder, $photoPath]);
            @mkdir($photoFolder, 0777, true);
            $photoSrc = self::combinePaths([$sourceFolder, $photo]);
            if (!file_exists($photoSrc)) {
                $errors[] = "Missing file: {$photoSrc}";
                continue;
            }

            // Main Photo
            $mainPhoto = trim($row['main_photo'] ?? null);
            if (!$mainPhoto) {
                $mainPhoto = $photo;
            }
            $mainSrc = self::combinePaths([$sourceFolder, $mainPhoto]);
            $mainDest = self::combinePaths([$photoFolder, "p" . $photoFileName]);
            $copied = @copy($mainSrc, $mainDest);
            if (!$copied) {
                $error |= true;
                $errors[] = "! failed to copy main photo '{$mainSrc}' for user {$userID} (-> {$mainDest}).";
            }

            //$thumbPhoto = trim($row['thumb_photo'] ?? null);
            $thumbSrc = self::combinePaths([$sourceFolder, $mainPhoto]);
            $thumbDest = self::combinePaths([$photoFolder, "n" . $photoFileName]);
            $copied = @copy($thumbSrc, $thumbDest);
            if (!$copied) {
                $error |= true;
                $errors[] = "! failed to copy thumbnail '{$thumbSrc}' for user {$userID} (-> {$thumbDest}).";
            }
            if (!$error) {
                $completed++;
            }
            if (!($processed % 100)) {
                Log::comment(" - processed {$processed}\n");
            }
        }

        $nErrors = sizeof($errors);
        if ($nErrors) {
            Log::comment("{$nErrors} errors:");
            foreach ($errors as $error) {
                Log::comment("{$error}");
            }
        }
        Log::comment("Completed: {$completed}");
        Log::comment("Skipped: {$skipped}");
    }

    protected function conversationsV2(): void
    {
        $sql = <<<EOT
            create table tmp_to (
               id int,
               userid int,
               primary key (id, userid)
            );
            truncate table tmp_to;
            insert ignore tmp_to (id, userid)
            select mt_id, mt_from_id
            from :_message_topics;

            insert ignore tmp_to (id, userid)
            select mt_id,  mt_to_id
            from :_message_topics;

            create table tmp_to2 (
               id int primary key,
               userids varchar(255)
            );
            truncate table tmp_to2;
            insert tmp_to2 (id, userids)
            select id, group_concat(userid order by userid)
            from tmp_to
            group by id;

            create table tmp_conversation (
               id int primary key,
               title varchar(255),
               title2 varchar(255),
               userids varchar(255),
               groupid int
            );
            replace tmp_conversation (id, title, title2, userids)
            select mt_id, mt_title, mt_title, t2.userids
            from :_message_topics t
            join tmp_to2 t2 on t.mt_id = t2.id;

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
            replace tmp_group (title2, userids, groupid)
            select title2, userids, min(id)
            from tmp_conversation
            group by title2, userids;

            create index tidx_group on tmp_group(title2, userids);
            create index tidx_conversation on tmp_conversation(title2, userids);

            update tmp_conversation c
            join tmp_group g on c.title2 = g.title2 and c.userids = g.userids
            set c.groupid = g.groupid;
EOT;
        $this->dbInput()->unprepared($sql);

        $map = [
            'groupid' => 'ConversationID',
            'title2' => 'Subject',
            'mt_date' => 'DateInserted',
            'mt_from_id' => 'InsertUserID',
        ];
        $filters = [
            'mt_date' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $sql = "select mt.*, tc.title2, tc.groupid
            from :_message_topics mt
            join tmp_conversation tc on mt.mt_id = tc.id";
        $this->export('Conversation', $sql, $map, $filters);

        // Conversation Message.
        $map = [
            'msg_id' => 'MessageID',
            'groupid' => 'ConversationID',
            'msg_date' => 'DateInserted',
            'msg_post' => 'Body',
            'Format' => 'Format',
            'msg_author_id' => 'InsertUserID',
            'msg_ip_address' => 'InsertIPAddress',
            'Format=IPB',
        ];
        $filters = [
            'msg_date' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $sql = "select tx.*, tc.title2, tc.groupid
            from :_message_text tx
            join :_message_topics mt on mt.mt_msg_id = tx.msg_id
            join tmp_conversation tc on mt.mt_id = tc.id";
        $this->export('ConversationMessage', $sql, $map, $filters);

        // User Conversation.
        $userConversation_Map = [
            'userid' => 'UserID',
            'groupid' => 'ConversationID'
        ];
        $sql = "select distinct g.groupid, t.userid
            from tmp_to t
            join tmp_group g on g.groupid = t.id";
        $this->export('UserConversation', $sql, $userConversation_Map);

        // Cleanup
        $this->dbInput()->unprepared(
            "drop table tmp_conversation;
            drop table tmp_to;
            drop table tmp_to2;
            drop table tmp_group;"
        );
    }

    protected function conversations(): void
    {
        if (!$this->hasInputSchema('message_topic_user_map')) {
            $this->conversationsV2(); // v2
            return;
        }
        $map = [
            'mt_id' => 'ConversationID',
            'mt_date' => 'DateInserted',
            'mt_title' => 'Subject',
            'mt_starter_id' => 'InsertUserID',
        ];
        $filters = [
            'mt_date' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $sql = "select * from :_message_topics where mt_is_deleted = 0";
        $this->export('Conversation', $sql, $map, $filters);

        // Conversation Message.
        $map = [
            'msg_id' => 'MessageID',
            'msg_topic_id' => 'ConversationID',
            'msg_date' => 'DateInserted',
            'msg_post' => 'Body',
            'Format' => 'Format',
            'msg_author_id' => 'InsertUserID',
        ];
        $filters = [
            'msg_date' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $sql = "select m.*, 'IPB' as Format from :_message_posts m";
        $this->export('ConversationMessage', $sql, $map, $filters);

        // User Conversation.
        $userConversation_Map = [
            'map_user_id' => 'UserID',
            'map_topic_id' => 'ConversationID',
            'Deleted' => 'Deleted'
        ];
        $sql = "select t.*, !map_user_active as Deleted from :_message_topic_user_map t";
        $this->export('UserConversation', $sql, $userConversation_Map);
    }

    protected function users(): void
    {
        $memberID = 'id';
        if ($this->hasInputSchema('members', 'member_id')) {
            $memberID = 'member_id';
        }
        $map = [
            $memberID => 'UserID',
            'members_display_name' => 'Name',
            'email' => 'Email',
            'joined' => 'DateInserted',
            'firstvisit' => 'DateFirstVisit',
            'ip_address' => 'InsertIPAddress',
            'time_offset' => 'HourOffset',
            'last_activity' => 'DateLastActive',
            'member_banned' => 'Banned',
            'title' => 'Title',
            'location' => 'Location',
            'HashMethod=ipb',
        ];
        $filters = [
            'members_display_name' => \Porter\Filter\DecodeHtml::class,
            'joined' => \Porter\Filter\UnixtimeToDate::class,
            'firstvisit' => \Porter\Filter\UnixtimeToDate::class,
            'last_activity' => \Porter\Filter\UnixtimeToDate::class,
        ];

        // Build query.
        $select = ", concat(mc.converge_pass_hash, '$', mc.converge_pass_salt) as Password";
        $from = "left join :_members_converge mc on m.$memberID = mc.converge_id";
        if ($this->hasInputSchema('members', 'members_pass_hash')) {
            $select = ",concat(m.members_pass_hash, '$', m.members_pass_salt) as Password";
            $from = '';
        }

        $showEmail = '0';
        if ($this->hasInputSchema('members', 'hide_email')) {
            $showEmail = '!hide_email';
        }
        if ($this->hasInputSchema('member_extra')) {
            $sql = "select m.*, m.joined as firstvisit, x.location, $showEmail as ShowEmail,
                case when x.avatar_location in ('noavatar', '') then null
                    when x.avatar_location like 'upload:%'
                        then concat('ipb/', right(x.avatar_location, length(x.avatar_location) - 7))
                    when x.avatar_type = 'upload' then concat('ipb/', x.avatar_location)
                    when x.avatar_type = 'url' then x.avatar_location
                    when x.avatar_type = 'local' then concat('style_avatars/', x.avatar_location)
                    else null
                end as Photo
                $select
            from :_members m
            left join :_member_extra x on m.$memberID = x.id
                $from";
        } else {
            $sql = "select m.*, joined as firstvisit, $showEmail as ShowEmail,
                case when length(p.pp_main_photo) <= 3 or p.pp_main_photo is null then null
                    when p.pp_main_photo like '%//%' then p.pp_main_photo
                    else concat('ipb/', p.pp_main_photo)
                end as Photo
                $select
                from :_members m
                left join :_profile_portal p on m.$memberID = p.pp_member_id
                $from";
        }
        $this->export('User', $sql, $map, $filters);
    }

    protected function roles(): void
    {
        $memberID = 'id';
        if ($this->hasInputSchema('members', 'member_id')) {
            $memberID = 'member_id';
        }
        $role_Map = [
            'g_id' => 'RoleID',
            'g_title' => 'Name'
        ];
        $this->export('Role', "select * from :_groups", $role_Map);

        // User Role.
        $groupID = 'mgroup';
        if ($this->hasInputSchema('members', 'member_group_id')) {
            $groupID = 'member_group_id';
        }
        $userRole_Map = [
            $memberID => 'UserID',
            $groupID => 'RoleID'
        ];

        $sql = "select m.$memberID, m.$groupID from :_members m";
        if ($this->hasInputSchema('members', 'mgroup_others')) {
            $sql .= " union all
            select m.$memberID, g.g_id
            from :_members m
            join :_groups g on find_in_set(g.g_id, m.mgroup_others)";
        }
        $this->export('UserRole', $sql, $userRole_Map);
    }

    protected function signatures(): void
    {
        $userMeta_Map = [
            'UserID' => 'UserID',
            'Name' => 'Name',
            'Value' => 'Value'
        ];
        if ($this->hasInputSchema('profile_portal', 'signature')) {
            $sql = "select pp_member_id as UserID, 'Plugin.Signatures.Sig' as Name, signature as Value
            from :_profile_portal
            where length(signature) > 1
            union all
            select pp_member_id as UserID, 'Plugin.Signatures.Format' as Name, 'IPB' as Value
            from :_profile_portal
            where length(signature) > 1";
            $this->export('UserMeta', $sql, $userMeta_Map);
        } elseif ($this->hasInputSchema('member_extra', ['id', 'signature'])) {
            $sql = "select id as UserID, 'Plugin.Signatures.Sig' as Name, signature as Value
            from :_member_extra
            where length(signature) > 1
            union all
            select id as UserID, 'Plugin.Signatures.Format' as Name, 'IPB' as Value
            from :_member_extra
            where length(signature) > 1";
            $this->export('UserMeta', $sql, $userMeta_Map);
        }
    }

    protected function categories(): void
    {
        $map = [
            'id' => 'CategoryID',
            'name' => 'Name',
            'name_seo' => 'UrlCode',
            'description' => 'Description',
            'parent_id' => 'ParentCategoryID',
            'position' => 'Sort'
        ];
        $filters = [
            'name' => \Porter\Filter\DecodeHtml::class,
        ];
        $this->export('Category', "select * from :_forums", $map, $filters);
    }

    protected function discussions(): void
    {
        $descriptionSQL = 'p.post';
        $hasTopicDesc = ($this->hasInputSchema('topics', ['description']));
        $hasPostDesc = $this->hasInputSchema('posts', ['description']);
        if ($hasTopicDesc || $hasPostDesc) {
            $table = ($hasTopicDesc) ? 't' : 'p';
            // @todo filter
            $descriptionSQL = "case
                when $table.description <> '' and p.post is not null
                    then concat('<div class=\"IPBDescription\">', $table.description, '</div>', p.post)
                when $table.description <> '' 
                    then $table.description
                else p.post
            end";
        }
        $map = [
            'tid' => 'DiscussionID',
            'title' => 'Name',
            'description' => 'SubName',
            'forum_id' => 'CategoryID',
            'starter_id' => 'InsertUserID',
            'start_date' => 'DateInserted',
            'edit_time' => 'DateUpdated',
            //'last_post' => 'DateLastPost',
            'posts' => 'CountComments',
            'views' => 'CountViews',
            'pinned' => 'Announce',
            'post' => 'Body',
            'closed' => 'Closed',
            'Format=BBCode',
        ];
        $filters = [
            'start_date' => \Porter\Filter\UnixtimeToDate::class,
            'edit_time' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $sql = "select t.*, p.edit_time, $descriptionSQL as post,
                case when t.state = 'closed' then 1 else 0 end as closed
            from :_topics t
            left join :_posts p on t.topic_firstpost = p.pid";
        $this->export('Discussion', $sql, $map, $filters);
    }

    protected function tags(): void
    {
        $this->dbInput()->unprepared("DROP TABLE IF EXISTS `z_tag` ");
        $this->dbInput()->unprepared("CREATE TABLE `z_tag` (
            `TagID` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `FullName` varchar(50) DEFAULT NULL,
            PRIMARY KEY (`TagID`), UNIQUE KEY `FullName` (`FullName`))");
        $this->dbInput()->unprepared("insert into z_tag (FullName) 
            (select distinct t.tag_text as FullName from :_core_tags t)");

        $map = [
            'tag_meta_id' => 'DiscussionID',
            'tag_added' => 'DateInserted',
            'CategoryID=0',
        ];
        $filters = ['tag_added' => \Porter\Filter\UnixtimeToDate::class];
        $sql = "select TagID, tag_meta_id, t.tag_added
            from :_core_tags t
            left join z_tag zt on t.tag_text = zt.FullName";
        $this->export('TagDiscussion', $sql, $map, $filters);

        $map = [
            'FullName' => 'FullName',
            'FullNameToName' => 'Name',
        ];
        $filters = [
            'FullNameToName' => 'FormatUrl'
        ];
        $sql = "select TagID, FullName, FullName as FullNameToName from z_tag zt";
        $this->export('Tag', $sql, $map, $filters);
    }

    protected function comments(): void
    {
        $map = [
            'pid' => 'CommentID',
            'topic_id' => 'DiscussionID',
            'author_id' => 'InsertUserID',
            'ip_address' => 'InsertIPAddress',
            'post_date' => ['Column' => 'DateInserted', 'Filter' => 'UnixtimeToDate'],
            'edit_time' => ['Column' => 'DateUpdated', 'Filter' => 'UnixtimeToDate'],
            'post' => 'Body',
            'Format=BBCode',
        ];
        $filters = [
            'post_date' => \Porter\Filter\UnixtimeToDate::class,
            'edit_time' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $sql = "select p.* from :_posts p
            join :_topics t on p.topic_id = t.tid
            where p.pid between {from} and {to}
                and p.pid <> t.topic_firstpost";
        $this->export('Comment', $sql, $map, $filters);
    }

    protected function attachments(): void
    {
        $map = [
            'attach_id' => 'MediaID',
            'atype_mimetype' => 'Type',
            'attach_file' => 'Name',
            'attach_path' => 'Path',
            'attach_date' => 'DateInserted',
            'thumb_path' => 'ThumbPath',
            'thumb_width' => 'ThumbWidth',
            'attach_member_id' => 'InsertUserID',
            'attach_filesize' => 'Size',
            'ForeignID' => 'ForeignID',
            'ForeignTable' => 'ForeignTable',
            'img_width' => 'ImageWidth',
            'img_height' => 'ImageHeight'
        ];
        $filters = [
            'attach_date' => \Porter\Filter\UnixtimeToDate::class,
            'thumb_path' => \Porter\Filter\NullIfNotImage::class,
            'thumb_width' => \Porter\Filter\NullIfNotImage::class,
        ];
        $sql = "select a.*, ty.atype_mimetype, ty.atype_mimetype as Mime,
               concat('ipb/', a.attach_location) as attach_path,
               concat('ipb/', a.attach_location) as thumb_path,
               128 as thumb_width,
               case when p.pid = t.topic_firstpost then 'discussion' else 'comment' end as ForeignTable,
               case when p.pid = t.topic_firstpost then t.tid else p.pid end as ForeignID,
               case a.attach_img_width when 0 then a.attach_thumb_width else a.attach_img_width end as img_width,
               case a.attach_img_height when 0 then a.attach_thumb_height else a.attach_img_height end as img_height
            from :_attachments a
            join :_posts p on a.attach_rel_id = p.pid and a.attach_rel_module = 'post'
            join :_topics t  on t.tid = p.topic_id
            left join :_attachments_type ty on a.attach_ext = ty.atype_extension";
        $this->export('Media', $sql, $map, $filters);
    }

    /**
     * Build a valid path from multiple pieces.
     */
    public static function combinePaths(array|string $paths, string $delimiter = '/'): string
    {
        if (is_array($paths)) {
            $mungedPath = implode($delimiter, $paths);
            $mungedPath = str_replace(
                [$delimiter . $delimiter . $delimiter, $delimiter . $delimiter],
                [$delimiter, $delimiter],
                $mungedPath
            );
            return str_replace(['http:/', 'https:/'], ['http://', 'https://'], $mungedPath);
        } else {
            return $paths;
        }
    }
}
