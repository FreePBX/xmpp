#!/usr/bin/env php
<?php
include '/etc/freepbx.conf';

if (empty($argv[1])) {
	die(var_dump($argv));
}
$mail_data = array();
$params = json_decode(base64_decode($argv[1]), true);

if (count($params) != 4) {
	die();
}

$admin_email = get_current_user() . '@' . gethostname();
if(function_exists('sysadmin_get_storage_email')){
	$emails = sysadmin_get_storage_email();
	//Check that what we got back above is a email address
	if(!empty($emails['fromemail']) && filter_var($emails['fromemail'],FILTER_VALIDATE_EMAIL)){
	  //Fallback address
	  $admin_email = $emails['fromemail'];
	}
}


$mail_data['ucp_url'] = '';

if(\FreePBX::Modules()->moduleHasMethod("Ucp","getUcpLink")) {
		$mail_data['ucp_url'] = \FreePBX::Ucp()->getUcpLink();
}

$sender = \FreePBX::Userman()->getUserByUsername($params['sender']);
$receiver = \FreePBX::Userman()->getUserByUsername($params['receiver']);
$mail_data['room'] = $params['room'];
$mail_data['date_now'] = date('F j h:i A');
$mail_data['message'] = $params['message'];
$mail_data['sender_name'] = $sender['displayname'];
$mail_data['receiver_name'] = $receiver['displayname'];
$mail_data['mentioned_you'] = _('mentioned you');
$mail_data['message_intended_for'] = _('This message was intended for');
$mail_data['if_it_was_error'] = _('If you think this e-mail was an error, please contact us at');
$mail_data['admin_email'] = $admin_email;
$mail_data['too_many_emails'] = _('Too many emails?');
$mail_data['change_your_notification'] = sprintf(_('Change your notification preferences %s'), '<a href="'.$mail_data['ucp_url'].'" style="color:rgb(17, 114, 186); text-decoration:none;">'._('here').'</a>');

if (empty($sender) || empty($receiver)) {
	die();
}
// Check if the user has the mail notifications enabled
if (!\FreePBX::Userman()->getCombinedModuleSettingByID($receiver['id'], 'Xmpp', 'mail')) {
	die();
}


$data = \FreePBX::Contactmanager()->getImageByID($sender['id'], $sender['email'], $sender['type']);

if (!empty($data['image'])) {
	$buffer = base64_encode($data['image']);
	$mail_data['sender_avatar_block'] = <<<EOF
			<td width="15%">
			<img src="data:$data[format];base64,$buffer" alt="$mail_data[sender_name]" height="56" width="55">
			</td>
EOF;
} else {
	$noimage = 'data:image/png;base64,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';
	$mail_data['sender_avatar_block'] = '<td width="15%" height="56" width="55"><img src="'.$noimage.'" alt="No Image"></td>';
}
$htmlMessage = file_get_contents(__DIR__.'/../templates/chatmailer.tpl');
preg_match_all('/%([\w|\d]*)%/', $htmlMessage, $matches);
foreach ($matches[1] as $match) {
	$replacement = !empty($mail_data[$match]) ? $mail_data[$match] : '';
	$htmlMessage = str_replace('%'.$match.'%', $replacement, $htmlMessage);
}
$mailer = new \CI_Email();
$mailer->from($sender['email']);
$mailer->to($receiver['email']);
$mailer->subject(sprintf(_("%s just mentioned you in the room %s but you're offline"), $mail_data['sender_name'], $mail_data['room']));
$mailer->set_mailtype('html');
$mailer->message($htmlMessage);
$mailer->send();
?>
