Data Relay Center (DRC)
-----------------------

A powerful multi-channel, secure, general-purpose WebSocket PHP server and client SDKs for PHP and Javascript.  Similar to how Internet Relay Chat (IRC) works but designed specifically for data and the web!  DRC is the missing communication protocol for the modern web.

[![Donate](https://cubiclesoft.com/res/donate-shield.png)](https://cubiclesoft.com/donate/)

Features
--------

* Join and leave channels just like IRC.
* Set custom attributes and generate access tokens per client.
* Dynamic custom channel management.  A channel is created when the first client joins and removed when the last client leaves.
* Precision access controls to define channel authorities by IP address(es) and/or security token(s).
* WebSocket Origin whitelisting and other defenses to prevent abuse.
* Has a liberal open source license.  MIT or LGPL, your choice.
* Designed for relatively painless integration into your projects.
* Sits on GitHub for all of that pull request and issue tracker goodness to easily submit changes and ideas respectively.

Use Cases
---------

* Easily add data broadcasting support to an application to notify that a file or some data has been added, modified, or removed and dynamically update the application in response.
* Expand existing RESTful APIs where polling is typically done in order to reduce system request load and to reduce the number of times that users hit rate limits.
* Build collaborative web-based tools.  Private Google Docs or Office 365 anyone?
* Build a Slack/IRC chat clone and integrate it into your environment to offer realtime customer service, technical support, etc.
* Bypass corporate firewall restrictions to introduce realtime web connectivity to internal backend infrastructure.  (Obviously, be careful with this!)

Getting Started
---------------

It is highly recommended that the [PECL libev extension be installed](https://pecl.php.net/package/ev) via `pecl install ev` and [raising the system ulimit](https://stackoverflow.com/questions/34588/how-do-i-change-the-number-of-open-files-limit-in-linux) to support thousands of connections.  Nginx is also recommeneded to proxy connections to the server.

Download or clone this repository.  Configure the software:

```
php config.php
```

To get up and running quickly, the following will allow localhost clients to automatically be channel authorities:

```
php config.php origins add http://127.0.0.1
```

Start the server:

```
php server.php
```

Run the [example receive client](sdks/php/test_recv_client.php) in a second console/terminal:

```
cd sdks/php
php test_recv_client.php
```

Run the [example send client](sdks/php/test_send_client.php) in a third console/terminal:

```
cd sdks/php
php test_send_client.php
```

If all goes well, the example receive client will see the example send client join the channel and broadcast a packet to all attached receive clients.

If running the server on a local computer with a web browser, run [sdks/js/test_recv_client.html](sdks/js/test_recv_client.html) in the web browser and show the Javascript console to watch the messages received.  This probably requires setting up a localhost web server.

When ready to install on a server, run the following to install the software as an at-boot system service and start running it:

```
php config.php service install

service php-data-relay-center start
```

Then to proxy requests from Nginx to the backend DRC server:

```
location /drc/ {
	proxy_pass http://127.0.0.1:7328;
	proxy_http_version 1.1;
	proxy_set_header Host $http_host;
	proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
	proxy_set_header X-Forwarded-Proto $scheme;
	proxy_set_header Upgrade $http_upgrade;
	proxy_set_header Connection "Upgrade";
	proxy_send_timeout 300s;
	proxy_read_timeout 300s;
}
```

Nginx is recommended as it can handle routing for thousands of connected clients whereas Apache will hit a hard connection limit of around 150 connections by default.

Design Overview
---------------

DRC is a general purpose server with client SDKs to join channels and manage communication across channels.  Both broadcast and client-to-client communications are built-in.  It is designed to be flexible to accommodate a wide range of needs.

A channel is made up of a name + protocol.  A channel is created when the first client joins and removed when the last client leaves.  Channel names can be up to 256 bytes and protocol names can be up to 64 bytes in length.  Only clients with a valid security token (temporary grant or authority token) or a whitelisted IP may join a channel.

Authorities are clearly identified by the DRC server either by an authority token or a whitelisted IP address.  They have automatic rights to grant temporary tokens to allow non-authority clients to join a channel.  Temporary grant tokens are also associated with useful information (e.g. a user ID or email address) that might be used by another authority.

Non-authority clients using a temporary grant token may join the specific channel associated with the token within 30 seconds after the temporary grant token is issued.  On leaving the channel, the grant token may be used again within 30 seconds to rejoin the channel (e.g. handles temporary network failures).

The main intent of DRC is communication with and/or waiting on server-side infrastructure to process tasks.  However, building IRC-like chat systems with broadcast to/from all clients in a channel is also doable.

Example Usage
-------------

There are two main client SDKs.  The PHP client SDK is intended for use on servers as an authority client while the Javascript client SDK is intended for use in web browsers as a non-authority client.

Let's say Bob starts generating a report that takes about two minutes to complete.  An implementation will generally show a spinner until the report is complete.  Without DRC, there are limited options available:  Periodically query for completion (e.g. poll an API every 15 seconds) or write a WebSocket server for the purpose.  Both options are kind of wasteful of resources for an event that only occurs on occasion.  With DRC, we first issue a grant token and have Bob's web browser join the channel:

```php
<?php
	$reportid = 15597332;
	$userid = 14435;

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/sdk_drc_client.php";

	$drc = new DRCClient();

	// Connect to the DRC server.
	$result = $drc->Connect("ws://127.0.0.1:7328", "http://127.0.0.1");
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	// Create a grant token.
	$result = $drc->CreateToken(false, "app-report-" . $reportid, "app-report-notify", DRCClient::CM_RECV_ONLY, array("uid" => $userid), true);
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	$token = $result["data"]["token"];
?>

<script type="text/javascript" src="support/drc_client.js"></script>

<script type="text/javascript">
(function() {
	var drc = new DRCClient('ws://127.0.0.1:7328');

	drc.debug = true;

	drc.addEventListener('connect', function(msg) {
		drc.JoinChannel('app-report-<?=$reportid?>', 'app-report-notify', '<?=$token?>');
	});

	drc.addEventListener('message', function(msg) {
		alert('Report ' + msg.status + '!');
	});
})();
</script>
```

Once the report is ready, the server notifies the client via the DRC channel:

```php
<?php
	$reportid = 15597332;

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/sdk_drc_client.php";

	$drc = new DRCClient();

	// Connect to the DRC server.
	$result = $drc->Connect("ws://127.0.0.1:7328", "http://127.0.0.1");
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	// Join the channel.
	$result = $drc->JoinChannel("app-report-" . $reportid, "app-report-notify", false, true);
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	$channel = $result["data"]["channel"];

	$clientid = $drc->GetClientID();
	echo "Connected as client ID " . $clientid . "\n";

	// Set the extra data for this client.  Optional.
	$result = $drc->SetExtra($channel, $clientid, array("node" => "master-1"), true);
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	// Broadcast a command to all clients on the channel.
	$result = $drc->SendCommand($channel, "action_results", -1, array("status" => "ready"), true);
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	echo "Done.\n";
?>
```

It is important to note a race condition here.  If the report finishes running BEFORE the web browser connects into the channel, it is possible for the web browser to not receive the message since the server will send the message to an empty channel and then immediately disconnect.  There are many options available to solve this problem.  One common option is to wait for about 30 seconds before disconnecting in order to re-send the command if a new client connects to the channel during that time:

```php
<?php
	// ...

	// Broadcast a command to all clients on the channel.
	$result = $drc->SendCommand($channel, "action_results", -1, array("status" => "ready"), true);
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	// Wait for 30 seconds before exiting.
	$waituntil = time() + 30;
	$result = $drc->Wait(3);
	while ($result["success"] && $waituntil <= time())
	{
		do
		{
			$result = $drc->Read();
			if (!$result["success"])  break;

			if ($result["data"] !== false && isset($result["data"]["cmd"]) && $result["data"]["cmd"] === "JOINED")
			{
				// Notify the new client that the report is ready.
				$drc->SendCommand($channel, "action_results", $result["data"]["id"], array("status" => "ready"), true);
			}
		} while ($result["data"] !== false);

		$result = $drc->Wait(3);
	}

	echo "Done.\n";
?>
```

This is just one example of what is possible with DRC.  See the Use Cases near the top of this page for more ideas.

Documentation
-------------

* [PHP client SDK](docs/php_sdk_drc_client.md)
* [Javascript client SDK](docs/js_sdk_drc_client.md)

Limitations
-----------

Even though the libev PECL extension is available for Windows, the Windows version of the extension is buggy and, even if it weren't buggy, libev falls back to select() on Windows instead of using IOCP.  As a result, the libev PECL extension is always ignored by DRC when run on Windows.  Windows + select() is perfectly fine for testing even though it is limited to around 256 simultaneous connections.

Once the system/user/application ulimit is reached, the DRC server spins at 100% CPU as it can't accept new connections [even though there are more are in the queue](http://pod.tst.eu/http://cvs.schmorp.de/libev/ev.pod#The_special_problem_of_accept_ing_wh).  Detecting this issue is difficult.

A single IP address can consume all available descriptors and reach the configured system/user/application ulimit.  It's not particularly obvious how to solve this problem either (e.g. IPv4 NAT + hundreds of legitimate connections vs. one rogue IP spanning hundreds of bogus connections).  OSes and TCP/IP are both kind of broken when it comes to scalability past a couple hundred connections.

DRC server becomes somewhat bogged down when 500+ clients join a single channel.  This is especially noticeable when 500+ authority clients join a single channel.  However, most channels will rarely have more than a single authority client.
