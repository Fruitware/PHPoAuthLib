<?php

namespace OAuth\OAuth2\Service;

use OAuth\OAuth2\Service\Exception\MissingRefreshTokenException;
use OAuth\OAuth2\Token\StdOAuth2Token;
use OAuth\Common\Http\Exception\TokenResponseException;
use OAuth\Common\Http\Uri\Uri;
use OAuth\Common\Consumer\CredentialsInterface;
use OAuth\Common\Http\Client\ClientInterface;
use OAuth\Common\Storage\TokenStorageInterface;
use OAuth\Common\Http\Uri\UriInterface;
use OAuth\Common\Token\TokenInterface;

class Bitrix24 extends AbstractService
{
    const SCOPE_DEPARTMENT = 'department';
    const SCOPE_CRM = 'crm';
    const SCOPE_CALENDAR = 'calendar';
    const SCOPE_USER = 'user';
    const SCOPE_ENTITY = 'entity';
    const SCOPE_TASK = 'task';
    const SCOPE_TASKS_EXTENDED = 'tasks_extended';
    const SCOPE_IM = 'im';
    const SCOPE_LOG = 'log';
    const SCOPE_SONET_GROUP = 'sonet_group';

    public function __construct(
        CredentialsInterface $credentials,
        ClientInterface $httpClient,
        TokenStorageInterface $storage,
        array $scopes,
        UriInterface $baseApiUri
    ) {
        parent::__construct($credentials, $httpClient, $storage, $scopes, $baseApiUri);
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthorizationEndpoint()
    {
        return new Uri(sprintf('%s/oauth/authorize/', $this->baseApiUri));
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessTokenEndpoint()
    {
        return new Uri(sprintf('%s/oauth/token/', $this->baseApiUri));
    }

    /**
     * {@inheritdoc}
     */
    public function requestAccessToken($code, $state = null)
    {
        if (null !== $state) {
            $this->validateAuthorizationState($state);
        }

        $responseBody = $this->httpClient->retrieveResponse(
            $this->getAccessTokenUri($code),
            array(),
            $this->getExtraOAuthHeaders(),
            'GET'
        );

        $token = $this->parseAccessTokenResponse($responseBody);
        $this->storage->storeAccessToken($this->service(), $token);

        return $token;
    }

    public function refreshAccessToken(TokenInterface $token)
    {
        $refreshToken = $token->getRefreshToken();

        if (empty($refreshToken)) {
            throw new MissingRefreshTokenException();
        }

        $responseBody = $this->httpClient->retrieveResponse(
            $this->getRefreshTokenUri($refreshToken),
            array(),
            $this->getExtraOAuthHeaders(),
            'GET'
        );
        $token = $this->parseAccessTokenResponse($responseBody);
        $this->storage->storeAccessToken($this->service(), $token);

        return $token;
    }

    public function getRefreshTokenUri($refreshToken)
    {
        $parameters = array(
            'grant_type'    => 'refresh_token',
            'type'          => 'web_server',
            'client_id'     => $this->credentials->getConsumerId(),
            'client_secret' => $this->credentials->getConsumerSecret(),
            'refresh_token' => $refreshToken,
        );

        // Build the url
        $url = clone $this->getAccessTokenEndpoint();
        foreach ($parameters as $key => $val) {
            $url->addToQuery($key, $val);
        }

        return $url;
    }

    public function getAccessTokenUri($code)
    {
        $parameters = array(
            'code'          => $code,
            'client_id'     => $this->credentials->getConsumerId(),
            'client_secret' => $this->credentials->getConsumerSecret(),
            'redirect_uri'  => $this->credentials->getCallbackUrl(),
            'grant_type'    => 'authorization_code',
            'scope'         => $this->scopes
        );

        $parameters['scope'] = implode(' ', $this->scopes);

        // Build the url
        $url = clone $this->getAccessTokenEndpoint();
        foreach ($parameters as $key => $val) {
            $url->addToQuery($key, $val);
        }

        return $url;
    }

    /**
     * {@inheritdoc}
     */
    protected function parseAccessTokenResponse($responseBody)
    {
        $data = json_decode($responseBody, true);

        if (null === $data || !is_array($data)) {
            throw new TokenResponseException('Unable to parse response.');
        } elseif (isset($data['error'])) {
            throw new TokenResponseException('Error in retrieving token: "' . $data['error'] . '"');
        }

        $token = new StdOAuth2Token();
        $token->setAccessToken($data['access_token']);
        $token->setLifetime($data['expires_in']);

        if (isset($data['refresh_token'])) {
            $token->setRefreshToken($data['refresh_token']);
            unset($data['refresh_token']);
        }

        unset($data['access_token']);
        unset($data['expires_in']);

        $token->setExtraParams($data);

        return $token;
    }
}
