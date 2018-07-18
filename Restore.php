<?php
namespace FreePBX\modules\Xmpp;
use FreePBX\modules\Backup as Base;
class Restore Extends Base\RestoreBase{
  public function runRestore($jobid){
    $xmpp = $this->FreePBX->Xmpp;
    $configs = $this->getConfigs();
    if(!empty($configs['options'])){
        foreach ($configs['options'] as $key => $value) {
            $xmpp->saveOption($key,$value);
        }
    }

    if(!empty($configs['users'])){
	    foreach ($configs['users'] as $user) {
        	$xmpp->saveUser($user['user'],$user['username']);
        	$xmpp->setPass($user['username'],$user['password']);
    	    }
    }
  }
}
