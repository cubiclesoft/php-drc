<?php
	// Data Relay Center server.
	// (C) 2019 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/drc_functions.php";
	require_once $rootpath . "/support/websocket_server.php";
	require_once $rootpath . "/support/websocket_server_libev.php";
	require_once $rootpath . "/support/websocket.php";
	require_once $rootpath . "/support/ipaddr.php";
	require_once $rootpath . "/support/str_basics.php";
	require_once $rootpath . "/support/random.php";

	// Load configuration.
	$config = DRC_LoadConfig();

	if ($argc > 1)
	{
		// Service Manager PHP SDK.
		require_once $rootpath . "/support/servicemanager.php";

		$sm = new ServiceManager($rootpath . "/servicemanager");

		echo "Service manager:  " . $sm->GetServiceManagerRealpath() . "\n\n";

		$servicename = "php-data-relay-center";

		if ($argv[1] == "install")
		{
			// Install the service.
			$args = array();
			$options = array(
				"nixuser" => "php-drc",
				"nixgroup" => "php-drc"
			);

			$result = $sm->Install($servicename, __FILE__, $args, $options, true);
			if (!$result["success"])  CLI::DisplayError("Unable to install the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "start")
		{
			// Start the service.
			$result = $sm->Start($servicename, true);
			if (!$result["success"])  CLI::DisplayError("Unable to start the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "stop")
		{
			// Stop the service.
			$result = $sm->Stop($servicename, true);
			if (!$result["success"])  CLI::DisplayError("Unable to stop the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "uninstall")
		{
			// Uninstall the service.
			$result = $sm->Uninstall($servicename, true);
			if (!$result["success"])  CLI::DisplayError("Unable to uninstall the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "dumpconfig")
		{
			$result = $sm->GetConfig($servicename);
			if (!$result["success"])  CLI::DisplayError("Unable to retrieve the configuration for the '" . $servicename . "' service.", $result);

			echo "Service configuration:  " . $result["filename"] . "\n\n";

			echo "Current service configuration:\n\n";
			foreach ($result["options"] as $key => $val)  echo "  " . $key . " = " . $val . "\n";
		}
		else
		{
			echo "Command not recognized.  Run the service manager directly for anything other than 'install', 'start', 'stop', 'uninstall', and 'dumpconfig'.\n";
		}

		exit();
	}

	// Process configuration.
	$whitelist = array();
	foreach ($config["whitelist"] as $ipaddr => $protocols)
	{
		$ipaddr = IPAddr::NormalizeIP($ipaddr);
		$whitelist[$ipaddr["shortipv6"]] = $protocols;
	}

	// Initialize server.
	$wsserver = (LibEvWebSocketServer::IsSupported() ? new LibEvWebSocketServer() : new WebSocketServer());
	$wsserver->SetAllowedOrigins($config["origins"]);

	echo "Starting server " . (LibEvWebSocketServer::IsSupported() ? "with PECL libev support" : "without PECL libev support (not recommended)") . "...\n";
	$result = $wsserver->Start("127.0.0.1", "7328");
	if (!$result["success"])
	{
		var_dump($result);
		exit();
	}

	echo "Ready.\n";

	$channelmap = array();
	$channels = array();
	$nextchannel = 1;

	const CM_RECV_ONLY = 0;
	const CM_SEND_TO_AUTHS = 1;
	const CM_SEND_TO_ANY = 2;

	$rng = new CSPRNG();
	$tokenmap = array();
	$nexttoken = 1;

	function LeaveChannel($client, $channel)
	{
		global $wsserver, $channelmap, $channels, $tokenmap;

		// Remove the client.
		if (!$client->appdata["channels"][$channel])  $client->appdata["no_auth"]--;

		unset($client->appdata["channels"][$channel]);

		$info = $channels[$channel]["clients"][$client->id];

		unset($channels[$channel]["clients"][$client->id]);

		// Notify all previously notified clients of the removed client.
		foreach ($channels[$channel]["clients"] as $id2 => $info2)
		{
			$client2 = $wsserver->GetClient($id2);
			if ($client2 === false)  continue;

			if (!$info["auth"] && ($info2["mode"] === CM_RECV_ONLY || $info2["mode"] === CM_SEND_TO_AUTHS))  continue;

			$ws2 = $client2->websocket;

			$result = array(
				"channel" => $channel,
				"success" => true,
				"cmd" => "LEFT",
				"from" => 0,
				"id" => $client->id
			);

			$ws2->Write(json_encode($result, JSON_UNESCAPED_SLASHES), WebSocket::FRAMETYPE_TEXT);

			$wsserver->UpdateClientState($id2);
		}

		// Update token map to handle temporary client disconnects.
		if ($info["tokenid"] !== false && $info["token"] !== false)
		{
			$tokenmap[$info["tokenid"]] = array(
				"expires" => time() + 30,
				"token" => $info["token"],
				"channel" => $channels[$channel]["channel"],
				"protocol" => $channels[$channel]["protocol"],
				"clientmode" => $info["mode"],
				"extra" => $info["extra"],
				"makeauth" => $info["auth"]
			);
		}

		// Remove empty channels.
		if (!count($channels[$channel]["clients"]))
		{
			$key = $channels[$channel]["channel"] . "|" . $channels[$channel]["protocol"];

			unset($channelmap[$key]);
			unset($channels[$channel]);
		}

		$result = array(
			"channel" => $channel,
			"success" => true,
			"cmd" => "LEFT",
			"from" => 0,
			"id" => $client->id
		);

		return $result;
	}

	$stopfilename = __FILE__ . ".notify.stop";
	$reloadfilename = __FILE__ . ".notify.reload";
	$lastservicecheck = time();
	$running = true;

	do
	{
		$result = $wsserver->Wait(3);

		// Handle active clients.
		foreach ($result["clients"] as $id => $client)
		{
			if ($client->appdata === false)
			{
				echo "WebSocket client ID " . $id . " connected.\n";

				$client->appdata = array("channels" => array(), "no_auth" => 0);

				// Parse "X-Forwarded-For" header for channel authority whitelist (if any).
				$pos = strrpos($client->ipaddr, ":");
				if ($pos !== false)  $client->ipaddr = substr($client->ipaddr, 0, $pos);
				$client->ipaddr = str_replace(array("[", "]"), "", $client->ipaddr);

				$ipaddr = IPAddr::NormalizeIP(isset($client->headers["X-Forwarded-For"]) ? $client->headers["X-Forwarded-For"] : $client->ipaddr);

				$client->ipaddr = $ipaddr["shortipv6"];
			}

			$ws = $client->websocket;

			$result2 = $ws->Read();
			while ($result2["success"] && $result2["data"] !== false)
			{
				// Attempt to normalize the input.
				$data = @json_decode($result2["data"]["payload"], true);

				if (!is_array($data))  $result3 = array("channel" => 0, "success" => false, "error" => "Data sent is not an array/object or was not able to be decoded.", "errorcode" => "invalid_data");
				else if (!isset($data["cmd"]) || !is_string($data["cmd"]) || $data["cmd"] === "GRANTED" || $data["cmd"] === "JOINED" || $data["cmd"] === "LEFT")  $result3 = array("channel" => 0, "success" => false, "error" => "The 'cmd' is missing or invalid.", "errorcode" => "missing_invalid_cmd");
				else if ($data["cmd"] === "GRANT")
				{
					if (!isset($data["channel"]) || !is_string($data["channel"]) || !strlen($data["channel"]) || strlen($data["channel"]) > 256)  $result3 = array("channel" => 0, "success" => false, "error" => "The 'channel' is missing or invalid.", "errorcode" => "missing_invalid_channel");
					else if (!isset($data["protocol"]) || !is_string($data["protocol"]) || !strlen($data["protocol"]) || strlen($data["protocol"]) > 64)  $result3 = array("channel" => 0, "success" => false, "error" => "The 'protocol' is missing or invalid.", "errorcode" => "missing_invalid_protocol");
					else if (!isset($data["clientmode"]) || !is_numeric($data["clientmode"]) || (int)$data["clientmode"] < 0 || (int)$data["clientmode"] > 2)  $result3 = array("channel" => 0, "success" => false, "error" => "The 'clientmode' is missing or invalid.", "errorcode" => "missing_invalid_clientmode");
					else if (!isset($data["extra"]) || !is_array($data["extra"]))  $result3 = array("channel" => 0, "success" => false, "error" => "The 'extra' option is missing or invalid.", "errorcode" => "missing_invalid_extra");
					else
					{
						// Create a client authorization token.
						if (isset($whitelist[$client->ipaddr]) && ($whitelist[$client->ipaddr] === true || in_array($data["protocol"], $whitelist[$client->ipaddr])))  $auth = true;
						else
						{
							$auth = false;

							if (isset($data["token"]) && is_string($data["token"]))
							{
								// Scan tokens list in constant time.
								foreach ($config["tokens"] as $token => $protocols)
								{
									if (Str::CTstrcmp($token, $data["token"]) === 0 && ($protocols === true || in_array($data["protocol"], $protocols)))  $auth = true;
								}
							}
						}

						if (!$auth)  $result3 = array("channel" => 0, "success" => false, "error" => "Access denied.  Invalid or missing authorization.", "errorcode" => "access_denied");
						else
						{
							$token = $rng->GenerateToken();

							$tokenmap[$nexttoken] = array(
								"expires" => time() + 30,
								"token" => $token,
								"channel" => $data["channel"],
								"protocol" => $data["protocol"],
								"clientmode" => $data["clientmode"],
								"extra" => $data["extra"],
								"makeauth" => (isset($data["makeauth"]) && $data["makeauth"] === true)
							);

							$result3 = array(
								"channel" => 0,
								"success" => true,
								"cmd" => "GRANTED",
								"token" => $token . "-" . $nexttoken,
								"channelname" => $data["channel"],
								"protocol" => $data["protocol"]
							);

							$nexttoken++;
						}
					}
				}
				else if ($data["cmd"] === "JOIN")
				{
					if (!isset($data["channel"]) || !is_string($data["channel"]) || !strlen($data["channel"]) || strlen($data["channel"]) > 256)  $result3 = array("channel" => 0, "success" => false, "error" => "The 'channel' is missing or invalid.", "errorcode" => "missing_invalid_channel");
					else if (!isset($data["protocol"]) || !is_string($data["protocol"]) || !strlen($data["protocol"]) || strlen($data["protocol"]) > 64)  $result3 = array("channel" => 0, "success" => false, "error" => "The 'protocol' is missing or invalid.", "errorcode" => "missing_invalid_protocol");
					else
					{
						// Join the channel.
						$info = array(
							"auth" => false,
							"mode" => CM_RECV_ONLY,
							"tokenid" => false,
							"token" => false,
							"extra" => false
						);

						if ((!isset($data["ipauth"]) || $data["ipauth"] !== false) && isset($whitelist[$client->ipaddr]) && ($whitelist[$client->ipaddr] === true || in_array($data["protocol"], $whitelist[$client->ipaddr])))  $info["auth"] = true;
						else if (isset($data["token"]) && is_string($data["token"]))
						{
							// Scan tokens list in constant time.
							foreach ($config["tokens"] as $token => $protocols)
							{
								if (Str::CTstrcmp($token, $data["token"]) === 0 && ($protocols === true || in_array($data["protocol"], $protocols)))  $info["auth"] = true;
							}

							// Attempt to match a client token.
							if (!$info["auth"])
							{
								$pos = strrpos($data["token"], "-");
								if ($pos !== false)
								{
									$id2 = (int)substr($data["token"], $pos + 1);
									$token = substr($data["token"], 0, $pos);

									if (isset($tokenmap[$id2]) && Str::CTstrcmp($tokenmap[$id2]["token"], $token) === 0 && $tokenmap[$id2]["channel"] === $data["channel"] && $tokenmap[$id2]["protocol"] === $data["protocol"])
									{
										$info["mode"] = $tokenmap[$id2]["clientmode"];
										$info["tokenid"] = $id2;
										$info["token"] = $token;
										$info["extra"] = $tokenmap[$id2]["extra"];
										if ($tokenmap[$id2]["makeauth"])  $info["auth"] = true;

										unset($tokenmap[$id2]);
									}
								}
							}
						}

						if ($info["auth"])  $info["mode"] = CM_SEND_TO_ANY;

						if (!$info["auth"] && $client->appdata["no_auth"] >= 256)  $result3 = array("channel" => 0, "success" => false, "error" => "Per-connection channel limit reached.", "errorcode" => "max_channel_limit_reached");
						else if (!$info["auth"] && $info["extra"] === false)  $result3 = array("channel" => 0, "success" => false, "error" => "Access denied.  Invalid or missing token.", "errorcode" => "access_denied");
						else
						{
							// Create the channel if it doesn't exist.
							$key = $data["channel"] . "|" . $data["protocol"];

							if (!isset($channelmap[$key]))
							{
								$channelmap[$key] = $nextchannel;

								$channels[$nextchannel] = array(
									"channel" => $data["channel"],
									"protocol" => $data["protocol"],
									"clients" => array()
								);

								$nextchannel++;
							}

							$channel = $channelmap[$key];
							$channels[$channel]["clients"][$id] = $info;

							$client->appdata["channels"][$channel] = $info["auth"];

							if (!$info["auth"])  $client->appdata["no_auth"]++;

							$cleaninfo = $info;

							unset($cleaninfo["tokenid"]);
							unset($cleaninfo["token"]);

							// Notify all clients with sufficient access to send to the new client.
							foreach ($channels[$channel]["clients"] as $id2 => $info2)
							{
								$client2 = $wsserver->GetClient($id2);
								if ($client2 === false || $client2->websocket === false)  continue;

								if ($id !== $id2 && !$info["auth"] && ($info2["mode"] === CM_RECV_ONLY || $info2["mode"] === CM_SEND_TO_AUTHS))  continue;

								$ws2 = $client2->websocket;

								$result3 = array(
									"channel" => $channel,
									"success" => true,
									"cmd" => "JOINED",
									"from" => 0,
									"id" => $id,
									"info" => $cleaninfo
								);

								if ($id === $id2)
								{
									$result3["channelname"] = $data["channel"];
									$result3["protocol"] = $data["protocol"];
									$result3["clients"] = array();

									foreach ($channels[$channel]["clients"] as $id3 => $info3)
									{
										if ($id !== $id3 && !$info["auth"] && $info["mode"] !== CM_SEND_TO_ANY && !$info3["auth"])  continue;

										unset($info3["tokenid"]);
										unset($info3["token"]);

										$result3["clients"][$id3] = $info3;
									}
								}

								$ws2->Write(json_encode($result3, JSON_UNESCAPED_SLASHES), $result2["data"]["opcode"]);

								$wsserver->UpdateClientState($id2);
							}

							$result3 = false;
						}
					}
				}
				else if ($data["cmd"] === "SET_EXTRA")
				{
					if (!isset($data["channel"]))  $result3 = array("channel" => 0, "success" => false, "error" => "The 'channel' is missing.", "errorcode" => "missing_channel");
					else if (!is_numeric($data["channel"]) || !isset($channels[(int)$data["channel"]]) || !isset($client->appdata["channels"][(int)$data["channel"]]))  $result3 = array("channel" => 0, "success" => false, "error" => "The 'channel' is invalid.", "errorcode" => "invalid_channel");
					else if (!$channels[(int)$data["channel"]]["clients"][$client->id]["auth"])  $result3 = array("channel" => 0, "success" => false, "error" => "Access denied.  Not an authority.", "errorcode" => "access_denied");
					else if (!is_numeric($data["id"]) || !isset($channels[(int)$data["channel"]]["clients"][(int)$data["id"]]))  $result3 = array("channel" => 0, "success" => false, "error" => "The 'id' is invalid.", "errorcode" => "invalid_id");
					else if (!isset($data["extra"]) || !is_array($data["extra"]))  $result3 = array("channel" => 0, "success" => false, "error" => "The 'extra' option is missing or invalid.", "errorcode" => "missing_invalid_extra");
					else
					{
						$channel = (int)$data["channel"];
						$id2 = (int)$data["id"];
						$info = $channels[(int)$data["channel"]]["clients"][$id2];
						$info["extra"] = $data["extra"];

						$channels[(int)$data["channel"]]["clients"][$id2] = $info;

						// Notify all clients with sufficient access to send to the client.
						foreach ($channels[$channel]["clients"] as $id2 => $info2)
						{
							$client2 = $wsserver->GetClient($id2);
							if ($client2 === false || $client2->websocket === false)  continue;

							if (!$info["auth"] && ($info2["mode"] === CM_RECV_ONLY || $info2["mode"] === CM_SEND_TO_AUTHS))  continue;

							$ws2 = $client2->websocket;

							$result3 = array(
								"channel" => $channel,
								"success" => true,
								"cmd" => "SET_EXTRA",
								"from" => 0,
								"id" => $id2,
								"extra" => $info["extra"]
							);

							$ws2->Write(json_encode($result3, JSON_UNESCAPED_SLASHES), $result2["data"]["opcode"]);

							$wsserver->UpdateClientState($id2);
						}

						$result3 = false;
					}
				}
				else if ($data["cmd"] === "LEAVE")
				{
					if (!isset($data["channel"]))  $result3 = array("channel" => 0, "success" => false, "error" => "The 'channel' is missing.", "errorcode" => "missing_channel");
					else if (!is_numeric($data["channel"]) || !isset($channels[(int)$data["channel"]]) || !isset($client->appdata["channels"][(int)$data["channel"]]))  $result3 = array("channel" => 0, "success" => false, "error" => "The 'channel' is invalid.", "errorcode" => "invalid_channel");
					else
					{
						$channel = (int)$data["channel"];

						$result3 = LeaveChannel($client, $channel);
					}
				}
				else
				{
					if (!isset($data["channel"]))  $result3 = array("channel" => 0, "success" => false, "error" => "The 'channel' is missing.", "errorcode" => "missing_channel");
					else if (!is_numeric($data["channel"]) || !isset($channels[(int)$data["channel"]]) || !isset($client->appdata["channels"][(int)$data["channel"]]))  $result3 = array("channel" => 0, "success" => false, "error" => "The 'channel' is invalid.", "errorcode" => "invalid_channel");
					else
					{
						$channel = (int)$data["channel"];
						$info = $channels[$channel]["clients"][$client->id];

						if ($info["mode"] === CM_RECV_ONLY)  $result3 = array("channel" => $channel, "success" => false, "error" => "Access denied.  Sending is not allowed on this channel.", "errorcode" => "access_denied");
						else if (!isset($data["to"]))  $result3 = array("channel" => $channel, "success" => false, "error" => "The 'to' recipient is missing.", "errorcode" => "missing_to");
						else if (!is_numeric($data["to"]) || ((int)$data["to"] > -1 && !isset($channels[$channel]["clients"][(int)$data["to"]])))  $result3 = array("channel" => $channel, "success" => false, "error" => "The 'to' recipient is invalid.", "errorcode" => "invalid_to");
						else
						{
							$to = (int)$data["to"];
							unset($data["to"]);

							$result3 = array(
								"channel" => $channel,
								"success" => true,
								"from" => $client->id,
								"cmd" => $data["cmd"]
							);

							$result3 = $result3 + $data;

							if ($to > -1)
							{
								$info2 = $channels[$channel]["clients"][$to];

								if ($info["mode"] === CM_SEND_TO_AUTHS && !$info2["auth"])  $result3 = array("channel" => $channel, "success" => false, "error" => "The 'to' recipient is invalid.", "errorcode" => "invalid_to");
								else
								{
									$client2 = $wsserver->GetClient($to);

									if ($client2 === false || $client2->websocket === false)  $result3 = array("channel" => $channel, "success" => false, "error" => "The 'to' recipient is invalid.", "errorcode" => "invalid_to");
									else
									{
										$client2->websocket->Write(json_encode($result3, JSON_UNESCAPED_SLASHES), $result2["data"]["opcode"]);

										$wsserver->UpdateClientState($to);

										$result3 = false;
									}
								}
							}
							else
							{
								// Broadcast the packet to all accessible clients.
								foreach ($channels[$channel]["clients"] as $id2 => $info2)
								{
									$client2 = $wsserver->GetClient($id2);
									if ($client2 === false || $client2->websocket === false)  continue;

									if ($info["mode"] === CM_SEND_TO_AUTHS && !$info2["auth"])  continue;

									$client2->websocket->Write(json_encode($result3, JSON_UNESCAPED_SLASHES), $result2["data"]["opcode"]);

									$wsserver->UpdateClientState($id2);
								}

								$result3 = false;
							}
						}
					}
				}

				// Send the response.
				if ($result3 !== false)
				{
					$ws->Write(json_encode($result3, JSON_UNESCAPED_SLASHES), $result2["data"]["opcode"]);

					$wsserver->UpdateClientState($id);
				}

				$result2 = $ws->Read();
			}
		}

		foreach ($result["removed"] as $id => $result2)
		{
			if ($result2["client"]->appdata !== false)
			{
				echo "WebSocket client ID " . $id . " disconnected.\n";

				// Leave associated channels.
				foreach ($result2["client"]->appdata["channels"] as $channel => $auth)
				{
					LeaveChannel($result2["client"], $channel);
				}
			}
		}

		$ts = time();
		if ($lastservicecheck <= $ts - 3)
		{
			// Remove expired tokens from the token map.
			foreach ($tokenmap as $num => $info)
			{
				if ($ts >= $info["expires"])  unset($tokenmap[$num]);
				else  break;
			}

			// Cleanup RAM.
			if (function_exists("gc_mem_caches"))  gc_mem_caches();

			// Check the status of the two service file options for correct Service Manager integration.
			if (file_exists($stopfilename))
			{
				// Initialize termination.
				echo "Stop requested.\n";

				$running = false;
			}
			else if (file_exists($reloadfilename) && !filesize($reloadfilename))
			{
				// Reload configuration and then remove reload file.
				echo "Reload requested.  Disconnecting all clients and reloading configuration.\n";

				$clients = $wsserver->GetClients();
				foreach ($clients as $client)
				{
					$wsserver->RemoveClient($client->id);

					// Leave associated channels.
					if ($client->appdata !== false)
					{
						foreach ($client->appdata["channels"] as $channel => $auth)
						{
							LeaveChannel($client, $channel);
						}
					}
				}

				$config = DRC_LoadConfig();

				$whitelist = array();
				foreach ($config["whitelist"] as $ipaddr => $protocols)
				{
					$ipaddr = IPAddr::NormalizeIP($ipaddr);
					$whitelist[$ipaddr["shortipv6"]] = $protocols;
				}

				$wsserver->SetAllowedOrigins($config["origins"]);

				file_put_contents($reloadfilename, "DONE");
				@unlink($reloadfilename);
			}

			$lastservicecheck = $ts;
		}
	} while ($running);
?>