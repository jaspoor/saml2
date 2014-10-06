<?php

class SAML2_Response_Exception_PreconditionNotMetException extends SAML2_Response_Exception_InvalidResponseException
{
    public static function createFromValidationResult(SAML2_Response_Validation_Result $result)
    {
        $message = sprintf(
            'Cannot process response, preconditions not met: "%s"',
            implode('", "', $result->getErrors())
        );

        return new self($message);
    }
}
