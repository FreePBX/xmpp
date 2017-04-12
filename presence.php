#!/usr/bin/php -q
<?php

if (posix_geteuid() === 0) {
        die("I am running as root. Please start me as a non-privileged user\n");
}

ini_set('error_log','/var/log/asterisk/presence.log');

// Check to see if I'm already running.
$lock = "/var/run/asterisk/presence.lock";
$fh = fopen($lock, "c");
if ($fh === false) {
        die("Unable to create/open lockfile $lock!\n");
}
if (!flock($fh, LOCK_EX|LOCK_NB)) {
        die("XMPP Already running...\n");
}

// bootstrap freepbx
$bootstrap_settings['freepbx_auth'] = false;
$restrict_mods = array(
	'xmpp' => true,
);
include '/etc/freepbx.conf';


$astman->Events("on");

function presence_user_extensions() {
	$userman = FreePBX::Userman();

	$extensions = array();

	$xmpp_users = FreePBX::Xmpp()->getAllUsers();
	foreach ($xmpp_users as $user_to) {
		$user = $userman->getUserByID($user_to['user']);
		if ($user['default_extension'] == 'none') {
			$default = array();
		} else {
			$default = array($user['default_extension']);
		}

		$assigned = $userman->getAssignedDevices($user_to['user']);
		if (!$assigned) {
			$assigned = array();
		}

		$extensions[$user_to['username']] = array_unique(array_merge($default, $assigned), SORT_NUMERIC);
	}

	return $extensions;
}

$astman->add_event_handler("presencestatus", function($event, $data, $server, $port) {
	global $comp;
	global $domain;

	$hints = split(',', $data['Hint']);
	$presence = $hints[1];
	if (!$presence) {
		return;
	}
	$extension = str_ireplace('CustomPresence:', '', $presence);

	$xmpp_users = FreePBX::Xmpp()->getAllUsers();
	$users_extensions = presence_user_extensions();
	foreach ($users_extensions as $user => $user_extensions) {
		/* User has the extension this presence update is from */
		if (in_array($extension, $user_extensions)) {
			foreach ($xmpp_users as $user_to) {
				if ($user == $user_to['username']) {
					continue;
				}

				send_status($user . '@' . $domain . '/FreePBX', $user_to['username'] . '@' . $domain, $data['Status'], $data['Message']);
			}
		}
	}
});

$status_types = array(
	'available' => true,
	'chat' => true,
	'away' => false,
	'dnd' => false,
	'xa' => false,
	'unavailable' => false
);

$domain = FreePBX::Xmpp()->getOption('domain');
$roster = array();
$component_status = 'unavailable';

stream_set_timeout($astman->socket, 0, 30000);

$periodic_jobs = array();

require_once 'JAXL/jaxl.php';

while (true) {
	$comp = new JAXL(array(
		'jid' => 'asterisk.' . $domain,
		'pass' => 'asterisk',

		'host' => 'localhost',
		'port' => '5347',

		'strict' => FALSE,
		'priv_dir' => '/tmp/.jaxl',
		'log_level' => 0 /*JAXL_DEBUG*/
	));

	$comp->require_xep(array(
		'0114' // XMPP component protocol
	));

	$comp->add_cb('on_connect', function() {
		global $astman;

		JAXLLoop::watch($astman->socket, array(
			'read' => 'manager_read',
		));
	});

	$comp->add_cb('on_disconnect', function() {
		global $astman;
		global $periodic_jobs;

		JAXLLoop::unwatch($astman->socket, array(
			'read' => true
		));

		foreach ($periodic_jobs as $job) {
			JAXLLoop::$clock->cancel_fun_call($job);
		}
	});

	$comp->add_cb('on_auth_success', function() {
		global $periodic_jobs;
		global $astman;
		global $comp;
		global $domain;
		global $component_status;

		$periodic_jobs[] = JAXLLoop::$clock->call_fun_periodic(1000 * 60 * 60, function() {
			global $db;

			/* PING! */
			$db->getOne("SELECT 1");
		}, NULL);

		$component_status = 'available';

		$xmpp_users = FreePBX::Xmpp()->getAllUsers();
		$xmpp_users = is_array($xmpp_users)?$xmpp_users:array();
		foreach ($xmpp_users as $user) {
			send_status('asterisk.' . $domain, $user['username'] . '@' . $domain, 'probe');
			send_status('asterisk.' . $domain, $user['username'] . '@' . $domain, 'subscribe');

			send_status('asterisk.' . $domain . '/FreePBX', $user['username'] . '@' . $domain, $component_status);
		}
	});

	$comp->add_cb('on_chat_message', function($stanza) {
		global $comp;
		global $domain;

		if ($stanza->body) {
			if (preg_match('/[Ss]tatus:(.*?)(,.*)?$/', $stanza->body, $matches)) {
				$status = trim($matches[1]);
				if (isset($matches[2])) {
					$message = trim($matches[2], ', ');
				}

				$jid = new XMPPJid($stanza->from);

				$xmpp_users = FreePBX::Xmpp()->getAllUsers();
				foreach ($xmpp_users as $user_to) {
					if ($jid->node == $user_to['username']) {
						continue;
					}

					send_status($jid->bare . '/FreePBX', $user_to['username'] . '@' . $domain, $status, $message);
				}

				roster_resource_set($jid->node, $jid->resource, $status, $message);
			}
		}
	});

	$comp->add_cb('on_presence_stanza', function($stanza) {
		global $comp;
		global $component_status;

		$jid = new XMPPJid($stanza->from);

		switch ($stanza->type) {
		case 'probe':
			send_status($stanza->to, $stanza->from, $component_status);
			break;
		case 'subscribe':
			roster_add($jid->node);

			send_status($stanza->to, $stanza->from, 'subscribed');
			break;
		case 'unsubscribe':
			roster_remove($jid->node);

			send_status($stanza->to, $stanza->from, 'unsubscribed');
			break;
		case 'unavailable':
			roster_resource_set($jid->node, $jid->resource);

			break;
		case NULL:
			$status = $stanza->show ? $stanza->show : 'available';
			$message = $stanza->status;

			roster_resource_set($jid->node, $jid->resource, $status, $message);

			break;
		}
	});

	// connect to the destination host/port
	if ($comp->connect($comp->get_socket_path())) {
		$comp->emit('on_connect');

		JAXLLoop::run(2, 0);

		$comp->emit('on_disconnect');
	}

	sleep(2);
	unset($comp);
}

function manager_read() {
	global $astman;

	$response = $astman->wait_response(true);

	$reconnects = $astman->reconnects;
	$oldsocket = $astman->socket;

	while ($response === false && $reconnects > 0) {
		$astman->disconnect();
		if ($astman->connect($astman->server . ':' . $astman->port, $astman->username, $astman->secret, $astman->events) !== false) {
			JAXLLoop::watch($astman->socket, array(
				'read' => 'manager_read',
			));
			JAXLLoop::unwatch($oldsocket, array(
				'read' => true
			));
			$response = true;
		} else {
			if ($reconnects > 1) {
				$astman->log("reconnect command failed, sleeping before next attempt");
				sleep(1);
			} else {
				$astman->log("FATAL: no reconnect attempts left, command permanently failed");
				exit;
			}
		}
		$reconnects--;
	}
};

function roster_add($user) {
	global $roster;

	if (!isset($roster[$user])) {
		$roster[$user] = array();
	}
}

function roster_remove($user) {
	global $roster;

	unset($roster[$user]);
}

function roster_resource_set($user, $resource, $status = '', $message = '') {
	global $roster;

	if (!isset($roster[$user])) {
		roster_add($user);
	}

	if ($status) {
		$roster[$user][$resource] = array(
			'status' => $status,
			'message' => $message
		);
	} else {
		unset($roster[$user][$resource]);
	}

	$status = status_aggregate($user);
	update_status($user, $status);
}

function status_aggregate($user) {
	global $roster;
	global $status_types;

	if (!isset($roster[$user]) || count($roster[$user]) == 0) {
		/* No logged in resources.  We have to assume that this user doesn't use xmpp. */
		return 'available';
	}

	$vals = array();

	foreach ($roster[$user] as $resource) {
		$vals[$resource['status']] = true;
	}

	foreach ($status_types as $key => $val) {
		if (isset($vals[$key])) {
			return $key;
		}
	}

	return 'unavailable';
}

function send_status($from, $to, $status, $message = '') {
	global $comp;

	if (!$comp) {
		return;
	}

	$pkt = $comp->get_pres_pkt(array(
		'from' => $from,
		'to' => $to,
	));
	$pkt->ns = NS_JABBER_COMPONENT_ACCEPT;
	switch ($status) {
	case 'available':
		if ($message) {
			$pkt->status = $message;
		}
		break;
	case 'probe':
	case 'subscribe':
	case 'unsubscribe':
	case 'subscribed':
	case 'unsubscribed':
	case 'unavailable':
		$pkt->type = $status;
		if ($message) {
			$pkt->status = $message;
		}
		break;
	default:
		$pkt->show = $status;
		if ($message) {
			$pkt->status = $message;
		}
		break;
	}
	$comp->send($pkt);
}

function update_status($user, $status, $message = '') {
	global $astman;
	global $amp_conf;

	if ($amp_conf['AST_FUNC_PRESENCE_STATE']) {
		$users_extensions = presence_user_extensions();
		if (!empty($users_extensions[$user])) {
			foreach ($users_extensions[$user] as $extension) {
				$ret = $astman->set_global($amp_conf['AST_FUNC_PRESENCE_STATE'] . '(CustomPresence:' . $extension . ')', $status . ',,' . $message);
			}
		}
	}
}

?>
