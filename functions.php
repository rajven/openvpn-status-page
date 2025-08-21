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
                    'banned' => isClientBanned($server, $parts[1]),
                ];
            }
        }
    }

    // Кэшируем результат
    $_SESSION['cached_status'][$server['name']] = $clients;

    return $clients;
}

function getAccountList($server) {
    $accounts = [];

    // Получаем список из index.txt (неотозванные сертификаты)
    if (!empty($server['cert_index']) && file_exists($server['cert_index'])) {
        $index_content = file_get_contents($server['cert_index']);
        $lines = explode("\n", $index_content);

        foreach ($lines as $line) {
            if (empty(trim($line))) continue;

            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 6 && $parts[0] === 'V') { // Только валидные сертификаты
                $username = $parts[5];
                $accounts[$username] = [
                    "username" => $username,
                    "ip" => null,
                    "banned" => isClientBanned($server, $username)
                ];
            }
        }
    }

    // Получаем список выданных IP из ipp.txt
    if (!empty($server['ipp_file']) && file_exists($server['ipp_file'])) {
        $ipp_content = file_get_contents($server['ipp_file']);
        $lines = explode("\n", $ipp_content);

        foreach ($lines as $line) {
            if (empty(trim($line))) continue;

            $parts = explode(',', $line);
            if (count($parts) >= 2) {
                $username = $parts[0];
                $ip = $parts[1];
                if (!isset($accounts[$username])) {
                    $accounts[$username] = [
                        "username" => $username,
                        "banned" => false
                    ];
                }
                $accounts[$username]["ip"] = $ip;
                $accounts[$username]["banned"] = isClientBanned($server, $username);
            }
        }
    }

    // Ищем IP-адреса в CCD файлах
    if (!empty($server['ccd']) && is_dir($server['ccd'])) {
        $ccd_files = scandir($server['ccd']);
        foreach ($ccd_files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $username = $file;
            $filepath = $server['ccd'] . '/' . $file;
            $content = file_get_contents($filepath);
            
            // Ищем строку ifconfig-push с IP адресом
            if (preg_match('/ifconfig-push\s+(\d+\.\d+\.\d+\.\d+)/', $content, $matches)) {
                $ip = $matches[1];
                if (!isset($accounts[$username])) {
                    $accounts[$username] = [
                        "username" => $username,
                        "banned" => false
                    ];
                }
                $accounts[$username]["ip"] = $ip;
                $accounts[$username]["banned"] = isClientBanned($server, $username);
            }
        }
    }

    return $accounts;
}

function isClientBanned($server, $client_name) {
    $ccd_file = "{$server['ccd']}/$client_name";
    return file_exists($ccd_file) && 
           preg_match('/^disable$/m', file_get_contents($ccd_file));
}

function kickClient($server, $client_name) {
    return openvpnManagementCommand($server, "kill $client_name");
}

function banClient($server, $client_name) {
    $ccd_file = "{$server['ccd']}/$client_name";
    
    // Добавляем директиву disable
    $content = file_exists($ccd_file) ? file_get_contents($ccd_file) : '';
    if (!preg_match('/^disable$/m', $content)) {
        file_put_contents($ccd_file, $content . "\ndisable\n");
    }

    // Кикаем клиента
    kickClient($server, $client_name);
    return true;
}

function unbanClient($server, $client_name) {
    $ccd_file = "{$server['ccd']}/$client_name";
    if (file_exists($ccd_file)) {
        $content = file_get_contents($ccd_file);
        $new_content = preg_replace('/^disable$\n?/m', '', $content);
        file_put_contents($ccd_file, $new_content);
        return true;
    }
    return false;
}

function formatBytes($bytes) {
    $bytes = (int)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(log($bytes)/log(1024));
    return round($bytes/pow(1024,$pow),2).' '.$units[$pow];
}

function getBannedClients($server, $active_clients) {
    $banned = [];
    $active_names = array_column($active_clients, 'name');
    
    if (is_dir($server['ccd'])) {
        foreach (scandir($server['ccd']) as $file) {
            if ($file !== '.' && $file !== '..' && is_file("{$server['ccd']}/$file")) {
                if (isClientBanned($server, $file) && !in_array($file, $active_names)) {
                    $banned[] = $file;
                }
            }
        }
    }
    
    return $banned;
}

function isClientActive($active_clients,$username) {
    $active_names = array_column($active_clients, 'name');
    if (in_array($username,$active_names)) { return true; }
    return false;
}
