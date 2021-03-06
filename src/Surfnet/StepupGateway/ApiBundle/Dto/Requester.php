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

namespace Surfnet\StepupGateway\ApiBundle\Dto;

use Surfnet\StepupBundle\Request\JsonConvertible;
use Symfony\Component\Validator\Constraints as Assert;

class Requester implements JsonConvertible
{
    /**
     * @Assert\NotBlank(message="requester.institution.must_be_set")
     * @Assert\Type(type="string", message="requester.institution.must_be_string")
     * @var string
     */
    public $institution;

    /**
     * @Assert\NotBlank(message="requester.identity.must_be_set")
     * @Assert\Type(type="string", message="requester.identity.must_be_string")
     * @var string
     */
    public $identity;
}
