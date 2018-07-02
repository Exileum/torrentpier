<?php
/**
 * TorrentPier – Bull-powered BitTorrent tracker engine
 *
 * @copyright Copyright (c) 2005-2018 TorrentPier (https://torrentpier.com)
 * @link      https://github.com/torrentpier/torrentpier for the canonical source repository
 * @license   https://github.com/torrentpier/torrentpier/blob/master/LICENSE MIT License
 */

/**
 * Captcha configuration.
 */
$config['captcha'] = [
    'disabled' => false,
    'keys' => [
        'public' => '',
        'secret' => '',
    ],
    'theme' => 'light',
];

/**
 * Logger configuration.
 */
$config['log'] = [
    'handlers' => [
        function () {
            return new \Monolog\Handler\StreamHandler(
                __DIR__ . '/../internal_data/log/app.log',
                \Monolog\Logger::DEBUG
            );
        }
    ],
];