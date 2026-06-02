<?php

namespace Iquesters\SmartMessenger\Services;

class VideoNormalizationException extends \RuntimeException
{
    public readonly string $clientCode;

    public function __construct(string $message, string $clientCode, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->clientCode = $clientCode;
    }
}
