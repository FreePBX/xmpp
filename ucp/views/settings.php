<div class="message alert" style="display:none;"></div>
<form role="form">
	<div class="form-group">
		<label for="xmpp-mails-enable-h" class="help"><?php echo _('Enable mail notifications')?> <i class="fa fa-question-circle"></i></label>
		<div class="onoffswitch">
			<input type="checkbox" name="xmpp-mails-enable" class="onoffswitch-checkbox" id="xmpp-mails-enable" <?php echo ($enabled) ? 'checked' : ''?>>
			<label class="onoffswitch-label" for="xmpp-mails-enable">
				<div class="onoffswitch-inner"></div>
				<div class="onoffswitch-switch"></div>
			</label>
		</div>
		<span class="help-block help-hidden" data-for="xmpp-mails-enable-h"><?php echo _('Used to indicate that somebody wants notifications for offline messages from chat.')?></span>
	</div>
</form>
