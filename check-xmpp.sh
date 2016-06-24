#!/bin/bash

SCRIPT=presence.php

# Check to see if a script by this name is running
PIDS=$(pidof -x $SCRIPT)

if [[ "$PIDS" ]]; then
	echo "Daemon Running ($PIDS)"
	exit 0
else
	echo "Daemon Not Running (Restarted)"
	/var/www/html/admin/modules/xmpp/start-xmpp.sh &> /dev/null &
	exit 1
fi


