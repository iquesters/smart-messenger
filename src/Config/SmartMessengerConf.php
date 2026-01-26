<?php

namespace Iquesters\SmartMessenger\Config;

use Iquesters\Foundation\Support\BaseConf;
use Iquesters\Foundation\Support\ApiConf;
use Iquesters\Foundation\Enums\Module;

class SmartMessengerConf extends BaseConf
{
    // Inherited property of BaseConf, must initialize
    protected ?string $identifier = Module::SMART_MESSENGER;
    
    // Media storage configuration
    protected string $media_storage_driver;
    
    // Media downgrade configuration
    protected bool $media_downgrade_enabled;
    protected int $media_downgrade_image_quality;
    protected int $media_downgrade_image_max_width;
    protected int $media_downgrade_image_max_height;
    
    // Cloud storage configuration (S3)
    protected ?string $media_s3_bucket;
    protected ?string $media_s3_region;
    protected ?string $media_s3_access_key;
    protected ?string $media_s3_secret_key;
    
    // Cloud storage configuration (Cloudinary)
    protected ?string $media_cloudinary_cloud_name;
    protected ?string $media_cloudinary_api_key;
    protected ?string $media_cloudinary_api_secret;

    protected function prepareDefault(BaseConf $default_values)
    {
        // Media storage driver: local, s3, cloudinary
        $default_values->media_storage_driver = 'local';
        
        // Media downgrade settings
        $default_values->media_downgrade_enabled = true;
        $default_values->media_downgrade_image_quality = 75;
        $default_values->media_downgrade_image_max_width = 1920;
        $default_values->media_downgrade_image_max_height = 1920;
        
        // S3 configuration (empty by default)
        $default_values->media_s3_bucket = null;
        $default_values->media_s3_region = null;
        $default_values->media_s3_access_key = null;
        $default_values->media_s3_secret_key = null;
        
        // Cloudinary configuration (empty by default)
        $default_values->media_cloudinary_cloud_name = null;
        $default_values->media_cloudinary_api_key = null;
        $default_values->media_cloudinary_api_secret = null;
    }
}