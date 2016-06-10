#!/bin/bash

# Check to see if a script by this name is running
PIDS=$(ps -u prosody | grep lua | awk ' { print $1 } ')

if [[ "$PIDS" ]]; then
	echo "Prosody Running ($PIDS)"
	exit 0
else
	echo "Prosody NOT RUNNING. Please start service"
	exit 2
fi


