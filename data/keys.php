<?php

/**
 * Currently unused.
 */

/**
 * Record of all keys in the Porter format.
 */
return [
    'Activity' => [
        'ActivityID' => 'int',
        'ActivityTypeID' => 'int',
        'NotifyUserID' => 'int',
        'ActivityUserID' => 'int',
        'RegardingUserID' => 'int',
        'RecordID' => 'int',
        'InsertUserID' => 'int',
    ],
    'ActivityComment' => [
        'ActivityCommentID' => 'int',
        'ActivityID' => 'int',
    ],
    'ActivityType' => [
        'ActivityTypeID' => 'int',
    ],
    'Attachment' => [
        'AttachmentID' => 'int',
        'ForeignID' => 'varchar(50)',
        'ForeignUserID' => 'int',
        'SourceID' => 'varchar(32)',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int',
    ],
    'Badge' => [
        'BadgeID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int',
    ],
    'Category' => [
        'CategoryID' => 'int',
        'ParentCategoryID' => 'int',
        'PointsCategoryID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int',
        'LastCommentID' => 'int',
        'LastDiscussionID' => 'int',
    ],
    'Comment' => [
        'CommentID' => 'int',
        'DiscussionID' => 'int',
        "parentRecordID" => "int",
        "parentCommentID" => "int",
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int',
        'DeleteUserID' => 'int',
    ],
    'Conversation' => [
        'ConversationID' => 'int',
        'ForeignID' => 'varchar(40)',
        'FirstMessageID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int',
        'LastMessageID' => 'int',
        'RegardingID' => 'int'
    ],
    'ConversationMessage' => [
        'MessageID' => 'int',
        'ConversationID' => 'int',
        'InsertUserID' => 'int',
    ],
    'Discussion' => [
        'DiscussionID' => 'int',
        'ForeignID' => 'varchar(200)',
        'CategoryID' => 'int',
        "statusID" => "int",
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int',
        'FirstCommentID' => 'int',
        'LastCommentID' => 'int',
        'LastCommentUserID' => 'int',
        'RegardingID' => 'int',
        'GroupID' => 'int',
    ],
    'Event' => [
        'EventID' => 'int',
        'ParentRecordType' => 'varchar(25)',
        'ParentRecordID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int',
        'GroupID' => 'int'
    ],
    'Group' => [
        'GroupID' => 'int',
        'CategoryID' => 'int',
        'LastCommentID' => 'int',
        'LastDiscussionID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int',
    ],
    'GroupApplicant' => [
        'GroupApplicantID' => 'int',
        'GroupID' => 'int',
        'UserID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int'
    ],
    'Media' => [ // Attachments
        'MediaID' => 'int',
        'InsertUserID' => 'int',
        'ForeignID' => 'int',
        'ForeignTable' => 'varchar(100)',
    ],
    'Poll' => [
        'PollID' => 'int',
        'DiscussionID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int'
    ],
    'PollOption' => [
        'PollOptionID' => 'int',
        'PollID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int'
    ],
    'PollVote' => [
        'UserID' => 'int',
        'PollOptionID' => 'int'
    ],
    'Rank' => [
        'RankID' => 'int',
    ],
    'ReactionType' => [
        'TagID' => 'int',
    ],
    'Role' => [
        'RoleID' => 'int',
    ],
    'Status' => [ // Ideation
        'StatusID' => 'int',
        'TagID' => 'int',
    ],
    'Tag' => [
        'TagID' => 'int',
        'InsertUserID' => 'int',
        'CategoryID' => 'int',
    ],
    'TagDiscussion' => [
        'TagID' => 'int',
        'DiscussionID' => 'int',
        'CategoryID' => 'int',
    ],
    'User' => [
        'UserID' => 'int',
        'InviteUserID' => 'int',
        'RankID' => 'int',
    ],
    'UserBadge' => [
        'UserID' => 'int',
        'BadgeID' => 'int',
        'InsertUserID' => 'int'
    ],
    'UserCategory' => [
        'UserID' => 'int',
        'CategoryID' => 'int',
    ],
    'UserComment' => [
        'UserID' => 'int',
        'CommentID' => 'int',
    ],
    'UserConversation' => [
        'UserID' => 'int',
        'ConversationID' => 'int',
        'LastMessageID' => 'int',
    ],
    'UserDiscussion' => [
        'UserID' => 'int',
        'DiscussionID' => 'int',
    ],
    'UserEvent' => [
        'EventID' => 'int',
        'UserID' => 'int',
    ],
    'UserGroup' => [
        'UserGroupID' => 'int',
        'GroupID' => 'int',
        'UserID' => 'int',
        'InsertUserID' => 'int',
    ],
    'UserMeta' => [
        'UserID' => 'int',
    ],
    'UserNote' => [
        'UserNoteID' => 'int',
        'UserID' => 'int',
        'RecordID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int',
    ],
    'UserPoints' => [
        'CategoryID' => 'int',
        'UserID' => 'int',
    ],
    'UserRole' => [
        'UserID' => 'int',
        'RoleID' => 'int'
    ],
    'UserTag' => [
        'RecordID' => 'int',
        'TagID' => 'int',
        'UserID' => 'int',
    ],
];
