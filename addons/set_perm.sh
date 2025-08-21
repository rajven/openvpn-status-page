#!/bin/bash

chown nobody:www-data /etc/openvpn/server/server1/ccd/*
chmod 464 /etc/openvpn/server/server1/ccd/*
chmod 644 /etc/openvpn/server/server1/ipp.txt
chmod 644 /etc/openvpn/server/server1/rsa/pki/index.txt

exit
