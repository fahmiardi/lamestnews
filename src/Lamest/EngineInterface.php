<?php

/*
 * This file is part of the Lamest application.
 *
 * (c) Daniele Alessandri <suppakilla@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Lamest;

/**
 * Defines an engine for a Lamest-driven application.
 *
 * @author Daniele Alessandri <suppakilla@gmail.com>
 */
interface EngineInterface
{
    const VERSION = '0.1.0';
    const COMPATIBILITY = '0.9.2';

    /**
     * Implements a generic and persisted rate limiting mechanism.
     *
     * @param int $delay Delay for the rate limite.
     * @param array $tags List of tags to create a limit key.
     * @return boolean
     */
    public function rateLimited($delay, Array $tags);

    /**
     * Creates a new user and returns a new autorization token.
     *
     * @param $username Username for the new user.
     * @param $password Password for the new user.
     * @return string
     */
    public function createUser($username, $password);

    /**
     * Fetches user details using the given user ID.
     *
     * @param string $userID ID of a registered user.
     * @return array
     */
    public function getUserByID($userID);

    /**
     * Fetches user details using the given username.
     *
     * @param string $username Username of a registered user.
     * @return array
     */
    public function getUserByUsername($username);

    /**
     * Adds the specified flags to the user.
     *
     * @param string $userID ID of the user.
     * @param string $flags Sequence of flags.
     * @return boolean
     */
    public function addUserFlags($userID, $flags);

    /**
     * Checks if one or more flags are set for the specified user.
     *
     * @param array $user User details.
     * @param string $flags Sequence of flags.
     * @return boolean
     */
    public function checkUserFlags(Array $user, $flags);

    /**
     * Checks if the specified user is an administrator.
     *
     * @param array $user User details.
     * @return boolean
     */
    public function isUserAdmin(Array $user);

    /**
     * Returns some counters for the specified user.
     *
     * @param array $user User details.
     * @return array
     */
    public function getUserCounters(Array $user);

    /**
     * Verifies if the username / password pair identifies a user and
     * returns its authorization token and form secret.
     *
     * @param $username Username of a registered user.
     * @param $password Password of a registered user.
     * @return array
     */
    public function verifyUserCredentials($username, $password);

    /**
     * Updates the authentication token for the specified users with a new one,
     * effectively invalidating the current sessions for that user.
     *
     * @param string $userID ID of a registered user.
     * @return string
     */
    public function updateAuthToken($userID);

    /**
     * Returns the data for a logged in user.
     *
     * @param string $authToken Token used for user authentication.
     * @return array
     */
    public function authenticateUser($authToken);

    /**
     * Increments the user karma when a certain amout of time has passed.
     *
     * @param array $user User details.
     * @param int $increment Amount of the increment.
     * @param int $interval Interval of time in seconds.
     * @return boolean
     */
    public function incrementUserKarma(Array &$user, $increment, $interval = 0);

    /**
     * Gets the karma of the specified user.
     *
     * @param array $user User details.
     * @return int
     */
    public function getUserKarma(Array $user);

    /**
     * Updates the profile for the given user.
     *
     * @param array $user User details.
     * @param array $attributes Profile attributes.
     */
    public function updateUserProfile(Array $user, Array $attributes);

    /**
     * Gets how many seconds the user has to wait before submitting a new post.
     *
     * @param array $user User details.
     */
    public function getNewPostEta(Array $user);

    /**
     * Gets the list of the current top news items.
     *
     * @param array $user Current user.
     * @param int $start Offset from which to start in the list of latest news.
     * @param int $count Maximum number of news items.
     * @return array
     */
    public function getTopNews(Array $user = null, $start = 0, $count = null);

    /**
     * Gets the list of the latest news in chronological order.
     *
     * @param array $user Current user.
     * @param int $start Offset from which to start in the list of latest news.
     * @param int $count Maximum number of news items.
     * @return array
     */
    public function getLatestNews(Array $user = null, $start = 0, $count = null);

    /**
     * Gets the list of the saved news for the specified user.
     *
     * @param array $user Current user.
     * @param int $start Offset from which to start in the list of saved news.
     * @param int $count Maximum number of news items.
     * @return array
     */
    public function getSavedNews(Array $user, $start = 0, $count = null);

    /**
     * Gets the list of comments for the specified user that received one or more
     * (including them).
     *
     * @param array $user Current user.
     * @param int $maxSubThreads Number of comments to retrieve.
     * @param boolean $reset Reset the unread replies count.
     * @return array
     */
    public function getReplies(Array $user, $maxSubThreads, $reset = false);

    /**
     * Retrieves one or more news items using their IDs.
     *
     * @param array $user Details of the current user.
     * @param string|array $newsIDs One or multiple news IDs.
     * @param boolean $updateRank Specify if the rank of news should be updated.
     * @return mixed
     */
    public function getNewsByID(Array $user, $newsIDs, $updateRank = false);

    /**
     * Retrieves the comments tree for the news.
     *
     * @param array $user Details of the current user.
     * @param array $news Details of the news item.
     * @return array
     */
    public function getNewsComments(Array $user, Array $news);

    /**
     * Adds a new news item.
     *
     * @param string $title Title of the news.
     * @param string $url URL of the news.
     * @param string $text Text of the news.
     * @param string $userID User that sumbitted the news.
     * @return string
     */
    public function insertNews($title, $url, $text, $userID);

    /**
     * Edit an already existing news item.
     *
     * @param string $user User that edited the news.
     * @param string $newsID ID of the news item.
     * @param string $title Title of the news.
     * @param string $url URL of the news.
     * @param string $text Text of the news.
     * @return string
     */
    public function editNews(Array $user, $newsID, $title, $url, $text);

    /**
     * Upvotes or downvotes the specified news item.
     *
     * The function ensures that:
     *   1) The vote is not duplicated.
     *   2) The karma is decreased for the voting user, accordingly to the vote type.
     *   3) The karma is transferred to the author of the post, if different.
     *   4) The news score is updated.
     *
     * It returns the news rank if the vote was inserted, or false upon failure.
     *
     * @param string $newsID ID of the news being voted.
     * @param array|string $user Instance or string ID of the voting user.
     * @param string $type 'up' for upvoting a news item.
     *                     'down' for downvoting a news item.
     * @param string $error Error message returned on a failed vote.
     * @return mixed New rank for the voted news, or FALSE upon error.
     */
    public function voteNews($newsID, $user, $type, &$error = null);

    /**
     * Deletes an already existing news item.
     *
     * @param string $user User that edited the news.
     * @param string $newsID ID of the news item.
     * @return boolean
     */
    public function deleteNews(Array $user, $newsID);

    /**
     * Handles various kind of actions on a comment depending on the arguments.
     *
     * 1) If comment_id is -1 insert a new comment into the specified news.
     * 2) If comment_id is an already existing comment in the context of the
     *    specified news, updates the comment.
     * 3) If comment_id is an already existing comment in the context of the
     *    specified news, but the comment is an empty string, delete the comment.
     *
     * Return value:
     *
     * If news_id does not exist or comment_id is not -1 but neither a valid
     * comment for that news, nil is returned.
     * Otherwise an hash is returned with the following fields:
     *   news_id: the news id
     *   comment_id: the updated comment id, or the new comment id
     *   op: the operation performed: "insert", "update", or "delete"
     *
     * More informations:
     *
     * The parent_id is only used for inserts (when comment_id == -1), otherwise
     * is ignored.
     *
     * @param array $user Details of the current user.
     * @param string $newsID ID of the news associated to the comment.
     * @param string $commentID ID of the comment, or -1 for a new comment.
     * @param string $parentID ID of the parent comment.
     * @param string $body Body of the comment, or null to delete an existing comment.
     * @return array
     */
    public function handleComment(Array $user, $newsID, $commentID, $parentID, $body = null);

    /**
     * Gets a specific comment.
     *
     * @param string $newsID ID of the associated news item.
     * @param string $commentID ID of the comment.
     * @return array
     */
    public function getComment($newsID, $commentID);

    /**
     * Retrieves the list of comments for the specified user.
     *
     * @param array $user Details of the current user.
     * @param string $start Offset for the list of comments.
     * @param string $count Maximum number of comments (-1 to retrieve all of them).
     * @param mixed $callback Callback invoked on each comment.
     * @return array
     */
    public function getUserComments(Array $user, $start = 0, $count = -1, $callback = null);

    /**
     * Post a new comment on the specified news item.
     *
     * @param string $newsID ID of the associated news item.
     * @param array $comment Details and contents of the new comment.
     * @return boolean
     */
    public function postComment($newsID, Array $comment);

    /**
     * Registers a vote (up or down) for the specified comment.
     *
     * @param array $user Details of the voting user.
     * @param string $newsID ID of the associated news item.
     * @param string $commentID ID of the comment.
     * @param string $type 'up' for upvoting a news item.
     *                     'down' for downvoting a news item.
     * @return boolean
     */
    public function voteComment(Array $user, $newsID, $commentID, $type);

    /**
     * Edits the specified comment by updating only the passed values.
     *
     * @param string $newsID ID of the associated news item.
     * @param string $commentID ID of the comment.
     * @param array $updates Fields and values for the update.
     * @return boolean
     */
    public function editComment($newsID, $commentID, Array $updates);

    /**
     * Deletes a specific comment.
     *
     * @param string $newsID ID of the associated news item.
     * @param string $commentID ID of the comment.
     * @return boolean
     */
    public function deleteComment($newsID, $commentID);

    /**
     * Returns the currently authenticated user.
     *
     * @return array
     */
    public function getUser();
}
