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
	if($num >= 2) {
		$command = $params[0];
		$cmd_data = json_decode(base64_decode($params[1]), true);
		$username = $cmd_data['username'];
		$password = $cmd_data['password'];

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
				if($xmpp->auth($username, $password)) {
					$data = FreePBX::Userman()->getUserByUsername($username);
					unset($data['password']);
					unset($data['email']);
					if(FreePBX::Modules()->moduleHasMethod('Zulu', 'getContactLUIDByZuluID')) {
						$data['uuid'] = FreePBX::Zulu()->getContactLUIDByZuluID($data['id']);
					} else {
						$data['uuid'] = null;
					}
					echo json_encode(array("status" => true, "data" => $data));
				} else {
					echo json_encode(array("status" => false));
				}
			break;
			default:
				echo json_encode(array("status" => false));
			break;
		}
		exit();
	}
}
echo 0;
exit();
