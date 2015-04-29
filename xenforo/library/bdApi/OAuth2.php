<?php

require(dirname(__FILE__) . '/Lib/oauth2-php/OAuth2.inc');

class bdApi_OAuth2 extends OAuth2
{

    /**
     * @var bdApi_Model_OAuth2
     */
    protected $_model;

    /**
     * The current user id will be stored here to be used when new
     * auth code/token/refresh token is created.
     *
     * By default, this property will have the value of zero but it
     * will be set to the auth code's value of user_id if getAuthCode()
     * is called and the requested record is found.
     *
     * @var int
     */
    protected $_userId = 0;

    /**
     * Gets effective token (access token) of the current request.
     *
     * This method mimics parent::verifyAccessToken().
     *
     * @return array
     */
    public function getEffectiveToken()
    {
        $tokenParam = $this->getAccessTokenParams();

        if ($tokenParam === FALSE) {
            // no token param found
            return false;
        }

        $token = $this->getAccessToken($tokenParam);

        if ($token === NULL) {
            // no token found
            return false;
        }

        if (isset($token["expires"]) && time() > $token["expires"]) {
            // expired
            return false;
        }

        return $token;
    }

    /**
     * Exposes createAccessToken with visibility public.
     *
     * @param string $clientId
     * @param string $scope
     *
     * @return array token
     */
    public function createAccessTokenPublic($clientId, $scope = NULL)
    {
        return $this->createAccessToken($clientId, $scope);
    }

    /**
     * Includes user id into returned token
     *
     * @param $client_id
     * @param string $scope
     *
     * @return array token
     */
    protected function createAccessToken($client_id, $scope = NULL)
    {
        $token = parent::createAccessToken($client_id, $scope);
        $token['user_id'] = $this->getUserId();
        return $token;
    }

    /**
     * Exposes genAccessToken with visibility public.
     */
    public function genAccessTokenPublic()
    {
        return $this->genAccessToken();
    }

    /**
     * Sets effective user id of the current request.
     *
     * @param int $userId
     */
    public function setUserId($userId)
    {
        $this->_userId = $userId;
    }

    /**
     * Gets the effective user id of the current request.
     *
     * @return int the effective user id
     */
    public function getUserId()
    {
        if ($this->_userId > 0) {
            return $this->_userId;
        } else {
            return XenForo_Visitor::getUserId();
        }
    }

    /**
     * Constructor
     *
     * @param bdApi_Model_OAuth2 $model
     */
    public function __construct(bdApi_Model_OAuth2 $model)
    {
        parent::__construct(array(
            'auth_code_lifetime' => bdApi_Option::get('authCodeTTL'),
            'access_token_lifetime' => bdApi_Option::get('tokenTTL'),
            'refresh_token_lifetime' => bdApi_Option::get('refreshTokenTTLDays') * 86400,
        ));

        if (XenForo_Application::debugMode()) {
            $this->setVariable('display_error', true);
        }

        $this->_model = $model;
    }

    protected function checkClientCredentials($clientId, $clientSecret = NULL)
    {
        $client = $this->_model->getClientModel()->getClientById($clientId);

        if (empty($client)) {
            // client not found
            return false;
        }

        if (!$this->_model->getClientModel()->verifySecret($client, $clientSecret)) {
            // the secret exists but not valid
            return false;
        }

        return true;
    }

    protected function getRedirectUri($clientId)
    {
        $client = $this->_model->getClientModel()->getClientById($clientId);

        if (empty($client)) {
            // client not found
            return false;
        }

        if (isset($_REQUEST['redirect_uri'])) {
            // check white-listed domains only if a redirect_uri is in the request
            $redirectUri = $this->_model->getClientModel()->getRedirectUri($client, $_REQUEST['redirect_uri']);

            if (!empty($redirectUri)) {
                return $redirectUri;
            }
        }

        return $client['redirect_uri'];
    }

    protected function getAccessToken($oauthToken)
    {
        $token = $this->_model->getTokenModel()->getTokenByText($oauthToken);

        if (empty($token)) {
            // token not found
            return NULL;
        }

        return $token + array(
            'client_id' => $token['client_id'],
            'expires' => $token['expire_date'],
            'scope' => $token['scope'],
        );
    }

    protected function setAccessToken($oauthToken, $clientId, $expireDate, $scope = NULL)
    {
        /* @var $dw bdApi_DataWriter_Token */
        $dw = XenForo_DataWriter::create('bdApi_DataWriter_Token');

        $dw->set('token_text', $oauthToken);
        $dw->set('client_id', $clientId);
        $dw->set('expire_date', $expireDate);
        $dw->set('user_id', $this->getUserId());
        $dw->set('scope', $scope ? $scope : '');

        $dw->save();
    }

    protected function getSupportedGrantTypes()
    {
        return array(
            OAUTH2_GRANT_TYPE_AUTH_CODE,
            OAUTH2_GRANT_TYPE_USER_CREDENTIALS,
            OAUTH2_GRANT_TYPE_REFRESH_TOKEN,
        );
    }

    protected function getSupportedScopes()
    {
        return $this->_model->getSystemSupportedScopes();
    }

    protected function getAuthCode($code)
    {
        $authCode = $this->_model->getAuthCodeModel()->getAuthCodeByText($code);

        if (empty($authCode)) {
            // auth code not found
            return NULL;
        }

        // store the user id to use later to create token/refresh_token
        $this->setUserId($authCode['user_id']);

        return $authCode + array(
            'code' => $authCode['auth_code_text'],
            'client_id' => $authCode['client_id'],
            'redirect_uri' => $authCode['redirect_uri'],
            'expires' => $authCode['expire_date'],
            'scope' => $authCode['scope'],
        );
    }

    protected function setAuthCode($code, $clientId, $redirectUri, $expireDate, $scope = NULL)
    {
        /* @var $dw bdApi_DataWriter_AuthCode */
        $dw = XenForo_DataWriter::create('bdApi_DataWriter_AuthCode');

        $dw->set('auth_code_text', $code);
        $dw->set('client_id', $clientId);
        $dw->set('redirect_uri', $redirectUri);
        $dw->set('expire_date', $expireDate);
        $dw->set('user_id', $this->getUserId());
        $dw->set('scope', $scope ? $scope : '');

        $dw->save();
    }

    protected function checkUserCredentials($clientId, $username, $password)
    {
        $userId = $this->_model->getUserModel()->validateAuthentication($username, $password);

        if (!empty($userId) AND $userId > 0) {
            $this->setUserId($userId);

            return array('scope' => bdApi_Template_Helper_Core::getInstance()->scopeJoin($this->_model->getSystemSupportedScopes()));
        } else {
            return false;
        }
    }

    protected function getRefreshToken($refreshTokenText)
    {
        $refreshToken = $this->_model->getRefreshTokenModel()->getRefreshTokenByText($refreshTokenText);

        if (empty($refreshToken)) {
            // refresh token not found
            return NULL;
        }

        // store the user id to use later to create token/refresh_token
        $this->setUserId($refreshToken['user_id']);

        return $refreshToken + array(
            'token' => $refreshToken['refresh_token_text'],
            'client_id' => $refreshToken['client_id'],
            'expires' => $refreshToken['expire_date'],
            'scope' => $refreshToken['scope'],
        );
    }

    protected function setRefreshToken($refreshToken, $clientId, $expireDate, $scope = NULL)
    {
        /* @var $dw bdApi_DataWriter_RefreshToken */
        $dw = XenForo_DataWriter::create('bdApi_DataWriter_RefreshToken');

        $dw->set('refresh_token_text', $refreshToken);
        $dw->set('client_id', $clientId);
        $dw->set('expire_date', $expireDate);
        $dw->set('user_id', $this->getUserId());
        $dw->set('scope', $scope ? $scope : '');

        $dw->save();
    }

    protected function unsetRefreshToken($refreshToken)
    {
        $token = $this->_model->getRefreshTokenModel()->getRefreshTokenByText($refreshToken);

        if (!empty($token)) {
            /* @var $dw bdApi_DataWriter_RefreshToken */
            $dw = XenForo_DataWriter::create('bdApi_DataWriter_RefreshToken');

            $dw->setExistingData($token, true);
            $dw->set('expire_date', XenForo_Application::$time);

            $dw->save();
        }
    }

    protected function getDefaultAuthenticationRealm()
    {
        return $this->_model->getSystemAuthenticationRealm();
    }

    public function grantAccessToken($sondhEdited = 20141113)
    {
        // extend to guard against future update of OAuth2 lib
        parent::grantAccessToken($sondhEdited);
    }

}
