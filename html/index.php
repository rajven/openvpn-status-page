<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ваш IP-адрес</title>
    <meta name="description" content="Здесь Вы можете узнать свой IP-адрeс">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, notranslate, noimageindex">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto,sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            max-width: 700px;
            width: 100%;
            text-align: center;
        }

        h1 {
            color: #2c3e50;
            margin-bottom: 1.5rem;
            font-size: 2rem;
        }

        .network-info {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
            border-radius: 10px;
            overflow: hidden;
        }

        .network-info th {
            background: linear-gradient(135deg, #43AA2E 0%, #2E8B57 100%);
            color: white;
            padding: 1rem;
            font-size: 1.2rem;
            border: none;
        }

        .network-info td {
            background: #f8f9fa;
            padding: 1.2rem;
            font-size: 1.1rem;
            color: #2c3e50;
            border: 2px solid #43AA2E;
        }

        .ip-address {
            font-weight: bold;
            color: #e74c3c;
            font-size: 1.3rem;
        }

        .external-ip {
            font-weight: bold;
            color: #3498db;
            font-size: 1.3rem;
        }

        .status-message {
            margin: 1.5rem 0;
            padding: 1rem;
            background: #E0EED3;
            border: 2px solid #43AA2E;
            border-radius: 8px;
            color: #2c3e50;
            font-size: 1.1rem;
        }

        .external-status {
            margin: 1.5rem 0;
            padding: 1rem;
            background: #D6EAF8;
            border: 2px solid #3498db;
            border-radius: 8px;
            color: #2c3e50;
            font-size: 1.1rem;
        }

        .footer {
            margin-top: 2rem;
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        .loading {
            color: #7f8c8d;
            font-style: italic;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1.5rem;
                margin: 1rem;
            }

            h1 {
                font-size: 1.5rem;
            }

            .network-info th,
            .network-info td {
                padding: 0.8rem;
                font-size: 1rem;
            }

            .ip-address, .external-ip {
                font-size: 1.1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Определение IP-адреса в сети</h1>

        <?php

        function get_user_ip() {
            if (!empty(getenv("HTTP_CLIENT_IP"))) { return getenv("HTTP_CLIENT_IP"); }
            if (!empty(getenv("HTTP_X_FORWARDED_FOR"))) { return getenv("HTTP_X_FORWARDED_FOR"); }
            if (!empty(getenv("REMOTE_ADDR"))) { return getenv("REMOTE_ADDR"); }
            if (!empty($_SERVER['REMOTE_ADDR'])) { return $_SERVER['REMOTE_ADDR']; }
            return 'Не удалось определить';
        }

        $ip = get_user_ip();

        ?>

        <table class="network-info">
            <tr>
                <th>IP-Адрес для этого сервера</th>
                <td class="ip-address"><?php echo htmlspecialchars($ip); ?></td>
            </tr>
            <tr>
                <th>Ваш IP-адрес для мира</th>
                <td class="external-ip" id="external-ip">
                    <span class="loading">Определение...</span>
                </td>
            </tr>
                <td><a href="/admin/" target="_blank">Панель администратора</a></td>
                <td><a href="/admin/" target="_blank">Уголок пользователя</a></td>
            </tr>
        </table>

        <div class="external-status" id="external-status">
            Определение внешнего IP-адреса...
        </div>

        <div class="footer">
            &copy; <?php echo date('Y'); ?> Сервис определения сетевого статуса
        </div>
    </div>

    <script>
        // Функция для получения IP через внешний API
        function fetchExternalIP() {
            // Пробуем несколько сервисов на случай недоступности одного из них
            const services = [
                'https://api.ipify.org?format=json',
                'https://ipinfo.io/json',
                'https://api.myip.com'
            ];

            let currentService = 0;

            function tryNextService() {
                if (currentService >= services.length) {
                    document.getElementById('external-ip').innerHTML = 'Не удалось определить';
                    document.getElementById('external-status').innerHTML = 'Не удалось определить внешний IP-адрес';
                    return;
                }

                fetch(services[currentService])
                    .then(response => response.json())
                    .then(data => {
                        let ip;
                        if (data.ip) ip = data.ip;
                        else if (data.query) ip = data.query;
                        else if (data.ipAddress) ip = data.ipAddress;

                        if (ip) {
                            document.getElementById('external-ip').innerHTML = ip;
                            document.getElementById('external-status').innerHTML='Внешний IP-адрес успешно получен через внешний API';
                        } else {
                            currentService++;
                            tryNextService();
                        }
                    })
                    .catch(error => {
                        console.log('Ошибка получения IP:', error);
                        currentService++;
                        tryNextService();
                    });
            }

            tryNextService();
        }

        fetchExternalIP();
    </script>
</body>
</html>
