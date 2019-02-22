<?php
namespace FreePBX\modules\Xmpp;
use FreePBX\modules\Backup as Base;
class Backup Extends Base\BackupBase{
	public function runBackup($id,$transaction){
		$xmpp = $this->FreePBX->Xmpp;
		$configs = [
				'tables' => $this->dumpTables()
		];

		$this->addDependency('pm2');
		$this->addDependency('userman');
		$this->addConfigs($configs);
	}
}