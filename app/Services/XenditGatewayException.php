<?php

namespace App\Services;

use RuntimeException;

class XenditGatewayException extends RuntimeException
{
    public function __construct(
        private readonly int $httpStatus,
        private readonly ?string $errorCode,
        string $gatewayMessage,
        private readonly string $passengerMessage,
    ) {
        parent::__construct($gatewayMessage);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function passengerMessage(): string
    {
        return $this->passengerMessage;
    }
}
