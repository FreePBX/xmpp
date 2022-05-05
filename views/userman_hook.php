<div class="panel panel-info">
	<div class="panel-heading">
		<div class="panel-title">
			<a href="#" data-toggle="collapse" data-target="#moreinfo-xmpp"><i class="glyphicon glyphicon-info-sign"></i></a>&nbsp;&nbsp;&nbsp;<?php echo _("What is Chat")?>
		</div>
	</div>
	<!--At some point we can probably kill this... Maybe make is a 1 time panel that may be dismissed-->
	<div class="panel-body collapse" id="moreinfo-xmpp">
		<p><?php echo sprintf(_('This section will add this user to the Chat service. The Chat service is a local XMPP server that runs on your machine. The user can then login to the XMPP server at "%s" using "&lt;username&gt;@%s"'),$domain,$domain)?></p>
	</div>
</div>
<div class="element-container">
	<div class="row">
		<div class="col-md-12">
			<div class="">
				<div class="form-group row">
					<div class="col-md-3">
						<label class="control-label" for="xmpp_enable"><?php echo _('Enabled')?></label>
						<i class="fa fa-question-circle fpbx-help-icon" data-for="xmpp_enable"></i>
					</div>
					<div class="col-md-9">
						<span class="radioset">
							<input type="radio" id="xmpp1" name="xmpp_enable" value="true" <?php echo ($enabled) ? 'checked' : ''?> data-checked="<?php echo ($enabled) ? 'true' : 'false'?>"><label for="xmpp1"><?php echo _('Yes')?></label>
							<input type="radio" id="xmpp2" name="xmpp_enable" value="false" <?php echo (!is_null($enabled) && !$enabled) ? 'checked' : ''?>><label for="xmpp2"><?php echo _('No')?></label>
							<?php if($mode == "user") {?>
								<input type="radio" id="xmpp3" name="xmpp_enable" value='inherit' <?php echo is_null($enabled) ? 'checked' : ''; ?>>
								<label for="xmpp3"><?php echo _('Inherit')?></label>
							<?php } ?>
						</span>
					</div>
				</div>
			</div>
		</div>
	</div>
	<div class="row">
		<div class="col-md-12">
			<span id="xmpp_enable-help" class="help-block fpbx-help-block"><?php echo _("Enable XMPP for this user")?></span>
		</div>
	</div>
</div>
