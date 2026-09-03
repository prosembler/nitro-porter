<?php

/**
 * Drupal 6 exporter tool
 *
 * @author  Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Source;

use Porter\Source;

class Drupal6 extends Source
{
    public const array INFO = [
        'name' => 'Drupal 6',
        'defaultTablePrefix' => '',
        'charsetTable' => 'comment',
    ];

    protected function users(): void
    {
        $map = [
            'uid' => 'UserID',
            'name' => 'Name',
            'Password' => 'Password',
            'mail' => 'Email',
            'photo' => 'Photo',
            'created' => 'DateInserted',
            'login' => 'DateLastActive',
            'HashMethod=Django',
        ];
        $filters = [
            'created' => 'UnixtimeToDate',
            'login' => 'UnixtimeToDate',
        ];
        $this->export(
            'User',
            "select u.*,
                    nullif(concat('drupal/', u.picture), 'drupal/') as photo,
                    concat('md5$$', u.pass) as Password,
                from :_users u
                where uid > 0",
            $map,
            $filters
        );
    }

    protected function signatures(): void
    {
        $userMeta_Map = [
            'uid' => 'UserID',
            'Name=Plugins.Signatures.Sig',
            'signature' => 'Value'
        ];
        $this->export(
            'UserMeta',
            "select u.* from :_users u where uid > 0",
            $userMeta_Map
        );
    }

    protected function roles(): void
    {
        $role_Map = [
            'rid' => 'RoleID',
            'name' => 'Name'
        ];
        $this->export('Role', "select r.* from :_role r", $role_Map);

        // User Role.
        $userRole_Map = [
            'uid' => 'UserID',
            'rid' => 'RoleID'
        ];
        $this->export('UserRole', "select * from :_users_roles", $userRole_Map);
    }

    protected function categories(): void
    {
        $category_Map = [
            'tid' => 'CategoryID',
            'name' => 'Name',
            'description' => 'description',
            'parent' => 'ParentCategoryID'
        ];
        $this->export(
            'Category',
            "select t.*, nullif(h.parent, 0) as parent
                 from :_term_data t
                 join :_term_hierarchy h
                    on t.tid = h.tid",
            $category_Map
        );
    }

    protected function discussions(): void
    {
        $map = [
            'nid' => 'DiscussionID',
            'title' => 'Name',
            'body' => 'Body',
            'uid' => 'InsertUserID',
            'created' => 'DateInserted',
            'sticky' => 'Announce',
            'tid' => 'CategoryID'
        ];
        $filters = [
            'created' => 'UnixtimeToDate',
            'updated' => 'UnixtimeToDate',
        ];
        $this->export(
            'Discussion',
            "select n.*, nullif(n.changed, n.created) as updated, f.tid, r.body
                 from nodeforum f
                 left join node n on f.nid = n.nid
                 left join node_revisions r on r.nid = n.nid
                group by n.nid",
            $map,
            $filters
        );
    }

    protected function comments(): void
    {
        $map = [
            'cid' => 'CommentID',
            'uid' => 'InsertUserID',
            'body' => 'Body',
            'hostname' => 'InsertIPAddress',
            'created' => 'DateInserted',
            'changed' => 'DateUpdated',
            'Format=Html',
        ];
        $filters = [
            'created' => 'UnixtimeToDate',
        ];
        $this->export(
            'Comment',
            "select c.cid,  n.created, n.uid, r.body, n.title,
                    c.nid as DiscussionID,
                    nullif(n.changed, n.created) as changed
                 from node n
                 left join node_comments c on c.cid = n.nid
                 left join node_revisions r on r.nid = n.nid
                 where n.type = 'forum_reply'",
            $map,
            $filters
        );
    }

    protected function conversations(): void
    {
        $map = [
            'thread_id' => 'ConversationID',
            'author' => 'InsertUserID',
            'subject' => 'Subject',
            'timestamp' => 'DateInserted',
        ];
        $filters = [
            'timestamp' => 'UnixtimeToDate',
        ];
        $this->export(
            'Conversation',
            "select pmi.thread_id, pmm.author, pmm.subject, pmm.timestamp
                from pm_message as pmm
                inner join pm_index as pmi on pmi.mid = pmm.mid
                    and pmm.author = pmi.uid and pmi.deleted = 0 and pmi.uid > 0
                group by pmi.thread_id;",
            $map,
            $filters
        );

        // Conversation Messages.
        $map = [
            'mid' => 'MessageID',
            'thread_id' => 'ConversationID',
            'author' => 'InsertUserID',
            'timestamp' => 'DateInserted',
            'body' => 'Body',
            'Format=Html',
        ];
        $this->export(
            'ConversationMessage',
            "select pmm.mid, pmi.thread_id, pmm.author, pmm.timestamp, pmm.body
                from pm_message as pmm
                inner join pm_index as pmi on pmi.mid = pmm.mid 
                    and pmi.deleted = 0 and pmi.uid > 0;",
            $map,
            $filters
        );

        // User Conversation.
        $userConversation_Map = [
            'uid' => 'UserID',
            'thread_id' => 'ConversationID',
            'Deleted=0',
        ];
        $this->export(
            'UserConversation',
            "select pmi.uid, pmi.thread_id
                from pm_index as pmi
                inner join pm_message as pmm ON pmm.mid = pmi.mid
                where pmi.deleted = 0 and pmi.uid > 0
                group by pmi.uid, pmi.thread_id;",
            $userConversation_Map
        );
    }
}
