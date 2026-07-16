<?php

namespace League\Flysystem\AkamaiNetStorage\Exception;

use Akamai\Open\EdgeGrid\Authentication\Exception;

class CustomMessageException extends Exception
{
    public function __construct($message)
    {
        parent::__construct($message);
    }
}

