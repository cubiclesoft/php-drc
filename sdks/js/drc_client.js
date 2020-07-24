// Data Relay Center client Javascript SDK.
// (C) 2019 CubicleSoft.  All Rights Reserved.

(function() {
	window.DRCClient = function(url) {
		if (this === window)
		{
			console.error('[DRCClient] Error:  Did you forget to use the "new" keyword?');

			return;
		}

		var triggers = {};
		var ws, ready = false, queued = [], channels = {}, client_id = false;
		var $this = this;

		// Global vars.
		$this.debug = false;

		// Internal functions.
		var DispatchEvent = function(eventname, params) {
			if (!triggers[eventname])  return;

			triggers[eventname].forEach(function(callback) {
				callback.call($this, params);
			});
		};

		var SendMessage = function(msg) {
			if (!ready)  queued.push({ msg: msg });
			else
			{
				if ($this.debug)  console.log('[DRCClient] Sending:  ' + JSON.stringify(msg));

				ws.send(JSON.stringify(msg));
			}
		};

		var Reconnect = function() {
			ws = new WebSocket(url);

			ws.addEventListener('open', function(e) {
				ready = true;

				queued.forEach(function(info) {
					if ($this.debug)  console.log('[DRCClient] Sending:  ' + JSON.stringify(info.msg));

					ws.send(JSON.stringify(info.msg));
				});

				queued = [];

				DispatchEvent('connect');
			});

			ws.addEventListener('message', function(e) {
				if ($this.debug)  console.log('[DRCClient] Received:  ' + e.data);

				var msg = JSON.parse(e.data);

				if (!msg.success || !msg.cmd)
				{
					console.error('[DRCClient] Error:  ' + e.data);

					DispatchEvent('error', msg);
				}
				else if (msg.cmd === 'JOINED')
				{
					if (msg.channelname && msg.protocol && msg.clients)
					{
						channels[msg.channel] = msg;
						client_id = msg.id;
					}
					else if (channels[msg.channel])
					{
						channels[msg.channel].clients[msg.id] = msg.info;
					}

					DispatchEvent('joined', msg);
				}
				else if (msg.cmd === 'LEFT' && channels[msg.channel])
				{
					DispatchEvent('left', msg);

					if (msg.id === client_id)  delete channels[msg.channel];
					else  delete channels[msg.channel].clients[msg.id];
				}
				else if (channels[msg.channel])
				{
					DispatchEvent('message', msg);
				}
				else
				{
					if ($this.debug)  console.log('[DRCClient] Unhandled message:  ' + e.data);

					DispatchEvent('unhandled', msg);
				}
			});

			var reconnecttimeout;

			ws.addEventListener('error', function(e) {
				reconnecttimeout = setTimeout(Reconnect, 1000);
			});

			ws.addEventListener('close', function(e) {
				DispatchEvent('disconnect');

				ws = null;
				ready = false;
				channels = {};
				client_id = false;

				clearTimeout(reconnecttimeout);
				setTimeout(Reconnect, 500);
			});
		};

		Reconnect();

		// Public DOM-style functions.
		$this.addEventListener = function(eventname, callback) {
			if (!triggers[eventname])  triggers[eventname] = [];

			triggers[eventname].push(callback);
		};

		$this.removeEventListener = function(eventname, callback) {
			if (!triggers[eventname])  return;

			for (var x in triggers[eventname])
			{
				if (triggers[eventname][x] === callback)
				{
					delete triggers[eventname][x];

					return;
				}
			}
		};

		// Public SDK functions.

		// Create a token.  Requires authority.
		$this.CreateToken = function(authtoken, channelname, protocol, clientmode, extra) {
			if (!extra)  extra = {};

			var msg = {
				cmd: 'GRANT',
				channel: channelname,
				protocol: protocol,
				clientmode: clientmode,
				extra: extra
			};

			if (authtoken)  msg.token = authtoken;

			SendMessage(msg);
		};

		// Join a channel.
		$this.JoinChannel = function(channelname, protocol, authtoken, allowipauth) {
			var msg = {
				cmd: 'JOIN',
				channel: channelname,
				protocol: protocol
			};

			if (authtoken)  msg.token = authtoken;
			if (allowipauth === false)  msg.ipauth = false;

			SendMessage(msg);
		};

		$this.GetChannels = function() {
			return channels;
		};

		$this.GetChannel = function(channel) {
			return channels[channel];
		};

		$this.GetClientID = function() {
			return client_id;
		};

		// Set extra information for a channel client.  Requires authority.
		$this.SetExtra = function(channel, id, extra) {
			var msg = {
				channel: channel,
				cmd: 'SET_EXTRA',
				id: id,
				extra: extra
			};

			SendMessage(msg);
		};

		// Send a command message to a specific client (-1 is broadcast).
		$this.SendCommand = function(channel, cmd, to, options) {
			var msg = {
				channel: channel,
				cmd: cmd,
				to: to
			};

			if (options)  msg = Object.assign({}, options, msg);

			SendMessage(msg);
		};

		// Get a random auth client ID.
		$this.GetRandomAuthClientID = function(channel) {
			if (!channels[channel])  return false;

			var idmap = [];
			for (var id in channels[channel].clients)
			{
				if (channels[channel].clients[id].auth)  idmap.push(id);
			}

			if (!idmap.length)  return false;

			return idmap[Math.floor(Math.random() * idmap.length)];
		};

		// Send a command message to all authority clients.
		$this.SendCommandToAuthClients = function(channel, cmd, options) {
			if (!channels[channel])  return;

			for (var id in channels[channel].clients)
			{
				if (channels[channel].clients[id].auth)  $this.SendCommand(channel, cmd, id, options);
			}
		};

		// Leave a channel.
		$this.LeaveChannel = function(channel) {
			var msg = {
				cmd: 'LEAVE',
				channel: channel
			};

			SendMessage(msg);
		};
	};
})();
