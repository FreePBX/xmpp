<?php
// vim: set ai ts=4 sw=4 ft=php:
namespace FreePBX\modules;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
class Xmpp implements \BMO {
	private $dirty = false;

	public function __construct($freepbx = null) {
		if ($freepbx == null) {
			throw new \Exception("Not given a FreePBX Object");
		}
		$this->freepbx = $freepbx;
		$this->db = $freepbx->Database;
		$this->userman = $freepbx->Userman;
	}

	public function doConfigPageInit($page) {
	}

	public function install() {

	}
	public function uninstall() {
		$sql = 'DROP TABLES xmpp_users, xmpp_options';
		$sth = $this->db->prepare($sql);
		$sth->execute();

		$sql = 'TRUNCATE prosody';
		$sth = $this->db->prepare($sql);
		$sth->execute();
	}
	public function backup(){

	}
	public function restore($backup){

	}
	public function genConfig() {
		global $amp_conf; //database stuff is set in freepbx.conf
		$c = '';
		//modules
		$c .= 'modules_enabled = {
			"admin_adhoc";
			"admin_telnet";
			"bosh";
			"dialback";
			"disco";
			"groups";
			"legacyauth";
			"pep";
			"ping";
			"posix";
			"private";
			"roster";
			"saslauth";
			"tls";
		};' . PHP_EOL;
		//settings
		$c .= 'data_path = "/usr/com/prosody"' . PHP_EOL;
		$c .= 'authentication = "freepbx"' . PHP_EOL;
		$c .= 'allow_unencrypted_plain_auth = true' . PHP_EOL;
		$c .= 'use_libevent = false' . PHP_EOL;
		$c .= 'freepbx_auth_command = "'.$amp_conf['AMPBIN'].'/xmpp_auth.php"' . PHP_EOL;
		$c .= 'freepbx_auth_timeout = 2' . PHP_EOL;
		$c .= 'freepbx_auth_processes = 1' . PHP_EOL;
		$c .= 'storage = "sql"' . PHP_EOL;
		$c .= 'log = { ';
		//$c .= 'debug = "'.$amp_conf['ASTLOGDIR'].'/prosody_debug.log",';
		$c .= 'error = "'.$amp_conf['ASTLOGDIR'].'/prosody.log"';
		$c .= ' }' . PHP_EOL;
		$c .= 'ssl = { ';
			$c .= 'key = "/etc/pki/tls/private/prosody.key",';
			$c .= 'certificate = "/etc/pki/tls/certs/prosody.crt"';
			$c .= ' }' . PHP_EOL;
		$c .= 'pidfile = "/var/run/prosody/prosody.pid";' . PHP_EOL;

		//virtual host
		$c .= 'sql = { '
			. 'driver = "MySQL'
			. '", database = "' . $amp_conf['AMPDBNAME']
			. '", username = "' . $amp_conf['AMPDBUSER']
			. '", password = "' . $amp_conf['AMPDBPASS']
			. '", host = "' . $amp_conf['AMPDBHOST']
			. '" }' . PHP_EOL;

		$args = $this->getAllOptions();
		$c .= 'VirtualHost "' . $args['domain'] . '"' . PHP_EOL;
		$c .= 'groups_file = "'.$amp_conf['ASTETCDIR'].'/prosody_groups.txt"' . PHP_EOL;
		$c .= 'Component "conf.' . $args['domain'] . '" "muc"' . PHP_EOL;
		$c .= 'Component "asterisk.' . $args['domain'] . '"' . PHP_EOL;
		$c .= '  component_secret = "asterisk"' . PHP_EOL;
		$c .= '  validate_from_addresses = false' . PHP_EOL;

		$conf['prosody_additional.conf'] = $c;
		//add/update users
		$users = $this->getAllUsers();
		if (!empty($users)) {
			$grp = '[user]' . PHP_EOL;
			foreach ($users as $u) {
				if (!$u['username']) {
					// Don't act on empty users
					continue;
				}
				$grp .= $u['username'] . '@' . $args['domain'] .  PHP_EOL;
			}
			//write out shared roster
			$conf['prosody_groups.txt'] = $grp;
		}
		return $conf;
	}

	public function writeConfig($conf){
		global $amp_conf; //database stuff is set in freepbx.conf

		//unlike other fpbx modules, we dont want to re-generate configs if we dont need to
		//hence we track if we really need to regenerate them or not and act accordingly
		$opts = $this->getAllOptions();

		// If it's not dirty make sure the file is really there since
		// we need to know if it's there for the parsing that comes next
		$pfd = $this->freepbx->Config->get('ASTETCDIR') . '/prosody_additional.conf';
		if ($opts['dirty'] == 'false' && !file_exists($pfd)) {
			$opts['dirty'] = 'true';
		}

		// If it's not dirty (and the file exists) let's make sure the DB credentials
		// have not changed since if they have we need to regenerate
		//
		if ($opts['dirty'] == 'false') {
			$str = file_get_contents($pfd);
			$puname = 'username = "' . $amp_conf['AMPDBUSER'] . '"';
			$ppass  = 'password = "' . $amp_conf['AMPDBPASS'] . '"';
			$pdb    = 'database = "' . $amp_conf['AMPDBNAME'] . '"';

			if (strpos($str, $puname) === false || strpos($str, $ppass) === false || strpos($str, $pdb) === false) {
				$opts['dirty'] = 'true';
			}
		}

		if ($opts['dirty'] == 'false') {
			//nothing to do!
			return true;
		}
		foreach($conf as $file => $contents) {
			$this->freepbx->WriteConfig->writeConfig($file, $contents, false);
		}

		/*
		* update the hostname in the database
		*
		* This is a risky move as we dont have any control over
		* whtat happens in the table or a spec file/change log for changes in schema
		* However, once the VirtualHost (i.e. what we call domain) is changed, it is
		* no longer posible to remove user from deleted host. As a result, for every
		* time we change the domain, we would have a bunch of stale users that we cant
		* remove. Instead, we convert them in to "usefull" entires using a direct DB
		* manipulation. Not the most elegent, but hey - it works.
		*
		*/
		try {
			$sql = 'UPDATE prosody SET host = :host';
			$sth = $this->db->prepare($sql);
			$sth->execute(array(":host" => $opts['domain']));

			if (function_exists('sysadmin_restart_xmpp')) {
				sysadmin_restart_xmpp();
				sleep(5); //sleep for 5! come on....
			}

			//remove posibly stale users
			$sql = 'SELECT * FROM prosody WHERE store = "accounts"';
			$sth = $this->db->prepare($sql);
			$sth->execute();
			$listed= $sth->fetch(\PDO::FETCH_ASSOC);
			if (!empty($listed)) {
				foreach ($listed as $l) {
					$l['delete'] = false;

					//run some test to see if we need to delete this user
					if ($l['host'] != $opts['domain']) {
						$l['delete'] = true;
					}

					//no point in running the next test if we already decided were deleting the user, so only run it conditionally
					if ($l['delete'] == false) {
						$l['delete'] = true; //test
						if ($users) {
							foreach ($users as $u) {
								if ($l['user'] == strtolower($u['username'])) {//prosody saves all users as lowercase
									$l['delete'] = false;
									break;
								}
							}
						}
					}
				}
			}
		} catch(\Exception $e) {
			return false;
		}
		//mark xmpp as clean
		$this->saveOption('dirty', 'false');
	}

	public function usermanShowPage() {
		if(isset($_REQUEST['action'])) {
			switch($_REQUEST['action']) {
				case 'showgroup':
					return array(
						array(
							"title" => "XMPP",
							"rawname" => "xmpp",
							"content" => load_view(__DIR__.'/views/userman_hook.php',array("mode" => "group", "enabled" => ($this->userman->getModuleSettingByGID($_REQUEST['group'],'xmpp','enable')), "domain"=>$this->getOption("domain")))
						)
					);
				break;
				case 'showuser':
					$enabled = $this->userman->getModuleSettingByID($_REQUEST['user'],'xmpp','enable',true);
					return array(
						array(
							"title" => "XMPP",
							"rawname" => "xmpp",
							"content" => load_view(__DIR__.'/views/userman_hook.php',array("mode" => "user", "enabled" => $enabled, "domain"=>$this->getOption("domain")))
						)
					);
				break;
				case 'addgroup':
				case 'adduser':
					$mode = ($_REQUEST['action'] == 'addgroup') ? 'group' : 'user';
					return array(
						array(
							"title" => "XMPP",
							"rawname" => "xmpp",
							"content" => load_view(__DIR__.'/views/userman_hook.php',array("mode" => $mode, "enabled" => ($_REQUEST['action'] == 'adduser' ? null : false), "domain"=>$this->getOption("domain")))
						)
					);
				break;
			}
		}
	}

	public function usermanDelGroup($id,$display,$data) {
	}

	public function usermanAddGroup($id, $display, $data) {
		$this->usermanUpdateGroup($id,$display,$data);
	}

	public function usermanUpdateGroup($id,$display,$data) {
		if($display == 'userman' && isset($_POST['type']) && $_POST['type'] == 'group') {
			if(isset($_POST['xmpp_enable'])) {
				if($_POST['xmpp_enable'] == 'true') {
					$this->userman->setModuleSettingByGID($id,'xmpp','enable', true);
				} else {
					$this->userman->setModuleSettingByGID($id,'xmpp','enable', null);
				}
			}
		}
		$group = $this->userman->getGroupByGID($id);
		foreach($group['users'] as $user) {
			$enabled = $this->userman->getCombinedModuleSettingByID($user, 'xmpp', 'enable');

			if($enabled && ($display == 'extensions' || $display == 'users')) {
				$this->saveUser($user, $data['username']);
			} else {
				$data = $this->userman->getUserByID($user);
				$data['prevUsername'] = $data['username'];
				$this->usermanUpdateUser($user, $display, $data);
			}
		}
	}

	public function usermanAddUser($id, $display, $data) {
		if($display == 'userman' && isset($_POST['type']) && $_POST['type'] == 'user') {
			if(isset($_POST['xmpp_enable'])) {
				if($_POST['xmpp_enable'] == 'true') {
					$this->userman->setModuleSettingByID($id,'xmpp','enable', true);
				} elseif($_POST['xmpp_enable'] == 'false') {
					$this->userman->setModuleSettingByID($id,'xmpp','enable', false);
				} else {
					$this->userman->setModuleSettingByID($id,'xmpp','enable', null);
				}
			}
		}
		$enabled = $this->userman->getCombinedModuleSettingByID($id, 'xmpp', 'enable');

		if($enabled && ($display == 'extensions' || $display == 'users')) {
			$this->saveUser($id, $data['username']);
		} else {
			$this->usermanUpdateUser($id, $display, $data);
		}
	}

	public function usermanUpdateUser($id, $display, $data) {
		if(isset($_POST['xmpp_enable'])) {
			if($_POST['xmpp_enable'] == 'true') {
				$this->userman->setModuleSettingByID($id,'xmpp','enable', true);
			} elseif($_POST['xmpp_enable'] == 'false') {
				$this->userman->setModuleSettingByID($id,'xmpp','enable', false);
			} else {
				$this->userman->setModuleSettingByID($id,'xmpp','enable', null);
			}
		}

		$enabled = $this->userman->getCombinedModuleSettingByID($id, 'xmpp', 'enable');

		if($enabled && $display == 'userman') {
			$xmppEnable = ($_POST['xmpp_enable'] == 'true') ? true : false;
			if($xmppEnable) {
				$this->saveUser($id, $data['username']);
				if($data['prevUsername'] != $data['username']) {
					$sql = "UPDATE prosody SET user = :user WHERE user = :puser";
					$sth = $this->db->prepare($sql);
					$sth->execute(array(":user" => $data['username'], ":puser" => $data['prevUsername']));
				}
			} elseif(!$xmppEnable) {
				$this->usermanDelUser($id, $display, $data);
			}
		} elseif($enabled) {
			$user = $this->getUser($id);
			if(!empty($user)) {
				$this->saveUser($id, $data['username']);
				if($data['prevUsername'] != $data['username']) {
					$sql = "UPDATE prosody SET user = :user WHERE user = :puser";
					$sth = $this->db->prepare($sql);
					$sth->execute(array(":user" => $data['username'], ":puser" => $data['prevUsername']));
				}
			}
		}
	}

	public function usermanDelUser($id, $display, $data) {
		$user = $this->getUserByID($id);
		if(!empty($user)) {
			$this->delUser($id);
			$sql = "DELETE FROM prosody WHERE user = :puser";
			$sth = $this->db->prepare($sql);
			$sth->execute(array(":puser" => $user['username']));
		}
	}

	public function saveOption($keyword, $value) {
		if (!$this->dirty) {
			//check what this settings is set to that can decide if anything changed yet
			if ($this->getOption($keyword) != $value) {
				$this->dirty = true;
				$this->saveOption('dirty', 'true');
				needreload();
			}
		}

		//insert
		$sql = 'REPLACE INTO xmpp_options (keyword, value) VALUES (:keyword, :value)';
		$sth = $this->db->prepare($sql);
		$sth->execute(array(":keyword" => $keyword, ":value" => $value));
	}

	public function getOption($option) {
		$sql = 'SELECT value FROM xmpp_options WHERE keyword = :option';
		$sth = $this->db->prepare($sql);
		$sth->execute(array(":option" => $option));
		$option = $sth->fetch(\PDO::FETCH_ASSOC);
		return isset($option['value']) ? $option['value'] : false;
	}

	public function getAllOptions() {
		$sql = 'SELECT keyword, value FROM xmpp_options';
		$sth = $this->db->prepare($sql);
		$sth->execute();
		$ret = $sth->fetchAll(\PDO::FETCH_ASSOC);

		//ensure defaults are set
		$defaults = array(
			//'dbhost'	=> '',
			//'dbname'	=> 'localhost',
			//'dbpass'	=> '',
			//'dbuser'	=> '',
			'domain'	=> '',
			//'port'		=> '5222',
			'dirty'		=> 'false'

		);
		foreach ($ret as $my => $r) {
			$res[$r['keyword']] = $r['value'];
		}
		foreach ($defaults as $k => $v) {
			if (!isset($res[$k])) {
				$res[$k] = $v;
			}
		}
		return $res;
	}

	public function delOption($keyword) {
		$sql = 'DELETE FROM xmpp_options WHERE keyword = :keyword';
		$sth = $this->db->prepare($sql);
		$sth->execute(array(":keyword" => $keyword));

		needreload();
		$this->saveOption('dirty', 'true');
	}

	public function saveUser($id, $username) {
		//validate data
		if (!ctype_digit($id) || !$username) {
			return false;
		}

		//prosody only saved lowercase names. correct that here so that we have the proper data saved
		$username = strtolower($username);

		//only submit if there really are changes
		$user = $this->getUser($id);
		if ($user && $user['username'] == $username) {
			return true;
		}

		//insert
		$sql = 'REPLACE INTO xmpp_users (user, username, password) VALUES (:user, :username, :password)';
		$sth = $this->db->prepare($sql);
		//intentially blank out password
		$ret = $sth->execute(array(":username" => $username, ":password" => "", ":user" => $id));
		//mark as dirty
		needreload();
		$this->saveOption('dirty', 'true');
		return $ret;
	}

	public function getUser($id) {
		$user = $this->getUserByID($id);
		if(empty($user)) {
			return false;
		}
		return $user;
	}

	public function isUser($username) {
		$user = $this->userman->getUserByUsername($username);
		if(empty($user)) {
			return false;
		}
		$enabled = $this->userman->getCombinedModuleSettingByID($user['id'], 'xmpp', 'enable');
		if(!$enabled) {
			return false;
		}
		return true;
	}

	public function auth($username,$password) {
		$user = $this->userman->getUserByUsername($username);
		if(empty($user)) {
			return false;
		}
		$enabled = $this->userman->getCombinedModuleSettingByID($user['id'], 'xmpp', 'enable');
		if(!$enabled) {
			return false;
		}
		return $this->userman->checkCredentials($username,$password);
	}

	public function setPass($username,$password) {
		$usettings = $this->userman->getAuthAllPermissions();
		if(!$usettings['changePassword']) {
			return false;
		}
		$user = $this->userman->getUserByUsername($username);
		$o = $this->userman->updateUser($user['id'], $user['username'], $user['username'], $user['default_extension'], $user['description'], array(), $password);
		return $o['status'];
	}

	public function getAllUsers() {
		$sql = 'SELECT * FROM xmpp_users';
		$sth = $this->db->prepare($sql);
		$sth->execute();
		$users = $sth->fetchAll(\PDO::FETCH_ASSOC);
		if (empty($users)) {
			return false;
		}
		foreach($users as &$user) {
			//Prosody will crash if username is blank
			$user['username'] = !empty($user['username']) ? $user['username'] : $user['user'];
		}
		return $users;
	}

	public function delUser($id) {
		$sql = 'DELETE FROM xmpp_users WHERE user = :id';
		$sth = $this->db->prepare($sql);
		$sth->execute(array(":id" => $id));

		needreload();
		$this->saveOption('dirty', 'true');
	}

	public function getUserByID($id) {
		$sql = 'SELECT u.*, o.value as domain FROM xmpp_users u, xmpp_options o WHERE o.keyword = "domain" AND u.user = :id';
		$sth = $this->db->prepare($sql);
		$sth->execute(array(":id" => $id));
		return $sth->fetch(\PDO::FETCH_ASSOC);
	}

	public function usermanAddContactInfo($user) {
		$o = $this->getUserByID($user['id']);
		if(!empty($o)) {
			$user['xmpp'] = $o['username'] . '@' . $o['domain'];
		}
		return $user;
	}

	public function dashboardService() {
		$services = array(
			array(
				'title' => 'Prosody (XMPP)',
				'type' => 'unknown',
				'tooltip' => _("Unknown"),
				'order' => 999,
				'command' => __DIR__."/check-prosody.sh",
			),
			array(
				'title' => 'XMPP Presence',
				'type' => 'unknown',
				'tooltip' => _("Unknown"),
				'order' => 999,
				'command' => __DIR__."/check-xmpp.sh"
			)
		);
		foreach($services as &$service) {
			$output = '';
			exec($service['command']." 2>&1", $output, $ret);
			if ($ret === 0) {
				$service = array_merge($service, $this->genAlertGlyphicon('ok', $output[0]));
			} elseif ($ret === 1) {
				$service = array_merge($service, $this->genAlertGlyphicon('warning', $output[0]));
			} else {
				$service = array_merge($service, $this->genAlertGlyphicon('error', $output[0]));
			}

		}

		return $services;
	}

	private function genAlertGlyphicon($res, $tt = null) {
		return $this->freepbx->Dashboard->genStatusIcon($res, $tt);
	}

	public function getActionBar($request) {
		$buttons = array();
		switch($request['display']) {
			case 'xmpp':
				$buttons = array(
					'reset' => array(
						'name' => 'reset',
						'id' => 'reset',
						'value' => _('Reset')
					),
					'submit' => array(
						'name' => 'submit',
						'id' => 'submit',
						'value' => _('Submit')
					)
				);
			break;
		}
		return $buttons;
	}
	public function chownFreepbx() {
		$moduledir = __DIR__;
		exec('mkdir -p /usr/com/prosody');
		$files = array();
		$files[] = array('type' => 'file',
			'path' => $moduledir.'/bin/xmpp_auth.php',
			'perms' => 0755);
		$files[] = array('type' => 'file',
			'path' => $moduledir.'/presence.php',
			'perms' => 0755);
		$files[] = array('type' => 'file',
			'path' => $moduledir.'/check-prosody.sh',
			'perms' => 0755);
		$files[] = array('type' => 'file',
			'path' => $moduledir.'/check-xmpp.sh',
			'perms' => 0755);
		$files[] = array('type' => 'file',
			'path' => $moduledir.'/start-xmpp.sh',
			'perms' => 0755);
		$files[] = array('type' => 'file',
			'path' => '/var/log/prosody',
			'perms' => 0777);
		$files[] = array('type' => 'file',
			'path' => '/usr/com/prosody',
			'owner' => 'prosody',
			'group' => 'prosody',
			'perms' => 0775);
		$files[] = array('type' => 'file',
			'path' => '/etc/pki/tls/private/prosody.key',
			'owner' => 'prosody',
			'group' => 'prosody',
			'perms' => 0664);
		$files[] = array('type' => 'file',
			'path' => '/etc/pki/tls/certs/prosody.crt',
			'owner' => 'prosody',
			'group' => 'prosody',
			'perms' => 0664);
		$files[] = array('type' => 'rdir',
			'path' => '/tmp/.jaxl',
			'perms' => 0777,
			'owner' => 'asterisk',
			'group' => 'asterisk',
		);

		return $files;
	}
	public function startFreepbx($output) {
		$script = __DIR__."/start-xmpp.sh";
		if(!file_exists($script)) {
			return true;
		}

		$output->writeln(_("Running XMPP Hooks"));
		$output->writeln(_("Starting XMPP Server"));
		$process = new Process("$script &> /dev/null");
		$process->run();
		// executes after the command finishes
		if ($process->isSuccessful()) {
			$output->writeln(_("XMPP Server Started"));
		} else {
			$output->writeln(sprintf(_("XMPP Server Start Failed: %s"),$process->getErrorOutput()));
		}
		return true;
	}
	public function stopFreepbx($output) {
		$script = __DIR__."/start-xmpp.sh";
		if(!file_exists($script)) {
			return true;
		}

		$pids = `pidof -x presence.php`;

		if ($pids) {
			$allpids = explode(" ", $pids);
			foreach ($allpids as $p) {
				posix_kill($p, 9);
			}
			$output->writeln(_("XMPP Server Stopped"));
		} else {
			$output->writeln(_("XMPP Server was not running"));
		}
	}
}
