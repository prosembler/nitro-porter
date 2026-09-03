<?php

/**
 * AnswerHub exporter tool.
 * Assume https://github.com/vanilla/addons/tree/master/plugins/QnA will be enabled.
 *
 * @author  Alexandre Chouinard
 */

namespace Porter\Source;

use Porter\Source;

class AnswerHub extends Source
{
    public const array INFO = [
        'name' => 'answerhub',
        'defaultTablePrefix' => '',
        'charsetTable' => 'nodes',
    ];

    protected function users(): void
    {
        $map = [
            'c_id' => 'UserID',
            'c_name' => 'Name',
            'c_email' => 'Email',
            'c_creation_date' => 'DateInserted',
            'c_birthday' => 'DateOfBirth',
            'c_last_seen' => 'DateLastActive',
            'Admin=0',
        ];
        $filters = [
            'c_email' => 'BlankEmails',
        ];
        $query = "select user.*, user_email.c_email
                from :_authoritables as user
                     left join :_user_emails as user_email on user_email.c_user = user.c_id
                where user.c_type = 'user'
                    and user.c_name != '\$\$ANON_USER\$\$'";
        $this->export('User', $query, $map, $filters);
    }

    protected function roles(): void
    {
        // Role.
        $map = [
            'c_id' => 'RoleID',
            'c_name' => 'Name',
            'c_description' => 'Description',
        ];
        $query = "select * from :_authoritables where c_type = 'group'";
        $this->export('Role', $query, $map);

        // Add a default role past existing IDs.
        $result = $this->query("select c_reserved from id_generators where c_identifier = 'AUTHORITABLE'");
        $lastID = ($result && $row = $result->nextResultRow()) ? $row['c_reserved'] : 0;
        $this->dbPorter()->table('Role')->insertOrIgnore([
            ['RoleID' => $lastID++, 'Name' => 'System Administrator', 'Description' => 'System users from AnswerHub']
        ]);

        // User Role.
        $map = [
            'c_groups' => 'RoleID',
            'c_members' => 'UserID',
        ];
        $query = $this->sourceQB()->from('authoritable_groups')->select();
        $this->export('UserRole', $query, $map);
    }

    protected function categories(): void
    {
        $map = [
            'c_id' => 'CategoryID',
            'c_name' => 'Name',
        ];
        $this->export(
            'Category',
            "select containers.*,
                    case
                        when parents.c_type = 'space' then containers.c_parent
                        else null end as ParentCategoryID,
                from containers
                left join containers as parents on parents.c_id = containers.c_parent
                where containers.c_type = 'space' and containers.c_active = 1",
            $map
        );
    }

    protected function discussions(): void
    {
        $map = [
            'c_id' => 'DiscussionID',
            'c_title' => 'Name',
            'c_primaryContainer' => 'CategoryID',
            'c_author' => 'InsertUserID',
            'c_creation_date' => 'DateInserted',
            'Format=Html',
        ];
        $query = "select questions.*,
                if(questions.c_type = 'question', 'Question', NULL) as Type,
                COALESCE(NULLIF(nr.c_body, ''), NULLIF(questions.c_body, ''), questions.c_title ) as Body,
                if(locate('[closed]', questions.c_normalized_state) > 0, 1, 0) as Closed,
                if(questions.c_type = 'question',
                    if(count(answers.c_id) > 0,
                        if (locate('[accepted]', group_concat(ifnull(answers.c_normalized_state, ''))) = 0,
                            if (locate('[rejected]', group_concat(ifnull(answers.c_normalized_state, ''))) = 0,
                                'Answered', 'Rejected'
                            ), 'Accepted'
                        ), 'Unanswered'
                    ), null
                ) as QnA
            from :_nodes as questions
            left join (
                select c_node, c_body
                from :_node_revisions nr
                where c_id in (select max(c_id) as id from :_node_revisions group by c_node)
            )  nr on nr.c_node = questions.c_id
	        left join :_nodes as answers on answers.c_parent = questions.c_id
                and answers.c_type = 'answer' and answers.c_visibility = 'full'
            where questions.c_type in ('question', 'topic') and questions.c_visibility = 'full'
            group by questions.c_id";
        $this->export('Discussion', $query, $map);
    }

    protected function comments(): void
    {
        $map = [
            'c_id' => 'CommentID',
            'c_parent' => 'DiscussionID',
            'c_author' => 'InsertUserID',
            'c_creation_date' => 'DateInserted',
            'Format=Html',
        ];
        $this->export(
            'Comment',
            "select answers.*,
                COALESCE(NULLIF(nr.c_body, ''), answers.c_body) as Body,
                if (
                    locate('[accepted]', answers.c_normalized_state) = 0,
                    if( locate('[rejected]', answers.c_normalized_state) = 0, null,'Rejected'),
                    'Accepted'
                ) as QnA
            from :_nodes as answers
            left join (
                select c_node, c_body 
                from :_node_revisions nr
                where c_id in (select max(c_id) as id from :_node_revisions group by c_node)
            )  nr on nr.c_id = answers.c_id
            where answers.c_type in ('answer', 'comment')
                  and answers.c_visibility = 'full'",
            $map
        );
    }

    protected function tags(): void
    {
        // Tags.
        $map = [
            'c_id' => 'TagID',
            'c_plug' => 'Name',
            'c_title' => 'FullName',
            'c_creation_date' => 'DateInserted',
        ];
        $query = $this->sourceQB()->from('nodes')->select()->where('c_type', '=', 'topic');
        $this->export('Tags', $query, $map);

        // TagDiscussion.
        $map = [
            'c_topics' => 'TagID',
            'c_nodes' => 'DiscussionID',
            'CategoryID=-1',
        ];
        $query = $this->sourceQB()->from('node_topics')->select()->whereIn(
            'c_nodes',
            function ($query) {
                $query->select('c_nodes')->from('nodes')->where('c_type', '=', 'question');
            }
        );
        $this->export('TagDiscussion', $query, $map);
    }

    protected function attachments(): void
    {
        $map = [
            'c_id' => 'MediaID',
            'c_name' => 'Name',
            'c_mime_type' => 'Type',
            'c_size' => 'Size',
            'c_user' => 'InsertUserID',
            'c_creation_date' => 'DateInserted',
            'c_Node' => 'ForeignID',
        ];
        $query = "select m.*, na.c_Node,
                    concat('attachments', m.c_name) as `Path`,
                    if(n.c_type = 'question', 'discussion', 'comment') as `ForeignTable`
                from :_managed_files as m
                join :_node_attachments na on na.c_attachments = m.c_id
                join :_nodes n on na.c_Node = n.c_id";
        $this->export('Media', $query, $map);

        $map = [
            'c_id' => 'MediaID',
            'c_addedBy' => 'InsertUserID',
            'c_creation_date' => 'DateInserted',
            'c_node' => 'ForeignID',
            'Size=1',
        ];
        $filters = [
            'Type' => 'ExtToMime',
            'Name' => \Porter\Filter\ExtractFilenameFromPath::class,
        ];
        $query = "select
                    s.c_url as `Name`,
                    s.c_url as `Path`,
                    s.c_url as `Type`,
                    if(n.c_type = 'question', 'discussion', 'comment') as `ForeignTable`
                from :_sources s
                join :_nodes n on s.c_node = n.c_id";
        $this->export('Media', $query, $map, $filters);
    }
}
