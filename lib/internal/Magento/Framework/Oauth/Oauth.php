<?php
/**
 * Copyright 2013 Adobe
 * All Rights Reserved.
 */

namespace Magento\Framework\Oauth;

use Magento\Framework\Oauth\Helper\Utility;
use Magento\Framework\Encryption\Helper\Security;
use Magento\Framework\Phrase;
use Magento\Framework\Oauth\Exception as AuthException;

/**
 * Authorization service.
 */
class Oauth implements OauthInterface
{
    /**
     * @var  \Magento\Framework\Oauth\Helper\Oauth
     */
    protected $_oauthHelper;

    /**
     * @var \Magento\Framework\Oauth\NonceGeneratorInterface
     */
    protected $_nonceGenerator;

    /**
     * @var \Magento\Framework\Oauth\TokenProviderInterface
     */
    protected $_tokenProvider;

    /**
     * @var Utility
     */
    private Utility $httpUtility;

    /**
     * @param Helper\Oauth $oauthHelper
     * @param NonceGeneratorInterface $nonceGenerator
     * @param TokenProviderInterface $tokenProvider
     * @param Utility $utility
     */
    public function __construct(
        Helper\Oauth $oauthHelper,
        NonceGeneratorInterface $nonceGenerator,
        TokenProviderInterface $tokenProvider,
        Utility $utility
    ) {
        $this->_oauthHelper = $oauthHelper;
        $this->_nonceGenerator = $nonceGenerator;
        $this->_tokenProvider = $tokenProvider;
        $this->httpUtility = $utility;
    }

    /**
     * Retrieve array of supported signature methods.
     *
     * @return string[]
     */
    public static function getSupportedSignatureMethods()
    {
        return [self::SIGNATURE_SHA256];
    }

    /**
     * @inheritdoc
     */
    public function getRequestToken($params, $requestUrl, $httpMethod = 'POST')
    {
        $this->_validateProtocolParams($params);
        $consumer = $this->_tokenProvider->getConsumerByKey($params['oauth_consumer_key']);
        $this->_tokenProvider->validateConsumer($consumer);
        $this->_validateSignature($params, $consumer->getSecret(), $httpMethod, $requestUrl);

        return $this->_tokenProvider->createRequestToken($consumer);
    }

    /**
     * @inheritdoc
     */
    public function getAccessToken($params, $requestUrl, $httpMethod = 'POST')
    {
        $required = [
            'oauth_consumer_key',
            'oauth_signature',
            'oauth_signature_method',
            'oauth_nonce',
            'oauth_timestamp',
            'oauth_token',
            'oauth_verifier',
        ];

        $this->_validateProtocolParams($params, $required);
        $consumer = $this->_tokenProvider->getConsumerByKey($params['oauth_consumer_key']);
        $tokenSecret = $this->_tokenProvider->validateRequestToken(
            $params['oauth_token'],
            $consumer,
            $params['oauth_verifier']
        );

        $this->_validateSignature($params, $consumer->getSecret(), $httpMethod, $requestUrl, $tokenSecret);

        return $this->_tokenProvider->getAccessToken($consumer);
    }

    /**
     * @inheritdoc
     */
    public function validateAccessTokenRequest($params, $requestUrl, $httpMethod = 'POST')
    {
        $required = [
            'oauth_consumer_key',
            'oauth_signature',
            'oauth_signature_method',
            'oauth_nonce',
            'oauth_timestamp',
            'oauth_token',
        ];

        $this->_validateProtocolParams($params, $required);
        $consumer = $this->_tokenProvider->getConsumerByKey($params['oauth_consumer_key']);
        $tokenSecret = $this->_tokenProvider->validateAccessTokenRequest($params['oauth_token'], $consumer);

        $this->_validateSignature($params, $consumer->getSecret(), $httpMethod, $requestUrl, $tokenSecret);

        return $consumer->getId();
    }

    /**
     * @inheritdoc
     */
    public function validateAccessToken($accessToken)
    {
        return $this->_tokenProvider->validateAccessToken($accessToken);
    }

    /**
     * @inheritdoc
     */
    public function buildAuthorizationHeader(
        $params,
        $requestUrl,
        $signatureMethod = self::SIGNATURE_SHA256,
        $httpMethod = 'POST'
    ) {
        $required = ["oauth_consumer_key", "oauth_consumer_secret", "oauth_token", "oauth_token_secret"];
        $this->_checkRequiredParams($params, $required);
        $consumer = $this->_tokenProvider->getConsumerByKey($params['oauth_consumer_key']);
        $headerParameters = [
            'oauth_nonce' => $this->_nonceGenerator->generateNonce($consumer),
            'oauth_timestamp' => $this->_nonceGenerator->generateTimestamp(),
            'oauth_version' => '1.0',
        ];
        $headerParameters = array_merge($headerParameters, $params);
        $headerParameters['oauth_signature'] = $this->httpUtility->sign(
            $params,
            $signatureMethod,
            $headerParameters['oauth_consumer_secret'],
            $headerParameters['oauth_token_secret'],
            $httpMethod,
            $requestUrl
        );

        return $this->httpUtility->toAuthorizationHeader($headerParameters);
    }

    /**
     * Validate signature based on the signature method used.
     *
     * @param array $params
     * @param string $consumerSecret
     * @param string $httpMethod
     * @param string $requestUrl
     * @param string $tokenSecret
     * @return void
     * @throws Exception|OauthInputException
     */
    protected function _validateSignature($params, $consumerSecret, $httpMethod, $requestUrl, $tokenSecret = null)
    {
        if (!in_array($params['oauth_signature_method'], self::getSupportedSignatureMethods())) {
            throw new OauthInputException(
                new Phrase(
                    'Signature method %1 is not supported',
                    [$params['oauth_signature_method']]
                )
            );
        }

        $calculatedSign = $this->httpUtility->sign(
            $this->processNonRequiredParams($params),
            $params['oauth_signature_method'],
            $consumerSecret,
            $tokenSecret,
            $httpMethod,
            $requestUrl
        );

        if (!Security::compareStrings($calculatedSign, $params['oauth_signature'])) {
            throw new AuthException(new Phrase('The signature is invalid. Verify and try again.'));
        }
    }

    /**
     * Avoid double encoding for query param values
     *
     * @param array $params
     * @return array
     */
    private function processNonRequiredParams(array $params): array
    {
        $requiredParams = [
            "oauth_consumer_key",
            "oauth_consumer_secret",
            "oauth_token",
            "oauth_token_secret",
            "oauth_signature"
        ];
        foreach ($params as $key => $value) {
            if (!in_array($key, $requiredParams)) {
                $params[$key] = urldecode($value);
            }
        }

        return $params;
    }

    /**
     * Validate oauth version.
     *
     * @param string $version
     * @return void
     * @throws OauthInputException
     */
    protected function _validateVersionParam($version)
    {
        // validate version if specified
        if ('1.0' != $version) {
            throw new OauthInputException(new Phrase('The "%1" Oauth version isn\'t supported.', [$version]));
        }
    }

    /**
     * Validate request and header parameters.
     *
     * @param array $protocolParams
     * @param array $requiredParams
     * @return void
     * @throws OauthInputException
     */
    protected function _validateProtocolParams($protocolParams, $requiredParams = [])
    {
        // validate version if specified.
        if (isset($protocolParams['oauth_version'])) {
            $this->_validateVersionParam($protocolParams['oauth_version']);
        }

        // Required parameters validation. Default to minimum required params if not provided.
        if (empty($requiredParams)) {
            $requiredParams = [
                "oauth_consumer_key",
                "oauth_signature",
                "oauth_signature_method",
                "oauth_nonce",
                "oauth_timestamp",
            ];
        }
        $this->_checkRequiredParams($protocolParams, $requiredParams);

        if (isset($protocolParams['oauth_token'])
            && !$this->_tokenProvider->validateOauthToken($protocolParams['oauth_token'])) {
            throw new OauthInputException(new Phrase('The token length is invalid. Check the length and try again.'));
        }

        // Validate signature method.
        if (!in_array($protocolParams['oauth_signature_method'], self::getSupportedSignatureMethods())) {
            throw new OauthInputException(
                new Phrase(
                    'Signature method %1 is not supported',
                    [$protocolParams['oauth_signature_method']]
                )
            );
        }

        $consumer = $this->_tokenProvider->getConsumerByKey($protocolParams['oauth_consumer_key']);
        $this->_nonceGenerator->validateNonce(
            $consumer,
            $protocolParams['oauth_nonce'],
            $protocolParams['oauth_timestamp']
        );
    }

    /**
     * Check if mandatory OAuth parameters are present.
     *
     * @param array $protocolParams
     * @param array $requiredParams
     * @return void
     * @throws OauthInputException
     */
    protected function _checkRequiredParams($protocolParams, $requiredParams)
    {
        $exception = new OauthInputException();
        foreach ($requiredParams as $param) {
            if (!isset($protocolParams[$param])) {
                $exception->addError(
                    new Phrase('"%fieldName" is required. Enter and try again.', ['fieldName' => $param])
                );
            }
        }
        if ($exception->wasErrorAdded()) {
            throw $exception;
        }
    }
}
