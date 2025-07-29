Проект рисует страницу состояния Openvpn сервера

Возможности:
- Отображаются подключенные пользователи
- Можно забанить/разбанить пользователя

Для работы скрипта нужен апач и php на сервере с openvpn:

apt install apache2 php 

a2enmod session

В конфиге сервера openvpn надо включить интерфейс управления:

management 127.0.0.1 3003 /etc/openvpn/server/password

В файл /etc/openvpn/server/password надо на первой строчке написать пароль подключения

У апача должны быть права записи в каталог конфигурации пользователя:

chmod 775 /etc/openvpn/server/server/ccd

chown nobody:www-data -R /etc/openvpn/server/server/ccd

И права на чтение списка сертфикатов и списка выданных адресов:

chmod 655 /etc/openvpn/server/server/ipp.txt

chmod 655 /etc/openvpn/server/server/rsa/pki/index.txt

Конфигурация opnepvn-сервера в скрипте - в массив servers вписать нужные сервера:

    'server1' => [
        'name' => 'server1',
        'title' => 'Server1',
        'config' => '/etc/openvpn/server/server.conf',
        'ccd' => '/etc/openvpn/server/server/ccd',
        'port' => '3003',
        'host' => '127.0.0.1',
        'password' => 'password',
        'cert_index' => '/etc/openvpn/server/server/rsa/pki/index.txt',
        'ipp_file' => '/etc/openvpn/server/server/ipp.txt'
    ],

Ну и всё.

Upd: Добавлено чтение пользователей VPN-сервера из списка выданных сертификатов и списка выданных ip-адресов. 
