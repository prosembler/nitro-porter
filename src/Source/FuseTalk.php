<?php

/**
 * FuseTalk exporter tool.
 *
 * You need to convert the database to MySQL first.
 * Use that: https://github.com/tburry/dbdump
 *
 * Tested with FuseTalk Enterprise Edition v4.0
 *
 * @author  Alexandre Chouinard
 */

namespace Porter\Source;

use Porter\Log;
use Porter\Source;

class FuseTalk extends Source
{
    public const array INFO = [
        'name' => 'FuseTalk',
        'defaultTablePrefix' => 'ftdb_',
        'charsetTable' => 'messages',
    ];

    /**
     * @var array Required tables => columns
     */
    public array $sourceTables = [
        'categories' => [],
        'forums' => [],
        'threads' => [],
        'messages' => [],
        'users' => [],
    ];

    public function setup(): void
    {
        $this->createIndices(); // Speed up the export.
    }

    protected function createIndices(): void
    {
        Log::comment("Creating indexes...");
        if (!$this->indexExists('ix_users_userid', ':_users')) {
            $this->dbInput()->unprepared('create index ix_users_userid on :_users (iuserid)');
        }
        if (!$this->indexExists('ix_banning_banstring', ':_banning')) {
            $this->dbInput()->unprepared('create index ix_banning_banstring on :_banning (vchbanstring)');
        }
        if (!$this->indexExists('ix_forumusers_userid', ':_forumusers')) {
            $this->dbInput()->unprepared('create index ix_forumusers_userid on :_forumusers (iuserid)');
        }
        if (!$this->indexExists('ix_groupusers_userid', ':_groupusers')) {
            $this->dbInput()->unprepared('create index ix_groupusers_userid on :_groupusers (iuserid)');
        }
        if (!$this->indexExists('ix_privatemessages_vchusagestatus', ':_privatemessages')) {
            $this->dbInput()->unprepared(
                'create index ix_privatemessages_vchusagestatus on :_privatemessages (vchusagestatus)'
            );
        }
        if (!$this->indexExists('ix_threads_id_pollflag', ':_threads')) {
            $this->dbInput()->unprepared('create index ix_threads_id_pollflag on :_threads (ithreadid, vchpollflag)');
        }
        if (!$this->indexExists('ix_threads_poll', ':_threads')) {
            $this->dbInput()->unprepared('create index ix_threads_poll on :_threads (vchpollflag)');
        }
        Log::comment("Indexes done!");
    }

    protected function users(): void
    {
        $map = [];
        $this->export(
            'User',
            "select
                    user.iuserid as UserID,
                    user.vchnickname as Name,
                    user.vchemailaddress as Email,
                    user.vchpassword as Password,
                    'md5' as HashMethod,
                    if (forumusers.vchauthoricon is not null,
                        concat('authoricons/', forumusers.vchauthoricon), null) as Photo,
                    user.dtinsertdate as DateInserted,
                    user.dtlastvisiteddate as DateLastActive,
                    user.bapproved as Confirmed,
                    if (user.iuserlevel = 0, 1, 0) as Admin,
                    if (coalesce(bemail.vchbanstring, bname.vchbanstring, 0) != 0, 1, 0) as Banned
                from :_users as user
                    left join :_forumusers as forumusers using (iuserid)
                    left join :_banning as bemail on b.vchbanstring = user.vchemailaddress
                    left join :_banning as bname on b.vchbanstring = user.vchnickname
                group by user.iuserid;",
            $map
        );
    }

    protected function signatures(): void
    {
        $query = "select user.iuserid as UserID,
                    'Plugin.Signatures.Sig' as Name,
                    user.txsignature as Value
                from :_users as user
                where nullif(nullif(user.txsignature, ''), char(0)) is not null";
        $this->export('UserMeta', $query);

        $query = "select user.iuserid,
                    'Plugin.Signatures.Format',
                    'Html'
                from :_users as user
                where nullif(nullif(user.txsignature, ''), char(0)) is not null";
        $this->export('UserMeta', $query);
    }

    protected function roles(): void
    {
        $memberRoleID = 1;
        $result = $this->query("select max(igroupid) as maxRoleID from :_groups");
        if ($result && $row = $result->nextResultRow()) {
            $memberRoleID += $row['maxRoleID'];
        }
        $this->export(
            'Role',
            "select groups.igroupid as RoleID, groups.vchgroupname as Name
                from :_groups as groups
                union all
                select $memberRoleID as RoleID, 'Members'
                from dual"
        );

        // User Role.
        $this->export(
            'UserRole',
            "select user.iuserid as UserID,
                    ifnull (user_role.igroupid, $memberRoleID) as RoleID
                from :_users as user
                left join :_groupusers as user_role using (iuserid)"
        );
    }

    protected function conversations(): void
    {
        $this->dbInput()->unprepared("drop table if exists zConversations;");
        $this->dbInput()->unprepared("create table zConversations(
                `ConversationID` int(11) not null AUTO_INCREMENT,
                `User1` int(11) not null,
                `User2` int(11) not null,
                `DateInserted` datetime not null,
                primary key (`ConversationID`),
                key `IX_zConversation_User1_User2` (`User1`,`User2`));");
        $this->dbInput()->unprepared("insert into zConversations(`User1`, `User2`, `DateInserted`)
                select  if (pm.iuserid < pm.iownerid, pm.iuserid, pm.iownerid) as User1,
                    if (pm.iuserid < pm.iownerid, pm.iownerid, pm.iuserid) as User2,
                    min(pm.dtinsertdate)
                from :_privatemessages as pm
                group by User1, User2");

        // Conversations.
        $this->export(
            'Conversation',
            "select  c.ConversationID, c.User1 as InsertUserID, c.DateInserted from zConversations as c;"
        );

        // Conversation Messages.
        $map = [
            'txmessage' => 'Body',
            'imessageid' => 'MessageID',
            'iownerid' => 'InsertUserID',
            'dtinsertdate' => 'DateInserted',
            'Format=Html',
        ];
        $filters = [
            'txmessage' => \Porter\Filter\FuseTalkSmileyUrl::class,
        ];
        $this->export(
            'ConversationMessage',
            "select pm.imessageid, c.ConversationID, pm.txmessage, pm.iownerid, pm.dtinsertdate
                from zConversations as c
                inner join :_privatemessages as pm on pm.iuserid = c.User1 and pm.iownerid = c.User2
                where vchusagestatus = 'sent'
                union all
                select pm.imessageid, c.ConversationID, pm.txmessage, pm.iownerid, pm.dtinsertdate
                from zConversations as c
                inner join :_privatemessages as pm on pm.iuserid = c.User2 and pm.iownerid = c.User1
                where vchusagestatus = 'sent';",
            $map,
            $filters
        );

        // User Conversation.
        $this->export(
            'UserConversation',
            "select c.ConversationID, c.User1 as UserID, now() as DateLastViewed
                from zConversations as c
                union all
                select c.ConversationID, c.User2 as UserID, now() as DateLastViewed
                from zConversations as c;"
        );
    }

    protected function categories(): void
    {
        $map = [
            'icategoryid' => 'CategoryID',
            'vchcategoryname' => 'Name',
            'vchdescription' => 'Description',
            'ParentCategoryID=-1',
        ];
        $this->export(
            'Category',
            "select categories.icategoryid, categories.vchcategoryname, categories.vchdescription
                from :_categories as categories",
            $map
        );
    }

    protected function discussions(): void
    {
        // Skip "Body". It will be fixed at import.
        // The first comment is going to be used to fill the missing data and will then be deleted
        $map = [
            'ithreadid' => 'DiscussionID',
            'icategoryid' => 'CategoryID',
            'vchthreadname' => 'Name',
            'iuserid' => 'InsertUserID',
            'dtinsertdate' => 'DateInserted',
            'Format=Html',
        ];
        $this->export(
            'Discussion',
            "select *,
                    if (threads.vchalertthread = 'Yes' and threads.dtstaydate > now(), 2, 0) as Announce,
                    if (threads.vchthreadlock = 'Locked', 1, 0) as Closed
                from :_threads as threads",
            $map
        );
    }

    protected function comments(): void
    {
        // The iparentid column doesn't make any sense since the display is ordered by date only (no "sub" comment)
        $map = [
            'imessageid' => 'CommentID',
            'ithreadid' => 'DiscussionID',
            'iuserid' => 'InsertUserID',
            'txmessage' => 'Body',
            'dtmessagedate' => 'DateInserted',
            'Format=Html',
        ];
        $filters = [
            'txmessage' => \Porter\Filter\FuseTalkSmileyUrl::class,
        ];
        $this->export('Comment', "select * from :_messages as messages", $map, $filters);
    }
}
