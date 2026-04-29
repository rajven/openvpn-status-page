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

    if (empty($server['host']) || empty($server['port']) || empty($server['password'])) { return false; }

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

//    // Проверка существования исполняемого файла
//    if (empty(SHOW_SERVERS_CRT) || !file_exists(SHOW_SERVERS_CRT) || !is_executable(SHOW_SERVERS_CRT)) {
//        error_log('SHOW_SERVERS_CRT is not configured properly', 0);
//        return false;
//    }

    if (empty(SHOW_SERVERS_CRT)) {
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

function get_crt_date_info($server, $client_name) {
    $default = [
        'date' => '-',
        'status' => 'unknown',
        'days' => null,
        'valid' => false,
        'html' => '<span class="cert-date error">-</span>'
    ];
    
    if (empty($server) || empty($client_name) || empty(SHOW_CRT_DATE)) {
        return $default;
    }

    $pki_dir = dirname($server['cert_index']);
    
    $command = sprintf(
        'sudo %s %s %s 2>&1',
        escapeshellcmd(SHOW_CRT_DATE),
        escapeshellarg($client_name),
        escapeshellarg($pki_dir)
    );

    exec($command, $output, $return_var);

    if ($return_var !== 0 || empty($output)) {
        error_log("Cert check failed for $client_name");
        return $default;
    }

    $parts = explode(';', trim($output[0]));
    
    if (count($parts) < 5) {
        return $default;
    }
    
    $until_str = $parts[2];
    $status = $parts[3];
    $days = (int)$parts[4];
    
    $timestamp = strtotime($until_str);
    if ($timestamp === false) {
        return $default;
    }
    
    $formatted_date = date('Y-m-d', $timestamp);
    $is_valid = ($status === 'VALID');
    
    // Генерируем HTML
    if (!$is_valid) {
        $html = '<span class="cert-date expired">' . $formatted_date . ' (expired ' . $days . 'd ago)</span>';
    } else {
        if ($days < 7) {
            $html = '<span class="cert-date expiring-soon">' . $formatted_date . ' (' . $days . 'd left)</span>';
        } else {
            $html = '<span class="cert-date valid">' . $formatted_date . ' (' . $days . 'd left)</span>';
        }
    }

    return [
        'date' => $formatted_date,
        'status' => $status,
        'days' => $days,
        'valid' => $is_valid,
        'html' => $html
    ];
}


function getBannedClients($server) {
    // Проверка входных параметров
    if (empty($server["ccd"]) || !is_string($server["ccd"])) {
        return [];
    }

//    // Проверка существования исполняемого файла
//    if (empty(SHOW_BANNED) || !file_exists(SHOW_BANNED) || !is_executable(SHOW_BANNED)) {
//        error_log('SHOW_BANNED is not configured properly', 0);
//        return [];
//    }

    if (empty(SHOW_BANNED)) {
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

//    // Проверка существования исполняемого файла
//    if (empty(GET_IPS_FROM_CCD) || !file_exists(GET_IPS_FROM_CCD) || !is_executable(GET_IPS_FROM_CCD)) {
//        error_log('SHOW_BANNED is not configured properly', 0);
//        return [];
//    }

    if (empty(GET_IPS_FROM_CCD)) {
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

//    // Проверка существования исполняемого файла
//    if (empty(GET_IPS_FROM_IPP) || !file_exists(GET_IPS_FROM_IPP) || !is_executable(GET_IPS_FROM_IPP)) {
//        error_log('SHOW_BANNED is not configured properly', 0);
//        return [];
//    }

    if (empty(GET_IPS_FROM_IPP)) {
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

    // Получаем список из index.txt
    if (!empty($server['cert_index']) && !empty(SHOW_PKI_INDEX)) {
        $servers_list = get_servers_crt($server['cert_index']);
        
        $command = sprintf(
            'sudo %s %s 2>&1',
            escapeshellcmd(SHOW_PKI_INDEX),
            escapeshellarg($server['cert_index'])
        );
        exec($command, $index_content, $return_var);
        
        if ($return_var == 0) {
            foreach ($index_content as $line) {
                $line = trim($line);
                if (empty($line)) { continue; }
                
                // Парсим строку index.txt
                $cert_info = parse_index_line($line);
		
                $username = $cert_info['username'];
                
                if (empty($username)) { continue; }
                if (isset($servers_list[$username])) { continue; }
                
                // Парсим дату окончания
                $cert_date = '-';
                $days_left = null;
                $valid = $cert_info['is_valid'];
                $revoked = $cert_info['is_revoked'];
                $expired = $cert_info['is_expired'];
                
                if (!empty($cert_info['expires'])) {
                    $timestamp = parse_openvpn_date($cert_info['expires']);
                    if ($timestamp) {
                        $cert_date = date('Y-m-d', $timestamp);
                        $days_left = ceil(($timestamp - time()) / 86400);
                        
                        // Корректируем статус если истек по дате, но не помечен как E
                        if ($valid && $days_left < 0) {
                            $expired = true;
                            $valid = false;
                            $days_left = abs($days_left);
                        }
                    }
                }
                
                $accounts[$username] = [
                    "username" => $username,
                    "ip" => null,
                    "banned" => isset($banned[$username]) || $revoked || $expired,
                    "revoked" => $revoked,
                    "expired" => $expired,
                    "valid" => $valid && !$revoked && !$expired,
                    "cert_date" => $cert_date,
                    "days_left" => $days_left,
                    "serial" => $cert_info['serial'],
                    "status_code" => $cert_info['status'],
                    "revoke_date" => $cert_info['revoked_date']
                ];
            }
        } else {
            error_log("Failed to execute SHOW_PKI_INDEX: " . implode("\n", $index_content));
        }
    }

    // Получаем список выданных IP из ipp.txt
    if (!empty($server['ipp_file'])) {
        $ipps = getClientIPsIPP($server);
        foreach ($ipps as $username => $ip) {
            if (!isset($accounts[$username]) && empty($server['cert_index'])) {
                $accounts[$username] = getDefaultAccount($username, isset($banned[$username]));
            }
            if (isset($accounts[$username]) && !empty($server['cert_index'])) {
                $accounts[$username]["ip"] = $ip;
            }
        }
    }

    // Ищем IP-адреса в CCD файлах
    if (!empty($server['ccd']) && is_dir($server['ccd'])) {
        $ccds = getClientIPsCCD($server);
        foreach ($ccds as $username => $ip) {
            if (!isset($accounts[$username]) && empty($server['cert_index'])) {
                $accounts[$username] = getDefaultAccount($username, isset($banned[$username]));
            }
            if (isset($accounts[$username]) && !empty($server['cert_index'])) {
                $accounts[$username]["ip"] = $ip;
            }
        }
    }
    
    return $accounts;
}

function parse_index_line($line) {
    // Разбиваем по табуляции (стандартный разделитель index.txt)
    $parts = explode("\t", trim($line));
    
    if (count($parts) < 6) {
        // Если табуляция не сработала, пробуем разбить по пробелам с учетом пустых полей
        $parts = preg_split('/\s+/', $line);
    }
    
    // Структура index.txt:
    // [0] - Status (V/R/E)
    // [1] - Expiration date (YYMMDDHHMMSSZ)
    // [2] - Revocation date (пусто или дата)
    // [3] - Serial number (hex)
    // [4] - Distinguished Name (например: "unknown /CN=username")
    // [5] - может быть еще одно поле если DN содержит пробелы
    
    $status = $parts[0] ?? '';
    $expires = $parts[1] ?? '';
    $revoked = $parts[2] ?? '';
    $serial = $parts[3] ?? '';
    
    // Последнее поле - это DN, может содержать пробелы
    // Объединяем все оставшиеся части
    $dn = implode(' ', array_slice($parts, 4));
    
    // Извлекаем username из DN
    $username = '';
    if (preg_match('/\/CN=([^\/\s]+)/', $dn, $matches)) {
        $username = trim($matches[1]);
    }
    
    return [
        'status' => $status,
        'expires' => $expires,
        'revoked_date' => $revoked ?: null,
        'serial' => $serial,
        'dn' => $dn,
        'username' => $username,
        'is_valid' => ($status === 'V'),
        'is_revoked' => ($status === 'R'),
        'is_expired' => ($status === 'E')
    ];
}

// Вспомогательная функция для создания записи по умолчанию
function getDefaultAccount($username, $is_banned = false) {
    return [
        "username" => $username,
        "ip" => null,
        "banned" => $is_banned,
        "revoked" => false,
        "expired" => false,
        "valid" => true,
        "cert_date" => '-',
        "days_left" => null,
        "serial" => null,
        "status_code" => 'V',
        "revoke_date" => null
    ];
}

// Функция парсинга дат OpenVPN (YYMMDDHHMMSSZ)
function parse_openvpn_date($date_str) {
    if (empty($date_str) || $date_str === 'unknown') {
        return false;
    }
    // Формат: YYMMDDHHMMSSZ (например: 351119103201Z)
    // 35 = 2035 год, 11 = ноябрь, 19 = день, 10:32:01
    if (preg_match('/^(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})Z$/', $date_str, $matches)) {
        $year = 2000 + intval($matches[1]);
        $month = intval($matches[2]);
        $day = intval($matches[3]);
        $hour = intval($matches[4]);
        $minute = intval($matches[5]);
        $second = intval($matches[6]);
        // Проверка валидности даты
        if (checkdate($month, $day, $year)) {
            return mktime($hour, $minute, $second, $month, $day, $year);
        }
    }
    return false;
}

function kickClient($server, $client_name) {
    return openvpnManagementCommand($server, "kill $client_name");
}

function removeCCD($server, $client_name) {
//    if (empty($server["ccd"]) || empty($client_name) || empty(REMOVE_CCD) || !file_exists(REMOVE_CCD)) { return false; }
    if (empty($server["ccd"]) || empty($client_name) || empty(REMOVE_CCD)) { return false; }

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
//    if (empty($server["ccd"]) || empty($client_name) || empty(BAN_CLIENT) || !file_exists(BAN_CLIENT)) { return false; }
    if (empty($server["ccd"]) || empty($client_name) || empty(BAN_CLIENT)) { return false; }


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
//    if (empty($server["ccd"]) || empty($client_name) || empty(BAN_CLIENT) || !file_exists(BAN_CLIENT)) { return false; }
    if (empty($server["ccd"]) || empty($client_name) || empty(BAN_CLIENT)) { return false; }

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
//    if (empty(REVOKE_CRT) || !file_exists(REVOKE_CRT)) {
    if (empty(REVOKE_CRT)) {
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

function process_create_user($servers, $server_name = null, $username = null, $force = false) {
    // Если параметры не переданы (явно null), берем из $_POST
    if ($server_name === null) {
        $server_name = $_POST['server'] ?? '';
    }
    if ($username === null) {
        $username = trim($_POST['username'] ?? '');
    }

    // Проверка наличия скрипта создания
    if (empty(CREATE_CRT)) {
        send_json_response(false, 'Create certificate script not configured');
        return true;
    }

    if (empty($username) || !isset($servers[$server_name]) || empty($servers[$server_name]['cert_index'])) {
        send_json_response(false, 'Invalid parameters');
        return true;
    }

    // Нормализация имени пользователя
//    mb_internal_encoding('UTF-8');
//    $username = mb_strtolower($username);
    
    // Проверка на пробельные символы
    if (preg_match('/\s/', $username)) {
        send_json_response(false, 'Username cannot contain spaces');
        return true;
    }
    
    // Проверка на специальные символы
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
        send_json_response(false, 'Username can only contain letters, numbers, underscores and hyphens');
        return true;
    }
    
    // Проверка длины имени
    if (strlen($username) < 3 || strlen($username) > 32) {
        send_json_response(false, 'Username must be between 3 and 32 characters');
        return true;
    }

    $server = $servers[$server_name];
    $rsa_dir = dirname(dirname($server['cert_index']));

    // Выполнение команды создания пользователя
    if (!$force) {
        $command = sprintf(
            'sudo %s %s %s 2>&1',
            escapeshellcmd(CREATE_CRT),
            escapeshellarg($rsa_dir),
            escapeshellarg($username)
            );
        } else {
        $command = sprintf(
            'sudo %s %s %s --force 2>&1',
            escapeshellcmd(CREATE_CRT),
            escapeshellarg($rsa_dir),
            escapeshellarg($username)
            );
        }
    
    error_log("Creating user: $username on server: $server_name");
    error_log("Command: $command");
    
    exec($command, $output, $return_var);
    
    if ($return_var === 0) {
        // Логируем успешное создание
        error_log("User $username created successfully on server $server_name");
        send_json_response(true, 'User created successfully');
    } else {
        $error_message = implode("\n", $output);
        error_log("Failed to create user $username: $error_message");
        send_json_response(false, 'Failed to create user: ' . $error_message);
    }
    return true;
}

// Вспомогательная функция для отправки JSON ответов
function send_json_response($success, $message, $data = []) {
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data));
    exit;
}
