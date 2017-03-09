var XmppC = UCPMC.extend({
	init: function() {
		this.socket = null;
		this.jid = null;
		this.roster = {};
		this.icon = "sf sf-xmpp-logo";
		this.typing = {};
		this.enabled = false;
		this.connecting = false;
		this.online = false;
		this.initalizing = {};
		var Xmpp = this;
		$(document).on("chatWindowAdded", function(event, windowId, module, object) {
			if (module == "Xmpp") {
				Xmpp.initalizing[object.data("from")] = false;
				object.on("click", function() {
					object.find(".title-bar").css("background-color", "");
				});
				var ea = object.find("textarea").emojioneArea()[0].emojioneArea;
				ea.on("keyup", function(editor, event) {
					if (event.keyCode == 13) {
						Xmpp.sendMessage(windowId, object.data("from"), ea.getText());
						ea.setText(" ");
					}
				});
				ea.on("input propertychange", function(event) {
						Xmpp.sendState(object.data("from"), "composing");
						if (typeof Xmpp.typing[object.data("from")] !== "undefined") {
							clearTimeout(Xmpp.typing[object.data("from")]);
							delete Xmpp.typing[object.data("from")];
						}
						Xmpp.typing[object.data("from")] = setTimeout( function() {
							Xmpp.sendState(object.data("from"), "paused");
						}, 1000);
				});
				object.find(".cancelExpand").click(function() {
					Xmpp.sendState(object.data("from"), "gone");
				});
			}
		});

		$(window).bind("presenceStateChange", function() {
			Xmpp.sendEvent("setPresence", Presencestate.menu.presence);
		});
	},
	displaySimpleWidget: function(widget_type_id) {
		var clone = $(".widget-extra-menu[data-module=xmpp][data-widget_type_id="+widget_type_id+"] .clone"),
				roster = $(".widget-extra-menu[data-module=xmpp][data-widget_type_id="+widget_type_id+"] .roster"),
				$this = this;
		$.each(this.roster, function(k,v) {
			var user = clone.clone();
			user.removeClass("hidden clone").addClass("user").attr("data-jid",v.user).data("jid",v.user);
			user.find(".name").text(v.name);
			user.find("i").css("color",(v.show == "available") ? "green" : "grey");
			user.click(function() {
				if(!$this.initalizing[v.user]) {
					$this.initalizing[v.user] = true;
					$this.initiateChat(v.user);
				}
			});
			roster.append(user);
		});
		$(".widget-extra-menu[data-module=xmpp][data-widget_type_id="+widget_type_id+"] .status span").text((this.online ? _("Connected") : _("Offline")));
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
		user[1] = (typeof user[1] !== "undefined") ? user[1] : Xmpp.jid._domain;
		if (to === "") {
			alert(_("Need a valid recipient"));
			return false;
		} else if (user[0] == this.jid.user && user[1] == this.jid._domain) {
			alert(_("Recursively sending to yourself is not allowed!"));
			return;
		}
		if(!$(".message-box [data-id='"+encodeURIComponent(user[0] + "@" + user[1])+"']").length) {
			UCP.addChat("Xmpp", encodeURIComponent(user[0] + "@" + user[1]), Xmpp.icon, user[0] + "@" + user[1], Xmpp.jid.user + "@" + user[1]);
			UCP.closeDialog();
		} else {
			Xmpp.initalizing[user[0] + "@" + user[1]] = false;
		}

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
		if (this.socket !== null && this.socket.connected) {
			this.socket.emit(key, value);
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
		$(".custom-widget[data-widget_rawname=xmpp] a i").css("color", "red");
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
						$(".custom-widget[data-widget_rawname=xmpp] a i").css("color", "red");
					});
					Xmpp.socket.on("connect", function(socket) {
						Xmpp.sendEvent("login",{ username: username, password: password });
					});
					Xmpp.socket.on("prompt", function() {
						console.log("prompt");
						UCP.showDialog(_("XMPP Credentials"), _("Please enter your username and password to login to the XMPP server")+"<br/><label>" + _("Username") + ":</label><br/>" +
							'<input type="text" class="form-control" name="username" value=""></br>' +
							"<label>" + _("Password") + ":</label><br/>" +
							'<input type="password" class="form-control" name="password" value=""></br>',
							'<button class="btn btn-default" onclick="UCP.Modules.Xmpp.login();return false;">'+_("Login")+"</button>"
						);
					});
					Xmpp.socket.on("online", function(data) {
						Xmpp.online = true;
						$(document).bind("logOut", function( event ) {
							Xmpp.sendEvent("logout");
						});
						Xmpp.jid = data.jid;
						$(".message-box[data-module='Xmpp'] .response textarea").prop("disabled", false);
						$(".custom-widget[data-widget_rawname=xmpp] a i").css("color", "green");
						if (typeof UCP.Modules.Presencestate !== "undefined" && typeof UCP.Modules.Presencestate.menu.presence !== "undefined") {
							Xmpp.sendEvent("setPresence", UCP.Modules.Presencestate.menu.presence);
						}
					});
					Xmpp.socket.on("roster", function(data) {
						$.each(data, function(i, v) {
							Xmpp.roster[v.user] = v;
							if (v.subscription == "to" || v.subscription == "both") {
								//Xmpp.sendEvent("getPresence", v.user);
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
							if ((contact != Xmpp.jid.user + "@" + Xmpp.jid._domain) && data.show != "unavailable") {
								console.log("online");
							} else if ((contact != Xmpp.jid.user + "@" + Xmpp.jid._domain) && data.show == "unavailable") {
								console.log("offline");
							}
							/*
							if ((contact != Xmpp.jid.user + "@" + Xmpp.jid._domain) && data.show != "unavailable") {
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
							} else if ((contact != Xmpp.jid.user + "@" + Xmpp.jid._domain) && data.show == "unavailable") {
								if ($('#xmpp-menu .contact[data-contact="' + encodeURIComponent(contact) + '"]').length > 0) {
									$('#xmpp-menu .contact[data-contact="' + encodeURIComponent(contact) + '"]').fadeOut("fast", function() {
										$(this).remove();
										var h = $("#xmpp-menu").outerHeight() + 30;
										$("#xmpp-menu").data("hidden", h);
										$("#xmpp-menu").css("top", "-" + h + "px");
									});
								}
							}
							*/
						}
					});
					Xmpp.socket.on("offline", function(data) {
						Xmpp.online = false;
						$(".message-box[data-module='Xmpp'] .response textarea").prop("disabled", true);
						$(".custom-widget[data-widget_rawname=xmpp] a i").css("color", "red");
					});
					Xmpp.socket.on("message", function(data) {
						var fhost = data.from.host.split("/"),
								thost = data.to.host.split("/"),
								hostdisplay = (Xmpp.jid._domain != fhost) ? "@" + fhost[0] : '',
								fjid = data.from.username + "@" + fhost[0],
								tjid = data.to.username + "@" + thost[0],
								windowid = encodeURIComponent(data.from.username + "@" + Xmpp.jid._domain),
								Notification = new Notify(sprintf(_("New Message from %s"), Xmpp.replaceContact(UCP.Modules.Xmpp.roster[fjid].name)), {
							body: emojione.unifyUnicode(data.message),
							icon: "modules/Sms/assets/images/comment.png",
							timeout: 3
						});
						UCP.addChat("Xmpp", windowid, Xmpp.icon, data.from.username + "@" + fhost[0], data.to.username + "@" + thost[0], data.from.username + "@" + fhost[0], data.id, data.message, false, false, 'in');
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
