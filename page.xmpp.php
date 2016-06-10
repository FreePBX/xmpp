<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$get_vars = array(
				'action'	=> '',
				'dbname'	=> '',
				'dbhost'	=> '',
				'dbuser'	=> '',
				'dbpass'	=> '',
				'domain'	=> '',
				'port'		=> '',
				'submit'	=> '',
				'type'		=> ''
				);

foreach ($get_vars as $k => $v) {
	$var[$k] = isset($_REQUEST[$k]) ? $_REQUEST[$k] : $v;
}

//set action to delete if delete was pressed instead of submit
if ($var['submit'] == _('Delete') && $var['action'] == 'save') {
	$var['action'] = 'delete';
}

//action actions
switch ($var['action']) {
	case 'save':
		foreach($var as $k => $v) {
			switch ($k) {
				case 'domain':
					if ($v) {
						FreePBX::Xmpp()->saveOption($k, $v);
					}
					break;
				default:
					break;
			}
		}
}

//view action
switch ($var['action']) {
	case 'edit':
	case 'save':
	default:
		$var = array_merge($var, FreePBX::Xmpp()->getAllOptions());
		echo load_view(dirname(__FILE__) . '/views/xmpp.php', $var);
	break;
}
