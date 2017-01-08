<?php

namespace Dsl\MyTarget\Operator\Exception;

use Dsl\MyTarget\Exception\SdkException;

class UnexpectedFileArgumentException extends \InvalidArgumentException
    implements SdkException
{
    /**
     * @param mixed $file
     */
    public function __construct($file)
    {
        parent::__construct(sprintf("Unexpected type: %s (StreamInterface, resource or file path is expected)",
            is_object($file) ? get_class($file) : gettype($file)));
    }
}
