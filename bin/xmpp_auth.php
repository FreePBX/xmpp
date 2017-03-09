#!/usr/bin/env php
<?php
error_reporting(0);
$bootstrap_settings['freepbx_auth'] = false;
$restrict_mods = true;
include '/etc/freepbx.conf';

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
			case 'jsonauth':
				if($xmpp->auth($username, $params[2])) {
					$data = FreePBX::Userman()->getUserByUsername($username);
					unset($data['password']);
					echo json_encode(array("status" => true, "data" => $data));
				} else {
					echo json_encode(array("status" => false));
				}
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
