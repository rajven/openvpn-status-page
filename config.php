<?php

defined('CONFIG') or die('Direct access not allowed');

define('REQUEST_INTERVAL', 60);
define('SHOW_CERT_SCRIPT','/etc/openvpn/server/show_client_crt.sh');

// config.php - конфигурация OpenVPN серверов
return [
    'server1' => [
        'name' => 'server1',
        'title' => 'Server1',
        'config' => '/etc/openvpn/server/server.conf',
        'ccd' => '/etc/openvpn/server/server/ccd',
        'port' => '3003',
        'host' => '127.0.0.1',
        'password' => 'password',
        'cfg_template' => 'server1.ovpn.template',
        'cert_index' => '/etc/openvpn/server/server/rsa/pki/index.txt',
        'ipp_file' => '/etc/openvpn/server/server/ipp.txt'
    ],
];
