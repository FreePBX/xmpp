<?php
namespace FreePBX\modules\Xmpp;
use FreePBX\modules\Backup as Base;
class Restore Extends Base\RestoreBase{
  public function runRestore($jobid){
    $xmpp = $this->FreePBX->Xmpp;
    $configs = $this->getConfigs();
    $this->processData($xmpp, $configs);

  }

  public function processLegacy($pdo, $data, $tables, $unknownTables, $tmpfiledir){
    $tables = array_flip($tables+$unknownTables);
    if(!isset(tables['xmpp_users'])){
      return $this;
    }
    $bmo = $this->FreePBX->Xmpp;
    $bmo->setDatabase($pdo);
    $configs = [
      'users' => $bmo->getAllUsers(),
      'options' => $bmo->getAllOptions(),
    ];
    $bmo->resetDatabase();
    $this->processData($bmo,$configs);
    return $this;
  }

  public function processData($xmpp, $configs){
    if (!empty($configs['options'])) {
      foreach ($configs['options'] as $key => $value) {
        $xmpp->saveOption($key, $value);
      }
    }

    if (!empty($configs['users'])) {
      foreach ($configs['users'] as $user) {
        $xmpp->saveUser($user['user'], $user['username']);
        $xmpp->setPass($user['username'], $user['password']);
      }
    }
  }
}
