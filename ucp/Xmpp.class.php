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
		$user = $this->UCP->User->getUser();
		$this->enabled = ($this->xmpp->getUserByID($user['id'])) ? true : false;
	}

	public function getSimpleWidgetList() {
		if (!$this->enabled) {
			return array();
		}

		return array(
			"rawname" => "xmpp",
			"display" => _("XMPP"),
			"icon" => "sf sf-xmpp-logo",
			"list" => array(
				array(
					"display" => "XMPP",
					"description" => _("XMPP is a chat service")
				)
			)
		);
	}

	public function getSimpleWidgetDisplay($id) {
		return array(
			'title' => _("XMPP"),
			'html' => $this->load_view(__DIR__."/views/widget.php",array())
		);
	}

	function getStaticSettings() {
		return array('enabled' => $this->enabled);
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
		}
		return $return;
	}
}
