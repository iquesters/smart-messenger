<?php

namespace Iquesters\SmartMessenger\Config;

use Iquesters\Foundation\Support\BaseConf;
use Iquesters\Foundation\Enums\Module;

class SmartMessengerConf extends BaseConf
{
    // Inherited property of BaseConf, must initialize
    protected ?string $identifier = Module::SMART_MESSENGER;
    
    
    protected function prepareDefault(BaseConf $default_values)
    {
        
    }
}