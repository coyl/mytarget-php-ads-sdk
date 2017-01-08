<?php

namespace Dsl\MyTarget\Mapper\Exception;

use Dsl\MyTarget\Exception\SdkException;

class TypeParsingException extends \LogicException
    implements SdkException, ContextUnawareException
{
    /**
     * @var string
     */
    public $type;

    /**
     * @param string $type
     */
    public function __construct($type)
    {
        parent::__construct(sprintf("Couldn't parse type %s", $type));

        $this->type = $type;
    }
}
