<?php

class bdApi_ControllerApi_ProfilePost extends bdApi_ControllerApi_Abstract
{
    public function actionGetIndex()
    {
        $profilePostIds = $this->_input->filterSingle('profile_post_ids', XenForo_Input::STRING);
        if (!empty($profilePostIds)) {
            return $this->responseReroute(__CLASS__, 'multiple');
        }

        return $this->responseReroute(__CLASS__, 'single');
    }

    public function actionSingle()
    {
        $profilePostId = $this->_input->filterSingle('profile_post_id', XenForo_Input::UINT);
        list($profilePost, $user) = $this->_getUserProfileHelper()->assertProfilePostValidAndViewable(
            $profilePostId,
            $this->_getProfilePostModel()->getFetchOptionsToPrepareApiData()
        );

        $data = array('profile_post' => $this->_filterDataSingle(
            $this->_getProfilePostModel()->prepareApiDataForProfilePost($profilePost, $user)
        ));

        return $this->responseData('bdApi_ViewApi_ProfilePost_Single', $data);
    }

    public function actionMultiple()
    {
        $profilePostIdsInput = $this->_input->filterSingle('profile_post_ids', XenForo_Input::STRING);
        $profilePostIds = array_map('intval', explode(',', $profilePostIdsInput));
        if (empty($profilePostIds)) {
            return $this->responseNoPermission();
        }

        $profilePosts = $this->_getProfilePostModel()->getProfilePostsByIds(
            $profilePostIds,
            $this->_getProfilePostModel()->getFetchOptionsToPrepareApiData()
        );

        $profilePostsOrdered = array();
        $profileUserIds = array();
        foreach ($profilePostIds as $profilePostId) {
            if (isset($profilePosts[$profilePostId])) {
                $profilePostsOrdered[$profilePostId] = $profilePosts[$profilePostId];
                $profileUserIds[] = $profilePosts[$profilePostId]['profile_user_id'];
            }
        }

        $profileUserIds = array_unique(array_map('intval', $profileUserIds));
        if (!empty($profileUserIds)) {
            /** @var XenForo_Model_User $userModel */
            $userModel = $this->getModelFromCache('XenForo_Model_User');
            $profileUsers = $userModel->getUsersByids($profileUserIds, array(
                'join' => XenForo_Model_User::FETCH_USER_FULL,
            ));
        }

        $profilePostsData = array();
        foreach ($profilePostsOrdered as $profilePost) {
            if (!isset($profileUsers[$profilePost['profile_user_id']])) {
                continue;
            }
            $profileUserRef = $profileUsers[$profilePost['profile_user_id']];

            $profilePostsData[] = $this->_getProfilePostModel()->prepareApiDataForProfilePost($profilePost, $profileUserRef);
        }

        $data = array(
            'profile_posts' => $this->_filterDataMany($profilePostsData),
        );

        return $this->responseData('bdApi_ViewApi_ProfilePost_Multiple', $data);
    }

    public function actionPutIndex()
    {
        $profilePostId = $this->_input->filterSingle('profile_post_id', XenForo_Input::UINT);
        list($profilePost, $user) = $this->_getUserProfileHelper()->assertProfilePostValidAndViewable($profilePostId);

        if (!$this->_getProfilePostModel()->canEditProfilePost($profilePost, $user, $errorPhraseKey)) {
            throw $this->getErrorOrNoPermissionResponseException($errorPhraseKey);
        }

        $dw = XenForo_DataWriter::create('XenForo_DataWriter_DiscussionMessage_ProfilePost');
        $dw->setExistingData($profilePost, true);
        $dw->set('message', $this->_input->filterSingle('post_body', XenForo_Input::STRING));
        $dw->save();

        return $this->responseReroute(__CLASS__, 'single');
    }

    public function actionDeleteIndex()
    {
        $profilePostId = $this->_input->filterSingle('profile_post_id', XenForo_Input::UINT);
        list($profilePost, $user) = $this->_getUserProfileHelper()->assertProfilePostValidAndViewable($profilePostId);

        $deleteType = 'soft';
        $options = array('reason' => '[bd] API');

        if (!$this->_getProfilePostModel()->canDeleteProfilePost($profilePost, $user, $deleteType, $errorPhraseKey)) {
            throw $this->getErrorOrNoPermissionResponseException($errorPhraseKey);
        }

        $dw = XenForo_DataWriter::create('XenForo_DataWriter_DiscussionMessage_ProfilePost');
        $dw->setExistingData($profilePost, true);
        $dw->set('message_state', 'deleted');
        $dw->save();

        XenForo_Model_Log::logModeratorAction(
            'profile_post',
            $profilePost,
            'delete_soft',
            $options,
            $user
        );

        return $this->responseMessage(new XenForo_Phrase('changes_saved'));
    }

    public function actionGetLikes()
    {
        $profilePostId = $this->_input->filterSingle('profile_post_id', XenForo_Input::UINT);
        list($profilePost,) = $this->_getUserProfileHelper()->assertProfilePostValidAndViewable($profilePostId);

        $likes = $this->_getLikeModel()->getContentLikes('profile_post', $profilePost['profile_post_id']);
        $users = array();

        if (!empty($likes)) {
            foreach ($likes as $like) {
                $users[] = array(
                    'user_id' => $like['like_user_id'],
                    'username' => $like['username'],
                );
            }
        }

        $data = array('users' => $users);

        return $this->responseData('bdApi_ViewApi_ProfilePost_Likes', $data);
    }

    public function actionPostLikes()
    {
        $profilePostId = $this->_input->filterSingle('profile_post_id', XenForo_Input::UINT);
        list($profilePost, $user) = $this->_getUserProfileHelper()->assertProfilePostValidAndViewable($profilePostId);

        if (!$this->_getProfilePostModel()->canLikeProfilePost($profilePost, $user, $errorPhraseKey)) {
            throw $this->getErrorOrNoPermissionResponseException($errorPhraseKey);
        }

        $likeModel = $this->_getLikeModel();

        $existingLike = $likeModel->getContentLikeByLikeUser('profile_post', $profilePost['profile_post_id'], XenForo_Visitor::getUserId());
        if (empty($existingLike)) {
            $latestUsers = $likeModel->likeContent('profile_post', $profilePost['profile_post_id'], $profilePost['user_id']);

            if ($latestUsers === false) {
                return $this->responseNoPermission();
            }
        }

        return $this->responseMessage(new XenForo_Phrase('changes_saved'));
    }

    public function actionDeleteLikes()
    {
        $profilePostId = $this->_input->filterSingle('profile_post_id', XenForo_Input::UINT);
        list($profilePost, $user) = $this->_getUserProfileHelper()->assertProfilePostValidAndViewable($profilePostId);

        if (!$this->_getProfilePostModel()->canLikeProfilePost($profilePost, $user, $errorPhraseKey)) {
            throw $this->getErrorOrNoPermissionResponseException($errorPhraseKey);
        }

        $likeModel = $this->_getLikeModel();

        $existingLike = $likeModel->getContentLikeByLikeUser('profile_post', $profilePost['profile_post_id'], XenForo_Visitor::getUserId());
        if (!empty($existingLike)) {
            $latestUsers = $likeModel->unlikeContent($existingLike);

            if ($latestUsers === false) {
                return $this->responseNoPermission();
            }
        }

        return $this->responseMessage(new XenForo_Phrase('changes_saved'));
    }

    public function actionGetComments()
    {
        $profilePostId = $this->_input->filterSingle('profile_post_id', XenForo_Input::UINT);

        $commentId = $this->_input->filterSingle('comment_id', XenForo_Input::UINT);
        if (!empty($commentId)) {
            list($comment, $profilePost, $user) = $this->_getUserProfileHelper()->assertProfilePostCommentValidAndViewable(
                $commentId,
                $this->_getProfilePostModel()->getCommentFetchOptionsToPrepareApiData()
            );
            if ($profilePost['profile_post_id'] != $profilePostId) {
                return $this->responseNoPermission();
            }

            $data = array(
                'comment' => $this->_filterDataSingle($this->_getProfilePostModel()->prepareApiDataForComment($comment, $profilePost, $user)),
            );

            return $this->responseData('bdApi_ViewApi_ProfilePost_Comments_Single', $data);
        }

        list($profilePost, $user) = $this->_getUserProfileHelper()->assertProfilePostValidAndViewable($profilePostId);

        $pageNavParams = array();
        $page = $this->_input->filterSingle('page', XenForo_Input::UINT);
        $limit = XenForo_Application::get('options')->messagesPerPage;

        $inputLimit = $this->_input->filterSingle('limit', XenForo_Input::UINT);
        if (!empty($inputLimit)) {
            $limit = $inputLimit;
            $pageNavParams['limit'] = $inputLimit;
        }

        $fetchOptions = array(
            'perPage' => $limit,
            'page' => $page
        );

        $comments = $this->_getProfilePostModel()->getProfilePostCommentsByProfilePost(
            $profilePostId,
            0,
            $this->_getProfilePostModel()->getCommentFetchOptionsToPrepareApiData($fetchOptions)
        );

        $total = $profilePost['comment_count'];

        $data = array(
            'comments' => $this->_filterDataMany($this->_getProfilePostModel()->prepareApiDataForComments($comments, $profilePost, $user)),
            'comments_total' => $total,
        );

        bdApi_Data_Helper_Core::addPageLinks($this->getInput(), $data, $limit, $total, $page, 'profile-posts/comments', $profilePost, $pageNavParams);

        return $this->responseData('bdApi_ViewApi_ProfilePost_Comments', $data);
    }

    public function actionPostComments()
    {
        $profilePostId = $this->_input->filterSingle('profile_post_id', XenForo_Input::UINT);
        list($profilePost, $user) = $this->_getUserProfileHelper()->assertProfilePostValidAndViewable($profilePostId);

        if (!$this->_getProfilePostModel()->canCommentOnProfilePost($profilePost, $user, $errorPhraseKey)) {
            throw $this->getErrorOrNoPermissionResponseException($errorPhraseKey);
        }

        $commentBody = $this->_input->filterSingle('comment_body', XenForo_Input::STRING);
        $visitor = XenForo_Visitor::getInstance();

        $dw = XenForo_DataWriter::create('XenForo_DataWriter_ProfilePostComment');
        $dw->setExtraData(XenForo_DataWriter_ProfilePostComment::DATA_PROFILE_USER, $user);
        $dw->setExtraData(XenForo_DataWriter_ProfilePostComment::DATA_PROFILE_POST, $profilePost);
        $dw->bulkSet(array(
            'profile_post_id' => $profilePost['profile_post_id'],
            'user_id' => $visitor['user_id'],
            'username' => $visitor['username'],
            'message' => $commentBody
        ));
        $dw->setOption(XenForo_DataWriter_ProfilePostComment::OPTION_MAX_TAGGED_USERS, $visitor->hasPermission('general', 'maxTaggedUsers'));
        $dw->preSave();

        if (!$dw->hasErrors()) {
            $this->assertNotFlooding('post');
        }

        $dw->save();
        $comment = $dw->getMergedData();

        $this->_request->setParam('comment_id', $comment['profile_post_comment_id']);
        return $this->responseReroute(__CLASS__, 'get-comments');
    }

    public function actionDeleteComments()
    {
        $profilePostId = $this->_input->filterSingle('profile_post_id', XenForo_Input::UINT);

        $commentId = $this->_input->filterSingle('comment_id', XenForo_Input::UINT);
        list($comment, $profilePost, $user) = $this->_getUserProfileHelper()->assertProfilePostCommentValidAndViewable(
            $commentId,
            $this->_getProfilePostModel()->getCommentFetchOptionsToPrepareApiData()
        );
        if ($profilePost['profile_post_id'] != $profilePostId) {
            return $this->responseNoPermission();
        }

        if (!$this->_getProfilePostModel()->canDeleteProfilePostComment($comment, $profilePost, $user, $errorPhraseKey)) {
            throw $this->getErrorOrNoPermissionResponseException($errorPhraseKey);
        }

        $dw = XenForo_DataWriter::create('XenForo_DataWriter_ProfilePostComment');
        $dw->setExistingData($commentId);
        $dw->delete();

        XenForo_Model_Log::logModeratorAction(
            'profile_post',
            $profilePost,
            'comment_delete',
            array(
                'username' => $comment['username'],
                'reason' => '[bd] API',
            ),
            $user
        );

        return $this->responseMessage(new XenForo_Phrase('changes_saved'));
    }

    public function actionPostReport()
    {
        $profilePostId = $this->_input->filterSingle('profile_post_id', XenForo_Input::UINT);
        list($profilePost, $user) = $this->_getUserProfileHelper()->assertProfilePostValidAndViewable($profilePostId);

        if (!$this->_getProfilePostModel()->canReportProfilePost($profilePost, $user, $errorPhraseKey)) {
            throw $this->getErrorOrNoPermissionResponseException($errorPhraseKey);
        }

        $message = $this->_input->filterSingle('message', XenForo_Input::STRING);
        if (!$message) {
            return $this->responseError(new XenForo_Phrase('please_enter_reason_for_reporting_this_message'), 400);
        }

        $this->assertNotFlooding('report');

        /* @var $reportModel XenForo_Model_Report */
        $reportModel = $this->getModelFromCache('XenForo_Model_Report');
        $reportModel->reportContent('profile_post', $profilePost, $message);

        return $this->responseMessage(new XenForo_Phrase('changes_saved'));
    }

    /**
     * @return XenForo_ControllerHelper_UserProfile
     */
    protected function _getUserProfileHelper()
    {
        return $this->getHelper('UserProfile');
    }

    /**
     * @return bdApi_XenForo_Model_ProfilePost
     */
    protected function _getProfilePostModel()
    {
        return $this->getModelFromCache('XenForo_Model_ProfilePost');
    }

    /**
     * @return XenForo_Model_Like
     */
    protected function _getLikeModel()
    {
        return $this->getModelFromCache('XenForo_Model_Like');
    }
}