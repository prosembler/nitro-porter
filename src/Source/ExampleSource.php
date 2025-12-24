<?php

/**
 * @author YOUR NAME email_optional
 *
 * @see https://api.laravel.com/docs/11.x/Illuminate/Database.html
 */

namespace Porter\Source;

use Porter\Source;
use Porter\Migration;

class ExampleSource extends Source // You MUST extend Source for this to work.
{
    public const SUPPORTED = [
        'name' => '_Example', // The package name users will see.
        'defaultTablePrefix' => '', // The default table prefix this software uses, if you know it.
        'charsetTable' => 'comments',  // Usually put the comments table name here. Used to derive charset.
        'features' => [  // Set features you support to 1 or a string (for support notes).
            'Users' => 1,
            'Passwords' => 0,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 0,
            'Roles' => 0,
            'Avatars' => 0,
            'PrivateMessages' => 0,
            'Signatures' => 0,
            'Attachments' => 0,
            'Bookmarks' => 0,
            'Badges' => 0,
            'UserNotes' => 0, // You can just deleted all the '0' rows if you're never going to add them.
            'Ranks' => 0,
            'Groups' => 0,
            'Tags' => 0,
            'Reactions' => 0,
            'Articles' => 0,
        ]
    ];

    /**
     * Main export process.
     *
     * @param Migration $port
     */
    public function run(Migration $port): void
    {
        // It's usually a good idea to do the porting in the approximate order laid out here.
        // Users
        $this->users($port); // Always pass $port to these methods.
        $this->roles($port);
        $this->userMeta($port);

        // Content
        $this->categories($port);
        $this->discussions($port);
        $this->comments($port);

        // Everything else
        // $this->attachments($port); // Doesn't exist yet!
    }

    /**
     * @param Migration $port
     */
    protected function users(Migration $port): void
    {
        // Map as much as possible using the $xMap array for clarity.
        // Key is always the source column name.
        // Value is either the destination column.
        $map = [
            'Author_ID' => 'UserID',
            'Username' => 'Name',
        ];
        // You can filter values with a function: $sourceColumnName => $filterFunctionName
        // Here, 'HTMLDecoder' is a function in `Functions/filter.php`. Check there for available filters.
        // Assume no filter is needed and only use one if you encounter issues.
        $filters = [
            'Name' => 'HTMLDecoder',
        ];
        // This is the query that the $map array above will be mapped against.
        // Therefore, our select statement must cover all the "source" columns.
        // It's frequently necessary to add joins, where clauses, and more to get the data we want.
        // @see https://api.laravel.com/docs/11.x/Illuminate/Database.html
        $port->export(
            'User',
            $port->sourceQB()->from('Users')->select(), // default select() = *
            $map,
            $filters
        );
    }

    /**
     * @param Migration $port
     */
    protected function roles(Migration $port): void
    {
        // Role.
        // The Vanilla roles table will be wiped by any import. If your current platform doesn't have roles,
        // you can hard code new ones into the select statement. See Vanilla's defaults for a good example.
        $map = array(
            'Group_ID' => 'RoleID',
            'Name' => 'Name', // We let these arrays end with a comma to prevent typos later as we add.
        );
        $port->export(
            'Role',
            // @see https://api.laravel.com/docs/9.x/Illuminate/Database.html
            $port->sourceQB()->from('tblGroup')->select(),
            $map
        ); // We can omit $filters when there are none.

        // User Role.
        // There's usually a secondary table for associating roles to users.
        $map = [
            'Author_ID' => 'UserID',
            'Group_ID' => 'RoleID',
        ];
        $port->export(
            'UserRole',
            $port->sourceQB()->from('tblAuthor')->select(),
            $map
        );
    }

    /**
     * @param Migration $port
     */
    protected function userMeta(Migration $port): void
    {
        // This is an example of pulling Signatures into Vanilla's UserMeta table.
        // This is often a good place for any extraneous data on the User table too.
        // The Profile Extender addon uses the namespace "Profile.[FieldName]"
        // You can add the appropriately-named fields after the migration.
        // Profiles will auto-populate with the migrated data.

        // When the query is longer, it's clearer to set it up THEN pass it to export().
        $query = $port->sourceQB()->from('tblAuthor')
            ->selectSub('Author_ID', 'UserID') // Use selectSub() to alias within the query.
            ->selectSub('Signature', 'Value')
            ->selectRaw("'Plugin.Signatures.Sig' as Name") // Use selectRaw() for more elaborate SQL.
            ->whereRaw("Signature <> ''");

        $port->export('UserMeta', $query); // No $map needed in this case.
    }

    /**
     * @param Migration $port
     */
    protected function categories(Migration $port): void
    {
        // Be careful to not import hundreds of categories. Try translating huge schemas to Tags instead.
        $map = [
            'Forum_ID' => 'CategoryID',
            'Forum_name' => 'Name',
        ];
        $port->export(
            'Category',
            $port->sourceQB()->from('tblCategory')->select(),
            $map
        );
    }

    /**
     * @param Migration $port
     */
    protected function discussions(Migration $port): void
    {
        // A frequent issue is for the OPs content to be on the comment/post table, so you may need to join it.
        $map = array(
            'Topic_ID' => 'DiscussionID',
            'Forum_ID' => 'CategoryID',
            'Author_ID' => 'InsertUserID',
            'Subject' => 'Name'
        );
        $filters = [
            'Subject' => 'HTMLDecoder', // Use the INPUT column name, not the Porter name.
        ];
        $query = $port->sourceQB()->from('tblTopic')
            ->select()
            // It's easier to convert between Unix time and MySQL datestamps during the db query.
            ->selectRaw("FROM_UNIXTIME(Message_date) as Message_date")
            ->join('tblThread', 'tblTopic.Start_Thread_ID', '=', 'tblThread.Thread_ID');

        $port->export('Discussion', $query, $map, $filters);
    }

    /**
     * @param Migration $port
     */
    protected function comments(Migration $port): void
    {
        // This is where big migrations are going to get bogged down.
        // Be sure you have indexes created for any columns you are joining on.
        $map = [
            'Thread_ID' => 'CommentID',
            'Topic_ID' => 'DiscussionID',
            'Author_ID' => 'InsertUserID',
            'IP_addr' => 'InsertIPAddress',
            'Message' => 'Body',
            'Format' => 'Format',
            'Message_date' => 'DateInserted',
        ];
        $port->export(
            'Comment',
            $port->sourceQB()->from('tblThread')->select(),
            $map
        );
    }
}
