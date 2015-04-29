<?php

class bdApi_XenForo_Model_Thread extends XFCP_bdApi_XenForo_Model_Thread
{
    protected $_bdApi_limitQueryResults_nodeId = false;

    public function getFetchOptionsToPrepareApiData(array $fetchOptions = array())
    {
        if (empty($fetchOptions['join'])) {
            $fetchOptions['join'] = XenForo_Model_Thread::FETCH_USER;
        } else {
            $fetchOptions['join'] |= XenForo_Model_Thread::FETCH_USER;
        }

        $fetchOptions['watchUserId'] = XenForo_Visitor::getUserId();
        $fetchOptions['replyBanUserId'] = XenForo_Visitor::getUserId();

        return $fetchOptions;
    }

    public function prepareApiDataForThreads(array $threads, array $forum, array $firstPosts)
    {
        $data = array();

        foreach ($threads as $key => $thread) {
            $firstPost = array();
            if (isset($firstPosts[$thread['first_post_id']])) {
                $firstPost = $firstPosts[$thread['first_post_id']];
            }

            $data[] = $this->prepareApiDataForThread($thread, $forum, $firstPost);
        }

        return $data;
    }

    public function prepareApiDataForThread(array $thread, array $forum, array $firstPost)
    {
        /* @var $postModel bdApi_XenForo_Model_Post */
        $postModel = $this->_getPostModel();

        $thread = $this->prepareThread($thread, $forum);

        $publicKeys = array(
            // xf_thread
            'thread_id' => 'thread_id',
            'node_id' => 'forum_id',
            'title' => 'thread_title',
            'view_count' => 'thread_view_count',
            'user_id' => 'creator_user_id',
            'username' => 'creator_username',
            'post_date' => 'thread_create_date',
            'last_post_date' => 'thread_update_date',
        );

        $data = bdApi_Data_Helper_Core::filter($thread, $publicKeys);

        if (isset($thread['reply_count'])) {
            $data['thread_post_count'] = $thread['reply_count'] + 1;
        }

        if (isset($thread['sticky']) AND isset($thread['discussion_state'])) {
            switch ($thread['discussion_state']) {
                case 'visible':
                    $data['thread_is_published'] = true;
                    $data['thread_is_deleted'] = false;
                    $data['thread_is_sticky'] = empty($thread['sticky']) ? false : true;
                    break;
                case 'moderated':
                    $data['thread_is_published'] = false;
                    $data['thread_is_deleted'] = false;
                    $data['thread_is_sticky'] = false;
                    break;
                case 'deleted':
                    $data['thread_is_published'] = false;
                    $data['thread_is_deleted'] = true;
                    $data['thread_is_sticky'] = false;
                    break;
            }
        }

        $data['thread_is_followed'] = !empty($thread['thread_is_watched']);

        if (!empty($firstPost)) {
            $data['first_post'] = $postModel->prepareApiDataForPost($firstPost, $thread, $forum);
        }

        $data['links'] = array(
            'permalink' => XenForo_Link::buildPublicLink('threads', $thread),
            'detail' => XenForo_Link::buildApiLink('threads', $thread),
            'followers' => XenForo_Link::buildApiLink('threads/followers', $thread),
            'forum' => XenForo_Link::buildApiLink('forums', $thread),
            'posts' => XenForo_Link::buildApiLink('posts', array(), array('thread_id' => $thread['thread_id'])),
            'first_poster' => XenForo_Link::buildApiLink('users', $thread),
            'first_post' => XenForo_Link::buildApiLink('posts', array('post_id' => $thread['first_post_id'])),
        );

        if ($thread['last_post_user_id'] != $thread['user_id']) {
            $data['links']['last_poster'] = XenForo_Link::buildApiLink('users', array(
                'user_id' => $thread['last_post_user_id'],
                'username' => $thread['last_post_username']
            ));
        }

        if ($thread['last_post_id'] != $thread['first_post_id']) {
            $data['links']['last_post'] = XenForo_Link::buildApiLink('posts', array('post_id' => $thread['last_post_id']));
        }

        $data['permissions'] = array(
            'view' => $this->canViewThread($thread, $forum),
            'edit' => $this->canEditThread($thread, $forum),
            'delete' => $this->canDeleteThread($thread, $forum),
            'follow' => $this->canWatchThread($thread, $forum),
            'post' => $this->canReplyToThread($thread, $forum),
            'upload_attachment' => $this->_getForumModel()->canUploadAndManageAttachment($forum),
        );

        return $data;
    }

    public function bdApi_getUnreadThreadIdsInForum($userId, $forumId, array $fetchOptions = array())
    {
        $this->_bdApi_limitQueryResults_nodeId = $forumId;
        $threadIds = $this->getUnreadThreadIds($userId, $fetchOptions);
        $this->_bdApi_limitQueryResults_nodeId = false;

        return $threadIds;
    }

    public function limitQueryResults($query, $limit, $offset = 0)
    {
        if ($this->_bdApi_limitQueryResults_nodeId !== false) {
            // TODO: improve this, it may break some query if the WHERE conditions contain a
            // mix of AND and OR operators
            $replacement = false;

            if (!is_array($this->_bdApi_limitQueryResults_nodeId)) {
                if ($this->_bdApi_limitQueryResults_nodeId > 0) {
                    $replacement = "\nthread.node_id = " . $this->_getDb()->quote($this->_bdApi_limitQueryResults_nodeId) . " AND\n";
                }
            } else {
                if (!empty($this->_bdApi_limitQueryResults_nodeId)) {
                    $replacement = "\nthread.node_id IN (" . $this->_getDb()->quote($this->_bdApi_limitQueryResults_nodeId) . ") AND\n";
                }
            }

            if ($replacement !== false AND preg_match('/\s(WHERE)\s/i', $query, $matches, PREG_OFFSET_CAPTURE) === 1) {
                $query = substr_replace($query, $replacement, $matches[1][1] + strlen($matches[1][0]), 0);
            }
        }

        return parent::limitQueryResults($query, $limit, $offset);
    }

}
