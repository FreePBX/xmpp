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
	private $foreverroot = "/tmp";
	private $nodeloc = "/tmp";

	public function __construct($freepbx = null) {
		if ($freepbx == null) {
			throw new \Exception("Not given a FreePBX Object");
		}
		$this->freepbx = $freepbx;
		$this->db = $freepbx->Database;
		$this->userman = $freepbx->Userman;
		$this->foreverroot = $this->freepbx->Config->get('ASTVARLIBDIR') . "/xmpp";
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

		$cwd = getcwd();
		$webuser = \FreePBX::Freepbx_conf()->get('AMPASTERISKWEBUSER');
		chdir($this->nodeloc);
		putenv("PATH=/bin:/usr/bin:/sbin");
		putenv("USER=".$webuser);
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

		putenv("HOME=".$home);
		putenv("FOREVER_ROOT=".$this->foreverroot);
		$astlogdir = $this->freepbx->Config->get("ASTLOGDIR");
		$varlibdir = $this->freepbx->Config->get("ASTVARLIBDIR");
		putenv("ASTLOGDIR=".$astlogdir);
		putenv("SHELL=/bin/bash");
		outn(_("Installing/Updating Required Libraries. This may take a while..."));
		file_put_contents($this->nodeloc."/logs/install.log","");

		$handle = popen("npm update 2>&1", "r");
		$log = fopen($this->nodeloc."/logs/install.log", "a");
		while (($buffer = fgets($handle, 4096)) !== false) {
			//out(trim($buffer));
			fwrite($log,$buffer);
			outn(".");
		}
		fclose($log);
		out("");
		out(_("Finished updating libraries!"));
		if(!file_exists($this->nodeloc."/node_modules/forever/bin/forever")) {
			out("");
			out(sprintf(_("There was an error installing. Please review the install log. (%s)"),$this->nodeloc."/logs/install.log"));
			return false;
		}

		if(!file_exists($varlibdir.'/xmpp')) {
			mkdir($varlibdir.'/xmpp');
		}

		//need forever to be executable. it's sooo cutable
		if(!is_executable($this->nodeloc."/node_modules/forever/bin/forever")) {
			chmod($this->nodeloc."/node_modules/forever/bin/forever",0755);
		}

		if($this->freepbx->Modules->checkStatus("sysadmin")) {
			touch("/var/spool/asterisk/incron/xmpp.logrotate");
		}

		$data = $this->getServiceStatus();
		$stopped = false;
		if(!empty($data)) {
			outn(_("Stopping old running processes..."));
			$this->stopFreepbx();
			out(_("Done"));
			$stopped = true;
		}

		//If we are root then start it as asterisk, otherwise we arent root so start it as the web user (all we can do really)
		//Only run if licensed
		if($stopped) {
			outn(_("Starting new Xmpp Process..."));
			$started = $this->startFreepbx();
			if(!$started) {
				out(_("Failed!"));
			} else {
				out(_("Started!"));
			}
		}

		$sql = 'DROP TABLES prosody';
		$sth = $this->db->prepare($sql);
		$sth->execute();
	}

	public function uninstall() {
		outn(_("Stopping old running processes..."));
		$this->stopFreepbx();
		out(_("Done"));
		exec("rm -Rf ".$this->nodeloc."/node_modules");

		$sql = 'DROP TABLES xmpp_users, xmpp_options';
		$sth = $this->db->prepare($sql);
		$sth->execute();
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
							"title" => _("Chat"),
							"rawname" => "xmpp",
							"content" => load_view(__DIR__.'/views/userman_hook.php',array("mode" => "group", "enabled" => ($this->userman->getModuleSettingByGID($_REQUEST['group'],'xmpp','enable')), "domain"=>$this->getOption("domain")))
						)
					);
				break;
				case 'showuser':
					$enabled = $this->userman->getModuleSettingByID($_REQUEST['user'],'xmpp','enable',true);
					return array(
						array(
							"title" => _("Chat"),
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
							"title" => _("Chat"),
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
		if(!file_exists($this->nodeloc."/node_modules/forever")) {
			$service = array_merge($service, $this->genAlertGlyphicon('critical', _("XMPP is damaged. Please reinstall.")));
			return array($service);
		}

		$service = array(
			'title' => _('Xmpp Daemon'),
			'type' => 'unknown',
			'tooltip' => _("Unknown"),
			'order' => 999,
			'glyph-class' => ''
		);
		$data = $this->getServiceStatus();
		if(!empty($data)) {
			$uptime = $this->get_date_diff(time(), (int)round($data['ctime']/1000));
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
			'path' => $moduledir.'/bin/xmpp_auth.php',
			'perms' => 0755);
		$files[] = array('type' => 'file',
			'path' => $moduledir.'/presence.php',
			'perms' => 0755);
		$files[] = array('type' => 'rdir',
			'path' => '/tmp/.jaxl',
			'perms' => 0777);
		$files[] = array('type' => 'execdir',
			'path' => __DIR__.'/node/node_modules/forever/bin',
			'perms' => 0755);
		$files[] = array('type' => 'rdir',
			'path' => $this->foreverroot,
			'perms' => 0775);

		return $files;
	}
	public function startFreepbx($output=null) {
		$webroot = $this->freepbx->Config->get("AMPWEBROOT");
		$varlibdir = $this->freepbx->Config->get("ASTVARLIBDIR");
		$astlogdir = $this->freepbx->Config->get("ASTLOGDIR");

		if(!file_exists($this->nodeloc."/node_modules/forever")) {
			if(is_object($output)) {
				$output->writeln("<error>"._("Xmpp is damaged. Please reinstall.")."</error>");
			}
			return false;
		}

		if(!is_executable($this->nodeloc."/node_modules/forever")) {
			if(is_object($output)) {
				$output->writeln("<error>"._("Unable to launch Xmpp monitor")."</error>");
			}
			return false;
		}

		$data = $this->getServiceStatus();
		if(!empty($data)) {
			$uptime = $this->get_date_diff(time(), (int)round($data['ctime']/1000));
			$output->writeln(sprintf(_("Xmpp Server has already been running on PID %s for %s"),$data['pid'],$uptime));
			return true;
		}

		$process = new Process("ps -edaf | grep mongo | grep -v grep");
		$process->run();
		if (!$process->isSuccessful()) {
			$output->writeln(_("Starting MongoDB Server..."));
			if($this->startMongoServer($output)) {
				$output->writeln("");
				$output->writeln("<error>"._("Failed")."</error>");
				return false;
			}
			$output->writeln("");
			$output->writeln(_("Done"));
		}

		$cmds = array(
			'cd '.$this->nodeloc,
			'mkdir -p '.$this->foreverroot,
			'mkdir -p logs',
			'export FOREVER_ROOT='.$this->foreverroot,
			'export ASTLOGDIR='.$astlogdir,
			'export HOME='.$this->getHomeDir()
		);

		$cmds[] = 'npm start';
		$command = implode(" && ", $cmds);
		if (posix_getuid() == 0) {
			$command = 'runuser -l asterisk -c "'.$command.'"';
		}
		$process = new Process($command);

		$process->run();

		// executes after the command finishes
		if(is_object($output)) {
			$output->writeln(_("Starting XMPP Server..."));
		}
		if ($process->isSuccessful()) {
			if(is_object($output)) {
				$progress = new ProgressBar($output, 0);
				$progress->setFormat('[%bar%] %elapsed%');
				$progress->start();
			}
			$i = 0;
			while($i < 100) {
				$data = $this->getServiceStatus();
				if(!empty($data)) {
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
					$output->writeln(sprintf(_("Started XMPP Server. PID is %s"),$data['pid']));
				}
				return true;
			}
			if(is_object($output)) {
				$output->write("<error>".sprintf(_("Failed to run: '%s'")."</error>",$command));
			}
		} else {
			if(is_object($output)) {
				$output->writeln("<error>".sprintf(_("Failed: %s")."</error>",$process->getErrorOutput()));
			}
		}
		return false;
	}

	/**
	 * Stop FreePBX for fwconsole hook
	 * @param object $output The output object.
	 */
	public function stopFreepbx($output=null) {
		$webroot = $this->freepbx->Config->get("AMPWEBROOT");
		$varlibdir = $this->freepbx->Config->get("ASTVARLIBDIR");
		$astlogdir = $this->freepbx->Config->get("ASTLOGDIR");
		if(!file_exists($this->nodeloc."/node_modules/forever")) {
			if(is_object($output)) {
				$output->writeln("<error>"._("xmpp is damaged. Please reinstall.")."</error>");
			}
			return false;
		}

		if(!is_executable($this->nodeloc."/node_modules/forever")) {
			if(is_object($output)) {
				$output->writeln("<error>"._("Unable to launch xmpp monitor")."</error>");
			}
			return false;
		}

		$data = $this->getServiceStatus();
		if(empty($data)) {
			if(is_object($output)) {
				$output->writeln("<error>"._("xmpp Server is not running")."</error>");
			}
			return false;
		}

		$cmds = array(
			'cd '.$this->nodeloc,
			'mkdir -p '.$this->foreverroot,
			'mkdir -p logs',
			'export FOREVER_ROOT='.$this->foreverroot,
			'export ASTLOGDIR='.$astlogdir,
			'export HOME='.$this->getHomeDir()
		);

		array_pop($cmds);
		$cmds[] = 'npm stop';
		$command = implode(" && ", $cmds);
		if (posix_getuid() == 0) {
			$process = new Process('runuser -l asterisk -c "'.$command.'"');
		} else {
			$process = new Process($command);
		}

		// executes after the command finishes
		if(is_object($output)) {
			$output->writeln(_("Stopping xmpp Server"));
		}

		$process->run();

		$data = $this->getServiceStatus();
		if (empty($data) && $process->isSuccessful()) {
			if(is_object($output)) {
				$output->writeln(_("Stopped xmpp Server"));
			}
		} else {
			if(is_object($output)) {
				$output->writeln("<error>".sprintf(_("xmpp Server Failed: %s")."</error>",$process->getErrorOutput()));
			}
			return false;
		}
		return true;
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

	private function getServiceStatus() {
		foreach(glob($this->foreverroot."/sock/*.sock") as $file) {
			$sock = @stream_socket_client('unix://'.$file, $errno, $errstr);
			if($sock === false) {
				continue;
			}
			fwrite($sock, '["data", {}]'."\r\n");
			$info = fread($sock, 16384)."\n";
			$children = json_decode($info,true);
			if(!is_array($children)) {
				//strange?
				continue;
			}
			array_shift($children);
			foreach($children as $child) {
				if($child['running']) {
					return $child;
				}
			}
			fclose($sock);
		}
		return false;
	}

	/**
	 * Get human readable time difference between 2 dates
	 *
	 * Return difference between 2 dates in year, month, hour, minute or second
	 * The $precision caps the number of time units used: for instance if
	 * $time1 - $time2 = 3 days, 4 hours, 12 minutes, 5 seconds
	 * - with precision = 1 : 3 days
	 * - with precision = 2 : 3 days, 4 hours
	 * - with precision = 3 : 3 days, 4 hours, 12 minutes
	 *
	 * From: http://www.if-not-true-then-false.com/2010/php-calculate-real-differences-between-two-dates-or-timestamps/
	 *
	 * @param mixed $time1 a time (string or timestamp)
	 * @param mixed $time2 a time (string or timestamp)
	 * @param integer $precision Optional precision
	 * @return string time difference
	 */
	private function get_date_diff( $time1, $time2, $precision = 2 ) {
		// If not numeric then convert timestamps
		if( !is_int( $time1 ) ) {
			$time1 = strtotime( $time1 );
		}
		if( !is_int( $time2 ) ) {
			$time2 = strtotime( $time2 );
		}
		// If time1 > time2 then swap the 2 values
		if( $time1 > $time2 ) {
			list( $time1, $time2 ) = array( $time2, $time1 );
		}
		// Set up intervals and diffs arrays
		$intervals = array( 'year', 'month', 'day', 'hour', 'minute', 'second' );
		$diffs = array();
		foreach( $intervals as $interval ) {
			// Create temp time from time1 and interval
			$ttime = strtotime( '+1 ' . $interval, $time1 );
			// Set initial values
			$add = 1;
			$looped = 0;
			// Loop until temp time is smaller than time2
			while ( $time2 >= $ttime ) {
				// Create new temp time from time1 and interval
				$add++;
				$ttime = strtotime( "+" . $add . " " . $interval, $time1 );
				$looped++;
			}
			$time1 = strtotime( "+" . $looped . " " . $interval, $time1 );
			$diffs[ $interval ] = $looped;
		}
		$count = 0;
		$times = array();
		foreach( $diffs as $interval => $value ) {
			// Break if we have needed precission
			if( $count >= $precision ) {
				break;
			}
			// Add value and interval if value is bigger than 0
			if( $value > 0 ) {
				if( $value != 1 ){
					$interval .= "s";
				}
				// Add value and interval to times array
				$times[] = $value . " " . $interval;
				$count++;
			}
		}
		// Return string with times
		return implode( ", ", $times );
	}

	private function startMongoServer($output) {
		touch("/var/spool/asterisk/incron/xmpp.mongodb-start");
		$process = new Process("ps -edaf | grep mongo | grep -v grep");
		$process->run();
		$i = 0;
		if(is_object($output)) {
			$progress = new ProgressBar($output, 0);
			$progress->setFormat('[%bar%] %elapsed%');
			$progress->start();
		}
		while(!$process->isSuccessful() && $i < 30) {
			$process = new Process("ps -edaf | grep mongo | grep -v grep");
			$process->run();
			$i++;
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
}
