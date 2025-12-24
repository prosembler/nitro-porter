<?php

/**
 * MVC exporter tool.
 *
 * Creates indexes & primary keys on current tables to accelerate the export process.
 * Initial ids are varchar, which can make the queries hang when joining or using some columns in conditions.
 * Ignores the creation if the index & keys if they already exist.
 *
 * @author  Olivier Lamy-Canuel
 */

namespace Porter\Source;

use Porter\Source;
use Porter\Migration;

class Mvc extends Source
{
    public const SUPPORTED = [
        'name' => 'MVC',
        'defaultTablePrefix' => '',
        'charsetTable' => 'Post',
        'features' => [
            'Users' => 1,
            'Passwords' => 0,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Roles' => 1,
            'Avatars' => 1,
            'PrivateMessages' => 0,
            'Signatures' => 1,
            'Attachments' => 1,
            'Bookmarks' => 0,
            'Badges' => 1,
            'Tags' => 1,
        ]
    ];

    /**
     * You can use this to require certain tables and columns be present.
     *
     * @var array Required tables => columns
     */
    public array $sourceTables = array(
        'MembershipUser' => array(),
        'Catagory' => array(),
        'Post' => array(),
        'Topic' => array(),
    );

    /**
     * Main export process.
     *
     * @param Migration $port
     */
    public function run(Migration $port): void
    {
        $this->users($port);
        $this->userMeta($port);
        $this->roles($port);
        $this->badges($port);

        $this->categories($port);
        $this->discussions($port);
        $this->comments($port);
        $this->tags($port);
        $this->attachments($port);
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        if (!$port->hasInputSchema('MembershipUser', 'UserID')) {
            $port->query("alter table :_MembershipUser add column UserID int(11) primary key auto_increment");
        }
        if (!$port->indexExists('mvc_users_id', ':_MembershipUser')) {
            $port->query("create INDEX mvc_users_id on :_MembershipUser(Id);");
        }
        $port->export(
            'User',
            "select
                    UserID,
                    UserName as Name,
                    'Reset' as HashMethod,
                    Email as Email,
                    Avatar as Photo,
                    CreateDate as DateInserted,
                    LastLoginDate as DateLastVisit,
                    LastActivityDate as DateLastActive,
                    IsBanned as Banned,
                    Location as Location
                from :_MembershipUser m"
        );
    }

    /**
     * @param Migration $port
     */
    protected function userMeta(Migration $port): void
    {
        $port->export(
            'UserMeta',
            "select
                    UserID,
                    'Website' as `Name`,
                    Website as `Value`
                from :_MembershipUser m
                where m.Website <> ''
                union
                select
                    UserID,
                    'Signatures.Sig',
                    Signature
                from :_MembershipUser m
                where m.Signature <> ''"
        );
    }

    /**
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        if (!$port->hasInputSchema('MembershipRole', 'RoleID')) {
            $port->query("alter table :_MembershipRole add column RoleID int(11) primary key auto_increment");
        }
        if (!$port->indexExists('mvc_role_id', ':_MembershipRole')) {
            $port->query("create INDEX mvc_role_id on `:_MembershipRole` (Id);");
        }
        $port->export(
            'Role',
            "select RoleID, RoleName as Name from :_MembershipRole"
        );

        // User Role.
        $port->export(
            'UserRole',
            "select
                    u.UserID as UserID,
                    r.RoleID as RoleID
                from :_MembershipUsersInRoles m,  :_MembershipRole r, :_MembershipUser u
                where r.RoleID = m.RoleIdentifier and u.UserID = m.UserIdentifier"
        );
    }

    /**
     * @param Migration $port
     */
    protected function badges(Migration $port): void
    {
        if (!$port->hasInputSchema('Badge', 'BadgeID')) {
            $port->query("alter table :_Badge add column BadgeID int(11) primary key auto_increment");
        }
        if (!$port->indexExists('mvc_badge_id', ':_Badge')) {
            $port->query("create INDEX mvc_badge_id on `:_Badge` (Id);");
        }
        $port->export(
            'Badge',
            "select
                    BadgeID,
                    Type as Type,
                    DisplayName as Name,
                    Description as Body,
                    Image as Photo,
                    AwardsPoints as Points
                from :_Badge"
        );

        $port->export(
            'UserBadge',
            "select
                    u.UserID,
                    b.BadgeID,
                    '' as Status,
                    now() as DateInserted
                from :_MembershipUser_Badge m, :_MembershipUser u, :_Badge b
                where u.UserID = m.MembershipUser_Id and b.BadgeID = m.Badge_Id"
        );
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
        if (!$port->hasInputSchema('Category', 'CategoryID')) {
            $port->query("alter table :_Category add column CategoryID int(11) primary key auto_increment");
        }
        if (!$port->indexExists('mvc_category_id', ':_Category')) {
            $port->query("create INDEX mvc_category_id on `:_Category` (Id);");
        }
        $port->export(
            'Category',
            "select
                    m.CategoryID,
                    p.CategoryID as ParentCategoryID,
                    m.Name as Name,
                    m.Description as Description,
                    m.DateCreated as DateInserted,
                    null as Sort
                from Category m, Category p
                where m.Category_Id <> '' and p.CategoryID = m.Category_Id
                union
                select
                    m.CategoryID,
                    '-1' as ParentCategoryID,
                    m.Name as Name,
                    m.Description as Description,
                    m.DateCreated as DateInserted,
                    null as Sort
                from Category m
                where m.Category_Id = ''"
        );
    }

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        if (!$port->hasInputSchema('Topic', 'TopicID')) {
            $port->query("alter table :_Topic add column TopicID int(11) primary key auto_increment");
        }
        if (!$port->indexExists('mvc_topic_id', ':_Topic')) {
            $port->query("create INDEX mvc_topic_id on `:_Topic` (Id);");
        }
        if (!$port->indexExists('mvc_topic_id', ':_Topic')) {
            $port->query("create INDEX mvc_topic_membershipuser_id on `:_Topic` (MembershipUser_Id);");
        }
        if (!$port->indexExists('mvc_topic_id', ':_Topic')) {
            $port->query("create INDEX mvc_topic_category_id on `:_Topic` (Category_Id);");
        }
        $port->export(
            'Discussion',
            "select
                    m.TopicID as DiscussionID,
                    c.CategoryID as CategoryID,
                    u.UserID as InsertUserID,
                    m.CreateDate as DateInserted,
                    m.Name as Name,
                    m.Views as CountViews,
                    'Html' as Format
                from :_Topic m
                left join :_MembershipUser u on u.Id = m.MembershipUser_Id
                left join :_Category c on c.Id = m.Category_Id"
        );
    }

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        if (!$port->hasInputSchema('Post', 'PostID')) {
            $port->query("alter table :_Post add column PostID int(11) primary key auto_increment");
        }
        if (!$port->indexExists('mvc_post_id', ':_Post')) {
            $port->query("create INDEX mvc_post_id on `:_Post` (Id);");
        }
        if (!$port->indexExists('mvc_post_id', ':_Post')) {
            $port->query("create INDEX mvc_post_topic_id on `:_Post` (Topic_Id);");
        }
        if (!$port->indexExists('mvc_post_id', ':_Post')) {
            $port->query("create INDEX mvc_post_membershipuser_id on `:_Post` (MembershipUser_Id);");
        }
        $port->export(
            'Comment',
            "select
                    m.PostID as CommentID,
                    d.TopicID as DiscussionID,
                    u.UserID as InsertUserID,
                    m.PostContent as Body,
                    m.DateCreated as DateInserted,
                    m.DateEdited as DateUpdated,
                    'Html' as Format
                from :_Post m
                left join :_Topic d on d.Id = m.Topic_Id
                left join :_MembershipUser u on u.Id = m.MembershipUser_Id"
        );
    }

    /**
     * @param Migration $port
     */
    protected function tags(Migration $port): void
    {
        if (!$port->hasInputSchema('TopicTag', 'TagID')) {
            $port->query("alter table :_TopicTag add column TagID int(11) primary key auto_increment");
        }
        if (!$port->indexExists('mvc_tag_id', ':_TopicTag')) {
            $port->query("create INDEX mvc_tag_id on `:_TopicTag` (Id);");
        }
        $port->export(
            'Tag',
            "select
                    TagID,
                    Tag as Name,
                    Tag as FullName,
                    now() as DateInserted
                from TopicTag"
        );
    }

    /**
     * @param Migration $port
     */
    protected function attachments(Migration $port): void
    {
        if (!$port->hasInputSchema('UploadedFile', 'MediaID')) {
            $port->query("alter table :_UploadedFile add column MediaID int(11) primary key auto_increment");
        }
        if (!$port->indexExists('mvc_file_id', ':_UploadedFile')) {
            $port->query("create INDEX mvc_file_id on `:_UploadedFile` (Id);");
        }
        // Use of placeholder for Type and Size due to lack of data in db.
        // Will require external script to get the info.
        $port->export(
            'Attachment',
            "select
                    MediaID,
                    Filename as Name,
                    concat('attachments/', u.Filename) as Path,
                    '' as Type,
                    0 as Size,
                    MembershipUser_Id InsertUserID,
                    u.DateCreated as DateInserted
                from :_UploadedFile u
                where u.Post_Id <> '' and m.Id = u.Id"
        );
    }
}
