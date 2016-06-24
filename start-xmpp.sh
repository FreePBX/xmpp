#!/bin/bash

PHP=/var/www/html/admin/modules/xmpp/presence.php

if [[ `id -u` == 0 ]]
then
	SU="su asterisk -c "
else
	SU=""
fi

[[ -e $PHP ]] && $SU $PHP &> /dev/null &

