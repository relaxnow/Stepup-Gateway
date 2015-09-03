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

namespace Surfnet\StepupGateway\U2fVerificationBundle\Tests\Service;

use Mockery as m;
use PHPUnit_Framework_TestCase as TestCase;
use Surfnet\StepupGateway\U2fVerificationBundle\Service\AuthenticationVerificationResult;
use Surfnet\StepupGateway\U2fVerificationBundle\Service\U2fVerificationService;
use Surfnet\StepupGateway\U2fVerificationBundle\Value\KeyHandle;
use Surfnet\StepupGateway\U2fVerificationBundle\Value\PublicKey;
use u2flib_server\Error;

final class U2fVerificationServiceAuthenticationVerificationTest extends TestCase
{
    const APP_ID = 'https://gateway.surfconext.invalid/u2f/app-id';

    /**
     * @test
     * @group signing
     */
    public function it_can_verify_a_signing_response()
    {
        $publicKey  = 'public-key';
        $keyHandle = 'key-handle';
        $challenge = 'challenge';

        $yubicoRequest = new \u2flib_server\SignRequest();
        $yubicoRequest->version = \u2flib_server\U2F_VERSION;
        $yubicoRequest->appId = self::APP_ID;
        $yubicoRequest->keyHandle = $keyHandle;
        $yubicoRequest->challenge = $challenge;

        $yubicoRegistrationIn = new \u2flib_server\Registration();
        $yubicoRegistrationIn->publicKey = $publicKey;
        $yubicoRegistrationIn->keyHandle = $keyHandle;
        $yubicoRegistrationIn->counter = 0;

        $yubicoRegistrationOut = new \u2flib_server\Registration();
        $yubicoRegistrationOut->publicKey = $publicKey;
        $yubicoRegistrationOut->keyHandle = $keyHandle;
        $yubicoRegistrationOut->counter = 10;

        $request = new \Surfnet\StepupGateway\U2fVerificationBundle\Dto\SignRequest();
        $request->version   = \u2flib_server\U2F_VERSION;
        $request->challenge = $challenge;
        $request->appId     = self::APP_ID;
        $request->keyHandle = $keyHandle;

        $response = new \Surfnet\StepupGateway\U2fVerificationBundle\Dto\SignResponse();
        $response->keyHandle = $keyHandle;
        $response->clientData = 'client-data';
        $response->signatureData = 'signature-data';

        $expectedResult = AuthenticationVerificationResult::success();

        $u2f = m::mock('u2flib_server\U2F');
        $u2f->shouldReceive('doAuthenticate')
            ->once()
            ->with(m::anyOf([$yubicoRequest]), m::anyOf([$yubicoRegistrationIn]), m::anyOf($response))
            ->andReturn($yubicoRegistrationOut);

        $registrationRepository = m::mock('Surfnet\StepupGateway\U2fVerificationBundle\Repository\RegistrationRepository');

        $registrationRepository
            ->shouldReceive('findByKeyHandle')
            ->with(m::anyOf(new KeyHandle($keyHandle)))
            ->once()
            ->andReturn(
                new \Surfnet\StepupGateway\U2fVerificationBundle\Entity\Registration(
                    new KeyHandle($keyHandle),
                    new PublicKey($publicKey)
                )
            );

        $registrationWithCounter10 = new \Surfnet\StepupGateway\U2fVerificationBundle\Entity\Registration(
            new KeyHandle($keyHandle),
            new PublicKey($publicKey)
        );
        $registrationWithCounter10->authenticationWasVerified(10);
        $registrationRepository->shouldReceive('save')->with(m::anyOf($registrationWithCounter10))->once();

        $service = new U2fVerificationService($u2f, $registrationRepository);

        $this->assertEquals($expectedResult, $service->verifyAuthentication($request, $response));
    }

    /**
     * @test
     * @group signing
     */
    public function it_will_handle_missing_registrations()
    {
        $keyHandle = 'key-handle';
        $challenge = 'challenge';

        $request = new \Surfnet\StepupGateway\U2fVerificationBundle\Dto\SignRequest();
        $request->version   = \u2flib_server\U2F_VERSION;
        $request->challenge = $challenge;
        $request->appId     = self::APP_ID;
        $request->keyHandle = $keyHandle;

        $response = new \Surfnet\StepupGateway\U2fVerificationBundle\Dto\SignResponse();
        $response->keyHandle = $keyHandle;
        $response->clientData = 'client-data';
        $response->signatureData = 'signature-data';

        $expectedResult = AuthenticationVerificationResult::registrationUnknown();

        $u2f = m::mock('u2flib_server\U2F');
        $u2f->shouldReceive('doAuthenticate')->never();

        $registrationRepository = m::mock('Surfnet\StepupGateway\U2fVerificationBundle\Repository\RegistrationRepository');
        $registrationRepository
            ->shouldReceive('findByKeyHandle')
            ->with(m::anyOf(new KeyHandle($keyHandle)))
            ->once()
            ->andReturn(null);

        $service = new U2fVerificationService($u2f, $registrationRepository);

        $this->assertEquals($expectedResult, $service->verifyAuthentication($request, $response));
    }

    /**
     * @test
     * @group signing
     * @dataProvider expectedVerificationErrors
     *
     * @param int $errorCode
     * @param AuthenticationVerificationResult $expectedResult
     */
    public function it_handles_expected_u2f_registration_verification_errors(
        $errorCode,
        AuthenticationVerificationResult $expectedResult
    ) {
        $keyHandle = 'key-handle';
        $challenge = 'challenge';
        $publicKey = 'public-key';

        $yubicoRequest = new \u2flib_server\SignRequest();
        $yubicoRequest->keyHandle = $keyHandle;
        $yubicoRequest->appId     = self::APP_ID;
        $yubicoRequest->version   = \u2flib_server\U2F_VERSION;
        $yubicoRequest->challenge = $challenge;

        $yubicoRegistration = new \u2flib_server\Registration();
        $yubicoRegistration->keyHandle = $keyHandle;
        $yubicoRegistration->publicKey = $publicKey;

        $request = new \Surfnet\StepupGateway\U2fVerificationBundle\Dto\SignRequest();
        $request->version   = \u2flib_server\U2F_VERSION;
        $request->challenge = $challenge;
        $request->appId     = self::APP_ID;
        $request->keyHandle = $keyHandle;

        $response = new \Surfnet\StepupGateway\U2fVerificationBundle\Dto\SignResponse();
        $response->clientData = 'client-data';
        $response->keyHandle = $keyHandle;
        $response->signatureData = 'signature-data';

        $u2f = m::mock('u2flib_server\U2F');
        $u2f->shouldReceive('doAuthenticate')
            ->once()
            ->with(m::anyOf([$yubicoRequest]), m::anyOf([$yubicoRegistration]), m::anyOf($response))
            ->andThrow(new Error('error', $errorCode));

        $registrationRepository = m::mock('Surfnet\StepupGateway\U2fVerificationBundle\Repository\RegistrationRepository');
        $registrationRepository
            ->shouldReceive('findByKeyHandle')
            ->with(m::anyOf(new KeyHandle($keyHandle)))
            ->once()
            ->andReturn(
                new \Surfnet\StepupGateway\U2fVerificationBundle\Entity\Registration(
                    new KeyHandle($keyHandle),
                    new PublicKey($publicKey)
                )
            );

        $service = new U2fVerificationService($u2f, $registrationRepository);

        $this->assertEquals($expectedResult, $service->verifyAuthentication($request, $response));
    }

    public function expectedVerificationErrors()
    {
        // Autoload the U2F class to make sure the error constants are loaded which are also defined in the file.
        class_exists('u2flib_server\U2F');

        return [
            'requestResponseMismatch' => [
                \u2flib_server\ERR_NO_MATCHING_REQUEST,
                AuthenticationVerificationResult::requestResponseMismatch()
            ],
            'responseWasNotSignedByDevice' => [
                \u2flib_server\ERR_AUTHENTICATION_FAILURE,
                AuthenticationVerificationResult::responseWasNotSignedByDevice()
            ],
            'publicKeyDecodingFailed' => [
                \u2flib_server\ERR_PUBKEY_DECODE,
                AuthenticationVerificationResult::publicKeyDecodingFailed()
            ],
        ];
    }

    /**
     * @test
     * @group signing
     * @dataProvider expectedDeviceErrors
     *
     * @param int $deviceErrorCode
     */
    public function it_handles_expected_device_errors($deviceErrorCode) {
        $keyHandle = 'key-handle';
        $challenge = 'challenge';

        $request = new \Surfnet\StepupGateway\U2fVerificationBundle\Dto\SignRequest();
        $request->version   = \u2flib_server\U2F_VERSION;
        $request->challenge = $challenge;
        $request->appId     = self::APP_ID;
        $request->keyHandle = $keyHandle;

        $response = new \Surfnet\StepupGateway\U2fVerificationBundle\Dto\SignResponse();
        $response->errorCode = $deviceErrorCode;
        $response->clientData = 'client-data';
        $response->keyHandle = $keyHandle;
        $response->signatureData = 'signature-data';

        $u2f = m::mock('u2flib_server\U2F');
        $u2f->shouldReceive('doAuthenticate')->never();

        $registrationRepository = m::mock('Surfnet\StepupGateway\U2fVerificationBundle\Repository\RegistrationRepository');
        $registrationRepository->shouldReceive('findByKeyHandle')->never();

        $service = new U2fVerificationService($u2f, $registrationRepository);

        $expectedResult = AuthenticationVerificationResult::deviceReportedError($deviceErrorCode);
        $this->assertEquals($expectedResult, $service->verifyAuthentication($request, $response));
    }

    public function expectedDeviceErrors()
    {
        // Autoload the U2F class to make sure the error constants are loaded which are also defined in the file.
        class_exists('u2flib_server\U2F');

        return [
            'ERROR_CODE_OTHER_ERROR' => [
                \Surfnet\StepupGateway\U2fVerificationBundle\Dto\SignResponse::ERROR_CODE_OTHER_ERROR,
            ],
            'ERROR_CODE_BAD_REQUEST' => [
                \Surfnet\StepupGateway\U2fVerificationBundle\Dto\SignResponse::ERROR_CODE_BAD_REQUEST,
            ],
            'ERROR_CODE_CONFIGURATION_UNSUPPORTED' => [
                \Surfnet\StepupGateway\U2fVerificationBundle\Dto\SignResponse::ERROR_CODE_CONFIGURATION_UNSUPPORTED,
            ],
            'ERROR_CODE_DEVICE_INELIGIBLE' => [
                \Surfnet\StepupGateway\U2fVerificationBundle\Dto\SignResponse::ERROR_CODE_DEVICE_INELIGIBLE,
            ],
            'ERROR_CODE_TIMEOUT' => [
                \Surfnet\StepupGateway\U2fVerificationBundle\Dto\SignResponse::ERROR_CODE_TIMEOUT,
            ],
        ];
    }

    /**
     * @test
     * @group signing
     * @dataProvider unexpectedVerificationErrors
     *
     * @param int $errorCode
     */
    public function it_throws_unexpected_u2f_registration_verification_errors($errorCode)
    {
        $challenge = 'challenge';
        $keyHandle = 'key-handle';
        $publicKey = 'public-key';

        $yubicoRequest = new \u2flib_server\SignRequest();
        $yubicoRequest->challenge = $challenge;
        $yubicoRequest->keyHandle = $keyHandle;
        $yubicoRequest->appId     = self::APP_ID;
        $yubicoRequest->version   = \u2flib_server\U2F_VERSION;

        $yubicoRegistration = new \u2flib_server\Registration();
        $yubicoRegistration->keyHandle = $keyHandle;
        $yubicoRegistration->publicKey = $publicKey;

        $request = new \Surfnet\StepupGateway\U2fVerificationBundle\Dto\SignRequest();
        $request->version   = \u2flib_server\U2F_VERSION;
        $request->challenge = $challenge;
        $request->appId     = self::APP_ID;
        $request->keyHandle = $keyHandle;

        $response = new \Surfnet\StepupGateway\U2fVerificationBundle\Dto\SignResponse();
        $response->clientData = 'client-data';
        $response->keyHandle = $keyHandle;
        $response->signatureData = 'signature-data';

        $u2f = m::mock('u2flib_server\U2F');
        $u2f->shouldReceive('doAuthenticate')
            ->once()
            ->with(m::anyOf([$yubicoRequest]), m::anyOf([$yubicoRegistration]), m::anyOf($response))
            ->andThrow(new Error('error', $errorCode));

        $registrationRepository = m::mock('Surfnet\StepupGateway\U2fVerificationBundle\Repository\RegistrationRepository');
        $registrationRepository
            ->shouldReceive('findByKeyHandle')
            ->with(m::anyOf(new KeyHandle($keyHandle)))
            ->once()
            ->andReturn(
                new \Surfnet\StepupGateway\U2fVerificationBundle\Entity\Registration(
                    new KeyHandle($keyHandle),
                    new PublicKey($publicKey)
                )
            );

        $service = new U2fVerificationService($u2f, $registrationRepository);

        $this->setExpectedExceptionRegExp('Surfnet\StepupGateway\U2fVerificationBundle\Exception\LogicException');
        $service->verifyAuthentication($request, $response);
    }

    public function unexpectedVerificationErrors()
    {
        // Autoload the U2F class to make sure the error constants are loaded which are also defined in the file.
        class_exists('u2flib_server\U2F');

        return [
            [\u2flib_server\ERR_NO_MATCHING_REGISTRATION],
            [\u2flib_server\ERR_ATTESTATION_VERIFICATION],
            [\u2flib_server\ERR_BAD_RANDOM],
            [235789],
        ];
    }
}
