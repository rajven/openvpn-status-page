# OpenVPN Status Monitor

Проект предоставляет веб-интерфейс для мониторинга состояния OpenVPN сервера.

## Возможности

- Отображение подключенных пользователей в реальном времени
- Управление доступом:
  - Блокировка/разблокировка пользователей (ban/unban)
- Генерация конфигурационных файлов для клиентов
- Автоматическое обновление данных (каждые 60 секунд)

## Требования

- Сервер с OpenVPN
- Веб-сервер Apache2
- PHP 7.4+
- Доступ к управляющему интерфейсу OpenVPN

## Установка

###  Установите необходимые пакеты:

```bash
apt install apache2 php
a2enmod session
```

### Настройте OpenVPN:
```bash
echo "management 127.0.0.1 3003 /etc/openvpn/server/password" >> /etc/openvpn/server/server1.conf
echo "your_password" > /etc/openvpn/server/password
```

### Настройте права доступа:
```bash
chmod 775 /etc/openvpn/server/server1/ccd
chown nobody:www-data -R /etc/openvpn/server/server1/ccd
chmod 644 /etc/openvpn/server/server1/ipp.txt
chmod 644 /etc/openvpn/server/server1/rsa/pki/index.txt
```

### Установите скрипты:
```bash
cp addons/sudoers.d/www-data /etc/sudoers.d/
cp addons/show_client_crt.sh /etc/openvpn/server/
chmod 555 /etc/openvpn/server/show_client_crt.sh
```
### Создайте шаблон конфигурации клиента (без сертификатов) в каталоге сайта.

### Отредактируйте файл конфигурации config.php

```php
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
```
## Использование

Откройте веб-интерфейс в браузере

Для управления пользователями используйте кнопки:

Ban - заблокировать пользователя

Unban - разблокировать пользователя

Для скачивания конфигурации клиента нажмите на имя пользователя
