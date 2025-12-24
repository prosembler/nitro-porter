<?php

/**
 * User Voice exporter tool
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Log;
use Porter\Source;
use Porter\Migration;

class UserVoice extends Source
{
    public const SUPPORTED = [
        'name' => 'User Voice',
        'defaultTablePrefix' => 'cs_',
        'charsetTable' => 'Threads',
        'features' => [
            'Users' => 1,
            'Passwords' => 1,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 0,
            'Roles' => 1,
            'Avatars' => 1,
            'PrivateMessages' => 0,
            'Signatures' => 1,
            'Attachments' => 0,
            'Bookmarks' => 1,
        ]
    ];

    /**
     * Main export method.
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
        $this->bookmarks($port);

        //$this->attachments();
        // Decode files in database.
        $this->exportHexAvatars($port);
        //$this->ExportHexAttachments($ex);
    }

    /**
     * Role IDs are crazy hex strings of hyphenated octets.
     * Create an integer RoleID using the first 4 characters.
     *
     * @param  string $roleID
     * @return int
     */
    public function roleIDConverter($roleID): int
    {
        return hexdec(substr($roleID, 0, 4));
    }

    /**
     * Avatars are hex-encoded in the database.
     */
    public function exportHexAvatars(Migration $port): void
    {
        $thumbnail = true;
        Log::comment("Exporting hex encoded columns...");

        $result = $port->query("select UserID, Length, ContentType, Content from :_UserAvatar");
        $path = '/www/porter/userpics';
        $count = 0;

        while ($row = $result->nextResultRow()) {
            // Build path
            if (!file_exists(dirname($path))) {
                $r = mkdir(dirname($path), 0777, true);
                if (!$r) {
                    die("Could not create " . dirname($path));
                }
            }

            $photoPath = $path . '/pavatar' . $row['UserID'] . '.jpg';
            file_put_contents($photoPath, hex2bin($row['Content']));

            if ($thumbnail === true) {
                $thumbnail = 50;
            }

            //$PicPath = str_replace('/avat', '/pavat', $photoPath);
            $thumbPath = str_replace('/pavat', '/navat', $photoPath);
            generateThumbnail($photoPath, $thumbPath, $thumbnail, $thumbnail);
            $count++;
        }
        //$port->status("$count Hex Encoded.\n");
        Log::comment("$count Hex Encoded.", false);
    }

    /**
     *
     */
    public function exportHexAttachments(Migration $port): void
    {
        Log::comment("Exporting hex encoded columns...");

        $result = $port->query(
            "select a.*, p.PostID
                from :_PostAttachments a
                left join :_Posts p on p.PostID = a.PostID
                where IsRemote = 0"
        );
        $path = '/www/porter/attach';
        $count = 0;

        while ($row = $result->nextResultRow()) {
            // Build path
            if (!file_exists(dirname($path))) {
                $r = mkdir(dirname($path), 0777, true);
                if (!$r) {
                    die("Could not create " . dirname($path));
                }
            }

            file_put_contents($path . '/' . $row['FileName'], hex2bin($row['Content']));
            $count++;
        }
        //$port->status("$count Hex Encoded.\n");
        Log::comment("$count Hex Encoded.", false);
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        $user_Map = array(
            'LastActivity' => array('Column' => 'DateLastActive'),
            'UserName' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
            'CreateDate' => array('Column' => 'DateInserted'),
        );
        $port->export(
            'User',
            "select u.*,
                    concat('sha1$', m.PasswordSalt, '$', m.Password) as Password,
                    'django' as HashMethod,
                    if(a.Content is not null, concat('import/userpics/avatar',u.UserID,'.jpg'), NULL) as Photo
                from :_Users u
                left join aspnet_Membership m on m.UserId = u.MembershipID
                left join :_UserAvatar a on a.UserID = u.UserID",
            $user_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        $role_Map = array(
            'RoleId' => array('Column' => 'RoleID', 'Filter' => array($this, 'roleIDConverter')),
            'RoleName' => 'Name'
        );
        $port->export(
            'Role',
            "select * from aspnet_Roles",
            $role_Map
        );

        // User Role.
        $userRole_Map = array(
            'RoleId' => array('Column' => 'RoleID', 'Filter' => array($this, 'roleIDConverter')),
        );
        $port->export(
            'UserRole',
            "select u.UserID, ur.RoleId
                from aspnet_UsersInRoles ur
                left join :_Users u on ur.UserId = u.MembershipID",
            $userRole_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
        $category_Map = array(
            'SectionID' => 'CategoryID',
            'ParentID' => 'ParentCategoryID',
            'SortOrder' => 'Sort',
            'DateCreated' => 'DateInserted'
        );
        $port->export(
            'Category',
            "select s.* from :_Sections s",
            $category_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        $discussion_Map = array(
            'ThreadID' => 'DiscussionID',
            'SectionID' => 'CategoryID',
            'UserID' => 'InsertUserID',
            'PostDate' => 'DateInserted',
            'ThreadDate' => 'DateLastComment',
            'TotalViews' => 'CountViews',
            'TotalReplies' => 'CountComments',
            'IsLocked' => 'Closed',
            'MostRecentPostAuthorID' => 'LastCommentUserID',
            'MostRecentPostID' => 'LastCommentID',
            'Subject' => array('Column' => 'Name', 'Filter' => 'HTMLDecoder'),
            'Body' => array('Column' => 'Body', 'Filter' => 'HTMLDecoder'),
            'IPAddress' => 'InsertIPAddress'
        );
        $port->export(
            'Discussion',
            "select t.*,
                    p.Subject,
                    p.Body,
                    'Html' as Format,
                    p.IPAddress as InsertIPAddress,
                    if(t.IsSticky  > 0, 2, 0) as Announce
                from :_Threads t
                left join :_Posts p on p.ThreadID = t.ThreadID
                where p.SortOrder = 1",
            $discussion_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        $comment_Map = array(
            'PostID' => 'CommentID',
            'ThreadID' => 'DiscussionID',
            'UserID' => 'InsertUserID',
            'IPAddress' => 'InsertIPAddress',
            'Body' => array('Column' => 'Body', 'Filter' => 'HTMLDecoder'),
            'PostDate' => 'DateInserted'
        );
        $port->export(
            'Comment',
            "select p.* from :_Posts p where SortOrder > 1",
            $comment_Map
        );
    }

    /**
     * @param Migration $port
     */
    protected function bookmarks(Migration $port): void
    {
        $userDiscussion_Map = array(
            'ThreadID' => 'DiscussionID'
        );
        $port->export(
            'UserDiscussion',
            "select t.*,
                    '1' as Bookmarked,
                    NOW() as DateLastViewed
                from :_TrackedThreads t",
            $userDiscussion_Map
        );
    }

    /*protected function attachments(): void
    {
        $Media_Map = array(
           'FileName' => 'Name',
           'ContentType' => 'Type',
           'ContentSize' => 'Size',
           'UserID' => 'InsertUserID',
           'Created' => 'DateInserted'
        );
        $ex->ExportTable('Media', "
           select a.*,
              if(p.SortOrder = 1, 'Discussion', 'Comment') as ForeignTable,
              if(p.SortOrder = 1, p.ThreadID, a.PostID) as ForeignID,
              concat('import/attach/', a.FileName) as Path
           from :_PostAttachments a
           left join :_Posts p on p.PostID = a.PostID
           where IsRemote = 0", $Media_Map);
    }*/
}
