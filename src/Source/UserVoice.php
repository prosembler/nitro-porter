<?php

/**
 * User Voice exporter tool
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Log;
use Porter\Source;

class UserVoice extends Source
{
    public const array INFO = [
        'name' => 'User Voice',
        'defaultTablePrefix' => 'cs_',
        'charsetTable' => 'Threads',
    ];

    /**
     * Avatars are hex-encoded in the database.
     */
    public function avatars(): void
    {
        $thumbnail = true;
        Log::comment("Exporting hex encoded columns...");
        $result = $this->query("select UserID, Length, ContentType, Content from :_UserAvatar");
        if (!$result) {
            return;
        }
        // @todo convert to filemap!
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
            self::generateThumbnail($photoPath, $thumbPath, $thumbnail, $thumbnail);
            $count++;
        }
        Log::comment("$count Hex Encoded.", false);
    }

    public function attachments(): void
    {
        $Media_Map = [
            'FileName' => 'Name',
            'ContentType' => 'Type',
            'ContentSize' => 'Size',
            'UserID' => 'InsertUserID',
            'Created' => 'DateInserted',
        ];
        $this->export('Media', "
           select a.*,
              if(p.SortOrder = 1, 'Discussion', 'Comment') as ForeignTable,
              if(p.SortOrder = 1, p.ThreadID, a.PostID) as ForeignID,
              concat('import/attach/', a.FileName) as Path
           from :_PostAttachments a
           left join :_Posts p on p.PostID = a.PostID
           where IsRemote = 0", $Media_Map);
        Log::comment("Exporting hex encoded columns...");
        $result = $this->query("select a.*, p.PostID
                from :_PostAttachments a
                left join :_Posts p on p.PostID = a.PostID
                where IsRemote = 0");
        if (!$result) {
            return;
        }
        // @todo convert to filemap!
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
        Log::comment("$count Hex Encoded.", false);
    }

    protected function users(): void
    {
        $map = [
            'LastActivity' => 'DateLastActive',
            'UserName' => 'Name',
            'CreateDate' => 'DateInserted',
            'HashMethod=django',
        ];
        $filter = [
            'UserName' => \Porter\Filter\DecodeHtml::class,
        ];
        $this->export(
            'User',
            "select u.*,
                    concat('sha1$', m.PasswordSalt, '$', m.Password) as Password,
                    if(a.Content is not null, concat('import/userpics/avatar',u.UserID,'.jpg'), NULL) as Photo
                from :_Users u
                left join aspnet_Membership m on m.UserId = u.MembershipID
                left join :_UserAvatar a on a.UserID = u.UserID",
            $map,
            $filter
        );
    }

    protected function roles(): void
    {
        $map = [
            'RoleId' => 'RoleID',
            'RoleName' => 'Name',
        ];
        $filters = [
            'RoleId' => \Porter\Filter\UserVoiceRoleID::class,
        ];
        $this->export('Role', "select * from aspnet_Roles", $map, $filters);

        // User Role.
        $userRole_Map = [
            'RoleId' => 'RoleID',
        ];
        $this->export(
            'UserRole',
            "select u.UserID, ur.RoleId
                from aspnet_UsersInRoles ur
                left join :_Users u on ur.UserId = u.MembershipID",
            $userRole_Map,
            $filters
        );
    }

    protected function categories(): void
    {
        $category_Map = [
            'SectionID' => 'CategoryID',
            'ParentID' => 'ParentCategoryID',
            'SortOrder' => 'Sort',
            'DateCreated' => 'DateInserted'
        ];
        $this->export(
            'Category',
            "select s.* from :_Sections s",
            $category_Map
        );
    }

    protected function discussions(): void
    {
        $map = [
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
            'Subject' => 'Name',
            'Body' => 'Body',
            'IPAddress' => 'InsertIPAddress',
            'Format=Html',
        ];
        $filters = [
            'Subject' => \Porter\Filter\DecodeHtml::class,
            'Body' => \Porter\Filter\DecodeHtml::class,
        ];
        $this->export(
            'Discussion',
            "select t.*, p.Subject, p.Body, if(t.IsSticky  > 0, 2, 0) as Announce
                from :_Threads t
                left join :_Posts p on p.ThreadID = t.ThreadID
                where p.SortOrder = 1",
            $map,
            $filters
        );
    }

    protected function comments(): void
    {
        $map = [
            'PostID' => 'CommentID',
            'ThreadID' => 'DiscussionID',
            'UserID' => 'InsertUserID',
            'IPAddress' => 'InsertIPAddress',
            'PostDate' => 'DateInserted'
        ];
        $filters = [
            'Body' => \Porter\Filter\DecodeHtml::class,
        ];
        $this->export(
            'Comment',
            "select p.* from :_Posts p where SortOrder > 1",
            $map,
            $filters
        );
    }

    protected function bookmarks(): void
    {
        $map = [
            'ThreadID' => 'DiscussionID',
            'Bookmarked=1',
        ];
        $this->export(
            'UserDiscussion',
            "select t.*, NOW() as DateLastViewed from :_TrackedThreads t",
            $map
        );
    }
}
