<?php

declare(strict_types=1);

namespace SAML2\Assertion;

use Psr\Log\LoggerInterface;

use SAML2\Configuration\Destination;
use SAML2\Configuration\IdentityProvider;
use SAML2\Configuration\ServiceProvider;
use SAML2\Response;
use SAML2\Signature\Validator;

interface ProcessorBuilderInterface
{

    /**
     * @param LoggerInterface $logger
     * @param Validator $signatureValidator
     * @param Destination $currentDestination
     * @param IdentityProvider $identityProvider
     * @param ServiceProvider $serviceProvider
     * @param Response $response
     * @return Processor
     */
    public function build(
        LoggerInterface $logger,
        Validator $signatureValidator,
        Destination $currentDestination,
        IdentityProvider $identityProvider,
        ServiceProvider $serviceProvider,
        Response $response
    ) : Processor;
}