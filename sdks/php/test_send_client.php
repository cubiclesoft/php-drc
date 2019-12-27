<?php
	// Data Relay Center example PHP client.
	// (C) 2019 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/sdk_drc_client.php";

	$drc = new DRCClient();

	// Connect to the server.
	// Note that the origin must be in the origins list or the call will fail with a 403 Forbidden response.
	$result = $drc->Connect("ws://127.0.0.1:7328", "http://127.0.0.1");
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	// Create a grant token.
	$result = $drc->CreateToken(false, "Test-Channel", "test-client", DRCClient::CM_SEND_TO_AUTHS, array("user" => "john_doe"), true);
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	echo "Token granted:  " . $result["data"]["token"] . "\n";

	// Join the channel.
	$result = $drc->JoinChannel("Test-Channel", "test-client", false, true);
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	$channel = $result["data"]["channel"];

	$clientid = $drc->GetClientID();
	echo "Connected as client ID " . $clientid . "\n";

	// Set the extra data for this client.
	$result = $drc->SetExtra($channel, $clientid, array("node" => "master-1"), true);
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}
var_dump($result);

	// Broadcast a command to all clients.
	$result = $drc->SendCommand($channel, "action_results", -1, array("status" => "it_worked", "owner" => "joe_schmoe"), true);
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	echo "Sent.\n";
?>