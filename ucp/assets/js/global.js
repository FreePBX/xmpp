var XmppC = UCPMC.extend({
	init: function() {
		this.socket = null;
		this.jid = null;
		this.roster = {};
		this.icon = "sf sf-xmpp-logo";
		this.typing = {};
		this.enabled = false;
		this.connecting = false;
		var Xmpp = this;
		$(document).on("chatWindowAdded", function(event, windowId, module, object) {
			if (module == "Xmpp") {
				object.on("click", function() {
					object.find(".title-bar").css("background-color", "");
				});
				object.find("textarea").keyup(function(event) {
					if (event.keyCode == 13) {
						var message = $(this).val();
						Xmpp.sendMessage(windowId, decodeURIComponent(windowId), message);
						$(this).val("");
					}
				});
				object.find("textarea").bind("input propertychange", function(event) {
						Xmpp.sendState(decodeURIComponent(windowId), "composing");
						if (typeof Xmpp.typing[decodeURIComponent(windowId)] !== "undefined") {
							clearTimeout(Xmpp.typing[decodeURIComponent(windowId)]);
							delete Xmpp.typing[decodeURIComponent(windowId)];
						}
						Xmpp.typing[decodeURIComponent(windowId)] = setTimeout( function() {
							Xmpp.sendState(decodeURIComponent(windowId), "paused");
						}, 1000);
				});
				object.find(".cancelExpand").click(function() {
					Xmpp.sendState(decodeURIComponent(windowId), "gone");
				});
			}
		});

		//Logged In
		$(document).bind("logIn", function( event ) {
      $("#xmpp-menu a.new").on("click", function() {
				if (Xmpp.socket === null || !Xmpp.socket.connected) {
					alert(_("There is currently no connection to a valid server"));
				} else {
					UCP.showDialog(_("Send Message"),
						"<label>From:</label> " + Xmpp.jid.user + "<br><label for=\"XmppTo\">To:</label><select class=\"form-control Tokenize Fill\" id=\"XmppTo\" multiple></select><button class=\"btn btn-default\" id=\"initiateXmpp\" style=\"margin-left: 72px;\">Initiate</button>",
						170,
						250,
						function() {
							$("#XmppTo").tokenize({ maxElements: 1, datas: "index.php?quietmode=1&module=xmpp&command=contacts" });
							$("#initiateXmpp").click(function() {
								setTimeout(function() {
									var val = ($("#XmppTo").val() !== null) ? $("#XmppTo").val()[0] : "";
									Xmpp.initiateChat(val);
								}, 50);
							});
							$("#XmppTo").keypress(function(event) {
								if (event.keyCode == 13) {
									setTimeout(function() {
										var val = ($("#XmppTo").val() !== null) ? $("#XmppTo").val()[0] : "";
										Xmpp.initiateChat(val);
									}, 50);
								}
							});
						}
					);
				}
			});
		});

		$(window).bind("presenceStateChange", function() {
			Xmpp.sendEvent("setPresence", Presencestate.menu.presence);
		});
	},
	settingsDisplay: function() {

	},
	settingsHide: function() {

	},
	poll: function(data) {

	},
	display: function(event) {
    $("#xmpp-mails-enable").change(function() {
				var mailsNotification = ($(this).is(":checked")) ? 1 : 0;
				$.post( "?quietmode=1&module=xmpp&command=mail", {"xmpp-mails-enable": mailsNotification}, function( data ) {
					if (data.status) {
						$("#message").addClass("alert-success");
						$("#message").text(_("Your settings have been saved"));
						$("#message").fadeIn( "slow", function() {
							setTimeout(function() { $("#message").fadeOut("slow"); }, 2000);
					});
					} else {
						$("#message").addClass("alert-error");
						$("#message").text(data.message);
						return false;
					}
				});
			});
	},
	hide: function(event) {

	},
	contactClickInitiate: function(user) {
		this.initiateChat(decodeURIComponent(user));
	},
	contactClickOptions: function(type) {
		if (type != "xmpp" || this.jid === null) {
			return false;
		}
		return [ { text: _("Start XMPP"), function: "contactClickInitiate", type: "xmpp" } ];
	},
	replaceContact: function(contact) {
		if (UCP.validMethod("Contactmanager", "lookup")) {
			contact = contact.length == 11 ? contact.substring(1) : contact;
			var entry = UCP.Modules.Contactmanager.lookup(contact);
			if (entry !== null && entry !== false) {
				return entry.displayname;
			}
		}
		return contact;
	},
	initiateChat: function(to) {
		var Xmpp = this,
				user = to.split("@");
		user[1] = (typeof user[1] !== "undefined") ? user[1] : Xmpp.jid.domain;
		if (to === "") {
			alert(_("Need a valid recipient"));
			return false;
		} else if (user[0] == this.jid.user && user[1] == this.jid.domain) {
			alert(_("Recursively sending to yourself is not allowed!"));
			return;
		}
		UCP.addChat("Xmpp", encodeURIComponent(user[0] + "@" + user[1]), Xmpp.icon, Xmpp.jid.user + "@" + user[1], this.replaceContact(user[0] + "@" + user[1]));
		UCP.closeDialog();
	},
	sendState: function(to, state) {
		switch (to) {
			case "active":
			case "composing":
			case "paused":
			case "inactive":
			case "gone":
			break;
		}
		this.sendEvent("composing", { to: to, state: state });
	},
	addUser: function(user) {
		this.sendEvent("addUser", user);
		this.sendEvent("subscribe", user);
	},
	removeUser: function(user) {
		this.sendEvent("removeUser", user);
		this.sendEvent("unsubscribe", user);
		//this.sendEvent("unsubscribed", user);
	},
	authorizeUser: function(user) {
		this.sendEvent("subscribed", user);
	},
	unauthorizeUser: function(user) {
		this.sendEvent("unsubscribed", user);
	},
	probe: function(user) {
		this.sendEvent("probe", user);
	},
	sendMessage: function(windowId, to, message) {
		var Xmpp = this,
				id = Math.floor((Math.random() * 100000) + 1);
		UCP.addChatMessage(windowId, _("Me"), id, message, false, false, 'out');
		if (typeof Xmpp.typing[decodeURIComponent(windowId)] !== "undefined") {
			clearTimeout(Xmpp.typing[decodeURIComponent(windowId)]);
			delete Xmpp.typing[decodeURIComponent(windowId)];
		}
		this.sendEvent("message", {
			to: to,
			message: emojione.unifyUnicode(message),
			id: id
		});
	},
	sendEvent: function(key, value) {
		var Xmpp = this;
		if (this.socket !== null && this.socket.connected) {
			this.socket.emit(key, value);
		} else if (this.socket !== null) {
			this.socket.on("connect", function(data) {
				Xmpp.socket.emit(key, value);
			});
		}
	},
	disconnect: function() {
		var Xmpp = this,
				listeners = [ "disconnect",
													"connect",
													"online",
													"offline",
													"message",
													"roster",
													"typing",
													"updatePresence",
													"subscribe",
													"unsubscribe",
													"subscribed",
													"unsubscribed" ];
		if (this.socket !== null) {
			$.each(listeners, function(i, v) {
				Xmpp.socket.removeAllListeners(v);
			});
		}
		$(".message-box[data-module='Xmpp'] .response textarea").prop("disabled", true);
		$("#nav-btn-xmpp i").css("color", "red");
		Xmpp.connecting = false;
	},
	login: function() {
		if(this.socket !== null) {
			this.sendEvent("login",{ username: $("input[name=username]").val(), password: $("input[name=password]").val() });
		}
		UCP.closeDialog();
	},
	connect: function(username, password) {
		var Xmpp = this;

		if (typeof Xmpp.staticsettings === "undefined") {
			$(document).bind("staticSettingsFinished", function( event ) {
				Xmpp.enabled = Xmpp.staticsettings.enabled;
				if (Xmpp.socket === null) {
					Xmpp.connect(username, password);
				}
			});
			return;
		} else {
			Xmpp.enabled = Xmpp.staticsettings.enabled;
		}

		if (Xmpp.connecting || !this.enabled) {
			return;
		}
		Xmpp.connecting = true;
		try {
			UCP.wsconnect("xmpp", function(socket) {
				if (socket === false) {
					Xmpp.socket = null;
					return false;
				} else {
					Xmpp.socket = socket;
					Xmpp.sendEvent("login",{ username: username, password: password });
					Xmpp.socket.on("disconnect", function(socket) {
						$(".message-box[data-module='Xmpp'] .response textarea").prop("disabled", true);
						$("#nav-btn-xmpp i").css("color", "red");
					});
					Xmpp.socket.on("connect", function(socket) {
						Xmpp.sendEvent("login",{ username: username, password: password });
					});
					Xmpp.socket.on("prompt", function() {
						UCP.showDialog(_("XMPP Credentials"), _("Please enter your username and password to login to the XMPP server")+"<br/><label>" + _("Username") + ":<br/>" +
							"<input type=\"text\" class=\"form-control\" name=\"username\" value=\"\"></label></br>" +
							"<label>" + _("Password") + ":<br/>" +
							"<input type=\"password\" class=\"form-control\" name=\"password\" value=\"\"></label></br>" +
							"<button class=\"btn btn-default\" style=\"margin-left: 87px;\" onclick=\"UCP.Modules.Xmpp.login();return false;\">"+_("Login")+"</button>",
						240);
					});
					Xmpp.socket.on("online", function(data) {
						$(document).bind("logOut", function( event ) {
							Xmpp.sendEvent("logout");
						});
						$("#nav-btn-xmpp").removeClass("hidden");
						UCP.calibrateMenus();
						Xmpp.jid = data.jid;
						$(".message-box[data-module='Xmpp'] .response textarea").prop("disabled", false);
						$("#nav-btn-xmpp i").css("color", "green");
						if (typeof Presencestate !== "undefined" && typeof Presencestate.menu.presence !== "undefined") {
							Xmpp.sendEvent("setPresence", Presencestate.menu.presence);
						}
					});
					Xmpp.socket.on("roster", function(data) {
						$.each(data, function(i, v) {
							Xmpp.roster[v.user] = v;
							if (v.subscription == "to" || v.subscription == "both") {
								Xmpp.sendEvent("getPresence", v.user);
							} else if (v.subscription == "from") {
								//console.log(v);
							} else if (v.subscription == "none") {
								//console.log(v);
							}
						});
					});
					Xmpp.socket.on("subscribe", function(data) {
						console.log(data.username + " will you subscribe?");
						//Xmpp.sendEvent("subscribed", data.username + "@" + data.host);
					});
					Xmpp.socket.on("unsubscribe", function(data) {
						console.log(data.username + " will you unsubscribe?");
						//Xmpp.sendEvent("unsubscribed", data.username + "@" + data.host);
					});
					Xmpp.socket.on("subscribed", function(data) {
						console.log(data.username + " I have subscribed");
						//Xmpp.sendEvent("subscribe", data.username + "@" + data.host);
					});
					Xmpp.socket.on("unsubscribed", function(data) {
						console.log(data.username + " I have unsubscribed");
						//Xmpp.sendEvent("unsubscribe", data.username + "@" + data.host);
					});
					Xmpp.socket.on("updatePresence", function(data) {
						var contact = data.username + "@" + data.host,
								el = null,
								h = null;
						if (typeof Xmpp.roster[contact] !== "undefined") {
							Xmpp.roster[contact].show = data.show;
							Xmpp.roster[contact].status = data.status;
							if ((contact != Xmpp.jid.user + "@" + Xmpp.jid.domain) && data.show != "unavailable") {
								if (!$('#xmpp-menu .contact[data-contact="' + encodeURIComponent(contact) + '"]').length) {
									$("#xmpp-menu .breaker").after("<li class=\"contact\" data-contact=\"" + encodeURIComponent(contact) + "\"><a data-contact=\"" + encodeURIComponent(contact) + "\"><i class=\"fa fa-circle\"></i>" + Xmpp.replaceContact(contact) + "</a></li>");
									h = $("#xmpp-menu").outerHeight() + 30;
									$("#xmpp-menu").data("hidden", h);
									$("#xmpp-menu").css("top", "-" + h + "px");
								}
								el = $("#xmpp-menu .contact[data-contact='" + encodeURIComponent(contact) + "']");
								el.off("click");
								el.click(function() {
									Xmpp.initiateChat(decodeURIComponent($(this).data("contact")));
								});
								switch (data.show) {
									case "available":
									case "chat":
										el.find("i").css("color", "green");
									break;
									case "dnd":
										el.find("i").css("color", "red");
									break;
									case "away":
									case "xa":
										el.find("i").css("color", "yellow");
									break;
								}
							} else if ((contact != Xmpp.jid.user + "@" + Xmpp.jid.domain) && data.show == "unavailable") {
								if ($('#xmpp-menu .contact[data-contact="' + encodeURIComponent(contact) + '"]').length > 0) {
									$('#xmpp-menu .contact[data-contact="' + encodeURIComponent(contact) + '"]').fadeOut("fast", function() {
										$(this).remove();
										var h = $("#xmpp-menu").outerHeight() + 30;
										$("#xmpp-menu").data("hidden", h);
										$("#xmpp-menu").css("top", "-" + h + "px");
									});
								}
							}
						}
					});
					Xmpp.socket.on("offline", function(data) {
						$(".message-box[data-module='Xmpp'] .response textarea").prop("disabled", true);
						$("#nav-btn-xmpp i").css("color", "red");
					});
					Xmpp.socket.on("message", function(data) {
						var fhost = data.from.host.split("/"),
								thost = data.to.host.split("/"),
								windowid = encodeURIComponent(data.from.username + "@" + fhost[0]),
								Notification = new Notify(sprintf(_("New Message from %s"), Xmpp.replaceContact(data.from.username)), {
							body: emojione.unifyUnicode(data.message),
							icon: "modules/Sms/assets/images/comment.png",
							timeout: 3
						});
						UCP.addChat("Xmpp", windowid, Xmpp.icon, Xmpp.replaceContact(data.from.username + "@" + fhost[0]), data.to.username + "@" + thost[0], Xmpp.replaceContact(data.from.username + "@" + thost[0]), data.id, data.message, false, false, 'in');
						if (UCP.notify) {
							Notification.show();
						}
						$(".message-box[data-id='" + windowid + "'] .response-status span").fadeOut("fast");
					});
					Xmpp.socket.on("typing", function(data) {
						var host = data.from.host.split("/"),
								windowid = encodeURIComponent(data.from.username + "@" + host[0]);
						if ($(".message-box[data-id='" + windowid + "']").length > 0) {
							if (data.typing) {
								$(".message-box[data-id='" + windowid + "'] .response-status").html("<span>" + sprintf(_("%s is typing..."), Xmpp.replaceContact(data.from.username + "@" + host[0])) + "</span>");
							} else {
								$(".message-box[data-id='" + windowid + "'] .response-status span").fadeOut("fast");
							}
						}
					});
				}
			});
		} catch (err) {}
	}
});
