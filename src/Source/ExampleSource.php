<?php

/**
 * @author YOUR NAME email_optional
 *
 * @see https://api.laravel.com/docs/11.x/Illuminate/Database.html
 */

namespace Porter\Source;

use Porter\Source;

/**
 * @see Package::MANIFEST for a complete list of method names you can use.
 */
class ExampleSource extends Source
{
    public const array INFO = [
        'name' => '_Example', // Package name users will see.
        'defaultTablePrefix' => '', // Default table prefix this software uses, if you know it.
        'charsetTable' => 'comments',  // Usually put the comments table name here. Used to derive charset.
    ];

    public const array FEATURE_REQUIREMENTS = [
        'polls' => [
            'enabled' => 'some/plugin', // Support notes of a required plugin or config.
            'schema' => [ // Required for the feature to run.
                'polls' => ['poll_id', 'poll_name'] // TableName => ColumnNames
            ],
        ],
    ];

    protected function users(): void
    {
        // Map as much as possible using the $xMap array for clarity.
        // Key is always the source column name.
        // Value is either the destination column.
        $map = [
            'Author_ID' => 'UserID',
            'Username' => 'Name',
        ];
        // You can filter values with a function: $sourceColumnName => $filterFunctionName
        // Here, 'DecodeHtml' is a class under `Filter/`. Check there for available filters.
        // Assume no filter is needed and only use one if you encounter issues.
        $filters = [
            'Name' => 'DecodeHtml',
        ];
        // This is the query that the $map array above will be mapped against.
        // Therefore, our select statement must cover all the "source" columns.
        // It's frequently necessary to add joins, where clauses, and more to get the data we want.
        // @see https://api.laravel.com/docs/11.x/Illuminate/Database.html
        $this->export(
            'User',
            $this->sourceQB()->from('Users')->select(), // default select() = *
            $map,
            $filters
        );
    }

    protected function roles(): void
    {
        // Role.
        // The Porter roles table will be wiped by any import. If your current platform doesn't have roles,
        // you can hard code new ones into the select statement. See Vanilla's defaults for a good example.
        $map = [
            'Group_ID' => 'RoleID',
            'Name' => 'Name', // We let these arrays end with a comma to prevent typos later as we add.
        ];
        $this->export(
            'Role',
            // @see https://api.laravel.com/docs/9.x/Illuminate/Database.html
            $this->sourceQB()->from('tblGroup')->select(),
            $map
        ); // We can omit $filters when there are none.

        // User Role.
        // There's usually a secondary table for associating roles to users.
        $map = [
            'Author_ID' => 'UserID',
            'Group_ID' => 'RoleID',
        ];
        $this->export(
            'UserRole',
            $this->sourceQB()->from('tblAuthor')->select(),
            $map
        );
    }

    protected function signatures(): void
    {
        // Example of pulling Signatures into Vanilla's UserMeta table.
        // You can add the appropriately-named fields after the migration in the dashboard
        // and profiles will auto-populate with the migrated data.

        // When the query is longer, it's clearer to set it up THEN pass it to export().
        $query = $this->sourceQB()->from('tblAuthor')
            ->selectSub('Author_ID', 'UserID') // Use selectSub() to alias within the query.
            ->selectSub('Signature', 'Value')
            // The Profile Extender addon uses the namespace "Profile.[FieldName]"
            ->selectRaw("'Plugin.Signatures.Sig' as Name") // Use selectRaw() for more elaborate SQL.
            ->whereRaw("Signature <> ''");

        $this->export('UserMeta', $query); // No $map needed in this case.
    }

    protected function categories(): void
    {
        // Be careful to not import hundreds of categories. Try translating huge schemas to Tags instead.
        $map = [
            'Forum_ID' => 'CategoryID',
            'Forum_name' => 'Name',
        ];
        $this->export(
            'Category',
            $this->sourceQB()->from('tblCategory')->select(),
            $map
        );
    }

    protected function discussions(): void
    {
        // A frequent issue is for the OPs content to be on the comment/post table, so you may need to join it.
        $map = [
            'Topic_ID' => 'DiscussionID',
            'Forum_ID' => 'CategoryID',
            'Author_ID' => 'InsertUserID',
            'Subject' => 'Name'
        ];
        $filters = [
            'Subject' => 'DecodeHtml', // Use the INPUT column name, not the Porter name.
        ];
        $query = $this->sourceQB()->from('tblTopic')
            ->select()
            // It's easier to convert between Unix time and MySQL datestamps during the db query.
            ->selectRaw("FROM_UNIXTIME(Message_date) as Message_date")
            ->join('tblThread', 'tblTopic.Start_Thread_ID', '=', 'tblThread.Thread_ID');

        $this->export('Discussion', $query, $map, $filters);
    }

    protected function comments(): void
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
        $this->export(
            'Comment',
            $this->sourceQB()->from('tblThread')->select(),
            $map
        );
    }
}
