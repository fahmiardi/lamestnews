<?php

/*
 * This file is part of the Lamer News application.
 *
 * (c) Daniele Alessandri <suppakilla@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Lamernews;

use Predis\Client;
use Predis\Pipeline\PipelineContext;

/**
 * Main abstraction to access the data of Lamer News stored in Redis.
 *
 * @author Daniele Alessandri <suppakilla@gmail.com>
 */
class RedisDatabase implements DatabaseInterface
{
    private $_redis;
    private $_options;

    /**
     * Initializes the database class.
     *
     * @param Client $redis Redis client used to access the database
     * @param array $options Array of options
     */
    public function __construct(Client $redis, Array $options = array())
    {
        $this->_redis = $redis;
        $this->_options = array_merge($this->getDefaults(), $options);
    }

    /**
     * Gets the default options to process the data stored in the database.
     *
     * @return array
     */
    protected function getDefaults()
    {
        return array(
            'password_min_length' => 8,

            // comments
            'comment_max_length' => 4096,
            'comment_edit_time' => 3600 * 2,
            'comment_reply_shift' => 60,

            // user
            'karma_increment_interval' => 3600 * 3,
            'karma_increment_amount' => 1,

            // news and ranking
            'news_age_padding' => 60 * 10,
            'top_news_per_page' => 30,
            'latest_news_per_page' => 100,
            'news_edit_time' => 60 * 15,
            'news_score_log_start' => 10,
            'news_score_log_booster' => 2,
            'rank_aging_factor' => 1,
            'prevent_repost_time' => 3600 * 48,
            'news_submission_break' => 60 * 15,
            'saved_news_per_page' => 10,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function createUser($username, $password)
    {
        $redis = $this->getRedis();

        if ($redis->exists("username.to.id:".strtolower($username))) {
            return;
        }

        $userID = $redis->incr('users.count');
        $authToken = Helpers::generateRandom();
        $salt = Helpers::generateRandom();

        $userDetails = array(
            'id' => $userID,
            'username' => $username,
            'salt' => $salt,
            'password' => Helpers::pbkdf2($password, $salt),
            'ctime' => time(),
            'karma' => 10,
            'about' => '',
            'email' => '',
            'auth' => $authToken,
            'apisecret' => Helpers::generateRandom(),
            'flags' => '',
            'karma_incr_time' => time(),
        );

        $redis->hmset("user:$userID", $userDetails);
        $redis->set("username.to.id:".strtolower($username), $userID);
        $redis->set("auth:$authToken", $userID);

        return $authToken;
    }

    /**
     * {@inheritdoc}
     */
    public function getUserByID($userID)
    {
        return $this->getRedis()->hgetall("user:$userID");
    }

    /**
     * {@inheritdoc}
     */
    public function getUserByUsername($username)
    {
        $userID = $this->getRedis()->get('username.to.id:'.strtolower($username));
        if (!$userID) {
            return;
        }

        return $this->getUserByID($userID);
    }

    /**
     * {@inheritdoc}
     */
    public function getUserCounters(Array $user)
    {
        $counters = $this->getRedis()->pipeline(function($pipe) use($user) {
            $pipe->zcard("user.posted:{$user['id']}");
            $pipe->zcard("user.comments:{$user['id']}");
        });

        return array(
            'posted_news' => $counters[0],
            'posted_comments' => $counters[1],
        );
    }

    /**
     * {@inheritdoc}
     */
    public function verifyUserCredentials($username, $password)
    {
        $user = $this->getUserByUsername($username);
        if (!$user) {
            return;
        }

        $hashedPassword = Helpers::pbkdf2($password, $user['salt']);
        if ($user['password'] !== $hashedPassword) {
            return;
        }

        return array(
            $user['auth'],
            $user['apisecret'],
        );
    }

    /**
     * {@inheritdoc}
     */
    public function authenticateUser($authToken)
    {
        if (!$authToken) {
            return;
        }

        $userID = $this->getRedis()->get("auth:$authToken");
        if (!$userID) {
            return;
        }

        $user = $this->getRedis()->hgetall("user:$userID");
        if (!$user) {
            return;
        }

        return $user;
    }

    /**
     * {@inheritdoc}
     */
    public function updateAuthToken($userID)
    {
        $user = $this->getUserByID($userID);
        if (!$user) {
            return;
        }

        $redis = $this->getRedis();
        $redis->del("auth:{$user['auth']}");

        $newAuthToken = Helpers::generateRandom();
        $redis->hmset("user:$userID","auth", $newAuthToken);
        $redis->set("auth:$newAuthToken", $userID);

        return $newAuthToken;
    }

    /**
     * {@inheritdoc}
     */
    public function incrementUserKarma(Array &$user, $increment, $interval)
    {
        $now = time();
        if ((int) $user['karma_incr_time'] >= $now - $interval) {
            return false;
        }

        $userKey = "user:{$user['id']}";
        $redis = $this->getRedis();

        $redis->hset($userKey, 'karma_incr_time', $now);
        $redis->hincrby($userKey, 'karma', $increment);

        $user['karma'] = (int) $user['karma'] + $increment;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function updateUserProfile(Array $user, Array $attributes) {
        $this->getRedis()->hmset("user:{$user['id']}", array(
            'about' => substr($attributes['about'], 0, 4095),
            'email' => substr($attributes['email'], 0, 255),
            'password' => $attributes['password'],
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function getNewPostEta(Array $user) {
        return $this->getRedis()->ttl("user:{$user['id']}:submitted_recently");
    }

    /**
     * {@inheritdoc}
     */
    public function getTopNews(Array $user = null)
    {
        $newsIDs = $this->getRedis()->zrevrange('news.top', 0, $this->getOption('top_news_per_page') - 1);

        if (!$newsIDs) {
            return array();
        }

        $result = $this->getNewsByID($user ?: array(), $newsIDs, true);

        // Sort by rank before returning, since we adjusted ranks during iteration.
        usort($result, function($a, $b) {
            return (float) $b['rank'] - (float) $a['rank'];
        });

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getLatestNews(Array $user = null)
    {
        $newsIDs = $this->getRedis()->zrevrange('news.cron', 0, $this->getOption('latest_news_per_page') - 1);
        return $this->getNewsByID($user ?: array(), $newsIDs, true);
    }

    /**
     * {@inheritdoc}
     */
    public function getSavedNews(Array $user, $start = 0)
    {
        $redis = $this->getRedis();
        $newsIDs = $redis->zrevrange("user.saved:{$user['id']}", $start, $start + $this->getOption('saved_news_per_page'));
        return array(
            'news' => $this->getNewsByID($user, $newsIDs),
            'count' => $redis->zcard("user.saved:{$user['id']}"),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getNewsByID(Array $user, $newsIDs, $updateRank = false)
    {
        if (!$newsIDs) {
            return array();
        }

        $newsIDs = !is_array($newsIDs) ? array($newsIDs) : array_values(array_filter($newsIDs));

        $redis = $this->getRedis();

        $newslist = $redis->pipeline(function($pipe) use($newsIDs) {
            foreach ($newsIDs as $newsID) {
                $pipe->hgetall("news:$newsID");
            }
        });

        if (!$newslist) {
            return array();
        }

        $result = array();

        // Get all the news
        $redis->pipeline(function($pipe) use($newslist, &$result) {
            foreach ($newslist as $news) {
                // Adjust rank if too different from the real-time value.
                if ($updateRank) {
                    $this->updateNewsRank($pipe, $news);
                }
                $result[] = $news;
            }
        });

        // Get the associated users information.
        $usernames = $redis->pipeline(function($pipe) use($result) {
            foreach ($result as $news) {
                $pipe->hget("user:{$news['user_id']}", 'username');
            }
        });

        foreach ($result as $i => &$news) {
            $news['username'] = $usernames[$i];
        }

        // Load user's vote information if we are in the context of a
        // registered user.
        if (isset($user)) {
            $votes = $redis->pipeline(function($pipe) use ($result, $user) {
                foreach ($result as $news) {
                    $pipe->zscore("news.up:{$news['id']}", $user['id']);
                    $pipe->zscore("news.down:{$news['id']}", $user['id']);
                }
            });
            foreach ($result as $i => &$news) {
                if ($votes[$i * 2]) {
                    $news['voted'] = 'up';
                }
                else if ($votes[$i * 2 + 1]) {
                    $news['voted'] = 'down';
                }
                else {
                    $news['voted'] = false;
                }
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getNewsComments(Array $user, Array $news)
    {
        $tree = array();
        $users = array();
        $comments = $this->getRedis()->hgetall("thread:comment:{$news['id']}");

        foreach ($comments as $id => $comment) {
            if ($id == 'nextid') {
                continue;
            }
            $comment = json_decode($comment, true);
            $comment['id'] = $id;
            $parentID = $comment['parent_id'];

            $userID = $comment['user_id'];
            if (!isset($users[$userID])) {
                $users[$userID] = $this->getUserByID($userID);
            }
            $comment['user'] = $users[$userID];

            if (!isset($tree[$parentID])) {
                $tree[$parentID] = array();
            }
            $tree[$parentID][] = $comment;
        }

        return $tree;
    }

    /**
     * Updates the rank of a news item.
     *
     * @param PipelineContext $pipe Pipeline used to batch the update operations.
     * @param array $news Single news item.
     */
    protected function updateNewsRank(PipelineContext $pipe, Array &$news)
    {
        $realRank = $this->computeNewsRank($news);
        if (abs($realRank - (float) $news['rank']) > 0.001) {
            $pipe->hmset("news:{$news['id']}", 'rank', $realRank);
            $pipe->zadd('news.top', $realRank , $news['id']);
            $news['rank'] = (string) $realRank;
        }
    }

    /**
     * Compute the score for a news item.
     *
     * @param array $news News item.
     * @return float
     */
    protected function computerNewsScore(Array $news)
    {
        $redis = $this->getRedis();

        // TODO: For now we are doing a naive sum of votes, without time-based
        // filtering, nor IP filtering. We could use just ZCARD here of course,
        // but ZRANGE already returns everything needed for vote analysis once
        // implemented.
        $upvotes = $redis->zrange("news.up:{$news['id']}", 0, -1, 'withscores');
        $downvotes = $redis->zrange("news.down:{$news['id']}", 0, -1, 'withscores');

        // Now let's add the logarithm of the sum of all the votes, since
        // something with 5 up and 5 down is less interesting than something
        // with 50 up and 50 down.
        $score = count($upvotes) / 2 - count($downvotes) / 2;
        $votes = count($upvotes) / 2 + count($downvotes) / 2;

        if ($votes > ($logStart = $this->getOption('news_score_log_start'))) {
            $score += log($votes - $logStart) * $this->getOption('news_score_log_booster');
        }

        return $score;
    }

    /**
     * Computes the rank of a news item.
     *
     * @param array $news Single news item.
     * @return float
     */
    protected function computeNewsRank(Array $news)
    {
        $age = time() - (int) $news['ctime'] + $this->getOption('news_age_padding');
        return (((float) $news['score']) * 1000) / ($age * $this->getOption('rank_aging_factor'));
    }

    /**
     * {@inheritdoc}
     */
    public function insertNews($title, $url, $text, $userID)
    {
        $redis = $this->getRedis();

        // Use a kind of URI using the "text" scheme if now URL has been provided.
        // TODO: remove duplicated code.
        $textPost = !$url;
        if (!$url) {
            $url = 'text://' . substr($text, 0, $this->getOption('comment_max_length'));
        }

        // Verify if a news with the same URL has been already submitted.
        if (!$textPost && ($id = $redis->get("url:$url"))) {
            return (int) $id;
        }

        $ctime = time();
        $newsID = $redis->incr('news.count');
        $newsDetails = array(
            'id' => $newsID,
            'title' => $title,
            'url' => $url,
            'user_id' => $userID,
            'ctime' => $ctime,
            'score' => 0,
            'rank' => 0,
            'up' => 0,
            'down' => 0,
            'comments' => 0,
        );
        $redis->hmset("news:$newsID", $newsDetails);

        // The posting user virtually upvoted the news posting it.
        $newsRank = $this->voteNews($newsID, $userID, 'up');
        // Add the news to the user submitted news.
        $redis->zadd("user.posted:$userID", $ctime, $newsID);
        // Add the news into the chronological view.
        $redis->zadd('news.cron', $ctime, $newsID);
        // Add the news into the top view.
        $redis->zadd('news.top', $newsRank, $newsID);
        // Set a timeout indicating when the user may post again
        $redis->setex("user:$userID:submitted_recently", $this->getOption('news_submission_break') ,'1');

        if (!$textPost) {
            // Avoid reposts for a certain amount of time using an expiring key.
            $redis->setex("url:$url", $this->getOption('prevent_repost_time'), $newsID);
        }

        return $newsID;
    }

    /**
     * {@inheritdoc}
     */
    public function editNews(Array $user, $newsID, $title, $url, $text)
    {
        $news = $this->getNewsByID($user, $newsID);

        if (!$news || $news['user_id'] != $userID) {
            return false;
        }
        if ((int) $news['ctime'] > time() - $this->getOption('news_edit_time')) {
            return false;
        }

        // Use a kind of URI using the "text" scheme if now URL has been provided.
        // TODO: remove duplicated code.
        $textPost = !$url;
        if (!$url) {
            $url = 'text://' . substr($text, 0, $this->getOption('comment_max_length'));
        }

        $redis = $this->getRedis();

        // The URL for recently posted news cannot be changed.
        if (!$textPost && $url != $news['url']) {
            if ($redis->get("url:$url")) {
                return false;
            }
            // Prevent DOS attacks by locking the new URL after it has been changed.
            $redis->del("url:{$news['url']}");
            if (!$textPost) {
                $redis->setex("url:$url", $this->getOption('prevent_repost_time', $newsID));
            }
        }

        $redis->hmset("news:$newsID", array(
            'title' => $title,
            'url' => $url,
        ));

        return $newsID;
    }

    /**
     * {@inheritdoc}
     */
    public function voteNews($newsID, $user, $type)
    {
        if ($type !== 'up' && $type !== 'down') {
            return false;
        }

        $user = is_array($user) ? $user : $this->getUserByID($user);
        $news = $this->getNewsByID($user, $newsID);
        if (!$user || !$news) {
            return false;
        }

        $redis = $this->getRedis();

        // Verify that the user has not already voted the news item.
        $hasUpvoted = $redis->zscore("news.up:$newsID", $user['id']);
        $hasDownvoted = $redis->zscore("news.down:$newsID", $user['id']);
        if ($hasUpvoted || $hasDownvoted) {
            return false;
        }

        $now = time();
        // Add the vote for the news item.
        if ($redis->zadd("news.$type:$newsID", $now, $user['id'])) {
            $redis->hincrby("news:$newsID", $type, 1);
        }
        if ($type === 'up') {
            $redis->zadd("user.saved:{$user['id']}", $now, $newsID);
        }

        // Compute the new score and karma updating the news accordingly.
        $news['score'] = $this->computerNewsScore($news);
        $rank = $this->computeNewsRank($news);
        $redis->hmset("news:$newsID", array(
            'score' => $news['score'],
            'rank' => $rank,
        ));
        $redis->zadd('news.top', $rank, $newsID);

        return $rank;
    }

    /**
     * {@inheritdoc}
     */
    public function handleComment(Array $user, $newsID, $commentID, $parentID, $body = null)
    {
        $redis = $this->getRedis();
        $news = $this->getNewsByID($user, $newsID);

        if (!$news) {
            return false;
        }

        if ($commentID == -1) {
            $comment = array(
                'score' => 0,
                'body' => $body,
                'parent_id' => $parentID,
                'user_id' => $user['id'],
                'ctime' => time(),
            );

            $commentID = $this->postComment($newsID, $comment);

            if (!$commentID) {
                return false;
            }

            $redis->hincrby("news:$newsID", 'comments', 1);
            $redis->zadd("user.comments:{$user['id']}", time(), "$newsID-$commentID");

            return array(
                'news_id' => $newsID,
                'comment_id' => $commentID,
                'op' => 'insert',
            );
        }

        // If we reached this point the next step is either to update or
        // delete the comment. So we make sure the user_id of the request
        // matches the user_id of the comment.
        // We also make sure the user is in time for an edit operation.
        $comment = $this->getComment($newsID, $commentID);

        if (!$comment || $comment['user_id'] != $user['id']) {
            return false;
        }
        if (!($comment['ctime'] > (time - $this->getOption('comment_edit_time')))) {
            return false;
        }

        if (!$body) {
            if (!$this->deleteComment($newsID, $commentID)) {
                return false;
            }
            $redis->hincrby("news:$newsID", 'comments', -1);

            return array(
                'news_id' => $newsID,
                'comment_id' => $commentID,
                'op' => 'delete',
            );
        }
        else {
            $update = array('body' => $body);
            if ($comment['del'] == true) {
                $update['del'] = 0;
            }
            if (!$this->editComment($newsID, $commentID, $update)) {
                return false;
            }

            return array(
                'news_id' => $newsID,
                'comment_id' => $commentID,
                'op' => 'update',
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getComment($newsID, $commentID)
    {
        $json = $this->getRedis()->hget("thread:comment:$newsID", $commentID);

        if ($json) {
            return json_decode($json, true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function postComment($newsID, Array $comment)
    {
        if (!isset($comment['parent_id'])) {
            // TODO: "no parent_id field"
            return false;
        }

        $redis = $this->getRedis();
        $threadKey = "thread:comment:$newsID";

        if ($comment['parent_id'] != -1) {
            if (!$redis->hget($threadKey, $comment['parent_id'])) {
                return false;
            }
        }

        $commentID = $redis->hincrby($threadKey, 'nextid', 1);
        $redis->hset($threadKey, $commentID, json_encode($comment));

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function editComment($newsID, $commentID, Array $updates)
    {
        $redis = $this->getRedis();
        $threadKey = "thread:comment:$newsID";

        $json = $redis->hget($threadKey, $commentID);

        if (!$json) {
            return false;
        }

        $comment = array_merge(json_decode($json, true), $updates);
        $redis->hset($threadKey, $commentID, json_encode($comment));

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteComment($newsID, $commentID)
    {
        return $this->editComment($newsID, $commentID, array('del' => 1));
    }

    /**
     * Gets an option by its name or returns all the options.
     *
     * @param string $option Name of the option.
     * @return mixed
     */
    public function getOption($option = null)
    {
        if (!$option) {
            return $this->_options;
        }
        if (isset($this->_options[$option])) {
            return $this->_options[$option];
        }
    }

    /**
     * Gets the underlying Redis client used to interact with Redis.
     *
     * @return Client
     */
    public function getRedis()
    {
        return $this->_redis;
    }
}
