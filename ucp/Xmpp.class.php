<?php
/**
 * This is the User Control Panel Object.
 *
 * Copyright (C) 2014 Schmooze Com, INC
 */
namespace UCP\Modules;
use \UCP\Modules as Modules;

class Xmpp extends Modules{
	protected $module = 'Xmpp';
	private $ext = 0;

	function __construct($Modules) {
		$this->Modules = $Modules;
		$this->xmpp = $this->UCP->FreePBX->Xmpp;
		$this->user = $this->UCP->User->getUser();
		$this->enabled = ($this->xmpp->getUserByID($this->user['id'])) ? true : false;
	}

	public function getSimpleWidgetList() {
		if (!$this->enabled) {
			return array();
		}

		return array(
			"rawname" => "xmpp",
			"display" => _("Chat"),
			"icon" => "fa fa-comments-o",
			"list" => array(
				array(
					"display" => _("Chat"),
					"description" => _("Chat service")
				)
			)
		);
	}

	public function getSimpleWidgetDisplay($id) {
		if (!$this->enabled) {
			return array();
		}
		return array(
			'title' => _("Chat"),
			'html' => $this->load_view(__DIR__."/views/widget.php",array())
		);
	}

	function getStaticSettings() {
		return array('enabled' => $this->enabled);
	}
	
	function poll($data) {
		$vars['xmpp-mails-enable'] = $this->UCP->FreePBX->Userman->getCombinedModuleSettingByID($this->user['id'], $this->module, 'mail');

		return array("data" => $vars);
	}

	function getUserSettingsDisplay() {
		
		if($this->enabled){
			$vars = array();
			$vars['enabled'] = $this->UCP->FreePBX->Userman->getCombinedModuleSettingByID($this->user['id'], $this->module, 'mail');
			$html = $this->load_view(__DIR__.'/views/settings.php', array('enabled' => $vars['enabled']));

			return array(
				array(
					"rawname" => "xmpp",
					"name" => "Xmpp",
					"html" => $html
				)
			);
		}
		return false;
	}
	//Left Menu
	public function getMenuItems() {
		$menu = array();
		if($this->enabled){
			$menu = array(
				"rawname" => "xmpp",
				"name" => _("Xmpp"),
				"badge" => false
			);
		}
		return $menu;
	}

	/**
	* Determine what commands are allowed
	*
	* Used by Ajax Class to determine what commands are allowed by this class
	*
	* @param string $command The command something is trying to perform
	* @param string $settings The Settings being passed through $_POST or $_PUT
	* @return bool True if pass
	*/
	function ajaxRequest($command, $settings) {
		switch($command) {
			case 'contacts':
				return true;
			break;
			case 'mail':
				return true;
			break;
			default:
				return false;
			break;
		}
	}

	/**
	* The Handler for all ajax events releated to this class
	*
	* Used by Ajax Class to process commands
	*
	* @return mixed Output if success, otherwise false will generate a 500 error serverside
	*/
	function ajaxHandler() {
		$return = array("status" => false, "message" => "");
		switch($_REQUEST['command']) {
			case 'contacts':
			$return = array();
			if($this->Modules->moduleHasMethod('Contactmanager','lookupMultiple')) {
				$search = !empty($_REQUEST['search']) ? $_REQUEST['search'] : "";
				$results = $this->Modules->Contactmanager->lookupMultiple($search);
				if(!empty($results)) {
					foreach($results as $res) {
						$res['xmpps'] = is_array($res['xmpps']) ? $res['xmpps'] : array();
						foreach($res['xmpps'] as $xmpp) {
							if(!empty($xmpp)) {
								$return[] = array(
									"value" => $xmpp,
									"text" => $res['displayname']
								);
							}
						}
					}
				}
			}
			break;
			case 'mail':
				$message = '';
				$return['status'] = true;

				if ($this->UCP->FreePBX->Userman->setModuleSettingByID($this->user['id'], $this->module,'mail', $_POST['xmpp-mails-enable'])) {
					$message = _('Xmpp mail notifications has been updated!');
				} else {
					$message = _('Error updating xmpp notifications');
					$alert = 'fail';
				}
				$return['alert'] = $alert;
				$return['message'] = $message; 
			break;
		}
		return $return;
	}
}
