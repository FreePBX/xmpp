#!/usr/bin/env php
<?php
error_reporting(0);
$bootstrap_settings['freepbx_auth'] = false;
$restrict_mods = true;
if (!@include_once(getenv('FREEPBX_CONF') ? getenv('FREEPBX_CONF') : '/etc/freepbx.conf')) {
	include_once('/etc/asterisk/freepbx.conf');
}

$xmpp = FreePBX::Xmpp();

if(!empty($argv[1])) {
	$params = explode(":",$argv[1],4);
	$num = count($params);
	if($num >= 3) {
		$command = $params[0];
		$username = $params[1];
		$host = $params[2];
		if($num == 4) {
			$password = $params[3];
		}
		switch($command) {
			case 'isuser':
				echo $xmpp->isUser($username) ? 1 : 0;
			break;
			case 'auth':
				echo $xmpp->auth($username, $password) ? 1 : 0;
			break;
			case 'setpass':
				echo $xmpp->setPass($username, $password) ? 1 : 0;
			break;
			default:
				echo 0;
			break;
		}
		exit();
	}
}
echo 0;
exit();
