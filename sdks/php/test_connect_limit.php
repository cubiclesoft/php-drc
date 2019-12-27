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

	$clients = array();
	do
	{
		$drc = new DRCClient();

		// Connect to the server.
		// Note that the origin must be in the origins list or the call will fail with a 403 Forbidden response.
echo "Connecting...";
		$result = $drc->Connect("ws://127.0.0.1:7328", "http://127.0.0.1");
		if (!$result["success"])
		{
			var_dump($result);

			break;
		}
echo "Connected...";

		// Join the channel.
		$result = $drc->JoinChannel("Test-Channel", "test-client", false, true);
		if (!$result["success"])
		{
			var_dump($result);

			break;
		}
echo "Joined channel.\n";

		$channel = $result["data"]["channel"];

		$clientid = $drc->GetClientID();
		echo "Connected as client ID " . $clientid . "\n";

		$num = 0;
		foreach ($clients as $drc2)
		{
			$drc2->Wait(0);

			do
			{
				$result = $drc2->Read();
				if (!$result["success"])  break;

				if ($result["data"] !== false)
				{
					// Do something with the data.
//					echo "Raw message from server:\n";
//					var_dump($result["data"]);
//					echo "\n";

					$num++;
				}
			} while ($result["data"] !== false);
		}

		if ($num)  echo "Received " . $num . " packets.\n";

		$clients[] = $drc;
	} while (1);

	echo "Total clients:  " . count($clients) . "\n";
?>