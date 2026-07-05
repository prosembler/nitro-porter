<?php

/**
 * Currently unused.
 */

/**
 * Record of all keys in the Porter format.
 */
return array(
    'Activity' => array(
        'ActivityID' => 'int',
        'ActivityTypeID' => 'int',
        'NotifyUserID' => 'int',
        'ActivityUserID' => 'int',
        'RegardingUserID' => 'int',
        'RecordID' => 'int',
        'InsertUserID' => 'int',
    ),
    'ActivityComment' => array(
        'ActivityCommentID' => 'int',
        'ActivityID' => 'int',
    ),
    'ActivityType' => array(
        'ActivityTypeID' => 'int',
    ),
    'Attachment' => array(
        'AttachmentID' => 'int',
        'ForeignID' => 'varchar(50)',
        'ForeignUserID' => 'int',
        'SourceID' => 'varchar(32)',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int',
    ),
    'Badge' => array(
        'BadgeID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int',
    ),
    'Category' => array(
        'CategoryID' => 'int',
        'ParentCategoryID' => 'int',
        'PointsCategoryID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int',
        'LastCommentID' => 'int',
        'LastDiscussionID' => 'int',
    ),
    'Comment' => array(
        'CommentID' => 'int',
        'DiscussionID' => 'int',
        "parentRecordID" => "int",
        "parentCommentID" => "int",
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int',
        'DeleteUserID' => 'int',
    ),
    'Conversation' => array(
        'ConversationID' => 'int',
        'ForeignID' => 'varchar(40)',
        'FirstMessageID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int',
        'LastMessageID' => 'int',
        'RegardingID' => 'int'
    ),
    'ConversationMessage' => array(
        'MessageID' => 'int',
        'ConversationID' => 'int',
        'InsertUserID' => 'int',
    ),
    'Discussion' => array(
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
    ),
    'Event' => array(
        'EventID' => 'int',
        'ParentRecordType' => 'varchar(25)',
        'ParentRecordID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int',
        'GroupID' => 'int'
    ),
    'Group' => array(
        'GroupID' => 'int',
        'CategoryID' => 'int',
        'LastCommentID' => 'int',
        'LastDiscussionID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int',
    ),
    'GroupApplicant' => array(
        'GroupApplicantID' => 'int',
        'GroupID' => 'int',
        'UserID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int'
    ),
    'Media' => array( // Attachments
        'MediaID' => 'int',
        'InsertUserID' => 'int',
        'ForeignID' => 'int',
        'ForeignTable' => 'varchar(100)',
    ),
    'Poll' => array(
        'PollID' => 'int',
        'DiscussionID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int'
    ),
    'PollOption' => array(
        'PollOptionID' => 'int',
        'PollID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int'
    ),
    'PollVote' => array(
        'UserID' => 'int',
        'PollOptionID' => 'int'
    ),
    'Rank' => array(
        'RankID' => 'int',
    ),
    'ReactionType' => array(
        'TagID' => 'int',
    ),
    'Role' => array(
        'RoleID' => 'int',
    ),
    'Status' => array( // Ideation
        'StatusID' => 'int',
        'TagID' => 'int',
    ),
    'Tag' => array(
        'TagID' => 'int',
        'InsertUserID' => 'int',
        'CategoryID' => 'int',
    ),
    'TagDiscussion' => array(
        'TagID' => 'int',
        'DiscussionID' => 'int',
        'CategoryID' => 'int',
    ),
    'User' => array(
        'UserID' => 'int',
        'InviteUserID' => 'int',
        'RankID' => 'int',
    ),
    'UserBadge' => array(
        'UserID' => 'int',
        'BadgeID' => 'int',
        'InsertUserID' => 'int'
    ),
    'UserCategory' => array(
        'UserID' => 'int',
        'CategoryID' => 'int',
    ),
    'UserComment' => array(
        'UserID' => 'int',
        'CommentID' => 'int',
    ),
    'UserConversation' => array(
        'UserID' => 'int',
        'ConversationID' => 'int',
        'LastMessageID' => 'int',
    ),
    'UserDiscussion' => array(
        'UserID' => 'int',
        'DiscussionID' => 'int',
    ),
    'UserEvent' => array(
        'EventID' => 'int',
        'UserID' => 'int',
    ),
    'UserGroup' => array(
        'UserGroupID' => 'int',
        'GroupID' => 'int',
        'UserID' => 'int',
        'InsertUserID' => 'int',
    ),
    'UserMeta' => array(
        'UserID' => 'int',
    ),
    'UserNote' => array(
        'UserNoteID' => 'int',
        'UserID' => 'int',
        'RecordID' => 'int',
        'InsertUserID' => 'int',
        'UpdateUserID' => 'int',
    ),
    'UserPoints' => array(
        'CategoryID' => 'int',
        'UserID' => 'int',
    ),
    'UserRole' => array(
        'UserID' => 'int',
        'RoleID' => 'int'
    ),
    'UserTag' => array(
        'RecordID' => 'int',
        'TagID' => 'int',
        'UserID' => 'int',
    ),
);
