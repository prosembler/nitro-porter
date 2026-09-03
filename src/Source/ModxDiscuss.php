<?php

/**
 * MODX Discuss exporter tool.
 *
 * @author  Robin Jurinka
 */

namespace Porter\Source;

use Porter\Source;

class ModxDiscuss extends Source
{
    public const array INFO = [
        'name' => 'MODX Discuss Extension',
        'defaultTablePrefix' => 'modx_discuss_',
        'charsetTable' => 'posts',
        'passwordHashMethod' => 'Vanilla',
    ];

    public array $sourceTables = [
        'categories' => [],
        'boards' => [],
        'posts' => [],
        'threads' => [],
        'users' => ['user', 'username', 'email', 'createdon'],
    ];

    protected function users(): void
    {
        $map = [
            'user' => 'UserID',
            'username' => 'Name',
            'password' => 'Password',
            'email' => 'Email',
            'createdon' => 'DateInserted',
            'birthdate' => 'DateOfBirth',
            'location' => 'Location',
            'confirmed' => 'Confirmed',
            'last_active' => 'DateLastActive',
            'title' => 'Title',
            'avatar' => 'Photo',
            'show_email' => 'ShowEmail',
        ];
        $this->export('User', "select * from :_users", $map);
    }

    protected function roles(): void
    {
        // Roles do not exist in Discuss.
        // @todo needs a 'member' role.
        $map = [
            'user' => 'UserID',
            'RoleID=32',
        ];
        $this->export('UserRole', "select * from :_moderators", $map);
    }

    protected function signatures(): void
    {
        $this->export( // @todo split non-signature data to users()
            'UserMeta',
            "select user as UserID, 'Plugin.Signatures.Sig' as `Name`, Signature as `Value`
                from :_users where Signature <> ''
                union
                select user as UserID, 'Profile.Website' as `Name`, website as `Value`
                from :_users where website <> ''
                union
                select user as UserID, 'Profile.LastName' as `Name`, name_last as `Value`
                from :_users where name_last <> ''
                union
                select user as UserID, 'Profile.FirstName' as `Name`, name_first as `Value`
                from :_users where name_first <> ''"
        );
    }

    protected function categories(): void
    {
        $map = [
            'id' => 'CategoryID',
            'name' => 'Name',
            'description' => 'Description',
            'rank' => 'Sort',
            'DisplayAs=Heading',
        ];
        $query = "select  id, name, description, rank, 
            case parent when 0 then '-1' else parent end as ParentCategoryID
            from :_boards";
        $this->export('Category', $query, $map);

        $map = [
            'name' => 'Name',
            'description' => 'Description',
            'rank' => 'Sort',
            'ParentCategoryID=-1',
            'DisplayAs=Heading',
        ];
        $query = "select name, description, rank, (select max(id) from :_boards) + id as CategoryID from :_categories";
        $this->export('Category', $query, $map);
    }

    protected function discussions(): void
    {
        $map = [
            'title' => 'Name',
            'id' => 'DiscussionID',
            'board' => 'CategoryID',
            'replies' => 'CountComments',
            'views' => 'CountViews',
            'locked' => 'Closed',
            'sticky' => 'Announce',
            'message' => 'Body',
            'author' => 'InsertUserID',
            'createdon' => 'DateInserted',
            'editedon' => 'DateUpdated',
            'Format=BBCode',
        ];
        $filters = [
            'title' => 'DecodeHtml',
        ];
        $this->export(
            'Discussion',
            "select t.id, t.board, t.title, t.replies, t.views, t.locked, t.sticky,
                    p.message, p.author, p.createdon, p.editedon,
                    case p.editedby when 0 then null else p.editedby end as `UpdateUserID`
                from :_threads t
                join :_posts p on t.id = p.thread",
            $map,
            $filters
        );
    }

    protected function comments(): void
    {
        $map = [
            'id' => 'CommentID',
            'thread' => 'DiscussionID',
            'message' => 'Body',
            'author' => 'InsertUserID',
            'createdon' => 'DateInserted',
            'editedon' => 'DateUpdated',
            'editedby2' => 'UpdateUserID',
            'Format=BBCode',
        ];
        $this->export(
            'Comment',
            'select p.id, p.thread, p.message, p.author, p.createdon, p.editedon,
                    case p.editedby when 0 then null else p.editedby end as editedby2
                from :_posts p
                where p.parent <> 0',
            $map
        );
    }
}
