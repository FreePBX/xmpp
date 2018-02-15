<?php
// vim: set ai ts=4 sw=4 ft=php:
namespace FreePBX\modules;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
//progress bar
use Symfony\Component\Console\Helper\ProgressBar;
class Xmpp implements \BMO {
	private $dirty = false;

	private $nodever = "0.12.18";
	private $npmver = "2.15.11";
	private $mongover = "2.4.14";
	private $nodeloc = "/tmp";

	public function __construct($freepbx = null) {
		if ($freepbx == null) {
			throw new \Exception("Not given a FreePBX Object");
		}
		$this->freepbx = $freepbx;
		$this->db = $freepbx->Database;
		$this->userman = $freepbx->Userman;
		$this->nodeloc = __DIR__."/node";
		if(!file_exists($this->nodeloc."/logs")) {
			mkdir($this->nodeloc."/logs");
		}
	}

	public function doConfigPageInit($page) {
	}

	public function install() {
		$sysadmin = $this->freepbx->Modules->checkStatus("sysadmin");
		if(!$sysadmin && (file_exists('/usr/bin/prosody') || file_exists('/usr/bin/prosodyctl'))) {
			out(_("Prosody is no longer used and conflicts with this package. Please remove it before continuing"));
			return false;
		} elseif($sysadmin && (file_exists('/usr/bin/prosody') || file_exists('/usr/bin/prosodyctl'))) {
			outn(_("Removing Prosody.."));
			$this->removeProsody();
			out(_("Done"));
		}

		if((file_exists('/usr/bin/prosody') || file_exists('/usr/bin/prosodyctl'))) {
			out(_("Unable to remove Prosody, it is no longer used and conflicts with this package. Please remove it before continuing"));
			return false;
		}

		$output = exec("mongod --version 2>/dev/null ");
		$output = str_replace("v","",trim($output));
		if(empty($output) && !$sysadmin) {
			out(_("MongoDB is not installed"));
			return false;
		} elseif(version_compare($output,$this->mongodb,"<") && !$sysadmin) {
			out(sprintf(_("MongoDB version is: %s requirement is %s"),$output,$this->mongodbver));
			return false;
		} elseif($sysadmin && (empty($output) || version_compare($output,$this->mongodb,"<"))) {
			outn(_("Installing/Updating MongoDB..."));
			$this->installMongo();

			$output = exec("mongod --version 2>/dev/null ");
			$output = str_replace("v","",trim($output));
			if(empty($output) || version_compare($output,$this->mongodb,"<")) {
				out(_("MongoDB is not installed"));
				return false;
			}

			out(_("Done"));
		}

		$output = exec("node --version 2>/dev/null "); //v0.10.29
		$output = str_replace("v","",trim($output));
		if(empty($output) && !$sysadmin) {
			out(_("Node is not installed"));
			return false;
		} elseif(version_compare($output,$this->nodever,"<") && !$sysadmin) {
			out(sprintf(_("Node version is: %s requirement is %s"),$output,$this->nodever));
			return false;
		} elseif($sysadmin && (empty($output) || version_compare($output,$this->nodever,"<"))) {
			outn(_("Installing/Updating NodeJS..."));
			$this->installNode();

			$output = exec("node --version");
			$output = str_replace("v","",trim($output));
			if(empty($output) || version_compare($output,$this->nodever,"<")) {
				out(_("Node is not installed"));
				return false;
			}
			out(_("Done"));
		}

		$output = exec("npm --version"); //v0.10.29
		$output = trim($output);
		if(empty($output)) {
			out(_("Node Package Manager is not installed"));
			return false;
		}
		if(version_compare($output,$this->npmver,"<")) {
			out(sprintf(_("NPM version is: %s requirement is %s"),$output,$this->npmver));
			return false;
		}

		if(file_exists($this->nodeloc."/package-lock.json")) {
			@unlink($this->nodeloc."/package-lock.json");
		}

		$webuser = \FreePBX::Freepbx_conf()->get('AMPASTERISKWEBUSER');
		$web = posix_getpwnam($webuser);
		$home = trim($web['dir']);
		if (!is_dir($home)) {
			// Well, that's handy. It doesn't exist. Let's use ASTSPOOLDIR instead, because
			// that should exist and be writable.
			$home = \FreePBX::Freepbx_conf()->get('ASTSPOOLDIR');
			if (!is_dir($home)) {
				// OK, I give up.
				throw new \Exception(sprintf(_("Asterisk home dir (%s) doesn't exist, and, ASTSPOOLDIR doesn't exist. Aborting"),$home));
			}
		}

		outn(_("Installing/Updating Required Libraries. This may take a while..."));
		if (php_sapi_name() == "cli") {
			out("The following messages are ONLY FOR DEBUGGING. Ignore anything that says 'WARN' or is just a warning");
		}

		$this->freepbx->Pm2->installNodeDependencies($this->nodeloc,function($data) {
			outn($data);
		});
		out("");
		out(_("Finished updating libraries!"));

		if($this->freepbx->Modules->checkStatus("sysadmin")) {
			touch("/var/spool/asterisk/incron/xmpp.logrotate");
		}

		$sql = 'DROP TABLE IF EXISTS `prosody`';
		$sth = $this->db->prepare($sql);
		$sth->execute();

		outn(_("Starting new Xmpp Process..."));
		$this->stopFreepbx();
		$started = $this->startFreepbx();
		if(!$started) {
			out(_("Failed!"));
		} else {
			out(sprintf(_("Started with PID %s!"),$started));
		}
	}

	public function uninstall() {
		outn(_("Stopping old running processes..."));
		$this->stopFreepbx();
		out(_("Done"));
		exec("rm -Rf ".$this->nodeloc."/node_modules");

		$sql = 'DROP TABLE IF EXISTS `xmpp_users`, `xmpp_options`';
		$sth = $this->db->prepare($sql);
		$sth->execute();
		try {
			$this->freepbx->Pm2->delete("xmpp");
		} catch(\Exception $e) {}

	}

	public function backup(){

	}
	public function restore($backup){

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
		if($this->freepbx->Modules->moduleHasMethod('Zulu', 'getContactLUIDByZuluID')) {
			$data['uuid'] = $this->freepbx->Zulu->getContactLUIDByZuluID($data['id']);
		} else {
			$data['uuid'] = null;
		}
		$data['freepbxId'] = $id;
		$addUserParam = base64_encode(json_encode($data));
		if(!is_executable(__DIR__.'/node/adduserletschat.js')) {
			chmod(__DIR__.'/node/adduserletschat.js', 0755);
		}
		$addUserCommand = 'node '.__DIR__.'/node/adduserletschat.js '.$addUserParam;
		$process = new Process($addUserCommand);
		try {
			$process->mustRun();
		}
		catch (ProcessFailedException $e){
			if(is_object($output)) {
				$output->writeln(sprintf(_('Add user to letschat failed: %s'),$e->getMessage()));
			}
			return false;
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
					$newData = array("id" => $id, "username" => $data['username']);
		            $updateUserParam = base64_encode(json_encode($newData));
		            if(!is_executable(__DIR__.'/node/updateuserletschat.js')) {
		            	chmod(__DIR__.'/node/updateuserletschat.js', 0755);
		            }
		            $updateUserCommand = 'node '.__DIR__.'/node/updateuserletschat.js '.$updateUserParam;
		            $process = new Process($updateUserCommand);
		            try {
		            	$process->mustRun();
		            }
		            catch (ProcessFailedException $e){
		            	if(is_object($output)) {
		            		$output->writeln(sprintf(_('Update of user on letschat failed: %s'),$e->getMessage()));
		            	}
		            	return false;
		            }
				}
			} elseif(!$xmppEnable) {
				$this->usermanDelUser($id, $display, $data);
			}
		} elseif($enabled) {
			$user = $this->getUser($id);
			if(!empty($user)) {
				$this->saveUser($id, $data['username']);
				if($data['prevUsername'] != $data['username']) {
					$newData = array("id" => $id, "username" => $data['username']);
		            $updateUserParam = base64_encode(json_encode($newData));
		            if(!is_executable(__DIR__.'/node/updateuserletschat.js')) {
		            	chmod(__DIR__.'/node/updateuserletschat.js', 0755);
		            }
		            $updateUserCommand = 'node '.__DIR__.'/node/updateuserletschat.js '.$updateUserParam;
		            $process = new Process($updateUserCommand);
		            try {
		            	$process->mustRun();
		            }
		            catch (ProcessFailedException $e){
		            	if(is_object($output)) {
		            		$output->writeln(sprintf(_('Update of user on letschat failed: %s'),$e->getMessage()));
		            	}
		            	return false;
		            }

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
		$data['freepbxId'] = $id;
		$delUserParam = base64_encode(json_encode($data));
		if(!is_executable(__DIR__.'/node/deluserletschat.js')) {
			chmod(__DIR__.'/node/deluserletschat.js', 0755);
		}
		$delUserCommand = 'node '.__DIR__.'/node/deluserletschat.js '.$delUserParam;
		$process = new Process($delUserCommand);
		try {
			$process->mustRun();
		}
		catch (ProcessFailedException $e){
			if(is_object($output)) {
				$output->writeln(sprintf(_('Deletion of user on letschat failed: %s'),$e->getMessage()));
			}
			return false;
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
		$service = array(
			'title' => _('Xmpp Daemon'),
			'type' => 'unknown',
			'tooltip' => _("Unknown"),
			'order' => 999,
			'glyph-class' => ''
		);
		$data = $this->freepbx->Pm2->getStatus("xmpp");
		if(!empty($data) && $data['pm2_env']['status'] == 'online') {
			$uptime = $data['pm2_env']['created_at_human_diff'];
			$service = array_merge($service, $this->genAlertGlyphicon('ok', sprintf(_("Running (Uptime: %s)"),$uptime)));
		} else {
			$service = array_merge($service, $this->genAlertGlyphicon('critical', _("Xmpp is not running")));
		}

		return array($service);
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
		$files = array();
		$files[] = array('type' => 'file',
			'path' => $moduledir.'/node/resetpbxusers.js',
			'perms' => 0755);
		$files[] = array('type' => 'file',
			'path' => $moduledir.'/bin/chatmailer.php',
			'perms' => 0755);
		$files[] = array('type' => 'file',
			'path' => $moduledir.'/bin/xmpp_auth.php',
			'perms' => 0755);
		$files[] = array('type' => 'file',
			'path' => $moduledir.'/presence.php',
			'perms' => 0755);
		$files[] = array('type' => 'rdir',
			'path' => '/tmp/.jaxl',
			'perms' => 0777);

		return $files;
	}
	public function startFreepbx($output=null) {
		$sysadmin = $this->freepbx->Modules->checkStatus("sysadmin");
		$process = new Process("ps -edaf | grep mongo | grep -v grep");
		$process->run();
		if(!$process->isSuccessful() && $sysadmin) {
			$this->startMongoServer($output);
			$process = new Process("ps -edaf | grep mongo | grep -v grep");
			$process->run();
			if(!$process->isSuccessful()) {
				if(is_object($output)) {
					$output->writeln(_("MongoDB is not running. Please start it before starting XMPP"));
				}
				return false;
			}
		} elseif(!$process->isSuccessful() && !$sysadmin) {
			if(is_object($output)) {
				$output->writeln(_("MongoDB is not running. Please start it before starting XMPP"));
			}
			return false;
		}

		if(!is_executable(__DIR__.'/node/resetpbxusers.js')) {
			chmod(__DIR__.'/node/resetpbxusers.js', 0755);
		}
		$process = new Process('node '.__DIR__.'/node/resetpbxusers.js');
		try {
				$process->mustRun();
		}
		catch (ProcessFailedException $e){
				if(is_object($output)) {
						$output->writeln(sprintf(_('Resetting PBX Users Failed: %s'),$e->getMessage()));
				}
				return false;
		}

		$status = $this->freepbx->Pm2->getStatus("xmpp");
		switch($status['pm2_env']['status']) {
			case 'online':
				if(is_object($output)) {
					$output->writeln(sprintf(_("Chat Server has already been running on PID %s for %s"),$status['pid'],$status['pm2_env']['created_at_human_diff']));
				}
				return $status['pid'];
			break;
			default:
				if(is_object($output)) {
					$output->writeln(_("Starting Chat Server..."));
				}
				$this->freepbx->Pm2->start("xmpp",__DIR__."/node/node_modules/lets-chat/app.js");
				$this->freepbx->Pm2->reset("xmpp");
				if(is_object($output)) {
					$progress = new ProgressBar($output, 0);
					$progress->setFormat('[%bar%] %elapsed%');
					$progress->start();
				}
				$i = 0;
				while($i < 100) {
					$data = $this->freepbx->Pm2->getStatus("xmpp");
					if(!empty($data) && $data['pm2_env']['status'] == 'online') {
						if(is_object($output)) {
							$progress->finish();
						}
						break;
					}
					if(is_object($output)) {
						$progress->setProgress($i);
					}
					$i++;
					usleep(100000);
				}
				if(is_object($output)) {
					$output->writeln("");
				}
				if(!empty($data)) {
					if(is_object($output)) {
						$output->writeln(sprintf(_("Started Chat Server. PID is %s"),$data['pid']));
					}
					return $data['pid'];
				}
				if(is_object($output)) {
					$output->write("<error>".sprintf(_("Failed to run: '%s'")."</error>",$command));
				}
			break;
		}
		return false;
	}

	/**
	 * Stop FreePBX for fwconsole hook
	 * @param object $output The output object.
	 */
	public function stopFreepbx($output=null) {
		exec("pgrep -f xmpp/node/node_modules/forever/bin/monitor",$o);
		if($o) {
			foreach($o as $z) {
				$z = trim($z);
				posix_kill($z, 9);
			}

			exec("pgrep -f lets-chat/app.js",$o);
			foreach($o as $z) {
				$z = trim($z);
				posix_kill($z, 9);
			}

			exec("pgrep -f letschat",$o);
			foreach($o as $z) {
				$z = trim($z);
				posix_kill($z, 9);
			}
		}

		$data = $this->freepbx->Pm2->getStatus("xmpp");
		if(empty($data) || $data['pm2_env']['status'] != 'online') {
			if(is_object($output)) {
				$output->writeln("<error>"._("Chat Server is not running")."</error>");
			}
			return false;
		}

		// executes after the command finishes
		if(is_object($output)) {
			$output->writeln(_("Stopping Chat Server"));
		}

		$this->freepbx->Pm2->stop("xmpp");

		$data = $this->freepbx->Pm2->getStatus("xmpp");
		if (empty($data) || $data['pm2_env']['status'] != 'online') {
			if(is_object($output)) {
				$output->writeln(_("Stopped Chat Server"));
			}
		} else {
			if(is_object($output)) {
				$output->writeln("<error>".sprintf(_("Chat Server Failed: %s")."</error>",$process->getErrorOutput()));
			}
			return false;
		}

		return true;
	}

	private function startMongoServer($output) {
		touch("/var/spool/asterisk/incron/xmpp.mongodb-start");
		$process = new Process("ps -edaf | grep mongo | grep -v grep");
		$process->run();
		$timer = 30;
		if(is_object($output)) {
			$progress = new ProgressBar($output, 0);
			$progress->setFormat('[%bar%] %elapsed%');
			$progress->start();
		}
		while(!$process->isSuccessful() && $timer > 0) {
			$process = new Process("ps -edaf | grep mongo | grep -v grep");
			$process->run();
			$timer--;
			if(is_object($output)) {
				$progress->setProgress($i);
			}
			sleep(1);
		}
		if(is_object($output)) {
			$progress->finish();
		}
	}

	private function installMongo() {
		touch("/var/spool/asterisk/incron/xmpp.mongodb-install");
		sleep(1);
		while(file_exists("/dev/shm/yumwrapper/yum.lock")) {
			outn(".");
			sleep(1);
		}
	}

	private function installNode() {
		touch("/var/spool/asterisk/incron/xmpp.node-install");
		sleep(1);
		while(file_exists("/dev/shm/yumwrapper/yum.lock")) {
			outn(".");
			sleep(1);
		}
	}

	private function removeProsody() {
		touch("/var/spool/asterisk/incron/xmpp.prosody-removal");
		sleep(1);
		while(file_exists("/dev/shm/yumwrapper/yum.lock")) {
			outn(".");
			sleep(1);
		}
	}

	private function generateRunAsAsteriskCommand($command) {
		return $this->freepbx->Pm2->generateRunAsAsteriskCommand($command,$this->nodeloc);
	}

	public function getHomeDir() {
		$webuser = \FreePBX::Freepbx_conf()->get('AMPASTERISKWEBUSER');
		$web = posix_getpwnam($webuser);
		$home = trim($web['dir']);
		if (!is_dir($home)) {
			// Well, that's handy. It doesn't exist. Let's use ASTSPOOLDIR instead, because
			// that should exist and be writable.
			$home = \FreePBX::Freepbx_conf()->get('ASTSPOOLDIR');
			if (!is_dir($home)) {
				// OK, I give up.
				throw new \Exception(sprintf(_("Asterisk home dir (%s) doesn't exist, and, ASTSPOOLDIR doesn't exist. Aborting"),$home));
			}
		}
		return $home;
	}
}
