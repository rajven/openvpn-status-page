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

function isServerCertificate($cert_index_path, $username) {
    // Получаем путь к каталогу issued
    $issued_dir = dirname(dirname($cert_index_path)) . '/pki/issued';
    
    // Проверяем существование каталога
    if (!is_dir($issued_dir)) {
        return 'fail: issued directory not found';
    }
    
    // Формируем путь к файлу сертификата
    $cert_file = $issued_dir . '/' . $username . '.crt';
    
    // Проверяем существование файла
    if (!file_exists($cert_file)) {
        return 'fail: certificate file not found';
    }
    
    // Читаем содержимое сертификата
    $cert_content = file_get_contents($cert_file);
    if ($cert_content === false) {
        return 'fail: cannot read certificate file';
    }
    
    // Парсим сертификат
    $cert_info = openssl_x509_parse($cert_content);
    if ($cert_info === false) {
        return 'fail: invalid certificate format';
    }
    
    // Проверяем Subject CN (Common Name)
    $common_name = $cert_info['subject']['CN'] ?? '';
    if ( $common_name !==  $username) {
        return 'fail: common name '.$common_name.' differ from username '.$username;
    }
    
    // Проверяем Extended Key Usage (если есть)
    $ext_key_usage = $cert_info['extensions']['extendedKeyUsage'] ?? '';
    
    // Проверяем, является ли это серверным сертификатом
    // Серверные сертификаты обычно имеют:
    // 1. CN, содержащее имя сервера (например, "server")
    // 2. Extended Key Usage: TLS Web Server Authentication
    $is_server_cert = (
        stripos($ext_key_usage, 'TLS Web Server Authentication') !== false ||
        stripos($ext_key_usage, 'serverAuth') !== false
    );

    return $is_server_cert ? 'fail: server certificate detected' : 'success';
}

function getAccountList($server) {
    $accounts = [];

    // Получаем список из index.txt (неотозванные сертификаты)
    if (!empty($server['cert_index']) && !empty(SHOW_PKI_INDEX) && file_exists(SHOW_PKI_INDEX)) {
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
		$result = isServerCertificate($server['cert_index'], $username);
		if (strpos($result, 'fail:') === 0) { continue; }
                $accounts[$username] = [
	        	"username" => $username,
	        	"ip" => null,
        		"banned" => isClientBanned($server, $username) || $revoked,
			"revoked" => $revoked
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
                if (!isset($accounts[$username]) && empty($server['cert_index'])) {
                    $accounts[$username] = [
                        "username" => $username,
                        "banned" => isClientBanned($server, $username),
			"ip" => $ip,
			"revoked" => false,
                    ];
                }
                if (isset($accounts[$username]) and !empty($server['cert_index'])) {
		    $accounts[$username]["ip"] = $ip;
		}
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
                if (!isset($accounts[$username]) && empty($server['cert_index'])) {
                    $accounts[$username] = [
                        "username" => $username,
                        "banned" => isClientBanned($server, $username),
			"ip" => $ip,
			"revoked" => false,
                    ];
                }
                if (isset($accounts[$username]) and !empty($server['cert_index'])) {
		    $accounts[$username]["ip"] = $ip;
		}
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
