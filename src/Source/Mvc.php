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
     */
    public function run(): void
    {
        $this->users();
        $this->userMeta();
        $this->roles();
        $this->badges();

        $this->categories();
        $this->discussions();
        $this->comments();
        $this->tags();
        $this->attachments();
    }

    /**
     */
    protected function users(): void
    {
        if (!$this->hasInputSchema('MembershipUser', 'UserID')) {
            $this->query("alter table :_MembershipUser add column UserID int(11) primary key auto_increment");
        }
        if (!$this->indexExists('mvc_users_id', ':_MembershipUser')) {
            $this->query("create INDEX mvc_users_id on :_MembershipUser(Id);");
        }
        $this->export(
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
     */
    protected function userMeta(): void
    {
        $this->export(
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
     */
    protected function roles(): void
    {
        if (!$this->hasInputSchema('MembershipRole', 'RoleID')) {
            $this->query("alter table :_MembershipRole add column RoleID int(11) primary key auto_increment");
        }
        if (!$this->indexExists('mvc_role_id', ':_MembershipRole')) {
            $this->query("create INDEX mvc_role_id on `:_MembershipRole` (Id);");
        }
        $this->export(
            'Role',
            "select RoleID, RoleName as Name from :_MembershipRole"
        );

        // User Role.
        $this->export(
            'UserRole',
            "select
                    u.UserID as UserID,
                    r.RoleID as RoleID
                from :_MembershipUsersInRoles m,  :_MembershipRole r, :_MembershipUser u
                where r.RoleID = m.RoleIdentifier and u.UserID = m.UserIdentifier"
        );
    }

    /**
     */
    protected function badges(): void
    {
        if (!$this->hasInputSchema('Badge', 'BadgeID')) {
            $this->query("alter table :_Badge add column BadgeID int(11) primary key auto_increment");
        }
        if (!$this->indexExists('mvc_badge_id', ':_Badge')) {
            $this->query("create INDEX mvc_badge_id on `:_Badge` (Id);");
        }
        $this->export(
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

        $this->export(
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
     */
    protected function categories(): void
    {
        if (!$this->hasInputSchema('Category', 'CategoryID')) {
            $this->query("alter table :_Category add column CategoryID int(11) primary key auto_increment");
        }
        if (!$this->indexExists('mvc_category_id', ':_Category')) {
            $this->query("create INDEX mvc_category_id on `:_Category` (Id);");
        }
        $this->export(
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
     */
    protected function discussions(): void
    {
        if (!$this->hasInputSchema('Topic', 'TopicID')) {
            $this->query("alter table :_Topic add column TopicID int(11) primary key auto_increment");
        }
        if (!$this->indexExists('mvc_topic_id', ':_Topic')) {
            $this->query("create INDEX mvc_topic_id on `:_Topic` (Id);");
        }
        if (!$this->indexExists('mvc_topic_id', ':_Topic')) {
            $this->query("create INDEX mvc_topic_membershipuser_id on `:_Topic` (MembershipUser_Id);");
        }
        if (!$this->indexExists('mvc_topic_id', ':_Topic')) {
            $this->query("create INDEX mvc_topic_category_id on `:_Topic` (Category_Id);");
        }
        $this->export(
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
     */
    protected function comments(): void
    {
        if (!$this->hasInputSchema('Post', 'PostID')) {
            $this->query("alter table :_Post add column PostID int(11) primary key auto_increment");
        }
        if (!$this->indexExists('mvc_post_id', ':_Post')) {
            $this->query("create INDEX mvc_post_id on `:_Post` (Id);");
        }
        if (!$this->indexExists('mvc_post_id', ':_Post')) {
            $this->query("create INDEX mvc_post_topic_id on `:_Post` (Topic_Id);");
        }
        if (!$this->indexExists('mvc_post_id', ':_Post')) {
            $this->query("create INDEX mvc_post_membershipuser_id on `:_Post` (MembershipUser_Id);");
        }
        $this->export(
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
     */
    protected function tags(): void
    {
        if (!$this->hasInputSchema('TopicTag', 'TagID')) {
            $this->query("alter table :_TopicTag add column TagID int(11) primary key auto_increment");
        }
        if (!$this->indexExists('mvc_tag_id', ':_TopicTag')) {
            $this->query("create INDEX mvc_tag_id on `:_TopicTag` (Id);");
        }
        $this->export(
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
     */
    protected function attachments(): void
    {
        if (!$this->hasInputSchema('UploadedFile', 'MediaID')) {
            $this->query("alter table :_UploadedFile add column MediaID int(11) primary key auto_increment");
        }
        if (!$this->indexExists('mvc_file_id', ':_UploadedFile')) {
            $this->query("create INDEX mvc_file_id on `:_UploadedFile` (Id);");
        }
        // Use of placeholder for Type and Size due to lack of data in db.
        // Will require external script to get the info.
        $this->export(
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
