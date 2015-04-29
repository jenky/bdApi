<?php

class bdApi_XenForo_DataWriter_Discussion_Thread extends XFCP_bdApi_XenForo_DataWriter_Discussion_Thread
{
    protected function _discussionPostDelete()
    {
        /* @var $subscriptionModel bdApi_Model_Subscription */
        $subscriptionModel = $this->getModelFromCache('bdApi_Model_Subscription');
        $subscriptionModel->deleteSubscriptionsForTopic(bdApi_Model_Subscription::TYPE_THREAD_POST, $this->get('thread_id'));

        parent::_discussionPostDelete();
    }

}
