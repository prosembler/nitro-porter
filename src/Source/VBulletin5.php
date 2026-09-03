<?php

/**
 * vBulletin 5 Connect exporter tool.
 *
 * Add this 301 route to sidestep vB4->5 upgrade category redirects.
 *    Expression: forumdisplay\.php\?([0-9]+)-([a-zA-Z0-9-_]+)
 *    Target: /categories/$2
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Log;

class VBulletin5 extends VBulletin
{
    public const array INFO = [
        'name' => 'vBulletin 5 Connect',
        'defaultTablePrefix' => 'vb_',
        'charsetTable' => 'node',
    ];

    public array $sourceTables = [
        'contenttype' => ['contenttypeid', 'class'],
        'node' => ['nodeid', 'description', 'title', 'description', 'userid', 'publishdate'],
        'text' => ['nodeid', 'rawtext'],
        'user' => [
            'userid',
            'username',
            'email',
            'referrerid',
            'timezoneoffset',
            'posts',
            'birthday_search',
            'joindate',
            'lastvisit',
            'lastactivity',
            'membergroupids',
            'usergroupid',
            'usertitle',
            'avatarid',
        ],
        'userfield' => ['userid'],
        'usergroup' => ['usergroupid', 'title', 'description'],
        'usertitle' => [],
    ];

    protected int $pmNodeID;

    protected array $categoryIDs;

    /**
     * @return int Number of poll that can be exported by the porter.
     */
    protected function getPollsCount(): int
    {
        $count = 0;
        $sql = "show tables like ':_poll';";
        $result = $this->query($sql);
        if ($result && $result->nextResultRow()) {
            $sql = "select count(*) AS Count
                from :_poll as p
                inner join :_node as n on n.nodeid = p.nodeid
                inner join :_node as pn on pn.nodeid = n.parentid
                inner join :_contenttype as ct on ct.contenttypeid = pn.contenttypeid
                where ct.class = 'Channel';";
            $result = $this->query($sql);
            if ($result && $row = $result->nextResultRow()) {
                $count = $row['Count'];
            }
        }
        return $count;
    }

    /**
     * Generate discussions for polls.
     */
    protected function generatePollsDiscussion(): void
    {
        $sql = "insert into vBulletinDiscussionTable( /* `nodeid`, will be auto generated */
                `type`, `title`, `userid`, `rawtext`, `parentid`,
                `lastcontentid`, `lastauthorid`, `Format`,
                `DateInserted`,
                `CountViews`,
                `Closed`,
                `Announce`,
                `PollID`
            ) select
                'poll' as type, n.title, n.userid, t.rawtext, n.parentid,
                n.lastcontentid, n.lastauthorid, 'BBCode' as Format,
                FROM_UNIXTIME(n.publishdate) as DateInserted,
                v.count as CountViews,
                convert(ABS(n.open-1),char(1)) as Closed,
                if(convert(n.sticky,char(1))>0,2,0) as Announce,
                n.nodeid as PollID
            from :_poll as p
            inner join :_node as n on n.nodeid = p.nodeid
            inner join :_node as pn on pn.nodeid = n.parentid
            inner join :_contenttype as ct on ct.contenttypeid = pn.contenttypeid
            left join :_nodeview v on v.nodeid = n.nodeid
            left join :_text t on t.nodeid = n.nodeid
            where ct.class = 'Channel';";
        $this->dbInput()->unprepared($sql);
    }

    protected function polls(): void
    {
        $map = [
            'nodeid' => 'PollID',
            'title' => 'Name',
            'discussionid' => 'DiscussionID',
            'anonymous' => 'Anonymous',
            'created' => 'DateInserted',
            'userid' => 'InsertUserId',
        ];
        $filters = [
            'created' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Poll',
            "select  p.nodeid,  n.title, n.created, n.userid,
                    vbdt.nodeid as discussionid, !p.public as anonymous
                from :_poll as p
                inner join :_node as n on n.nodeid = p.nodeid
                inner join :_node as pn on pn.nodeid = n.parentid
                inner join :_contenttype as pct on pct.contenttypeid = pn.contenttypeid
                /* this join only exports polls that could be wrapped in a discussion */
                inner join vBulletinDiscussionTable as vbdt on vbdt.PollID = p.nodeid;",
            $map,
            $filters
        );

        // Options.
        $map = [
            'polloptionid' => 'PollOptionID',
            'nodeid' => 'PollID',
            'title' => 'Body',
            'Format=BBCode',
            //'sort' => 'Sort',
            'created' => 'DateInserted',
            'userid' => 'InsertUserID',
        ];
        $filters = [
            'created' => 'UnixtimeToDate'
        ];
        $sql = "select po.polloptionid, po.nodeid, po.title, n.created, n.userid
            from :_polloption as po
            left join :_node as n on n.nodeid = po.nodeid;";
        // @todo generate a sort order
        $this->export('PollOption', $sql, $map, $filters);

        // Votes.
        $map = [
            'userid' => 'UserID',
            'polloptionid' => 'PollOptionID',
            'votedate' => 'DateInserted',
        ];
        $filters = [
            'votedate' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'PollVote',
            "select pv.userid, pv.polloptionid, pv.votedate from :_pollvote pv;",
            $map,
            $filters
        );
    }

    public function users(): void
    {
        $map = [
            'userid' => 'UserID',
            'username' => 'Name',
            'password2' => 'Password',
            'email' => 'Email',
            'referrerid' => 'InviteUserID',
            'timezoneoffset' => 'HourOffset',
            'usertitle' => 'Title',
            'posts' => 'RankID',
            // Use file avatar or the result of our blob export?
            ($this->getConfig('usefileavatar')) ? 'filephoto' : 'customphoto' => 'Photo',
        ];
        $ranks = $this->dbInput()->table('usertitle')
            ->select(['minposts', 'usertitleid'])
            ->orderBy('minposts', 'desc')->get();
        $filters = [
            'posts' => function ($value) use ($ranks) {
                // Look up the posts count in the ranks table.
                foreach ($ranks as $row) {
                    if ($value >= $row->minposts) {
                        return $row->usertitleid;
                    }
                }
                return null;
            },
        ];

        // vBulletin 5.1 changes the hash to crypt(md5(password), hash).
        // Switches from password & salt to token (and scheme & secret).
        // The scheme appears to be crypt()'s default and secret looks uselessly redundant.
        if ($this->hasInputSchema('user', 'token') !== true) {
            $passwordSQL = "concat(`password`, salt) as password2, 'vbulletin' as HashMethod,";
        } else {
            // vB 5.1 already concats the salt to the password as token, BUT ADDS A SPACE OF COURSE.
            $passwordSQL = "replace(token, ' ', '') as password2,
                case when scheme = 'legacy' then 'vbulletin' else 'vbulletin5' end as HashMethod,";
        }

        $this->export(
            'User',
            "select u.*, $passwordSQL
                    DATE_FORMAT(birthday_search,GET_FORMAT(DATE,'ISO')) as DateOfBirth,
                    FROM_UNIXTIME(joindate) as DateFirstVisit,
                    FROM_UNIXTIME(lastvisit) as DateLastActive,
                    FROM_UNIXTIME(joindate) as DateInserted,
                    FROM_UNIXTIME(lastactivity) as DateUpdated,
                    case when avatarrevision > 0 then
                        concat('userpics/avatar', u.userid, '_', avatarrevision, '.gif')
                        when av.avatarpath is not null then av.avatarpath
                        else null
                    end as filephoto,
                    {$this->avatarSelect},
                    case when ub.userid is not null then 1 else 0 end as Banned
                from :_user u
                left join :_customavatar a on u.userid = a.userid
                left join :_avatar av on u.avatarid = av.avatarid
                left join :_userban ub on u.userid = ub.userid and ub.liftdate <= now();",
            $map,
            $filters
        );
    }

    public function roles(): void
    {
        $map = [
            'usergroupid' => 'RoleID',
            'title' => 'Name',
            'description' => 'Description'
        ];
        $this->export('Role', 'select * from :_usergroup', $map);

        // UserRoles
        $userRole_Map = [
            'userid' => 'UserID',
            'usergroupid' => 'RoleID'
        ];
        $this->dbInput()->unprepared("drop table if exists VbulletinRoles");
        $this->dbInput()->unprepared("CREATE TABLE VbulletinRoles 
            (userid INT UNSIGNED not null, usergroupid INT UNSIGNED not null)");
        // Put primary groups into tmp table
        $this->dbInput()->unprepared("insert into VbulletinRoles 
            (userid, usergroupid) select userid, usergroupid from :_user");
        // Put stupid CSV column into tmp table
        $secondaryRoles = $this->query("select userid, usergroupid, membergroupids from :_user");
        if (is_object($secondaryRoles)) {
            while (($row = $secondaryRoles->nextResultRow()) !== false) {
                if ($row['membergroupids'] != '') {
                    $groups = explode(',', $row['membergroupids']);
                    foreach ($groups as $groupID) {
                        $this->dbInput()->unprepared(
                            "insert into VbulletinRoles (userid, usergroupid) values({$row['userid']},{$groupID})"
                        );
                    }
                }
            }
        }
        // Export from our tmp table and drop
        $this->export('UserRole', 'select distinct userid, usergroupid from VbulletinRoles', $userRole_Map);
        $this->dbInput()->unprepared("DROP TABLE IF EXISTS VbulletinRoles");
    }

    public function ranks(): void
    {
        $map = [
            'usertitleid' => 'RankID',
            'title' => 'Name',
            'title2' => 'Label',
            'minposts' => 'Attributes',
            'level' => 'Level',
        ];
        $filters = [
            'level' => function ($value) {
                static $level = 1;
                return $level++;
            },
            'minposts' => function ($value) {
                $result = [
                    'Criteria' => ['CountPosts' => $value]
                ];
                return serialize($result);
            },
        ];
        $this->export(
            'Rank',
            "select ut.*, ut.title as title2, 0 as level
                from :_usertitle ut
                order by ut.minposts;",
            $map,
            $filters
        );
    }

    public function categories(): void
    {
        $channels = [];
        $categoryIDs = [];
        $homeID = 0;
        $privateMessagesID = 0;
        // Filter Channels down to Forum tree
        $channelResult = $this->query(
            "select n.*
                from :_node n
                left join :_contenttype ct on n.contenttypeid = ct.contenttypeid
                where ct.class = 'Channel';"
        );
        if ($channelResult) {
            while ($channel = $channelResult->nextResultRow()) {
                $channels[$channel['nodeid']] = $channel;
                if ($channel['title'] == 'Forum') {
                    $homeID = $channel['nodeid'];
                }
                if ($channel['title'] == 'Private Messages') {
                    $privateMessagesID = $channel['nodeid'];
                }
            }
        }
        if (!$homeID) {
            exit("Missing node 'Forum'");
        }

        // Go through the category list 6 times to build a (up to) 6-deep hierarchy
        $categoryIDs[] = $homeID;
        for ($i = 0; $i < 6; $i++) {
            foreach ($channels as $channel) {
                if (in_array($channel['nodeid'], $categoryIDs)) {
                    continue;
                }
                if (in_array($channel['parentid'], $categoryIDs)) {
                    $categoryIDs[] = $channel['nodeid'];
                }
            }
        }
        // Drop 'Forum' from the tree
        if (($key = array_search($homeID, $categoryIDs)) !== false) {
            unset($categoryIDs[$key]);
        }

        $map = [
            'nodeid' => 'CategoryID',
            'title' => 'Name',
            'description' => 'Description',
            'userid' => 'InsertUserID',
            'parentid' => 'ParentCategoryID',
            'urlident' => 'UrlCode',
            'displayorder' => 'Sort',
            'lastcontentid' => 'LastDiscussionID',
            'textcount' => 'CountComments', // ???
            'totalcount' => 'CountDiscussions', // ???
            'publishdate' => 'DateInserted',
        ];
        $filters = [
            'publishdate' => \Porter\Filter\UnixtimeToDate::class,
        ];
        // Categories are Channels that were found in the Forum tree
        // If parent was 'Forum' set the parent to Root instead (-1)
        $this->export(
            'Category',
            "select n.*, if(parentid={$homeID},-1,parentid) as parentid
                from :_node n
                where nodeid in (" . implode(',', $categoryIDs) . ");",
            $map,
            $filters
        );
        $this->pmNodeID = $privateMessagesID;
        $this->categoryIDs = $categoryIDs;
    }

    public function comments(): void
    {
        // Detect inner comments (Can happen if a plugin is used)
        $innerCommentQuery = "select
                node.nodeid,
                nodePP.nodeid as parentid,
                node.userid,
                t.rawtext,
                FROM_UNIXTIME(node.publishdate)
            from :_node as node
                inner join :_contenttype as ct on ct.contenttypeid = node.contenttypeid
                    and ct.class = 'Text' /*Inner Comment*/
                inner join :_node as nodeP on nodeP.nodeid = node.parentid
                inner join :_contenttype as ctP on ctP.contenttypeid = nodeP.contenttypeid
                    and ctP.class = 'Text'/*Comment*/
                inner join :_node as nodePP on nodePP.nodeid = nodeP.parentid
                inner join :_contenttype as ctPP on ctPP.contenttypeid = nodePP.contenttypeid
                    and ctPP.class = 'Text'/*Discussion*/
                inner join :_node as nodePPP on nodePPP.nodeid = nodePP.parentid
                inner join :_contenttype as ctPPP on ctPPP.contenttypeid = nodePPP.contenttypeid
                    and ctPPP.class = 'Channel'/*Category*/
                left join :_text t on t.nodeid = node.nodeid
            where node.showpublished = 1";
        $result = $this->query($innerCommentQuery . ' limit 1');
        $innerCommentSQLFix = null;
        if ($result && $result->nextResultRow()) {
            $this->dbInput()->unprepared("create table `vBulletinInnerCommentTable` (
                    `nodeid` int(10) unsigned not null,
                    `parentid` int(11) not null,
                    `userid` int(10) unsigned default null,
                    `rawtext` mediumtext,
                    `DateInserted` datetime not null,
                    primary key (`nodeid`) );");
            $this->dbInput()->unprepared("insert into vBulletinInnerCommentTable $innerCommentQuery");
            $innerCommentSQLFix = "
                and n.nodeid not in (select nodeid from vBulletinInnerCommentTable)
            union all
            select * from vBulletinInnerCommentTable ";
        }

        $map = [
            'nodeid' => 'CommentID',
            'rawtext' => 'Body',
            'userid' => 'InsertUserID',
            'parentid' => 'DiscussionID',
            'publishdate' => 'DateInserted',
            'Format=BBCode',
        ];
        $this->export(
            'Comment',
            "select n.nodeid, n.parentid, n.userid, FROM_UNIXTIME(publishdate), t.rawtext
                from :_node n
                left join :_contenttype c on n.contenttypeid = c.contenttypeid
                left join :_text t on t.nodeid = n.nodeid
                where c.class = 'Text' and n.showpublished = 1
                    and parentid not in (" . implode(',', $this->categoryIDs) . ")
                    $innerCommentSQLFix",
            $map
        );

        if ($innerCommentSQLFix !== null) {
            $this->dbInput()->unprepared("drop table if exists vBulletinInnerCommentTable");
        }
    }

    public function attachments(): void
    {
        $instance = $this;
        $map = [
            'nodeid' => 'MediaID',
            'filename' => 'Name',
            'Path2' => 'Path',
            'ThumbPath2' => 'ThumbPath',
            'thumb_width' => 'ThumbWidth',
            'width' => 'ImageWidth',
            'height' => 'ImageHeight',
            'filesize' => 'Size',
        ];
        $filters = [
            'extension' => \Porter\Filter\ExtToMime::class,
            'Path2' => [$this, 'buildMediaPath'],
            'ThumbPath2' => function ($value, $field, $row) use ($instance) {
                $filter = new \Porter\Filter\NullIfNotImage($value, $field, $row);
                if ($filter()) {
                    return $instance->buildMediaPath($value, $field, $row);
                }
                return null;
            },
            'thumb_width' => \Porter\Filter\NullIfNotImage::class,
        ];
        $this->export(
            'Media',
            "select a.*,
                    filename as Path2,
                    filename as ThumbPath2,
                    128 as thumb_width,
                    FROM_UNIXTIME(f.dateline) as DateInserted,
                    f.userid as userid,
                    f.userid as InsertUserID,
                    if (f.width,f.width,1) as width,
                    if (f.height,f.height,1) as height,
                    n.parentid as ForeignID,
                    f.extension,
                    f.extension as Ext,
                    f.filesize,
                    if(n2.parentid in (" . implode(',', $this->categoryIDs) . "),'discussion','comment') as ForeignTable
                from :_attach a
                    left join :_node n on n.nodeid = a.nodeid
                    left join :_filedata f on f.filedataid = a.filedataid
                    left join :_node n2 on n.parentid = n2.nodeid
                where a.visible = 1",
            $map,
            $filters
        );
        // left join :_contenttype c on n.contenttypeid = c.contenttypeid
    }

    public function conversations(): void
    {
        $map = [
            'nodeid' => 'ConversationID',
            'userid' => 'InsertUserID',
            'totalcount' => 'CountMessages',
            'publishdate' => 'DateInserted',
            'title' => 'Subject',
        ];
        $filters = [
            'publishdate' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Conversation',
            "select n.*, n.nodeid as FirstMessageID
                from :_node n
                left join :_text t on t.nodeid = n.nodeid
                where parentid = {$this->pmNodeID} and t.rawtext <> '';",
            $map,
            $filters
        );

        // Conversation Messages.
        $map = [
            'nodeid' => 'MessageID',
            'rawtext' => 'Body',
            'userid' => 'InsertUserID',
            'publishdate' => 'DateInserted',
            'Format=BBCode',
        ];
        $this->export(
            'ConversationMessage',
            "select n.*, t.rawtext,
                    if(n.parentid<>{$this->pmNodeID},n.parentid,n.nodeid) as ConversationID
                from :_node n
                left join :_contenttype c on n.contenttypeid = c.contenttypeid
                left join :_text t on t.nodeid = n.nodeid
                where c.class = 'PrivateMessage' and t.rawtext <> '';",
            $map,
            $filters
        );

        // User Conversation.
        $map = [
            'userid' => 'UserID',
            'nodeid' => 'ConversationID',
            'deleted' => 'Deleted'
        ];
        // would be nicer to do an intermediary table to sum s.msgread for uc.CountReadMessages
        $this->export('UserConversation', "select * from :_sentto", $map);
    }

    protected function discussion(): void
    {
        $map = [
            'nodeid' => 'DiscussionID',
            'type' => 'Type',
            'title' => 'Name',
            'userid' => 'InsertUserID',
            'rawtext' => 'Body',
            'parentid' => 'CategoryID',
            'lastcontentid' => 'LastCommentID',
            'lastauthorid' => 'LastCommentUserID',
            'count' => 'CountViews',
            //'publishdate' => 'DateInserted',
            'Format=BBCode',
            // htmlstate - on,off,on_nl2br
            // infraction
            // attach
            // reportnodeid
        ];
        $discussionQuery = "select n.nodeid, n.title, n.userid, n.parentid, n.lastcontentid, n.lastauthorid,
                t.rawtext, v.count,
                FROM_UNIXTIME(publishdate) as DateInserted,
                convert(ABS(n.open-1),char(1)) as Closed,
                if(convert(n.sticky,char(1))>0,2,0) as Announce,
                null as type,
                null as PollID
            from :_node n
            left join :_contenttype ct on n.contenttypeid = ct.contenttypeid
            left join :_nodeview v on v.nodeid = n.nodeid
            left join :_text t on t.nodeid = n.nodeid
            where ct.class = 'Text' and n.showpublished = 1
                and parentid in (" . implode(',', $this->categoryIDs) . ");";

        // Polls need to be wrapped in a discussion so we are gonna need to postpone discussion creations
        if ($this->getPollsCount()) {
            // NOTE: Only polls that are directly under a channel (discussion) will be exported.
            // Vanilla poll plugin does not support polls as comments.
            $this->dbInput()->unprepared("drop table if exists vBulletinDiscussionTable;");
            // Create a temporary table to hold old discussions and to create new discussions for polls
            $this->dbInput()->unprepared("create table `vBulletinDiscussionTable` (
                    `nodeid` int(10) unsigned not null AUTO_INCREMENT,
                    `type` varchar(10) default null,
                    `title` varchar(255) default null,
                    `userid` int(10) unsigned default null,
                    `rawtext` mediumtext,
                    `parentid` int(11) not null,
                    `lastcontentid` int(11) not null default '0',
                    `lastauthorid` int(10) unsigned not null default '0',
                    `DateInserted` datetime not null,
                    `CountViews` int(11) not null default '1',
                    `Closed` tinyint(4) not null default '0',
                    `Announce` tinyint(4) not null default '0',
                    `PollID` int(10) unsigned, /* used to create poll->discussion mapping */
                    primary key (`nodeid`) );");
            $this->dbInput()->unprepared("insert into vBulletinDiscussionTable $discussionQuery");
            $this->generatePollsDiscussion();
            $sql = "select nodeid, type, title, userid, rawtext, parentid, lastcontentid, lastauthorid,
                    DateInserted, CountViews,  Closed, Announce
                from vBulletinDiscussionTable";
            $this->export('Discussion', $sql, $map);
        } else {
            $this->export('Discussion', $discussionQuery, $map);
        }
    }

    protected function bookmarks(): void
    {
        $map = [
            'discussionid' => 'DiscussionID',
            'userid' => 'InsertUserID',
            'Bookmarked=1',
        ];
        // Should be able to inner join `discussionread` for DateLastViewed
        // but it's blank in my sample data so I don't trust it.
        $this->export(
            'UserDiscussion',
            "select s.*, NOW() as DateLastViewed from :_subscribediscussion s;",
            $map
        );
    }
}
