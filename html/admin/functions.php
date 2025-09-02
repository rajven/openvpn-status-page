<?php

defined('CONFIG') or die('Direct access not allowed');

function canRequestStatus($server) {
    if (!isset($_SESSION['last_request_time'][$server['name']])) { return true; }
    if (time() - $_SESSION['last_request_time'][$server['name']] >= REQUEST_INTERVAL) { return true; }
    return false;
}

function updateLastRequestTime($server) {
    $_SESSION['last_request_time'][$server['name']] = time();
}

function openvpnManagementCommand($server, $command) {
    $mgmt_host = $server['host'];
    $mgmt_port = $server['port'];
    $mgmt_pass = $server['password'];

    $timeout = 5;
    $socket = @fsockopen($mgmt_host, $mgmt_port, $errno, $errstr, $timeout);

    if (!$socket) {
        error_log("OpenVPN management connection failed to {$server['name']}: $errstr ($errno)");
        return false;
    }

    stream_set_timeout($socket, $timeout);

    try {
        // Читаем приветственное сообщение
        $welcome = '';
        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === false) break;
            $welcome .= $line;
            if (strpos($welcome, 'ENTER PASSWORD:') !== false) break;
        }

        // Отправляем пароль
        if (@fwrite($socket, "$mgmt_pass\n") === false) {
            throw new Exception("Failed to send password");
        }

        // Ждем подтверждения аутентификации
        $authResponse = '';
        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === false) break;
            $authResponse .= $line;
            if (strpos($authResponse, 'SUCCESS:') !== false || strpos($authResponse, '>INFO:') !== false) break;
        }

        // Отправляем команду
        if (@fwrite($socket, "$command\n") === false) {
            throw new Exception("Failed to send command");
        }

        // Читаем ответ
        $response = '';
        $expectedEnd = strpos($command, 'status') !== false ? "END\r\n" : ">";
        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === false) break;
            $response .= $line;
            if (strpos($response, $expectedEnd) !== false) break;
        }

        return $response;

    } catch (Exception $e) {
        error_log("OpenVPN management error ({$server['name']}): " . $e->getMessage());
        return false;
    } finally {
        @fwrite($socket, "quit\n");
        @fclose($socket);
    }
}

function getOpenVPNStatus($server) {
    // Проверяем, можно ли делать запрос
    if (!canRequestStatus($server)) {
        // Возвращаем кэшированные данные или пустой массив
        return $_SESSION['cached_status'][$server['name']] ?? [];
    }

    // Обновляем время последнего запроса
    updateLastRequestTime($server);

    $response = openvpnManagementCommand($server, "status 2");
    if (!$response) return $_SESSION['cached_status'][$server['name']] ?? [];

    $banned = getBannedClients($server);

    $clients = [];
    $lines = explode("\n", $response);
    $in_client_list = false;

    foreach ($lines as $line) {
        $line = trim($line);

        if (strpos($line, 'HEADER,CLIENT_LIST') === 0) {
            $in_client_list = true;
            continue;
        }

        if (strpos($line, 'HEADER,ROUTING_TABLE') === 0) {
            $in_client_list = false;
            continue;
        }

//CLIENT_LIST,Common Name,Real Address,Virtual Address,Virtual IPv6 Address,Bytes Received,Bytes Sent,Connected Since,Connected Since (time_t),Username,Client ID,Peer ID,Data Channel Cipher
        if ($in_client_list && strpos($line, 'CLIENT_LIST') === 0) {
            $parts = explode(',', $line);
            if (count($parts) >= 9) {
                $clients[] = [
                    'name' => $parts[1],
                    'real_ip' => $parts[2],
                    'virtual_ip' => $parts[3],
                    'bytes_received' => formatBytes($parts[5]),
                    'bytes_sent' => formatBytes($parts[6]),
                    'connected_since' => $parts[7],
                    'username' => $parts[8] ?? $parts[1],
                    'cipher' => end($parts),
                    'banned' => isset($banned[$parts[1]]),
                ];
            }
        }
    }

    // Кэшируем результат
    $_SESSION['cached_status'][$server['name']] = $clients;

    return $clients;
}

function get_servers_crt($cert_index) {
    // Проверка входных параметров
    if (empty($cert_index) || !is_string($cert_index)) {
        return false;
    }

    // Проверка существования исполняемого файла
    if (empty(SHOW_SERVERS_CRT) || !file_exists(SHOW_SERVERS_CRT) || !is_executable(SHOW_SERVERS_CRT)) {
        error_log('SHOW_SERVERS_CRT is not configured properly', 0);
        return false;
    }

    $command = sprintf(
        'sudo %s %s 2>&1',
        escapeshellcmd(SHOW_SERVERS_CRT),
        escapeshellarg($cert_index)
    );

    exec($command, $cert_content, $return_var);

    if ($return_var !== 0) {
        error_log(sprintf(
            'Command failed: %s (return code: %d, output: %s)',
            $command,
            $return_var,
            implode("\n", $cert_content)
        ), 0);
        return false;
    }

    if (empty($cert_content)) {
        error_log('Empty certificate content for file: '.$cert_index, 0);
        return false;
    }

    $result = array_fill_keys($cert_content, true);

    return $result;
}

function getBannedClients($server) {
    // Проверка входных параметров
    if (empty($server["ccd"]) || !is_string($server["ccd"])) {
        return [];
    }

    // Проверка существования исполняемого файла
    if (empty(SHOW_BANNED) || !file_exists(SHOW_BANNED) || !is_executable(SHOW_BANNED)) {
        error_log('SHOW_BANNED is not configured properly', 0);
        return [];
    }

    $command = sprintf(
        'sudo %s %s 2>&1',
        escapeshellcmd(SHOW_BANNED),
        escapeshellarg($server["ccd"])
    );

    exec($command, $banned_content, $return_var);
    if ($return_var !== 0) {
        error_log(sprintf(
            'Command failed: %s (return code: %d)',
            $command,
            $return_var,
        ), 0);
        return [];
    }

    if (empty($banned_content)) { return []; }

    $result = array_fill_keys($banned_content, true);
    return $result;
}

function getClientIPsCCD($server) {
    // Проверка входных параметров
    if (empty($server["ccd"]) || !is_string($server["ccd"])) {
        return [];
    }

    // Проверка существования исполняемого файла
    if (empty(GET_IPS_FROM_CCD) || !file_exists(GET_IPS_FROM_CCD) || !is_executable(GET_IPS_FROM_CCD)) {
        error_log('SHOW_BANNED is not configured properly', 0);
        return [];
    }

    $command = sprintf(
        'sudo %s %s 2>&1',
        escapeshellcmd(GET_IPS_FROM_CCD),
        escapeshellarg($server["ccd"])
    );

    exec($command, $ccd_content, $return_var);
    if ($return_var !== 0) {
        error_log(sprintf(
            'Command failed: %s (return code: %d)',
            $command,
            $return_var,
        ), 0);
        return [];
    }

    if (empty($ccd_content)) { return []; }

    $result=[];
    foreach ($ccd_content as $line) {
	if (empty($line)) { continue; }
        list($login, $ip) = explode(' ', trim($line), 2);
	$result[$login] = $ip;
    }

    return $result;
}

function getClientIPsIPP($server) {
    // Проверка входных параметров
    if (empty($server["ipp_file"]) || !is_string($server["ipp_file"])) {
        return [];
    }

    // Проверка существования исполняемого файла
    if (empty(GET_IPS_FROM_IPP) || !file_exists(GET_IPS_FROM_IPP) || !is_executable(GET_IPS_FROM_IPP)) {
        error_log('SHOW_BANNED is not configured properly', 0);
        return [];
    }

    $command = sprintf(
        'sudo %s %s 2>&1',
        escapeshellcmd(GET_IPS_FROM_IPP),
        escapeshellarg($server["ipp_file"])
    );

    exec($command, $ipp_content, $return_var);
    if ($return_var !== 0) {
        error_log(sprintf(
            'Command failed: %s (return code: %d)',
            $command,
            $return_var,
        ), 0);
        return [];
    }

    if (empty($ipp_content)) { return []; }

    $result=[];
    foreach ($ipp_content as $line) {
	if (empty($line)) { continue; }
        list($login, $ip) = explode(',', trim($line), 2);
	$result[$login] = $ip;
    }

    return $result;
}

function getAccountList($server) {
    $accounts = [];

    $banned = getBannedClients($server);

    // Получаем список из index.txt (неотозванные сертификаты)
    if (!empty($server['cert_index']) && !empty(SHOW_PKI_INDEX) && file_exists(SHOW_PKI_INDEX)) {
	$servers_list = get_servers_crt($server['cert_index']);
        // Безопасное выполнение скрипта
        $command = sprintf(
            'sudo %s %s 2>&1',
            escapeshellcmd(SHOW_PKI_INDEX),
            escapeshellarg($server['cert_index']),
        );
        exec($command,  $index_content, $return_var);
        if ($return_var == 0) {
            foreach ($index_content as $line) {
                if (empty(trim($line))) { continue; }
                if (preg_match('/\/CN=([^\/]+)/', $line, $matches)) {
                        $username = trim($matches[1]);
                        }
                if (empty($username)) { continue; }
                $revoked = false;
                if (preg_match('/^R\s+/',$line)) { $revoked = true; }
                if (isset($servers_list[$username])) { continue; }
                $accounts[$username] = [
                        "username" => $username,
                        "ip" => null,
                        "banned" => isset($banned[$username]) || $revoked,
                        "revoked" => $revoked
                        ];
                }
            }
    }

    // Получаем список выданных IP из ipp.txt
    if (!empty($server['ipp_file']) && file_exists($server['ipp_file'])) {
	$ipps = getClientIPsIPP($server);
	foreach ($ipps as $username => $ip) {
            if (!isset($accounts[$username]) && empty($server['cert_index'])) {
                    $accounts[$username] = [
                        "username" => $username,
                        "banned" => isset($banned[$username]),
                        "ip" => $ip,
                        "revoked" => false,
                    ];
                }
            if (isset($accounts[$username]) and !empty($server['cert_index'])) {
                    $accounts[$username]["ip"] = $ip;
                }
        }
    }

    // Ищем IP-адреса в CCD файлах
    if (!empty($server['ccd']) && is_dir($server['ccd'])) {
	$ccds = getClientIPsCCD($server);
	foreach ($ccds as $username => $ip) {
            if (!isset($accounts[$username]) && empty($server['cert_index'])) {
                    $accounts[$username] = [
                        "username" => $username,
                        "banned" => isset($banned[$username]),
                        "ip" => $ip,
                        "revoked" => false,
                    ];
                }
            if (isset($accounts[$username]) and !empty($server['cert_index'])) {
                    $accounts[$username]["ip"] = $ip;
                }
        }
    }
    return $accounts;
}

function kickClient($server, $client_name) {
    return openvpnManagementCommand($server, "kill $client_name");
}

function removeCCD($server, $client_name) {
    if (empty($server["ccd"]) || empty($client_name) || empty(REMOVE_CCD) || !file_exists(REMOVE_CCD)) { return false; }

    $script_path = REMOVE_CCD;
    $ccd_file = "{$server['ccd']}/$client_name";
    $command = sprintf(
        'sudo %s %s 2>&1',
        escapeshellcmd($script_path),
        escapeshellarg($ccd_file)
    );
    exec($command, $output, $return_var);

    $_SESSION['last_request_time'] = [];

    if ($return_var === 0) {
        return true;
    } else {
        return false;
    }
}


function unbanClient($server, $client_name) {
    if (empty($server["ccd"]) || empty($client_name) || empty(BAN_CLIENT) || !file_exists(BAN_CLIENT)) { return false; }


    $script_path = BAN_CLIENT;
    $ccd_file = "{$server['ccd']}/$client_name";
    $command = sprintf(
        'sudo %s %s unban 2>&1',
        escapeshellcmd($script_path),
        escapeshellarg($ccd_file)
    );
    exec($command, $output, $return_var);

    $_SESSION['last_request_time'] = [];

    if ($return_var === 0) {
        return true;
    } else {
        return false;
    }
}

function banClient($server, $client_name) {
    if (empty($server["ccd"]) || empty($client_name) || empty(BAN_CLIENT) || !file_exists(BAN_CLIENT)) { return false; }

    $script_path = BAN_CLIENT;
    $ccd_file = "{$server['ccd']}/$client_name";
    $command = sprintf(
        'sudo %s %s ban 2>&1',
        escapeshellcmd($script_path),
        escapeshellarg($ccd_file)
    );
    exec($command, $output, $return_var);

    $_SESSION['last_request_time'] = [];

    if ($return_var === 0) {
        // Кикаем клиента
	kickClient($server, $client_name);
        return true;
    } else {
        return false;
    }
}

function revokeClient($server, $client_name) {
    if (empty(REVOKE_CRT) || !file_exists(REVOKE_CRT)) {
        return banClient($server, $client_name);
        }

    $script_path = REVOKE_CRT;
    $rsa_dir = dirname(dirname($server['cert_index']));

    $command = sprintf(
        'sudo %s %s %s %s 2>&1',
        escapeshellcmd($script_path),
        escapeshellarg('openvpn-server@'.$server['name']),
        escapeshellarg($rsa_dir),
        escapeshellarg($client_name)
    );

    exec($command, $output, $return_var);

    if ($return_var === 0) {
        return true;
    } else {
        return false;
    }
}


function formatBytes($bytes) {
    $bytes = (int)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(log($bytes)/log(1024));
    return round($bytes/pow(1024,$pow),2).' '.$units[$pow];
}

function isClientActive($active_clients,$username) {
    $active_names = array_column($active_clients, 'name');
    if (in_array($username,$active_names)) { return true; }
    return false;
}
