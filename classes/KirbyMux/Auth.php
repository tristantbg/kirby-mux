<?php

namespace KirbyMux;

use MuxPhp;
use GuzzleHttp;

class Auth
{
    public static function assetsApi()
    {
        kirby()->impersonate('kirby');
        // Authentication setup
        $config = MuxPhp\Configuration::getDefaultConfiguration()
            ->setUsername(option('tristantbg.kirby-mux.tokenId'))
            ->setPassword(option('tristantbg.kirby-mux.tokenSecret'));

        // API client initialization
        $assetsApi = new MuxPhp\Api\AssetsApi(
            new GuzzleHttp\Client(),
            $config
        );

        return $assetsApi;
    }
}
