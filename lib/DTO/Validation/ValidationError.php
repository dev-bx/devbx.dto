<?php

namespace Local\Lib\DTO\Validation;

class ValidationError
{
    public function __construct(
        protected string $message,
        protected string|int $code = 0
    ) {}

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getCode(): string|int
    {
        return $this->code;
    }
}