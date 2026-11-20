<?php

namespace Porter\Source;

use Porter\Config;
use Porter\Log;
use Porter\Source;

class WoltLab extends Source
{
    public const array INFO = [
        'name' => 'WoltLab Suite 5.x',
        'defaultTablePrefix' => 'wcf1_',
        'charsetTable' => 'user',
        'attachmentPath' => 'wcf/attachments/',
    ];

    public const array FEATURE_REQUIREMENTS = [
        'attachments' => [
            'enabled' => 'WoltLab Suite Forum',
            'schema' => ['attachment' => ['attachmentID', 'objectID', 'fileHash']],
        ],
        'conversations' => [
            'enabled' => 'WoltLab Suite Core',
            'schema' => [
                'conversation' => ['conversationID', 'subject'],
                'conversation_message' => ['messageID', 'conversationID', 'message'],
                'conversation_to_user' => ['conversationID', 'participantID'],
            ],
        ],
        'polls' => [
            'enabled' => 'WoltLab Suite Forum',
            'schema' => [
                'poll' => ['pollID', 'objectTypeID', 'objectID'],
                'poll_option' => ['optionID', 'pollID', 'optionValue'],
                'poll_option_vote' => ['pollID', 'optionID', 'userID'],
            ],
        ],
    ];

    protected const array FLAGS = [
        'fileTransferSupport' => true,
        'hasDiscussionBody' => false,
    ];

    /** @var array Required tables => columns */
    public array $sourceTables = [
        'user' => ['userID', 'username', 'email', 'password', 'registrationDate', 'emailConfirmed'],
        'user_group' => ['groupID', 'groupName'],
        'user_to_group' => ['userID', 'groupID'],
        'board' => [],
        'thread' => [],
        'post' => [],
    ];

    private int $missingAttachmentFiles = 0;

    public function verifySource(array $requiredSchema): void
    {
        $forumTables = array_intersect_key($requiredSchema, array_flip(['board', 'thread', 'post']));
        foreach (array_keys($forumTables) as $table) {
            unset($requiredSchema[$table]);
        }
        parent::verifySource($requiredSchema);

        $missingTables = [];
        foreach (array_keys($forumTables) as $table) {
            $exists = $this->dbInput()->select(
                'select table_name from information_schema.tables
                 where table_schema = database() and table_name = ?',
                [$this->forumTable($table)]
            );
            if (empty($exists)) {
                $missingTables[] = $this->forumTable($table);
            }
        }
        if (!empty($missingTables)) {
            trigger_error('Missing required tables: ' . implode(', ', $missingTables));
        }
    }

    protected function users(): void
    {
        $map = [
            'userID' => 'UserID',
            'username' => 'Name',
            'email' => 'Email',
            'password' => 'Password',
            'registrationDate' => 'DateInserted',
            'lastActivityTime' => 'DateLastActive',
            'emailConfirmed' => 'Confirmed',
        ];
        $filters = [
            'registrationDate' => \Porter\Filter\UnixtimeToDate::class,
            'lastActivityTime' => \Porter\Filter\UnixtimeToDate::class,
        ];
        $this->export('User', $this->sourceQB()->from('user')->select(), $map, $filters);
    }

    protected function roles(): void
    {
        $this->export(
            'Role',
            $this->sourceQB()->from('user_group')->select(),
            [
                'groupID' => 'RoleID',
                'groupName' => 'Name',
            ],
        );

        $this->export(
            'UserRole',
            $this->sourceQB()->from('user_to_group')->select(),
            [
                'userID' => 'UserID',
                'groupID' => 'RoleID',
            ],
        );
    }

    protected function categories(): void
    {
        $query = $this->sourceQB()->fromRaw($this->forumTable('board') . ' as b')->select();
        $this->export(
            'Category',
            $query,
            [
                'boardID' => 'CategoryID',
                'parentID' => 'ParentCategoryID',
                'position' => 'Sort',
                'title' => 'Name',
                'description' => 'Description',
                'isInvisible' => 'HideAllDiscussions',
            ],
            [
                'parentID' => fn($value) => $value ?: null,
            ],
        );
    }

    protected function discussions(): void
    {
        $threadAlias = $this->forumAlias('t');
        $firstPostAlias = $this->forumAlias('firstPost');
        $firstPostQuery = $this->sourceQB()->fromRaw(
            $this->forumTable('post') . ' as ' . $firstPostAlias
        )
            ->select('firstPost.postID')
            ->whereColumn('firstPost.threadID', 't.threadID')
            ->where('firstPost.isDeleted', '=', 0)
            ->where('firstPost.isDisabled', '=', 0)
            ->orderBy('firstPost.time')
            ->orderBy('firstPost.postID')
            ->limit(1);
        $query = $this->sourceQB()->fromRaw($this->forumTable('thread') . ' as ' . $this->forumAlias('t'))
            ->select([
                't.threadID',
                't.boardID',
                't.topic',
                't.time',
                't.userID',
                't.lastPostID',
                't.replies',
                't.views',
                't.isSticky',
                't.isClosed',
            ])
            ->selectSub($firstPostQuery, 'firstPostID')
            ->selectRaw("coalesce(nullif($threadAlias.lastPostTime, 0), $threadAlias.time) as lastPostTime")
            ->selectRaw("coalesce(nullif($threadAlias.lastPosterID, 0), $threadAlias.userID) as lastPosterID")
            ->where('t.isDeleted', '=', 0)
            ->where('t.isDisabled', '=', 0)
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->fromRaw($this->forumTable('post') . ' as ' . $this->forumAlias('firstPost'))
                    ->whereColumn('firstPost.threadID', 't.threadID')
                    ->where('firstPost.isDeleted', '=', 0)
                    ->where('firstPost.isDisabled', '=', 0);
            });

        $this->export(
            'Discussion',
            $query,
            [
                'threadID' => 'DiscussionID',
                'boardID' => 'CategoryID',
                'topic' => 'Name',
                'firstPostID' => 'FirstCommentID',
                'lastPostID' => 'LastCommentID',
                'time' => 'DateInserted',
                'userID' => 'InsertUserID',
                'lastPostTime' => 'DateLastComment',
                'lastPosterID' => 'LastCommentUserID',
                'replies' => 'CountComments',
                'views' => 'CountViews',
                'isSticky' => 'Announce',
                'isClosed' => 'Closed',
            ],
            [
                'time' => \Porter\Filter\UnixtimeToDate::class,
                'lastPostTime' => \Porter\Filter\UnixtimeToDate::class,
                'userID' => fn($value) => $value ?: null,
                'lastPosterID' => fn($value) => $value ?: null,
            ],
        );
    }

    protected function comments(): void
    {
        $query = $this->sourceQB()->fromRaw($this->forumTable('post') . ' as ' . $this->forumAlias('p'))
            ->join(
                $this->dbInput()->raw($this->forumTable('thread') . ' as ' . $this->forumAlias('t')),
                't.threadID',
                '=',
                'p.threadID'
            )
            ->select([
                'p.postID',
                'p.threadID',
                'p.userID',
                'p.message',
                'p.time',
                'p.editorID',
                'p.lastEditTime',
            ])
            ->where('t.isDeleted', '=', 0)
            ->where('t.isDisabled', '=', 0)
            ->where('p.isDeleted', '=', 0)
            ->where('p.isDisabled', '=', 0);

        $this->export(
            'Comment',
            $query,
            [
                'postID' => 'CommentID',
                'threadID' => 'DiscussionID',
                'userID' => 'InsertUserID',
                'message' => 'Body',
                'time' => 'DateInserted',
                'editorID' => 'UpdateUserID',
                'lastEditTime' => 'DateUpdated',
                'Format=BBCode',
            ],
            [
                'time' => \Porter\Filter\UnixtimeToDate::class,
                'lastEditTime' => \Porter\Filter\UnixtimeToDate::class,
                'userID' => fn($value) => $value ?: null,
                'editorID' => fn($value) => $value ?: null,
            ],
        );
    }

    protected function tags(): void
    {
        if (
            !$this->hasInputSchema('tag')
            || !$this->hasInputSchema('tag_to_object')
            || !$this->hasInputSchema('object_type', ['objectTypeID', 'objectType'])
        ) {
            return;
        }

        $tagQuery = $this->sourceQB()->from('tag')->select([
            'tagID',
            'name',
        ])
            ->addSelect('name as fullName');
        $this->export(
            'Tag',
            $tagQuery,
            [
                'tagID' => 'TagID',
                'name' => 'Name',
                'fullName' => 'FullName',
            ],
        );

        $query = $this->sourceQB()->from('tag_to_object as to')
            ->join('object_type as ot', 'ot.objectTypeID', '=', 'to.objectTypeID')
            ->join(
                $this->dbInput()->raw($this->forumTable('thread') . ' as ' . $this->forumAlias('t')),
                't.threadID',
                '=',
                'to.objectID'
            )
            ->where('ot.objectType', '=', 'com.woltlab.wbb.thread')
            ->where('t.isDeleted', '=', 0)
            ->where('t.isDisabled', '=', 0)
            ->select([
                'to.tagID',
                'to.objectID as discussionID',
            ]);
        $this->export(
            'TagDiscussion',
            $query,
            [
                'tagID' => 'TagID',
                'discussionID' => 'DiscussionID',
            ],
        );
    }

    protected function attachments(): void
    {
        if (
            !$this->hasInputSchema('attachment')
            || !$this->hasInputSchema('object_type', ['objectTypeID', 'objectType'])
        ) {
            return;
        }

        $postTypeIDs = $this->objectTypeIDs('com.woltlab.wbb.post');
        $messageTypeIDs = [];
        if ($this->hasInputSchema('conversation_message') && $this->hasInputSchema('conversation')) {
            $messageTypeIDs = $this->objectTypeIDs('com.woltlab.wcf.conversation.message');
        }
        if (empty($postTypeIDs) && empty($messageTypeIDs)) {
            return;
        }

        $attachmentRoot = rtrim($this->getPath('attachment', true), "/\\");
        if (!is_dir($attachmentRoot)) {
            $sourceRoot = Config::getInstance()->get('source_root');
            if ($sourceRoot && is_dir($sourceRoot)) {
                $attachmentRoot = rtrim($sourceRoot, "/\\");
            }
        }
        $attachmentRoot .= DIRECTORY_SEPARATOR;
        $attachmentAlias = $this->forumAlias('a');
        $objectTypeAlias = $this->forumAlias('ot');
        $query = $this->sourceQB()->from('attachment as a')
            ->join('object_type as ot', 'ot.objectTypeID', '=', 'a.objectTypeID')
            ->whereIn('ot.objectType', array_merge(
                empty($postTypeIDs) ? [] : ['com.woltlab.wbb.post'],
                empty($messageTypeIDs) ? [] : ['com.woltlab.wcf.conversation.message'],
            ))
            ->where(function ($query) use ($postTypeIDs, $messageTypeIDs) {
                if (!empty($postTypeIDs)) {
                    $query->where(function ($query) use ($postTypeIDs) {
                        $query->whereIn('a.objectTypeID', $postTypeIDs)
                            ->whereExists(function ($query) {
                                $query->selectRaw('1')
                                    ->fromRaw($this->forumTable('post') . ' as ' . $this->forumAlias('p'))
                                    ->join(
                                        $this->dbInput()->raw(
                                            $this->forumTable('thread') . ' as ' . $this->forumAlias('t')
                                        ),
                                        't.threadID',
                                        '=',
                                        'p.threadID'
                                    )
                                    ->whereColumn('p.postID', 'a.objectID')
                                    ->where('p.isDeleted', '=', 0)
                                    ->where('p.isDisabled', '=', 0)
                                    ->where('t.isDeleted', '=', 0)
                                    ->where('t.isDisabled', '=', 0);
                            });
                    });
                }
                if (!empty($messageTypeIDs)) {
                    $filter = function ($query) use ($messageTypeIDs) {
                        $query->whereIn('a.objectTypeID', $messageTypeIDs)
                            ->whereExists(function ($query) {
                                $query->selectRaw('1')
                                    ->from('conversation_message as cm')
                                    ->join('conversation as c', 'c.conversationID', '=', 'cm.conversationID')
                                    ->whereColumn('cm.messageID', 'a.objectID')
                                    ->where('c.isDraft', '=', 0);
                            });
                    };
                    empty($postTypeIDs) ? $query->where($filter) : $query->orWhere($filter);
                }
            })
            ->select([
                'a.attachmentID',
                'a.objectID',
                'a.userID',
                'a.uploadTime',
                'a.filename',
                'a.filesize',
                'a.fileType',
                'a.fileHash',
                'a.isImage',
                'a.width',
                'a.height',
            ])
            ->selectRaw(
                "case when $objectTypeAlias.objectType = 'com.woltlab.wbb.post' "
                    . "then 'Comment' else 'Message' end as foreignTable"
            )
            ->selectRaw(
                "concat(left($attachmentAlias.fileHash, 2), '/', $attachmentAlias.attachmentID, "
                    . "'-', $attachmentAlias.fileHash) as relativePath"
            )
            ->selectRaw(
                "concat(left($attachmentAlias.fileHash, 2), '/', $attachmentAlias.attachmentID, "
                    . "'-', $attachmentAlias.fileHash) as sourcePath"
            );

        $this->export(
            'Media',
            $query,
            [
                'attachmentID' => 'MediaID',
                'objectID' => 'ForeignID',
                'userID' => 'InsertUserID',
                'uploadTime' => 'DateInserted',
                'filename' => 'Name',
                'filesize' => 'Size',
                'fileType' => 'Type',
                'width' => 'ImageWidth',
                'height' => 'ImageHeight',
                'relativePath' => 'Path',
                'sourcePath' => 'SourceFullPath',
                'foreignTable' => 'ForeignTable',
            ],
            [
                'sourcePath' => function ($value) use ($attachmentRoot) {
                    if (empty($value)) {
                        $this->missingAttachmentFiles++;
                        return null;
                    }
                    $path = $attachmentRoot . str_replace('/', DIRECTORY_SEPARATOR, $value);
                    if (is_readable($path . '.bin')) {
                        return $path . '.bin';
                    }
                    if (is_readable($path)) {
                        return $path;
                    }
                    $this->missingAttachmentFiles++;
                    return null;
                },
                'userID' => fn($value) => $value ?: null,
                'uploadTime' => \Porter\Filter\UnixtimeToDate::class,
                'fileType' => fn($value) => $value ?: 'application/octet-stream',
            ],
        );
        if ($this->missingAttachmentFiles > 0) {
            Log::comment(
                "Missing {$this->missingAttachmentFiles} WoltLab attachment files under the configured source root."
            );
        }
    }

    protected function polls(): void
    {
        if (
            !$this->hasInputSchema('poll')
            || !$this->hasInputSchema('poll_option')
            || !$this->hasInputSchema('object_type', ['objectTypeID', 'objectType'])
        ) {
            return;
        }

        $postTypeIDs = $this->objectTypeIDs('com.woltlab.wbb.post');
        $threadTypeIDs = $this->objectTypeIDs('com.woltlab.wbb.thread');
        if (empty($postTypeIDs) && empty($threadTypeIDs)) {
            Log::comment('Skipping WoltLab polls: no WBB post or thread object type was found.');
            return;
        }

        $pollAlias = $this->forumAlias('p');
        $postAlias = $this->forumAlias('post');
        $threadAlias = $this->forumAlias('thread');
        $postThreadAlias = $this->forumAlias('postThread');
        $pollFirstPostAlias = $this->forumAlias('pollFirstPost');
        $query = $this->sourceQB()->from('poll as p')
            ->leftJoin($this->dbInput()->raw(
                $this->forumTable('post') . ' as ' . $this->forumAlias('post')
            ), function ($join) use ($postTypeIDs) {
                $join->on('post.postID', '=', 'p.objectID')
                    ->whereIn('p.objectTypeID', $postTypeIDs);
            })
            ->leftJoin($this->dbInput()->raw(
                $this->forumTable('thread') . ' as ' . $this->forumAlias('thread')
            ), function ($join) use ($threadTypeIDs, $postTypeIDs) {
                $join->on('thread.threadID', '=', 'p.objectID')
                    ->whereIn('p.objectTypeID', $threadTypeIDs);
            })
            ->leftJoin($this->dbInput()->raw(
                $this->forumTable('thread') . ' as ' . $this->forumAlias('postThread')
            ), function ($join) use ($postTypeIDs) {
                $join->on('postThread.threadID', '=', 'post.threadID')
                    ->whereIn('p.objectTypeID', $postTypeIDs);
            })
            ->whereIn('p.objectTypeID', array_merge($postTypeIDs, $threadTypeIDs))
            ->select([
                'p.pollID',
                'p.question',
                'p.time',
                'p.endTime',
                'p.isChangeable',
                'p.isPublic',
                'p.maxVotes',
                'p.resultsRequireVote',
                'p.votes',
                'post.postID',
                'thread.threadID',
            ])
            ->selectRaw("coalesce($postThreadAlias.threadID, $threadAlias.threadID) as discussionID")
            ->selectRaw(
                "coalesce($postAlias.postID, (
                    select $pollFirstPostAlias.postID
                    from {$this->forumTable('post')} as $pollFirstPostAlias
                    where $pollFirstPostAlias.threadID = $threadAlias.threadID
                        and $pollFirstPostAlias.isDeleted = 0
                        and $pollFirstPostAlias.isDisabled = 0
                    order by $pollFirstPostAlias.time, $pollFirstPostAlias.postID
                    limit 1
                )) as commentID"
            )
            ->selectRaw("coalesce($postAlias.userID, $threadAlias.userID, $postThreadAlias.userID) as userID")
            ->selectRaw("case when $pollAlias.maxVotes > 1 then 1 else 0 end as allowMultiple");

        $this->export(
            'Poll',
            $query,
            [
                'pollID' => 'PollID',
                'question' => 'Name',
                'discussionID' => 'DiscussionID',
                'commentID' => 'CommentID',
                'userID' => 'InsertUserID',
                'time' => 'DateInserted',
                'endTime' => 'DateClosed',
                'allowMultiple' => 'AllowMultiple',
                'maxVotes' => 'MaxVotes',
                'isChangeable' => 'AllowChangeVote',
                'resultsRequireVote' => 'HideVotes',
                'votes' => 'CountVotes',
                'isPublic' => 'Anonymous',
            ],
            [
                'time' => \Porter\Filter\UnixtimeToDate::class,
                'endTime' => \Porter\Filter\UnixtimeToDate::class,
                'userID' => fn($value) => $value ?: null,
                'votes' => \Porter\Filter\EmptyToZero::class,
                'isPublic' => fn($value) => $value ? 0 : 1,
            ],
        );

        $this->export(
            'PollOption',
            $this->sourceQB()->from('poll_option')
                ->whereIn('pollID', function ($query) use ($postTypeIDs, $threadTypeIDs) {
                    $query->select('pollID')
                        ->from('poll')
                        ->whereIn('objectTypeID', array_merge($postTypeIDs, $threadTypeIDs));
                })
                ->select(),
            [
                'optionID' => 'PollOptionID',
                'pollID' => 'PollID',
                'optionValue' => 'Body',
                'showOrder' => 'Sort',
                'votes' => 'CountVotes',
                'Format=Text',
            ],
            [
                'votes' => \Porter\Filter\EmptyToZero::class,
            ],
        );

        if ($this->hasInputSchema('poll_option_vote')) {
            $this->export(
                'PollVote',
                $this->sourceQB()->from('poll_option_vote')
                    ->whereIn('pollID', function ($query) use ($postTypeIDs, $threadTypeIDs) {
                        $query->select('pollID')
                            ->from('poll')
                            ->whereIn('objectTypeID', array_merge($postTypeIDs, $threadTypeIDs));
                    })
                    ->select(),
                [
                    'userID' => 'UserID',
                    'optionID' => 'PollOptionID',
                    'pollID' => 'PollID',
                ],
            );
        }
    }

    protected function conversations(): void
    {
        if (
            !$this->hasInputSchema('conversation')
            || !$this->hasInputSchema('conversation_message')
            || !$this->hasInputSchema('conversation_to_user')
        ) {
            return;
        }

        $firstMessageQuery = $this->sourceQB()->from('conversation_message as firstMessage')
            ->select('firstMessage.messageID')
            ->whereColumn('firstMessage.conversationID', 'c.conversationID')
            ->orderBy('firstMessage.time')
            ->orderBy('firstMessage.messageID')
            ->limit(1);
        $lastMessageQuery = $this->sourceQB()->from('conversation_message as lastMessage')
            ->select('lastMessage.messageID')
            ->whereColumn('lastMessage.conversationID', 'c.conversationID')
            ->orderByDesc('lastMessage.time')
            ->orderByDesc('lastMessage.messageID')
            ->limit(1);
        $lastMessageTimeQuery = $this->sourceQB()->from('conversation_message as lastMessage')
            ->select('lastMessage.time')
            ->whereColumn('lastMessage.conversationID', 'c.conversationID')
            ->orderByDesc('lastMessage.time')
            ->orderByDesc('lastMessage.messageID')
            ->limit(1);
        $lastMessageUserQuery = $this->sourceQB()->from('conversation_message as lastMessage')
            ->select('lastMessage.userID')
            ->whereColumn('lastMessage.conversationID', 'c.conversationID')
            ->orderByDesc('lastMessage.time')
            ->orderByDesc('lastMessage.messageID')
            ->limit(1);

        $this->export(
            'Conversation',
            $this->sourceQB()->from('conversation as c')
                ->where('c.isDraft', '=', 0)
                ->select([
                    'c.conversationID',
                    'c.subject',
                    'c.userID',
                    'c.time',
                    'c.replies',
                    'c.participants',
                ])
                ->selectSub($firstMessageQuery, 'firstMessageID')
                ->selectSub($lastMessageQuery, 'lastMessageID')
                ->selectSub($lastMessageTimeQuery, 'lastPostTime')
                ->selectSub($lastMessageUserQuery, 'lastPosterID'),
            [
                'conversationID' => 'ConversationID',
                'subject' => 'Subject',
                'firstMessageID' => 'FirstMessageID',
                'userID' => 'InsertUserID',
                'time' => 'DateInserted',
                'lastPostTime' => 'DateUpdated',
                'replies' => 'CountMessages',
                'participants' => 'CountParticipants',
                'lastPosterID' => 'UpdateUserID',
                'lastMessageID' => 'LastMessageID',
            ],
            [
                'time' => \Porter\Filter\UnixtimeToDate::class,
                'lastPostTime' => \Porter\Filter\UnixtimeToDate::class,
                'replies' => fn($value) => (int)$value + 1,
                'userID' => fn($value) => $value ?: null,
                'lastPosterID' => fn($value) => $value ?: null,
            ],
        );

        $this->export(
            'ConversationMessage',
            $this->sourceQB()->from('conversation_message as m')
                ->join('conversation as c', 'c.conversationID', '=', 'm.conversationID')
                ->where('c.isDraft', '=', 0)
                ->select('m.*'),
            [
                'messageID' => 'MessageID',
                'conversationID' => 'ConversationID',
                'message' => 'Body',
                'userID' => 'InsertUserID',
                'time' => 'DateInserted',
                'Format=BBCode',
            ],
            [
                'time' => \Porter\Filter\UnixtimeToDate::class,
                'userID' => fn($value) => $value ?: null,
            ],
        );

        $this->export(
            'UserConversation',
            $this->sourceQB()->from('conversation_to_user as cu')
                ->join('conversation as c', 'c.conversationID', '=', 'cu.conversationID')
                ->where('c.isDraft', '=', 0)
                ->where('cu.participantID', '>', 0)
                ->select('cu.*')
                ->selectSub(
                    $this->sourceQB()->from('conversation_message as lastMessage')
                        ->select('lastMessage.time')
                        ->whereColumn('lastMessage.conversationID', 'cu.conversationID')
                        ->orderByDesc('lastMessage.time')
                        ->orderByDesc('lastMessage.messageID')
                        ->limit(1),
                    'dateConversationUpdated'
                ),
            [
                'conversationID' => 'ConversationID',
                'participantID' => 'UserID',
                'lastVisitTime' => 'DateLastViewed',
                'lastMessageID' => 'LastMessageID',
                'dateConversationUpdated' => 'DateConversationUpdated',
            ],
            [
                'lastVisitTime' => \Porter\Filter\UnixtimeToDate::class,
                'dateConversationUpdated' => \Porter\Filter\UnixtimeToDate::class,
            ],
        );
    }

    /**
     * Resolve the WBB table prefix paired with the configured WCF prefix.
     */
    private function forumTable(string $table): string
    {
        $prefix = $this->dbInput()->getTablePrefix();
        if (preg_match('/^wcf(\\d+)_$/i', $prefix, $matches)) {
            return 'wbb' . $matches[1] . '_' . $table;
        }
        return $prefix . $table;
    }

    private function forumAlias(string $alias): string
    {
        return $this->dbInput()->getTablePrefix() . $alias;
    }

    /**
     * Return object-type IDs for the selected WBB object definitions.
     *
     * @return array<int>
     */
    private function objectTypeIDs(string $type): array
    {
        if (!$this->hasInputSchema('object_type', ['objectTypeID', 'objectType'])) {
            return [];
        }
        return $this->sourceQB()->from('object_type')
            ->where('objectType', '=', $type)
            ->pluck('objectTypeID')
            ->map(static fn($id) => (int)$id)
            ->all();
    }
}
