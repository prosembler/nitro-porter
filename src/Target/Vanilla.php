<?php

/**
 * @author Lincoln Russell, lincolnwebs.com
 */

namespace Porter\Target;

use Porter\Target;

/**
 *
 */
class Vanilla extends Target
{
    public const array INFO = [
        'name' => 'Vanilla',
        'defaultTablePrefix' => '',
        'avatarPath' => 'uploads/avatars/',
        'attachmentPath' => 'uploads/attachments/',
    ];

    protected const array FLAGS = [
        'hasDiscussionBody' => true,
    ];

    public function validate(): void
    {
        //
    }

    protected function users(): void
    {
        $query = $this->porterQB()->from('User')->select();
        $this->import('User', $query);
    }

    protected function roles(): void
    {
        $query = $this->porterQB()->from('Role')->select();
        $this->import('Role', $query);

        $query = $this->porterQB()->from('UserRole')->select();
        $this->import('UserRole', $query);
    }

    protected function categories(): void
    {
        $query = $this->porterQB()->from('Category')->select();
        $this->import('Category', $query);
    }

    protected function discussions(): void
    {
        $query = $this->porterQB()->from('Discussion')->select();
        $this->import('Discussion', $query);
    }

    protected function comments(): void
    {
        $query = $this->porterQB()->from('Comment')->select();
        $this->import('Comment', $query);
    }

    protected function tags(): void
    {
        $query = $this->porterQB()->from('Tag')->select();
        $this->import('Tag', $query);

        $query = $this->porterQB()->from('UserTag')->select();
        $this->import('UserTag', $query);
    }

    protected function reactions(): void
    {
        // @see tags()
    }

    protected function attachments(): void
    {
        $query = $this->porterQB()->from('Media')->select();
        $this->import('Media', $query);
    }

    protected function avatars(): void
    {
        // noop
    }
}
