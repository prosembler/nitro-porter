<?php

/**
 * vBulletin exporter tool.
 *
 * This will migrate all vBulletin data for 3.x and 4.x forums.
 * It migrates all attachments from 2.x and later.
 *
 * Supports the FileUpload, ProfileExtender, and Signature plugins.
 * All vBulletin data appropriate for those plugins will be prepared
 * and transferred.
 *
 * To export only 1 category, add 'forumid=#' parameter to the URL.
 * To extract avatars stored in database, add 'db-avatars=1' parameter to the URL.
 * To extract attachments stored in db, add 'db-files=1' parameter to the URL.
 * To extract all usermeta data (title, skype, custom profile fields, etc),
 *    add 'usermeta=1' parameter to the URL.
 * To stop the export after only extracting files, add 'files-only=1' param to the URL.
 *
 * TO MIGRATE FILES, BEFORE IMPORTING YOU MUST:
 * 1) Copy entire 'customavatars' folder into Vanilla's /upload folder.
 * 2) Copy entire 'attachments' folder into Vanilla's / upload folder.
 * 3) Make BOTH folders writable by the server.
 * 4) Enable the FileUpload plugin. (Media table must be present.)
 *
 * files-source - Command line option to fix / check files are on disk.  Files named .attach are renamed
 * to the proper name and missing files are reported in missing-files.txt.
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Log;
use Porter\Source;

class VBulletin extends Source
{
    public const INFO = [
        'name' => 'vBulletin 3 & 4',
        'defaultTablePrefix' => 'vb_',
        'charsetTable' => 'post',
    ];

    protected const array FLAGS = [
        'hasDiscussionBody' => false,
    ];

    /* @var string SQL fragment to build new path to attachments. */
    public string $attachSelect = "concat('/vbulletin/', left(f.filehash, 2), '/',
        f.filehash, '_', a.attachmentid,'.', f.extension) as Path";

    /* @var string SQL fragment to build new path to user photo. */
    public string $avatarSelect = "
        case when a.userid is not null then concat('customavatars/',
                a.userid % 100,'/avatar_', a.userid, right(a.filename, instr(reverse(a.filename), '.')))
            when av.avatarpath is not null then av.avatarpath
            else null
        end as customphoto
    ";

    public array $sourceTables = [
        //'attachment','contenttype','customavatar','filedata'
        'deletionlog' => ['type', 'primaryid'],
        'forum' => ['forumid', 'description', 'displayorder', 'title', 'description', 'displayorder'],
        //'phrase' => array('varname','text','product','fieldname','varname'),
        //'pm','pmgroup','pmreceipt','pmtext'
        'post' => ['postid', 'threadid', 'pagetext', 'userid', 'dateline', 'visible'],
        //'setting'
        'subscribethread' => ['userid', 'threadid'],
        'thread' => [
            'threadid',
            'forumid',
            'postuserid',
            'title',
            'open',
            'sticky',
            'dateline',
            'lastpost',
            'visible'
        ],
        //'threadread'
        'user' => [
            'userid',
            'username',
            'password',
            'email',
            'referrerid',
            'timezoneoffset',
            'posts',
            'salt',
            'birthday_search',
            'joindate',
            'lastvisit',
            'lastactivity',
            'membergroupids',
            'usergroupid',
            'usertitle',
            'homepage',
            'styleid',
            'avatarid'
        ],
        'userfield' => ['userid'],
        'usergroup' => ['usergroupid', 'title', 'description'],
    ];

    /**
     * Converts database blobs into files.
     *
     * Creates /attachments and /customavatars folders in the same directory as the export file.
     *
     * @param bool $attachments   Whether to move attachments.
     * @param bool $customAvatars Whether to move avatars.
     */
    public function doFileExport(bool $attachments = true, bool $customAvatars = true): void
    {
        if ($attachments) {
            $identity = 'f.attachmentid';
            $extension = '';
            if ($this->hasInputSchema('attachment', ['contenttypeid', 'contentid'])) {
                $extension = "right(a.filename, instr(reverse(a.filename), '.') - 1)";
                $identity = 'f.filedataid';
            } elseif ($this->hasInputSchema('attach')) {
                $identity = 'f.filedataid';
            } else {
                $extension = "right(filename, instr(reverse(filename), '.') - 1)";
            }

            // Table is dependent on vBulletin version (v4+ is filedata, v3 is attachment)
            $sql = "select f.filedata, $extension as extension,
                   concat('attachments/', f.userid, '/', $identity, '.', lower($extension)) as Path
               from ";
            if ($this->hasInputSchema('attachment', ['contenttypeid', 'contentid'])) {
                $sql .= ":_filedata f left join :_attachment a on a.filedataid = f.filedataid";
            } elseif ($this->hasInputSchema('attach')) {
                $sql .= ":_filedata f left join :_attach a on a.filedataid = f.filedataid";
            } else {
                $sql .= ":_attachment f";
            }
            $this->exportBlobs($sql, 'filedata', 'Path');
        }

        if ($customAvatars) {
            $avatarDataColumn = 'filedata';
            if ($this->hasInputSchema('customavatar', ['avatardata'])) {
                $avatarDataColumn = 'avatardata';
            }
            $sql = "select a.$avatarDataColumn,
                   if (a.userid is not null, concat('customavatars/', a.userid % 100,'/avatar_', a.userid,
                        right(a.filename, instr(reverse(a.filename), '.'))), null
                   ) as customphoto
                from :_customavatar a";
            $sql = str_replace('u.userid', 'a.userid', $sql);
            $this->exportBlobs($sql, $avatarDataColumn, 'customphoto', 80);
        }

        // Export the group icons no matter what.
        if ($this->hasInputSchema('socialgroupicon', 'thumbnail_filedata') && ($attachments || $customAvatars)) {
            $query = "select i.filedata, concat('vb/groupicons/', i.groupid, '.', i.extension) as path 
                from :_socialgroupicon i";
            $this->exportBlobs($query, 'filedata', 'path');
        }
    }

    /**
     * Convert database blobs into files.
     */
    public function exportBlobs(string $sql, string $blobColumn, string $pathColumn, int|bool $thumbnail = false): void
    {
        Log::comment('Exporting blobs...');
        $result = $this->query($sql);
        if (!$result) {
            return;
        }
        $count = 0;
        while ($row = $result->nextResultRow()) {
            // vBulletin attachment hack (can't do this in MySQL)
            if (strpos($row[$pathColumn], '.attach') && str_contains($row[$pathColumn], 'attachments/')) {
                $pathParts = explode('/', $row[$pathColumn]); // 3 parts

                // Split up the userid into a path, digit by digit
                $n = strlen($pathParts[1]);
                $dirParts = [];
                for ($i = 0; $i < $n; $i++) {
                    $dirParts[] = $pathParts[1][$i];
                }
                $pathParts[1] = implode('/', $dirParts);

                // Rebuild full path
                $row[$pathColumn] = implode('/', $pathParts);
            }
            $path = $row[$pathColumn];

            // Build path
            if (!file_exists(dirname($path))) {
                $r = mkdir(dirname($path), 0777, true);
                if (!$r) {
                    die("Could not create " . dirname($path));
                }
            }

            $picPath = '';
            if ($thumbnail) {
                $picPath = str_replace('/avat', '/pavat', $path);
                $fp = fopen($picPath, 'wb');
            } else {
                $fp = fopen($path, 'wb');
            }
            if (!is_resource($fp)) {
                die("Could not open $path.");
            }
            fwrite($fp, $row[$blobColumn]);
            fclose($fp);

            if ($thumbnail) {
                if ($thumbnail === true) {
                    $thumbnail = 50;
                }
                $thumbPath = str_replace('/avat', '/navat', $path);
                self::generateThumbnail($picPath, $thumbPath, $thumbnail, $thumbnail);
            }
            $count++;
        }
        Log::comment("$count Blobs.", false);
    }

    protected function bookmarks(): void
    {
        $minDiscussionWhere = 0;
        if ($this->hasInputSchema('threadread', ['readtime']) === true) {
            $threadReadTime = 'from_unixtime(tr.readtime)';
            $threadReadJoin = 'left join :_threadread as tr on tr.userid = st.userid and tr.threadid = st.threadid';
        } else {
            $threadReadTime = 'now()';
            $threadReadJoin = null;
        }
        $query = "select st.userid as UserID,
                st.threadid as DiscussionID,
                $threadReadTime as DateLastViewed,
                '1' as Bookmarked
            from :_subscribethread as st
                $threadReadJoin
                $minDiscussionWhere";
        $this->export('UserDiscussion', $query);
    }

    /**
     * Export the attachments as Media.
     *
     * In vBulletin 4.x, the filedata table was introduced.
     */
    public function attachments(): void
    {
        // @todo call doFileExport()
        if ($this->hasInputSchema('attachment') !== true) {
            return;
        }
        $instance = $this;
        $media_Map = [
            'attachmentid' => 'MediaID',
            'filename' => 'Name',
            'filesize' => 'Size',
            'userid' => 'InsertUserID',
            'filehash' => 'Path',
            'filethumb' => 'ThumbPath',
            'thumb_width' => 'ThumbWidth',
            'height' => 'ImageHeight',
            'width' => 'ImageWidth',
        ];
        $filters = [
            'filethumb' => function ($value, $field, $row) use ($instance) {
                $filter = new \Porter\Filter\NullIfNotImage($value, $field, $row);
                if ($filter()) {
                    return $instance->buildMediaPath($value, $field, $row);
                }
                return null;
            },
            'filehash' => [$this, 'buildMediaPath'],
            'thumb_width' => \Porter\Filter\NullIfNotImage::class,
            'height' => \Porter\Filter\NullIfNotImage::class,
            'width' => \Porter\Filter\NullIfNotImage::class,
        ];

        // Add hash fields if they exist (from 2.x)
        $attachColumns = ['hash', 'filehash'];
        $hasColumns = $this->hasInputSchema('attachment', $attachColumns);
        $attachColumnsString = '';
        foreach ($attachColumns as $columnName) {
            if (!$hasColumns) {
                $attachColumnsString .= ", null as $columnName";
            } else {
                $attachColumnsString .= ", a.$columnName";
            }
        }
        // Do the export
        if ($this->hasInputSchema('attachment', ['contenttypeid', 'contentid'])) {
            // Build an index to join on.
            if (!$this->indexExists('ix_thread_firstpostid', ':_thread')) {
                $this->dbInput()->unprepared('create index ix_thread_firstpostid on :_thread (firstpostid)');
            }
            // Exporting 4.x with 'filedata' table.
            $mediaSql = "select a.*, f.extension, f.extension as Ext, f.filesize, f.width, f.height,
                    case
                        when t.threadid is not null then 'discussion'
                        when ct.class = 'Post' then 'comment'
                        when ct.class = 'Thread' then 'discussion'
                        else ct.class
                    end as ForeignTable,
                    case
                        when t.threadid is not null then t.threadid
                        else a.contentid
                    end as ForeignID,
                    from_unixtime(a.dateline) as DateInserted
                    $attachColumnsString,
                    'mock_value' as filethumb,
                    128 as thumb_width
                from :_attachment a
                join :_contenttype ct on a.contenttypeid = ct.contenttypeid
                join :_filedata f on f.filedataid = a.filedataid
                left join :_thread t on t.firstpostid = a.contentid and a.contenttypeid = 1
                where a.contentid > 0";
        } else {
            // Exporting 3.x without 'filedata' table.
            // Do NOT grab every field to avoid 'filedata' blob in 3.x.
            // Left join 'attachment' because we can't left join 'thread' on firstpostid (not an index).
            // Lie about the height & width to spoof FileUpload serving generic thumbnail if they aren't set.
            $mediaSql = "select
                    a.attachmentid,
                    a.filename,
                    a.filename as Path/*,*/
                    $attachColumnsString,
                    a.userid,
                    'discussion' as ForeignTable,
                    t.threadid as ForeignID,
                    from_unixtime(a.dateline) as DateInserted,
                    '1' as height,
                    '1' as width,
                    'mock_value' as filethumb,
                    128 as thumb_width
                from :_thread t
                    left join :_attachment a ON a.postid = t.firstpostid
                where a.attachmentid > 0
                union all
                select
                    a.attachmentid,
                    a.filename,
                    a.filename as Path/*,*/
                    $attachColumnsString,
                    a.userid,
                    'comment' as ForeignTable,
                    a.postid as ForeignID,
                    from_unixtime(a.dateline) as DateInserted,
                    '1' as height,
                    '1' as width,
                    'mock_value' as filethumb,
                    128 as thumb_width
                from :_post p
                inner join :_thread t ON p.threadid = t.threadid
                left join :_attachment a ON a.postid = p.postid
                where p.postid <> t.firstpostid and a.attachmentid > 0";
        }
        $this->export('Media', $mediaSql, $media_Map, $filters);

        // files named .attach need to be named properly.
        // file needs to be renamed and db updated.
        // if its an images; we need to include .thumb
        //$attachmentPath = ''; //$this->param('files-source');
        /*if ($attachmentPath) {
            $missingFiles = array();
            if (is_dir($attachmentPath)) {
                $ex->comment("Checking files");
                $result = $ex->query($mediaSql);
                while ($row = $result->nextResultRow()) {
                    $filePath = $this->buildMediaPath('', '', $row);
                    $cdn = ''; //$this->param('cdn', '');

                    if (!empty($cdn)) {
                        $filePath = str_replace($cdn, '', $filePath);
                    }
                    $fullPath = $attachmentPath . $filePath;
                    if (file_exists($fullPath)) {
                        continue;
                    }

                    //check if named .attach
                    $p = explode('.', $fullPath);
                    $attachFilename = str_replace(end($p), 'attach', $fullPath);
                    if (file_exists($attachFilename)) {
                        // rename file
                        rename($attachFilename, $fullPath);
                        continue;
                    }

                    //check if md5 hash in root
                    if (getValue('hash', $row)) {
                        $md5Filename = $attachmentPath . $row['hash'] . '.' . $row['extension'];
                        if (file_exists($md5Filename)) {
                            // rename file
                            rename($md5Filename, $fullPath);
                            continue;
                        }
                    }

                    $missingFiles[] = $filePath;
                }
            } else {
                $ex->comment('Attachment Path not found');
            }
            $totalMissingFiles = count($missingFiles);
            if ($totalMissingFiles > 0) {
                $ex->comment('Missing files detected.  See ./missing_files.txt for full list.');
                $ex->comment(sprintf('Total missing files %d', $totalMissingFiles));
                file_put_contents('missing-files.txt', implode("\n", $missingFiles));
            }
        }*/
    }

    protected function polls(): void
    {
        $poll_Map = [
            'pollid' => 'PollID',
            'question' => 'Name',
            'threadid' => 'DiscussionID',
            'anonymous' => 'Anonymous',
            'dateline' => ['Column' => 'DateInserted', 'Filter' => 'UnixtimeToDate'],
            'postuserid' => 'InsertUserID'
        ];
        $this->export(
            'Poll',
            "select p.*, t.threadid, t.postuserid, !p.public as anonymous
                from :_poll p
                join :_thread t on p.pollid = t.pollid",
            $poll_Map
        );

        // Poll options
        $this->dbInput()->unprepared("drop table if exists zPollOptions;");
        $this->dbInput()->unprepared("create table zPollOptions (
                PollOptionID int(11) NOT NULL AUTO_INCREMENT,
                PollID int(11),
                Body varchar(250),
                Sort int(11),
                DateInserted int(11),
                InsertUserID int(11),
                PRIMARY KEY (`PollOptionID`));");

        $sql = "select p.*, t.postuserid
            from :_poll p
            join :_thread t on p.pollid = t.pollid";
        $r = $this->query($sql);
        $rowCount = 0;
        $sql  = "replace into zPollOptions (
                    PollOptionID,
                    PollID,
                    Body,
                    Sort,
                    DateInserted,
                    InsertUserID
                ) values ";
        if ($r) {
            while ($row = $r->nextResultRow()) {
                $options = explode('|||', $row['options']);
                foreach ($options as $i => $option) {
                    $rowCount++;
                    $option = addslashes($option);

                    $sql .= "(
                        {$rowCount},
                        {$row['pollid']},
                        '{$option}',
                        {$i},
                        {$row['dateline']},
                        {$row['postuserid']}
                    ),";
                }
            }
        }
        if ($rowCount > 0) {
            $this->dbInput()->unprepared(substr($sql, 0, -1));
        }

        $this->export(
            'PollOption',
            "select PollOptionID, PollID, Body, 'BBCdode' as Format, Sort,FROM_UNIXTIME(DateInserted), InsertUserID
            from zPollOptions"
        );

        $this->export(
            'PollVote',
            "select pv.userid as UserID, zp.PollOptionID, pv.pollid
            from :_pollvote pv
            join zPollOptions zp on pv.pollid = zp.PollID and pv.voteoption = zp.sort"
        );
    }

    public function ranks(): void
    {
        $hasRanks = $this->dbInput()->table('ranks')->select()->get()->count();
        if ($hasRanks) {
            $map = [
                'rankid' => 'RankID',
                'rankimg' => 'Name',
            ];
            $this->export(
                'Rank',
                "select rankid, rankimg, rankimg as Label,
                    concat('{\"Criteria\":{\"CountPosts\":\"', minposts, '\"}}') as Attributes
                    from :_ranks
                    where minposts > 0",
                $map
            );
        } else {
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
                    from :_usertitle as ut
                    order by ut.minposts",
                $map,
                $filters
            );
        }
    }

    /**
     * Filter used by $media_Map to build attachment path.
     *
     * vBulletin 3.0+ organizes its attachments by descending 1 level per digit
     * of the userid, named as the attachmentid with a '.attach' extension.
     * Example: User #312's attachments would be in the directory /3/1/2.
     *
     * In vBulletin 2.x, files were stored as an md5 hash in the root
     * attachment directory with a '.file' extension. Existing files were not
     * moved when upgrading to 3.x so older forums will need those too.
     */
    public function buildMediaPath(mixed $value, string $field, array $row): string
    {
        if (!empty($row['hash'])) {
            // Old school! (2.x)
            $filePath = $row['hash'] . '.' . $row['extension'];
        } else { // Newer than 3.0
            // 3.x uses attachmentid, 4.x uses filedataid
            $identity = $row['filedataid'] ?? $row['attachmentid'];

            // @todo restore detection of blob export
            // Build user directory path
            $chars = str_split($row['userid']);
            $dirParts = [];
            foreach ($chars as $char) {
                $dirParts[] = $char;
            }
            // If we're exporting blobs, simplify the folder structure.
            // Otherwise, we need to preserve vBulletin's eleventy subfolders.
            $separator = ''; //$this->param('separator', '');
            $filePath = implode($separator, $dirParts) . '/' . $identity . '.' . $row['extension'];
        }
        return 'attachments/' . $filePath;
    }

    /**
     * Don't allow image dimensions to creep in for non-images.
     */
    public function buildMediaDimension(mixed $value, string $field, array $row): mixed
    {
        // Non-images get no height/width
        if ($this->hasInputSchema('attachment', ['extension'])) {
            $extension = $row['extension'];
        } else {
            $extension = pathinfo($row['filename'], PATHINFO_EXTENSION);
        }
        if (in_array(strtolower($extension), ['jpg', 'gif', 'png', 'jpeg'])) {
            return null;
        }
        return $value;
    }

    /**
     * Filter used by $Media_Map to replace value for ThumbPath and ThumbWidth when the file is not an image.
     */
    public function filterThumbnailData(mixed $value, string $field, array $row): ?string
    {
        $images = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp'];
        if (in_array(strtolower($row['extension']), $images)) {
            return $value;
        }
        return null;
    }

    /**
     * Retrieve a value from the vBulletin setting table.
     *
     * @param string $name Variable for which we want the value.
     * @return mixed Value or FALSE if not found.
     */
    public function getConfig(string $name): mixed
    {
        $sql = "select * from :_setting where varname = '$name'";
        $result = $this->query($sql);
        if ($result && $row = $result->nextResultRow()) {
            return $row['value'];
        }
        return false;
    }

    protected function tags(): void
    {
        $this->export(
            'Tag',
            "select tagid as TagID,
                replace(lower(tagtext), ' ', '-') as Name,
                tagtext as FullName ,
                from_unixtime(dateline) as DateInserted
            from :_tag"
        );
        $this->export(
            'TagDiscussion',
            "select tagid as TagID,
                    threadid as DiscussionID,
                    -1 as CategoryID,
                    from_unixtime(dateline) as DateInserted
                from :_tagthread"
        );
    }

    protected function conversations(): void
    {
        $map = [
            'parentpmid' => 'ConversationID',
            'fromuserid' => 'InsertUserID',
            'dateline' => 'DateInserted',
            'pmtextid' => 'FirstMessageID',
            'title' => 'Subject',
        ];
        $filters = [
            'dateline' => \Porter\Filter\UnixtimeToDate::class,
            'title' => function ($value) {
                return str_replace('Re: ', '', $value);
            }
        ];
        $this->export(
            'Conversation',
            "select p.parentpmid, t.title, t.fromuserid, t.dateline, t.pmtextid
                from (
                    select parentpmid, min(p.pmtextid) as pmtextid
                    from (
                        select pmtextid, parentpmid from :_pm
                        where parentpmid <> 0 
                        group by pmtextid having count(pmtextid) > 1
                    ) p
                    group by parentpmid
                ) p
                join :_pmtext t on t.pmtextid = p.pmtextid",
            $map,
            $filters
        );

        $map = [
            'parentpmid' => 'ConversationID',
            'fromuserid' => 'InsertUserID',
            'dateline' => 'DateInserted',
            'message' => 'Body',
            'Format=BBCode',
        ];
        $filters = [
            'dateline' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'ConversationMessage',
            "select distinct t.pmtextid, p.parentpmid, t.message, t.fromuserid, t.dateline
                from :_pmtext t
                join (
                    select pmtextid, parentpmid
                    from :_pm
                    where parentpmid > 0
                    group by pmtextid having count(pmtextid) > 1
                ) p on t.pmtextid = p.pmtextid",
            $map,
            $filters
        );

        // User Conversation.
        $map = [
            'userid' => 'UserID',
            'parentpmid' => 'ConversationID',
            'messageread' => 'CountReadMessages',
        ];
        $this->export(
            'UserConversation',
            "select userid, parentpmid, messageread
                from :_pm
                where parentpmid > 0
            	group by userid, parentpmid",
            $map
        );
    }

    protected function reactions(): void
    {
        if ($this->hasInputSchema('post_thanks') !== true) {
            return;
        }
        $this->export(
            'UserTag',
            "select if(t.threadid is not null, 'Discussion', 'Comment') as RecordType,
                    if(t.threadid is not null, t.threadid, p.postid) as RecordID,
                    -1 as TagID,
                    p.userid as UserID,
                    from_unixtime(p.date) as DateInserted,
                    1 as Total
                from :_post_thanks p
                left join :_thread t on p.postid = t.firstpostid
                union
                select concat(if(t.threadid is not null, 'Discussion', 'Comment'), '-Total') as RecordType,
                    if(t.threadid is not null, t.threadid, p.postid) as RecordID,
                    -1 as TagID,
                    p.userid as UserID,
                    now() as DateInserted,
                    p.total as Total
                from (select postid, count(postid) as total, min(userid) as userid from :_post_thanks group by postid) p
                left join :_thread t on p.postid = t.firstpostid"
        );
    }

    protected function categories(): void
    {
        $map = [
            'title' => 'Name',
            'forumid' => 'CategoryID',
            'description' => 'Description',
            'parentid' => 'ParentCategoryID',
            'displayorder' => 'Sort',
        ];
        $filters = [
            'title' => \Porter\Filter\DecodeHtml::class,
        ];
        $this->export('Category', "select * from :_forum as f", $map, $filters);
    }

    protected function roles(): void
    {
        $role_Map = [
            'usergroupid' => 'RoleID',
            'title' => 'Name',
            'description' => 'Description'
        ];
        $this->export('Role', 'select * from :_usergroup', $role_Map);

        // UserRoles
        $userRole_Map = [
            'userid' => 'UserID',
            'usergroupid' => 'RoleID'
        ];
        $this->dbInput()->unprepared("drop table if exists VbulletinRoles");
        $this->dbInput()->unprepared("create table VbulletinRoles (
            userid int unsigned not null, usergroupid int unsigned not null)");
        // Put primary groups into tmp table
        $this->dbInput()->unprepared("insert into VbulletinRoles (userid, usergroupid) 
            select userid, usergroupid from :_user");
        // Put stupid CSV column into tmp table
        $secondaryRoles = $this->query("select userid, usergroupid, membergroupids from :_user");
        if (is_object($secondaryRoles)) {
            while ($row = $secondaryRoles->nextResultRow()) {
                if ($row['membergroupids'] != '') {
                    $groups = explode(',', $row['membergroupids']);
                    foreach ($groups as $groupID) {
                        if (!$groupID) {
                            continue;
                        }
                        $this->dbInput()->unprepared("insert into VbulletinRoles (userid, usergroupid)
                            values({$row['userid']},{$groupID})");
                    }
                }
            }
        }
        // Export from our tmp table and drop
        $this->export('UserRole', 'select distinct userid, usergroupid from VbulletinRoles', $userRole_Map);
        $this->dbInput()->unprepared("drop table if exists VbulletinRoles");
    }

    protected function users(): void
    {
        $hasRanks = $this->dbInput()->table('ranks')->select()->get()->count();
        if ($hasRanks) {
            $ranks = $this->dbInput()->table('ranks')->select(['minposts'])
                ->where('minposts', '>', 0)
                ->orderBy('minposts', 'desc')
                ->get();
        } else {
            $ranks = $this->dbInput()->table('usertitle')->select()
                ->selectRaw('usertitleid as RankID')
                ->orderBy('minposts', 'desc')
                ->get();
        }

        $map = [
            'userid' => 'UserID',
            'username' => 'Name',
            'email' => 'Email',
            'referrerid' => 'InviteUserID',
            'usertitle' => 'Title',
            'posts' => 'RankID',
            'joindate' => 'DateInserted',
            'lastvisit' => 'DateLastActive',
            'lastactivity' => 'DateUpdated', // ?
            'HashMethod=vbulletin',
            // Use file avatar or the result of our blob export?
            $this->getConfig('usefileavatar') ? 'filephoto' : 'customphoto' => 'Photo',
        ];
        $filters = [
            'joindate' => \Porter\Filter\UnixtimeToDate::class,
            'lastvisit' => \Porter\Filter\UnixtimeToDate::class,
            'lastactivity' => \Porter\Filter\UnixtimeToDate::class,
            'usertitle' => function ($value) {
                return trim(strip_tags(str_replace('&nbsp;', ' ', $value)));
            },
            'posts' => function ($value) use ($ranks) {
                // Look up the posts in the ranks table.
                foreach ($ranks as $row) {
                    if ($value >= $row->minposts) {
                        return $row->rankID;
                    }
                }
                return null;
            },
        ];
        $this->export(
            'User',
            "select u.userid, u.username, u.email, u.referrerid, u.usertitle, u.posts, 
                joindate, lastvisit, lastactivity, concat(`password`, salt) as Password,
                date_format(birthday_search, get_format(DATE, 'ISO')) as DateOfBirth,
                case when avatarrevision > 0
                        then concat('userpics/avatar', u.userid, '_', avatarrevision, '.gif')
                     when av.avatarpath is not null then av.avatarpath
                     else null
                     end as filephoto,
                {$this->avatarSelect},
                case when ub.userid is not null then 1 else 0 end as Banned
            from :_user u
            left join :_customavatar a on u.userid = a.userid
            left join :_avatar av on u.avatarid = av.avatarid
            left join :_userban ub on u.userid = ub.userid and ub.liftdate <= now()",
            $map,
            $filters
        );
        $this->userMeta();
    }

    protected function userMeta(): void
    {
        $this->dbInput()->unprepared("drop table if exists VbulletinUserMeta");
        $this->dbInput()->unprepared("create table VbulletinUserMeta(
                `UserID` int not null,
                `Name` varchar(255) not null,
                `Value` text not null);");
        // Standard vB user data
        $userFields = [
            'usertitle' => 'Title',
            'homepage' => 'Website',
            'styleid' => 'StyleID'
        ];

        foreach ($userFields as $field => $insertAs) {
            $this->dbInput()->unprepared("insert into VbulletinUserMeta (UserID, Name, Value)
                    select userid, 'Profile.$insertAs', $field
                    from :_user where $field != '' and $field != 'http://'
                    union 
                    select userid as UserID, concat('Preferences.Popup.NewComment.', forumid), 1 as Value
                    from :_subscribeforum
                    union 
                    select userid as UserID, concat('Preferences.Popup.NewDiscussion.', forumid), 1 as Value
                    from :_subscribeforum
                    union 
                    select userid as UserID, concat('Preferences.Email.NewComment.', forumid), 1 as Value
                    from :_subscribeforum where emailupdate > 1
                    union 
                    select userid as UserID, concat('Preferences.Email.NewDiscussion.', forumid), 1 as Value
                    from :_subscribeforum where emailupdate > 1");
        }

        if ($this->hasInputSchema('phrase', ['product', 'fieldname']) === true) {
            // Dynamic vB user data (userfield)
            $profileFields = $this->query("select distinct varname, text
                from :_phrase
                where product='vbulletin'
                    and fieldname='cprofilefield'
                    and varname like 'field%_title'");
            if (is_object($profileFields)) {
                $profileQueries = [];
                while ($field = $profileFields->nextResultRow()) {
                    $column = str_replace('_title', '', $field['varname']);
                    $name = preg_replace('/[^a-zA-Z0-9\s_-]/', '', $field['text']);
                    $profileQueries[] = "
                        insert into VbulletinUserMeta(UserID, Name, Value)
                        select userid, 'Profile." . $name . "', " . $column . "
                        from :_userfield
                        where " . $column . " != ''";
                }
                foreach ($profileQueries as $query) {
                    $this->dbInput()->unprepared($query);
                }
            }
        }

        // Users meta informations
        $this->export(
            'UserMeta',
            "select userid as UserID, 'Plugin.Signatures.Sig' as Name, signature as Value
                from :_usertextfield
                where nullif(signature, '') is not null
                union
                select userid, 'Plugin.Signatures.Format', 'BBCode'
                from :_usertextfield
                where nullif(signature, '') is not null
                union
                select * from VbulletinUserMeta"
        );
        $this->categories();
    }

    protected function comments(): void
    {
        $excludeFirstPost = '';
        $joinThreads = '';
        if ($this->getDiscussionBodyMode()) {
            // Don't export the OP, it would be redundant.
            $excludeFirstPost = 'p.postid <> t.firstpostid and';
            $joinThreads = 'inner join :_thread as t on p.threadid = t.threadid';
        }
        $map = [
            'postid' => 'CommentID',
            'threadid' => 'DiscussionID',
            'userid' => 'InsertUserID',
            'pagetext' => 'Body',
            'Format=BBCode',
        ];
        $filters = [
            'dateline' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Comment',
            "select p.postid, p.threadid, p.pagetext, p.dateline
                from :_post as p 
                $joinThreads
                left join :_deletionlog as d on (d.type='post' and d.primaryid=p.postid)
                where $excludeFirstPost d.primaryid is null and p.visible = 1",
            $map,
            $filters
        );
    }

    protected function wallposts(): void
    {
        // Activity (from visitor messages in vBulletin 3.8+)
        if (!$this->hasInputSchema('visitormessage') === true) {
            return;
        }
        $map = [
            'postuserid' => 'RegardingUserID',
            'userid' => 'ActivityUserID',
            'pagetext' => 'Story',
            'dateline' => 'DateInserted',
            'NotifyUserID=-1',
            'ActivityType=WallPost',
            'Format=BBCode',
        ];
        $filters = [
            'dateline' => \Porter\Filter\UnixtimeToDate::class,
            'DateUpdated' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Activity',
            "select vm.*, vm.postuserid as InsertUserID, vm.dateline as DateUpdated  
                '{RegardingUserID,you} &rarr; {ActivityUserID,you}' as HeadlineFormat
                from :_visitormessage as vm
                where state='visible'",
            $map,
            $filters
        );
    }

    protected function discussions(): void
    {
        $map = [
            'threadid' => 'DiscussionID',
            'forumid' => 'CategoryID',
            'postuserid' => 'InsertUserID',
            'dateline' => 'DateInserted',
            'lastpost' => 'DateLastComment',
            'title' => 'Name',
            'pagetext' => 'Body',
            'views' => 'CountViews',
            'Format=BBCode',
        ];
        $filters = [
            'dateline' => \Porter\Filter\UnixtimeToDate::class,
            'title' => \Porter\Filter\UnixtimeToDate::class,
            'lastpost' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Discussion',
            "select t.threadid, t.forumid, t.postuserid, t.views, t.title, t.dateline, lastpost,
                    p.postid as ForeignID, p.pagetext,
                    replycount+1 as CountComments,
                    convert(ABS(open-1), char(1)) as Closed,
                    if(convert(sticky, char(1)) > 0, 2, 0) as Announce,
                    if (t.pollid > 0, 'Poll', null) as Type
                from :_thread as t
                left join :_deletionlog as d on d.type='thread' and d.primaryid=t.threadid
                left join :_post as p on p.postid = t.firstpostid
                where d.primaryid is null and t.visible = 1",
            $map,
            $filters
        );
    }
}
