<?php

/**
 * Forum Access (Drupal 8) export support.
 *
 * @author  Lincoln Russell
 */

namespace Porter\Source;

use Porter\Source;
use Porter\ExportModel;

class ForumAccess extends Source
{
    public const SUPPORTED = [
        'name' => 'Forum Access (Drupal 8)',
        'prefix' => '',
        'charset_table' => 'comment',
        'options' => [

        ],
        'features' => [
            'Users' => 1,
            'Passwords' => 0,
            'Categories' => 1,
            'Discussions' => 1,
            'Comments' => 1,
            'Polls' => 0,
            'Roles' => 1,
            'Avatars' => 0,
            'PrivateMessages' => 0,
            'Signatures' => 0,
            'Attachments' => 0,
        ]
    ];

    /**
     * @param ExportModel $ex
     */
    public function run($ex)
    {
        $this->users($ex);
        //$this->roles($ex); // user__roles
        $this->categories($ex);
        $this->discussions($ex);
        $this->comments($ex);
        // private messages // private_message__field_subject, etc

        // nid=node, tid=taxonomy, rid=redirect, vid=node_revision??
    }

    /**
     * @param ExportModel $ex
     */
    protected function users(ExportModel $ex): void
    {
        $map = [
            'uid' => 'UserID',
            'name' => 'Name',
            'mail' => 'Email',
            'created' => ['Column' => 'DateInserted', 'Filter' => 'timestampToDate'],
            'changed' => ['Column' => 'DateUpdated', 'Filter' => 'timestampToDate'],
            'access' => ['Column' => 'DateLastActive', 'Filter' => 'timestampToDate'],
        ];
        $ex->export(
            'User',
            "select * from :_users_field_data",
            $map
        );
    }

    /**
     * @param ExportModel $ex
     */
    protected function roles(ExportModel $ex): void
    {
        $ex->export(
            'Role',
            "select rid as RoleID, name as Name from :_role"
        );

        // User Role.
        $ex->export(
            'UserRole',
            "select uid as UserID, rid as RoleID from :_users_roles"
        );
    }

    /**
     * @param ExportModel $ex
     */
    protected function categories(ExportModel $ex): void
    {
        $map = [
           'entity_id' => '',
           'revision_id' => '',
           'taxonomy_forums_target_id' => '', // what does this target?
        ];
        $ex->export(
            'Category',
            "select * from :_node__taxonomy_forums",
            $map
        );
    }
    // node_revision__taxonomy_forums

    /**
     * @param ExportModel $ex
     */
    protected function discussions(ExportModel $ex): void
    {
        $discussionMap = array(
            //
        );
        $ex->export(
            'Discussion',
            "select * from :_node",
            $discussionMap
        );
        // node,
        // node__body, entity_id, revision_id, body_value, body_summary
        //   body_format (full_html, filtered_html, fckeditor_full)
        //   bundle (page, book, library, story, forum)
        // node_field_data — nid, vid, type (forum), uid, created, changed - title 'New member "X"'
    }

    /**
     * @param ExportModel $ex
     */
    protected function comments(ExportModel $ex): void
    {
        $map = array(
            //
        );
        $ex->export(
            'Comment',
            "select * from :_node__comment_forum", // 7K
            $map
        );
        // entity_id, revision_id

        // node_revision__comment_forum
    }
}
