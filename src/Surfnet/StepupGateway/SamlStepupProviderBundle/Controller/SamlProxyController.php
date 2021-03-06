<?php

/**
 * Copyright 2014 SURFnet bv
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Surfnet\StepupGateway\SamlStepupProviderBundle\Controller;

use DateTime;
use Exception;
use SAML2_Const;
use SAML2_Response;
use Surfnet\SamlBundle\Http\XMLResponse;
use Surfnet\SamlBundle\SAML2\AuthnRequest;
use Surfnet\SamlBundle\SAML2\AuthnRequestFactory;
use Surfnet\StepupGateway\GatewayBundle\Saml\AssertionAdapter;
use Surfnet\StepupGateway\SamlStepupProviderBundle\Provider\Provider;
use Surfnet\StepupGateway\SamlStepupProviderBundle\Saml\StateHandler;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 *
 * Should be refactored, {@see https://www.pivotaltracker.com/story/show/90169776}
 */
class SamlProxyController extends Controller
{
    /**
     * @param string  $provider
     * @param Request $httpRequest
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function singleSignOnAction($provider, Request $httpRequest)
    {
        $provider = $this->getProvider($provider);

        /** @var \Psr\Log\LoggerInterface $logger */
        $logger = $this->get('logger');
        $logger->notice('Received AuthnRequest, started processing');

        /** @var \Surfnet\SamlBundle\Http\RedirectBinding $redirectBinding */
        $redirectBinding = $this->get('surfnet_saml.http.redirect_binding');

        try {
            $originalRequest = $redirectBinding->processRequest($httpRequest);
        } catch (Exception $e) {
            $logger->critical(sprintf('Could not process Request, error: "%s"', $e->getMessage()));

            return $this->render('unrecoverableError');
        }

        $originalRequestId = $originalRequest->getRequestId();
        $logger = $this->get('surfnet_saml.logger')->forAuthentication($originalRequestId);
        $logger->notice(sprintf(
            'AuthnRequest processing complete, received AuthnRequest from "%s", request ID: "%s"',
            $originalRequest->getServiceProvider(),
            $originalRequest->getRequestId()
        ));

        $logger->debug('Checking if SP "%s" is supported');
        /**
         * @var \Surfnet\StepupGateway\SamlStepupProviderBundle\Provider\ConnectedServiceProviders $connectedServiceProviders
         */
        $connectedServiceProviders = $this->get('gssp.connected_service_providers');
        if (!$connectedServiceProviders->isConnected($originalRequest->getServiceProvider())) {
            $logger->warning(sprintf(
                'Received AuthnRequest from SP "%s", while SP is not allowed to use this for SSO',
                $originalRequest->getServiceProvider()
            ));

            throw new AccessDeniedHttpException();
        }

        /** @var StateHandler $stateHandler */
        $stateHandler = $provider->getStateHandler();
        $stateHandler
            ->setRequestId($originalRequestId)
            ->setRequestServiceProvider($originalRequest->getServiceProvider())
            ->setRelayState($httpRequest->get(AuthnRequest::PARAMETER_RELAY_STATE, ''));

        $proxyRequest = AuthnRequestFactory::createNewRequest(
            $provider->getServiceProvider(),
            $provider->getRemoteIdentityProvider()
        );

        // if a Specific subject is given to authenticate we should proxy that and verify in the response
        // that that subject indeed was authenticated
        $nameId = $originalRequest->getNameId();
        if ($nameId) {
            $proxyRequest->setSubject($nameId, $originalRequest->getNameIdFormat());
            $stateHandler->setSubject($nameId);
        }

        $proxyRequest->setScoping([$originalRequest->getServiceProvider()]);
        $stateHandler->setGatewayRequestId($proxyRequest->getRequestId());

        $logger->notice(sprintf(
            'Sending Proxy AuthnRequest with request ID: "%s" for original AuthnRequest "%s" to GSSP "%s" at "%s"',
            $proxyRequest->getRequestId(),
            $originalRequest->getRequestId(),
            $provider->getName(),
            $provider->getRemoteIdentityProvider()->getSsoUrl()
        ));

        return $redirectBinding->createRedirectResponseFor($proxyRequest);
    }

    public function sendSecondFactorVerificationAuthnRequestAction($provider, $subjectNameId)
    {
        $provider = $this->getProvider($provider);
        $stateHandler = $provider->getStateHandler();

        $originalRequestId = $this->get('gateway.proxy.response_context')->getInResponseTo();

        $authnRequest = AuthnRequestFactory::createNewRequest(
            $provider->getServiceProvider(),
            $provider->getRemoteIdentityProvider()
        );
        $authnRequest->setSubject($subjectNameId);

        $stateHandler
            ->setRequestId($originalRequestId)
            ->setGatewayRequestId($authnRequest->getRequestId())
            ->setSubject($subjectNameId)
            ->markRequestAsSecondFactorVerification();

        /** @var \Surfnet\SamlBundle\Monolog\SamlAuthenticationLogger $logger */
        $logger = $this->get('surfnet_saml.logger')->forAuthentication($originalRequestId);
        $logger->notice(sprintf(
            'Sending AuthnRequest to verify Second Factor with request ID: "%s" to GSSP "%s" at "%s" for subject "%s"',
            $authnRequest->getRequestId(),
            $provider->getName(),
            $provider->getRemoteIdentityProvider()->getSsoUrl(),
            $subjectNameId
        ));

        /** @var \Surfnet\SamlBundle\Http\RedirectBinding $redirectBinding */
        $redirectBinding = $this->get('surfnet_saml.http.redirect_binding');

        return $redirectBinding->createRedirectResponseFor($authnRequest);
    }

    /**
     * @param string  $provider
     * @param Request $httpRequest
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function consumeAssertionAction($provider, Request $httpRequest)
    {
        $provider = $this->getProvider($provider);
        $stateHandler = $provider->getStateHandler();
        $originalRequestId = $stateHandler->getRequestId();

        /** @var \Surfnet\SamlBundle\Monolog\SamlAuthenticationLogger $logger */
        $logger = $this->get('surfnet_saml.logger')->forAuthentication($originalRequestId);

        $action = $stateHandler->hasSubject() ? 'Second Factor Verification' : 'Proxy Response';
        $logger->notice(
            sprintf('Received SAMLResponse, attempting to process for %s', $action)
        );

        try {
            /** @var \SAML2_Assertion $assertion */
            $assertion = $this->get('surfnet_saml.http.post_binding')->processResponse(
                $httpRequest,
                $provider->getRemoteIdentityProvider(),
                $provider->getServiceProvider()
            );
        } catch (Exception $exception) {
            $logger->error(sprintf('Could not process received Response, error: "%s"', $exception->getMessage()));

            $response = $this->createResponseFailureResponse($provider);

            return $this->renderSamlResponse('unprocessableResponse', $stateHandler, $response);
        }

        $adaptedAssertion = new AssertionAdapter($assertion);
        $expectedResponse = $stateHandler->getGatewayRequestId();
        if (!$adaptedAssertion->inResponseToMatches($expectedResponse)) {
            $logger->critical(sprintf(
                'Received Response with unexpected InResponseTo: "%s", %s',
                $adaptedAssertion->getInResponseTo(),
                ($expectedResponse ? 'expected "' . $expectedResponse . '"' : ' no response expected')
            ));

            return $this->render('unrecoverableError');
        }

        $authenticatedNameId = $assertion->getNameId();
        $isSubjectRequested = $stateHandler->hasSubject();
        if ($isSubjectRequested && ($stateHandler->getSubject() !== $authenticatedNameId['Value'])) {
            $logger->critical(sprintf(
                'Requested Subject NameID "%s" and Response NameID "%s" do not match',
                $stateHandler->getSubject(),
                $authenticatedNameId['Value']
            ));

            if ($stateHandler->secondFactorVerificationRequested()) {
                // the error should go to the original requesting service provider
                $targetServiceProvider = $this->get('gateway.proxy.response_context')->getServiceProvider();
                $stateHandler->setRequestServiceProvider($targetServiceProvider->getEntityId());
            }

            return $this->renderSamlResponse(
                'recoverableError',
                $stateHandler,
                $this->createAuthnFailedResponse($provider)
            );
        }

        $logger->notice('Successfully processed SAMLResponse');

        if ($stateHandler->secondFactorVerificationRequested()) {
            $logger->notice(
                'Second Factor verification was requested and was successful, forwarding to SecondFactor handling'
            );

            return $this->forward('SurfnetStepupGatewayGatewayBundle:SecondFactor:gssfVerified');
        }

        /** @var \Surfnet\StepupGateway\SamlStepupProviderBundle\Saml\ProxyResponseFactory $proxyResponseFactory */
        $targetServiceProvider = $this->getServiceProvider($stateHandler->getRequestServiceProvider());
        $proxyResponseFactory = $this->get('gssp.provider.' . $provider->getName() . '.response_proxy');
        $response             = $proxyResponseFactory->createProxyResponse($assertion, $targetServiceProvider);

        $logger->notice(sprintf(
            'Responding to request "%s" with response based on response from the remote IdP with response "%s"',
            $stateHandler->getRequestId(),
            $response->getId()
        ));

        return $this->renderSamlResponse('consumeAssertion', $stateHandler, $response);
    }

    /**
     * @param string $provider
     * @return XMLResponse
     */
    public function metadataAction($provider)
    {
        $provider = $this->getProvider($provider);

        /** @var \Surfnet\SamlBundle\Metadata\MetadataFactory $factory */
        $factory = $this->get('gssp.provider.' . $provider->getName() . '.metadata.factory');

        return new XMLResponse($factory->generate());
    }

    /**
     * @param string $provider
     * @return \Surfnet\StepupGateway\SamlStepupProviderBundle\Provider\Provider
     */
    private function getProvider($provider)
    {
        /** @var \Surfnet\StepupGateway\SamlStepupProviderBundle\Provider\ProviderRepository $providerRepository */
        $providerRepository = $this->get('gssp.provider_repository');

        if (!$providerRepository->has($provider)) {
            $this->get('logger')->info(sprintf('Requested GSSP "%s" does not exist or is not registered', $provider));

            throw new NotFoundHttpException('Requested provider does not exist');
        }

        return $providerRepository->get($provider);
    }

    /**
     * @param string         $view
     * @param StateHandler   $stateHandler
     * @param SAML2_Response $response
     * @return Response
     */
    public function renderSamlResponse($view, StateHandler $stateHandler, SAML2_Response $response)
    {
        $response = $this->render($view, [
            'acu'        => $response->getDestination(),
            'response'   => $this->getResponseAsXML($response),
            'relayState' => $stateHandler->getRelayState()
        ]);

        // clear the state so we can call again :)
        $stateHandler->clear();

        return $response;
    }

    /**
     * @param string   $view
     * @param array    $parameters
     * @param Response $response
     * @return Response
     */
    public function render($view, array $parameters = array(), Response $response = null)
    {
        return parent::render(
            'SurfnetStepupGatewaySamlStepupProviderBundle:SamlProxy:' . $view . '.html.twig',
            $parameters,
            $response
        );
    }

    /**
     * @param SAML2_Response $response
     * @return string
     */
    private function getResponseAsXML(SAML2_Response $response)
    {
        return base64_encode($response->toUnsignedXML()->ownerDocument->saveXML());
    }

    /**
     * Response that indicates that an error occurred in the responder (the gateway). Used to indicate that we could
     * not process the response we received from the upstream GSSP
     *
     * @param Provider $provider
     * @return SAML2_Response
     */
    private function createResponseFailureResponse(Provider $provider)
    {
        $response = $this->createResponse($provider);
        $response->setStatus(['Code' => SAML2_Const::STATUS_RESPONDER]);

        return $response;
    }

    /**
     * Response that indicates that the authentication could not be performed correctly. In this context it means
     * that the upstream GSSP did not responsd with the same NameID as we request to authenticate in the AuthnRequest
     *
     * @param Provider $provider
     * @return SAML2_Response
     */
    private function createAuthnFailedResponse(Provider $provider)
    {
        $response = $this->createResponse($provider);
        $response->setStatus([
            'Code'    => SAML2_Const::STATUS_RESPONDER,
            'SubCode' => SAML2_Const::STATUS_AUTHN_FAILED
        ]);

        return $response;
    }

    /**
     * Creates a standard response with default status Code (success)
     *
     * @param Provider $provider
     * @return SAML2_Response
     */
    private function createResponse(Provider $provider)
    {
        $serviceProvider = $this->getServiceProvider($provider->getStateHandler()->getRequestServiceProvider());

        $response = new SAML2_Response();
        $response->setDestination($serviceProvider->getAssertionConsumerUrl());
        $response->setIssuer($provider->getIdentityProvider()->getEntityId());
        $response->setIssueInstant((new DateTime('now'))->getTimestamp());
        $response->setInResponseTo($provider->getStateHandler()->getRequestId());

        return $response;
    }

    /**
     * @param string $serviceProvider
     * @return \Surfnet\SamlBundle\Entity\ServiceProvider
     */
    private function getServiceProvider($serviceProvider)
    {
        /**
         * @var \Surfnet\StepupGateway\SamlStepupProviderBundle\Provider\ConnectedServiceProviders $connectedServiceProviders
         */
        $connectedServiceProviders = $this->get('gssp.connected_service_providers');
        return $connectedServiceProviders->getConfigurationOf($serviceProvider);
    }
}
