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

	// Main loop.
	$result = $drc->Wait();
	while ($result["success"])
	{
		do
		{
			$result = $drc->Read();
			if (!$result["success"])  break;

			if ($result["data"] !== false)
			{
				// Do something with the data.
				echo "Raw message from server:\n";
				var_dump($result["data"]);
				echo "\n";
			}
		} while ($result["data"] !== false);

		$result = $drc->Wait();
	}

	// An error occurred.
	var_dump($result);
?>