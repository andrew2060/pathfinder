<?php
/**
 * Created by PhpStorm.
 * User: Exodus
 * Date: 23.01.2016
 * Time: 17:18
 *
 * Handles access to EVE-Online "CREST API" and "SSO" auth functions
 * - Add your API credentials in "environment.ini"
 * - Check "PATHFINDER.API" in "pathfinder.ini" for correct API URLs
 * Hint: \Web::instance()->request automatically caches responses by their response "Cache-Control" header!
 */

namespace Controller\Ccp;
use Controller;
use Controller\Api as Api;
use Data\Mapper as Mapper;
use Model;
use Lib;

class Sso extends Api\User{

    /**
     * @var int timeout (seconds) for API calls
     */
    const CREST_TIMEOUT                             = 3;

    /**
     * @var int expire time (seconds) for an valid "accessToken"
     */
    const ACCESS_KEY_EXPIRE_TIME                    = 20 * 60;

    // SSO specific session keys
    const SESSION_KEY_SSO                           = 'SESSION.SSO';
    const SESSION_KEY_SSO_ERROR                     = 'SESSION.SSO.ERROR';
    const SESSION_KEY_SSO_STATE                     = 'SESSION.SSO.STATE';

    // error messages
    const ERROR_CCP_SSO_URL                         = 'Invalid "ENVIRONMENT.[ENVIRONMENT].SSO_CCP_URL" url. %s';
    const ERROR_CCP_CREST_URL                       = 'Invalid "ENVIRONMENT.[ENVIRONMENT].CCP_CREST_URL" url. %s';
    const ERROR_RESOURCE_DEPRECATED                 = 'Resource: %s has been marked as deprecated. %s';
    const ERROR_ACCESS_TOKEN                        = 'Unable to get a valid "access_token. %s';
    const ERROR_VERIFY_CHARACTER                    = 'Unable to verify character data. %s';
    const ERROR_GET_ENDPOINT                        = 'Unable to get endpoint data. $s';
    const ERROR_FIND_ENDPOINT                       = 'Unable to find endpoint: %s';
    const ERROR_LOGIN_FAILED                        = 'Failed authentication due to technical problems: %s';

    /**
     * CREST "Scopes" are used by pathfinder
     * -> Enable scopes: https://developers.eveonline.com
     * @var array
     */
    private $requestScopes = [
        // 'characterFittingsRead',
        // 'characterFittingsWrite',
        'characterLocationRead',
        'characterNavigationWrite'
    ];

    /**
     * redirect user to CCP SSO page and request authorization
     * @param \Base $f3
     */
    public function requestAuthorization($f3){

        // used for "state" check between request and callback
        $state = bin2hex(mcrypt_create_iv(12, MCRYPT_DEV_URANDOM));
        $f3->set(self::SESSION_KEY_SSO_STATE, $state);

        $urlParams = [
            'response_type' => 'code',
            'redirect_uri' => Controller\Controller::getEnvironmentData('URL') . $f3->build('/sso/callbackAuthorization'),
            'client_id' => Controller\Controller::getEnvironmentData('SSO_CCP_CLIENT_ID'),
            'scope' => implode(' ', $this->requestScopes),
            'state' => $state
        ];

        $ssoAuthUrl = self::getAuthorizationEndpoint() . '?' . http_build_query($urlParams, '', '&', PHP_QUERY_RFC3986 );

        $f3->status(302);
        $f3->reroute($ssoAuthUrl);
    }

    /**
     * callback handler for CCP SSO user Auth
     * -> see requestAuthorization()
     * @param \Base $f3
     */
    public function callbackAuthorization($f3){
        $getParams = (array)$f3->get('GET');

        if($f3->exists(self::SESSION_KEY_SSO_STATE)){
            // check response and validate 'state'
            if(
                isset($getParams['code']) &&
                isset($getParams['state']) &&
                !empty($getParams['code']) &&
                !empty($getParams['state']) &&
                $f3->get(self::SESSION_KEY_SSO_STATE) === $getParams['state']
            ){
                // clear 'state' for new next request
                $f3->clear(self::SESSION_KEY_SSO_STATE);

                $accessData = $this->getCrestAccessData($getParams['code']);

                if(
                    isset($accessData->accessToken) &&
                    isset($accessData->refreshToken)
                ){
                    // login succeeded -> get basic character data for current login
                    $verificationCharacterData = $this->verifyCharacterData($accessData->accessToken);

                    if( !is_null($verificationCharacterData)){
                        // verification data available. Data is needed for "ownerHash" check

                        // get character data from CREST
                        $characterData = $this->getCharacterData($accessData->accessToken);

                        if(isset($characterData->character)){
                            // add "ownerHash" and CREST tokens
                            $characterData->character['ownerHash'] = $verificationCharacterData->CharacterOwnerHash;
                            $characterData->character['crestAccessToken'] = $accessData->accessToken;
                            $characterData->character['crestRefreshToken'] = $accessData->refreshToken;

                            // add/update static character data
                            $characterModel = $this->updateCharacter($characterData);

                            if( !is_null($characterModel) ){
                                // check if character is authorized to log in
                                if($characterModel->isAuthorized()){

                                    // character is authorized to log in
                                    // -> update character log (current location,...)
                                    $characterModel = $characterModel->updateLog();

                                    // check if there is already a user created who owns this char
                                    $user = $characterModel->getUser();

                                    if(is_null($user)){
                                        // no user found -> create one and connect to character
                                        /**
                                         * @var Model\UserModel $user
                                         */
                                        $user = Model\BasicModel::getNew('UserModel');
                                        $user->name = $characterModel->name;
                                        $user->save();

                                        /**
                                         * @var Model\UserCharacterModel $userCharactersModel
                                         */
                                        $userCharactersModel = Model\BasicModel::getNew('UserCharacterModel');
                                        $userCharactersModel->userId = $user;
                                        $userCharactersModel->characterId = $characterModel;
                                        $userCharactersModel->save();

                                        // get updated character model
                                        $characterModel = $userCharactersModel->getCharacter();
                                    }

                                    // login by character
                                    $loginCheck = $this->loginByCharacter($characterModel);

                                    if($loginCheck){
                                        // route to "map"
                                        $f3->reroute('@map');
                                    }else{
                                        $f3->set(self::SESSION_KEY_SSO_ERROR, sprintf(self::ERROR_LOGIN_FAILED, $characterModel->name));
                                    }
                                }else{
                                    // character is not authorized to log in
                                    $f3->set(self::SESSION_KEY_SSO_ERROR, 'Character "' . $characterModel->name . '" is not authorized to log in.');
                                }
                            }
                        }
                    }
                }
            }
        }

        // on error -> route back to login form
        $f3->reroute('@login');
    }

    /**
     * get a valid "access_token" for oAuth 2.0 verification
     * -> if $authCode is set -> request NEW "access_token"
     * -> else check for existing (not expired) "access_token"
     * -> else try to refresh auth and get fresh "access_token"
     * @param bool $authCode
     * @return null|\stdClass
     */
    public function getCrestAccessData($authCode){
        $accessData = null;

        if( !empty($authCode) ){
            // Authentication Code is set -> request new "accessToken"
            $accessData = $this->verifyAuthorizationCode($authCode);
        }else{
            // Unable to get Token -> trigger error
            self::getCrestLogger()->write(sprintf(self::ERROR_ACCESS_TOKEN, $authCode));
        }

        return $accessData;
    }

    /**
     * verify authorization code, and get an "access_token" data
     * @param $authCode
     * @return \stdClass
     */
    protected function verifyAuthorizationCode($authCode){
        $requestParams = [
            'grant_type' => 'authorization_code',
            'code' => $authCode
        ];

        return $this->requestAccessData($requestParams);
    }

    /**
     * get new "access_token" by an existing "refresh_token"
     * -> if "access_token" is expired, this function gets a fresh one
     * @param $refreshToken
     * @return \stdClass
     */
    public function refreshAccessToken($refreshToken){
        $requestParams = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken
        ];

        return $this->requestAccessData($requestParams);
    }

    /**
     * request an "access_token" AND "refresh_token" data
     * -> this can either be done by sending a valid "authorization code"
     * OR by providing a valid "refresh_token"
     * @param $requestParams
     * @return \stdClass
     */
    protected function requestAccessData($requestParams){
        $verifyAuthCodeUrl = self::getVerifyAuthorizationCodeEndpoint();
        $verifyAuthCodeUrlParts = parse_url($verifyAuthCodeUrl);

        $accessData = (object) [];
        $accessData->accessToken = null;
        $accessData->refreshToken = null;

        if($verifyAuthCodeUrlParts){
            $contentType = 'application/x-www-form-urlencoded';
            $requestOptions = [
                'timeout' => self::CREST_TIMEOUT,
                'method' => 'POST',
                'user_agent' => $this->getUserAgent(),
                'header' => [
                    'Authorization: Basic ' . $this->getAuthorizationHeader(),
                    'Content-Type: ' . $contentType,
                    'Host: ' . $verifyAuthCodeUrlParts['host']
                ]
            ];

            // content (parameters to send with)
            $requestOptions['content'] = http_build_query($requestParams);

            $apiResponse = Lib\Web::instance()->request($verifyAuthCodeUrl, $requestOptions);

            if($apiResponse['body']){
                $authCodeRequestData = json_decode($apiResponse['body']);

                if(isset($authCodeRequestData->access_token)){
                    // this token is required for endpoints that require Auth
                    $accessData->accessToken =  $authCodeRequestData->access_token;
                }

                if(isset($authCodeRequestData->refresh_token)){
                    // this token is used to refresh/get a new access_token when expires
                    $accessData->refreshToken =  $authCodeRequestData->refresh_token;
                }
            }else{
                self::getCrestLogger()->write(
                    sprintf(
                        self::ERROR_ACCESS_TOKEN,
                        print_r($requestParams, true)
                    )
                );
            }
        }else{
            self::getCrestLogger()->write(
                sprintf(self::ERROR_CCP_SSO_URL, __METHOD__)
            );
        }

        return $accessData;
    }

    /**
     * verify character data by "access_token"
     * -> get some basic information (like character id)
     * -> if more character information is required, use CREST endpoints request instead
     * @param $accessToken
     * @return mixed|null
     */
    protected function verifyCharacterData($accessToken){
        $verifyUserUrl = self::getVerifyUserEndpoint();
        $verifyUrlParts = parse_url($verifyUserUrl);
        $characterData = null;

        if($verifyUrlParts){
            $requestOptions = [
                'timeout' => self::CREST_TIMEOUT,
                'method' => 'GET',
                'user_agent' => $this->getUserAgent(),
                'header' => [
                    'Authorization: Bearer ' . $accessToken,
                    'Host: ' . $verifyUrlParts['host']
                ]
            ];

            $apiResponse = Lib\Web::instance()->request($verifyUserUrl, $requestOptions);

            if($apiResponse['body']){
                $characterData = json_decode($apiResponse['body']);
            }else{
                self::getCrestLogger()->write(sprintf(self::ERROR_VERIFY_CHARACTER, __METHOD__));
            }
        }else{
            self::getCrestLogger()->write(sprintf(self::ERROR_CCP_SSO_URL, __METHOD__));
        }

        return $characterData;
    }

    /**
     * get all available Endpoints
     * @param $accessToken
     * @return mixed|null
     */
    protected function getEndpoints($accessToken){
        $crestUrl = self::getCrestEndpoint();
        $contentType = 'application/vnd.ccp.eve.Api-v3+json';
        $endpoint = $this->getEndpoint($accessToken, $crestUrl, $contentType);

        return $endpoint;
    }

    /**
     * get a specific endpoint by its $resourceUrl
     * @param $accessToken
     * @param $resourceUrl
     * @param string $contentType
     * @return mixed|null
     */
    protected function getEndpoint($accessToken, $resourceUrl, $contentType = ''){
        $resourceUrlParts = parse_url($resourceUrl);
        $endpoint = null;

        if($resourceUrlParts){
            $requestOptions = [
                'timeout' => self::CREST_TIMEOUT,
                'method' => 'GET',
                'user_agent' => $this->getUserAgent(),
                'header' => [
                    'Authorization: Bearer ' . $accessToken,
                    'Host: login.eveonline.com',
                    'Host: ' . $resourceUrlParts['host']
                ]
            ];

            // if specific contentType is required -> add it to request header
            // CREST versioning can be done by calling different "Accept:" Headers
            if( !empty($contentType) ){
                $requestOptions['header'][] = 'Accept: ' . $contentType;
            }

            $apiResponse = Lib\Web::instance()->request($resourceUrl, $requestOptions);

            if($apiResponse['headers']){
                // check headers for  error
                $this->checkResponseHeaders($apiResponse['headers'], $requestOptions);

                if($apiResponse['body']){
                    $endpoint = json_decode($apiResponse['body'], true);
                }else{
                    self::getCrestLogger()->write(sprintf(self::ERROR_GET_ENDPOINT, __METHOD__));
                }
            }
        }else{
            self::getCrestLogger()->write(sprintf(self::ERROR_CCP_CREST_URL, __METHOD__));
        }

        return $endpoint;
    }

    /**
     * recursively walk down the CREST API tree by a given $path array
     * -> return "leaf" endpoint
     * @param $accessToken
     * @param $endpoint
     * @param array $path
     * @return null|string
     */
    protected function walkEndpoint($accessToken, $endpoint, $path = []){
        $targetEndpoint = null;

        if( !empty($path) ){
            $newNode = array_shift($path);
            if(isset($endpoint[$newNode])){
                $currentEndpoint = $endpoint[$newNode];
                if(isset($currentEndpoint['href'])){
                    $newEndpoint = $this->getEndpoint($accessToken, $currentEndpoint['href']);
                    $targetEndpoint = $this->walkEndpoint($accessToken, $newEndpoint, $path);
                }else{
                    // leaf found
                    $targetEndpoint = $currentEndpoint;
                }
            }else{
                // endpoint not found
                self::getCrestLogger()->write(sprintf(self::ERROR_FIND_ENDPOINT, $newNode));
            }
        }else{
            $targetEndpoint = $endpoint;
        }

        return $targetEndpoint;
    }

    /**
     * get character data
     * @param $accessToken
     * @return array
     */
    protected function getCharacterData($accessToken){
        $endpoints = $this->getEndpoints($accessToken);
        $characterData = (object) [];

        $endpoint = $this->walkEndpoint($accessToken, $endpoints, [
            'decode',
            'character'
        ]);

        if( !empty($endpoint) ){
            $characterData->character = (new Mapper\CrestCharacter($endpoint))->getData();
            if(isset($endpoint['corporation'])){
                $characterData->corporation = (new Mapper\CrestCorporation($endpoint['corporation']))->getData();
            }
        }

        return $characterData;
    }

    /**
     * get current character location data
     * -> solarSystem data where character is currently active
     * @param $accessToken
     * @return object
     */
    public function getCharacterLocationData($accessToken){
        $endpoints = $this->getEndpoints($accessToken);
        $locationData = (object) [];

        $endpoint = $this->walkEndpoint($accessToken, $endpoints, [
            'decode',
            'character',
            'location'
        ]);

        if( !empty($endpoint) ){
            if(isset($endpoint['solarSystem'])){
                $locationData->system = (new Mapper\CrestSystem($endpoint['solarSystem']))->getData();
            }
        }

        return $locationData;
    }

    /**
     * update character
     * @param $characterData
     * @return \Model\CharacterModel
     * @throws \Exception
     */
    protected function updateCharacter($characterData){

        $characterModel = null;
        $corporationModel = null;
        $allianceModel = null;

        if( isset($characterData->corporation) ){
            /**
             * @var Model\CorporationModel $corporationModel
             */
            $corporationModel = Model\BasicModel::getNew('CorporationModel');
            $corporationModel->getById($characterData->corporation['id'], 0);
            $corporationModel->copyfrom($characterData->corporation);
            $corporationModel->save();
        }

        if( isset($characterData->alliance) ){
            /**
             * @var Model\AllianceModel $allianceModel
             */
            $allianceModel = Model\BasicModel::getNew('AllianceModel');
            $allianceModel->getById($characterData->alliance['id'], 0);
            $allianceModel->copyfrom($characterData->alliance);
            $allianceModel->save();
        }

        if( isset($characterData->character) ){
            /**
             * @var Model\CharacterModel $characterModel
             */
            $characterModel = Model\BasicModel::getNew('CharacterModel');
            $characterModel->getById($characterData->character['id'], 0);
            $characterModel->copyfrom($characterData->character);
            $characterModel->corporationId = $corporationModel;
            $characterModel->allianceId = $allianceModel;
            $characterModel = $characterModel->save();
        }

        return $characterModel;
    }

    /**
     * check response "Header" data for errors
     * @param $headers
     * @param string $requestUrl
     * @param string $contentType
     */
    protected function checkResponseHeaders($headers, $requestUrl = '', $contentType = ''){
        $headers = (array)$headers;
        if(preg_grep ('/^X-Deprecated/i', $headers)){
            self::getCrestLogger()->write(sprintf(self::ERROR_RESOURCE_DEPRECATED, $requestUrl, $contentType));
        }
    }

    /**
     * get "Authorization:" Header data
     * -> This header is required for any Auth-required endpoints!
     * @return string
     */
    protected function getAuthorizationHeader(){
        return base64_encode(
            Controller\Controller::getEnvironmentData('SSO_CCP_CLIENT_ID') . ':'
            . Controller\Controller::getEnvironmentData('SSO_CCP_SECRET_KEY')
        );
    }

    /**
     * get CCP CREST url from configuration file
     * -> throw error if url is broken/missing
     * @return string
     */
    static function getCrestEndpoint(){
        $url = '';
        if( \Audit::instance()->url(self::getEnvironmentData('CCP_CREST_URL')) ){
            $url = self::getEnvironmentData('CCP_CREST_URL');
        }else{
            $error = sprintf(self::ERROR_CCP_CREST_URL, __METHOD__);
            self::getCrestLogger()->write($error);
            \Base::instance()->error(502, $error);
        }

        return $url;
    }

    /**
     * get CCP SSO url from configuration file
     * -> throw error if url is broken/missing
     * @return string
     */
    static function getSsoUrlRoot(){
        $url = '';
        if( \Audit::instance()->url(self::getEnvironmentData('SSO_CCP_URL')) ){
            $url = self::getEnvironmentData('SSO_CCP_URL');
        }else{
            $error = sprintf(self::ERROR_CCP_SSO_URL, __METHOD__);
            self::getCrestLogger()->write($error);
            \Base::instance()->error(502, $error);
        }

        return $url;
    }

    static function getAuthorizationEndpoint(){
        return self::getSsoUrlRoot() . '/oauth/authorize';
    }

    static function getVerifyAuthorizationCodeEndpoint(){
        return self::getSsoUrlRoot() . '/oauth/token';
    }

    static function getVerifyUserEndpoint(){
        return self::getSsoUrlRoot() . '/oauth/verify';
    }

    /**
     * get logger for CREST logging
     * @return \Log
     */
    static function getCrestLogger(){
        return parent::getLogger('crest');
    }
}