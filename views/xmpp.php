<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$heading = _("XMPP/Jabber Settings");
?>
<div class="container-fluid">
	<h1><?php echo $heading?></h1>
	<div class = "display full-border">
		<div class="row">
			<div class="col-sm-12">
				<div class="fpbx-container">
					<div class="display full-border">
						<form method="POST" action="" class="fpbx-submit" id="xmppedit" name="xmppedit">
						<input type="hidden" name="action" value="save">
						<!--Domain-->
						<div class="element-container">
							<div class="row">
								<div class="col-md-12">
									<div class="">
										<div class="form-group row">
											<div class="col-md-3">
												<label class="control-label" for="domain"><?php echo _("Domain") ?></label>
												<i class="fa fa-question-circle fpbx-help-icon" data-for="domain"></i>
											</div>
											<div class="col-md-9">
												<input type="text" class="form-control" id="domain" name="domain" value="<?php echo $domain?>">
											</div>
										</div>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span id="domain-help" class="help-block fpbx-help-block"><?php echo _("Domain XMPP will serve")?></span>
								</div>
							</div>
						</div>
						<!--END Domain-->
						</form>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
