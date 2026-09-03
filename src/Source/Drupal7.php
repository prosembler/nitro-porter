<?php

/**
 * Drupal 7 export support.
 *
 * @author  Francis Caisse
 */

namespace Porter\Source;

use Porter\Source;

class Drupal7 extends Source
{
    public const array INFO = [
        'name' => 'Drupal 7',
        'defaultTablePrefix' => '',
        'charsetTable' => 'comment',
    ];

    protected function users(): void
    {
        $map = [
            'uid' => 'UserID',
            'name' => 'Name',
            'pass' => 'Password',
            'mail' => 'Email',
            'HashMethod=Django',
            'created' => 'DateInserted',
            'login' => 'DateLastActive',
        ];
        $filters = [
            'created' => \Porter\Filter\UnixtimeToDate::class,
            'login' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'User',
            "select uid, name, mail, created, login,
                    nullif(concat('drupal_profile/',if(picture = 0, null, picture)), 'drupal_profile/') as Photo,
                    concat('md5$$', pass) as pass
                from :_users
                where uid > 0 and status = 1",
            $map,
            $filters
        );
    }

    protected function signatures(): void
    {
        $this->export(
            'UserMeta',
            "select  uid as UserID, signature as Value, 'Plugin.Signatures.Sig' as Name
                from :_users u
                where uid > 0 and status = 1 and signature is not null and signature <> ''
                union
                select uid as UserID, 'Html' as Value, 'Plugins.Signatures.Format' as Name
                from :_users u
                where uid > 0 and status = 1 and signature is not null and signature <> ''"
        );
    }

    protected function roles(): void
    {
        $map = ['rid' => 'RoleID', 'name' => 'name'];
        $this->export('Role', "select rid, name from :_role", $map);

        // User Role.
        $map = ['rid' => 'RoleID', 'uid' => 'UserID'];
        $this->export('UserRole', "select uid, rid from :_users_roles", $map);
    }

    protected function categories(): void
    {
        $map = [
            'tid' => 'CategoryID',
            'name' => 'Name',
            'description' => 'Description',
        ];
        $this->export(
            'Category',
            "select t.tid, t.name, t.description, 
                    if(th.parent = 0, null, th.parent) as ParentCategoryID
                from :_taxonomy_term_data t
                left join :_taxonomy_term_hierarchy th on th.tid = t.tid
                left join :_taxonomy_vocabulary tv on tv.vid = t.vid
                where tv.name in ('Forums', 'Discussion boards')",
            $map
        );
    }

    protected function discussions(): void
    {
        $map = [
            'nid' => 'DiscussionID',
            'uid' => 'InsertUserID',
            'tid' => 'CategoryID',
            'created' => 'DateInserted',
            'changed' => 'DateUpdated',
            'title' => 'Name',
            'sticky' => 'Announce',
            'body_value' => 'Body',
            'Format=Html',
        ];
        $filters = [
            'body_value' => \Porter\Filter\Base64ToFile::class,
            'created' => \Porter\Filter\UnixtimeToDate::class,
            'changed' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Discussion',
            "select n.nid, n.uid, n.created, f.tid, n.title, n.nid as FilterPostID,
                    nullif(n.changed, n.created) as changed
                    if(n.sticky = 1, 2, 0) as sticky,
                    concat(ifnull(r.body_value, b.body_value), ifnull(i.image, '')) as body_value
                from :_node n
                join :_field_data_body b on b.entity_id = n.nid
                left join :_forum f on f.vid = n.vid
                left join :_field_revision_body r on r.revision_id = n.vid
                left join ( 
                    select i.nid, concat('\n<img src=\"/uploads/', 
                        replace(uri, 'public://', ''), ' alt=\"', fileName, '\">') as image
                    from :_image i
                    join :_file_managed fm on fm.fid = i.fid
                    where image_size not like '%thumbnail'
                ) i on i.nid = n.nid
                where n.status = 1 and n.moderate = 0 and b.deleted = 0 and n.Type not in ('Page', 'webform')",
            $map,
            $filters
        );
    }

    protected function comments(): void
    {
        $map = [
            'cid' => 'CommentID',
            'nid' => 'DiscussionID',
            'uid' => 'InsertUserID',
            'created' => 'DateInserted',
            'changed' => 'DateUpdated',
            'comment_body_value' => 'Body',
            'Format=Html',
        ];
        $filters = [
            'body_value' => \Porter\Filter\Base64ToFile::class,
            'created' => \Porter\Filter\UnixtimeToDate::class,
            'changed' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Comment',
            "select c.cid, c.nid, c.uid, c.created, c.cid as FilterPostID,
                    nullif(n.changed, n.created) as changed
                    concat(
                        -- Title of the commment
                        if(c.subject is not null
                            and c.subject not like 'RE%'
                            and c.subject not like 'Re%'
                            and c.subject <> 'N/A',
                            concat('<b>', c.subject, '</b>\n'), ''),
                        -- Body
                        ifnull(r.comment_body_value, b.comment_body_value)
                    ) as comment_body_value
                from :_comment c
                join :_field_data_comment_body b on b.entity_id = c.cid
                left join :_field_revision_comment_body r on r.entity_id = c.cid
                where c.status = 1 and b.deleted = 0",
            $map,
            $filters
        );
    }

    protected function attachments(): void
    {
        $map = [
            'fid' => 'MediaID',
            'id' => 'ForeignID',
            'filemime' => 'Type',
            'filename' => 'Name',
            'filesize' => 'Size',
            'timestamp' => 'DateInserted',
        ];
        $filters = [
            'timestamp' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export(
            'Media',
            "select fm.fid, fm.filemime, fu.id, fm.filename, fm.filesize, timestamp,
                    if(fu.id = 'node', 'discussion', 'comment') as ForeignTable,
                    concat('drupal_attachments/', substring(fm.uri, 10)) as Path
                from file_managed fm
                join file_usage fu on fu.fid = fm.fid
                union
                select f.fid, f.filemime, fu.id, f.filename, f.filesize, timestamp,
                    if(fu.type = 'node', 'discussion', 'comment') as ForeignTable,
                    concat('drupal_attachments/', substring(f.uri, 10)) as Path
                from file_managed_audio f
                join file_usage_audio fu on fu.fid = f.fid",
            $map,
            $filters
        );
    }
}
