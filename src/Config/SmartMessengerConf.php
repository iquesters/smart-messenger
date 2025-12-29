<?php

namespace Iquesters\SmartMessenger\Config;

use Iquesters\Foundation\Support\BaseConf;
use Iquesters\Foundation\Support\ApiConf;
use Iquesters\Foundation\Enums\Module;

class SmartMessengerConf extends BaseConf
{
    // Inherited property of BaseConf, must initialize
    protected ?string $identifier = Module::SMART_MESSENGER;
    
    // properties of this class
    protected ApiConf $api_conf;

    protected function prepareDefault(BaseConf $default_values)
    {
        $default_values->api_conf = new ApiConf();
        $default_values->api_conf->prefix = 'smart-messenger'; // Must be auto generated from module enum - the vendor name  
        $default_values->api_conf->prepareDefault($default_values->api_conf);
    }
}