<?php

namespace Local\Lib\DTO\Exceptions;

use RuntimeException;

class UnmappedPropertiesException extends RuntimeException
{
    public function __construct(
        public readonly array $unmappedKeys,
        string $message = ""
    ) {
        if ($message === "") {
            $message = "Strict Mode: Unmapped properties found in input data - " . implode(', ', $unmappedKeys);
        }
        parent::__construct($message);
    }
}