<?php
	// Data Relay Center configuration tool.
	// (C) 2019 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cli.php";
	require_once $rootpath . "/support/drc_functions.php";

	// Process the command-line options.
	$options = array(
		"shortmap" => array(
			"s" => "suppressoutput",
			"?" => "help"
		),
		"rules" => array(
			"suppressoutput" => array("arg" => false),
			"help" => array("arg" => false)
		),
		"allow_opts_after_param" => false
	);
	$args = CLI::ParseCommandLine($options);

	if (isset($args["opts"]["help"]))
	{
		echo "The Data Relay Center (DRC) configuration tool\n";
		echo "Purpose:  Configure DRC and install the DRC system service from the command-line.\n";
		echo "\n";
		echo "This tool is question/answer enabled.  Just running it will provide a guided interface.  It can also be run entirely from the command-line if you know all the answers.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options] [cmdgroup cmd [cmdoptions]]\n";
		echo "Options:\n";
		echo "\t-s   Suppress most output.  Useful for capturing JSON output.\n";
		echo "\n";
		echo "Examples:\n";
		echo "\tphp " . $args["file"] . "\n";
		echo "\tphp " . $args["file"] . " whitelist add 127.0.0.1\n";
		echo "\tphp " . $args["file"] . " tokens add\n";
		echo "\tphp " . $args["file"] . " service install\n";

		exit();
	}

	$origargs = $args;
	$suppressoutput = (isset($args["opts"]["suppressoutput"]) && $args["opts"]["suppressoutput"]);

	// Get the command group.
	$cmdgroups = array(
		"whitelist" => "Manage the IP address authority whitelist",
		"tokens" => "Manage authority security tokens",
		"origins" => "Manage allowed Origin domains",
		"service" => "Manage the system service"
	);

	$cmdgroup = CLI::GetLimitedUserInputWithArgs($args, false, "Command group", false, "Available command groups:", $cmdgroups, true, $suppressoutput);

	// Get the command.
	switch ($cmdgroup)
	{
		case "whitelist":  $cmds = array("list" => "List the authority IP addresses", "add" => "Add an authority IP address", "add-protocol" => "Add an allowed protocol to an authority IP", "remove-protocol" => "Remove a protocol from an authority IP", "remove" => "Remove an authority IP address");  break;
		case "tokens":  $cmds = array("list" => "List the authority security tokens", "add" => "Add an authority security token", "add-protocol" => "Add an allowed protocol to a security token", "remove-protocol" => "Remove a protocol from a security token", "remove" => "Remove an authority security token");  break;
		case "origins":  $cmds = array("list" => "List the allowed origins", "add" => "Add an allowed origin", "remove" => "Remove an origin");  break;
		case "service":  $cmds = array("install" => "Install the system service", "remove" => "Remove the system service");  break;
	}

	if ($cmds !== false)  $cmd = CLI::GetLimitedUserInputWithArgs($args, false, "Command", false, "Available commands:", $cmds, true, $suppressoutput);

	// Load the configuration.
	$config = DRC_LoadConfig();

	function SaveConfig()
	{
		global $rootpath, $config;

		file_put_contents($rootpath . "/config.dat", json_encode($config, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

		chmod($rootpath . "/config.dat", 0640);
	}

	function GetIPAddress()
	{
		global $suppressoutput, $args, $config;

		if ($suppressoutput || CLI::CanGetUserInputWithArgs($args, "ip"))  $ipaddr = CLI::GetUserInputWithArgs($args, "ip", "IP address", false, "", $suppressoutput);
		else
		{
			$addrs = array();
			foreach ($config["whitelist"] as $ipaddr => $protocols)
			{
				$addrs[$ipaddr] = ($protocols === true ? "All protocols" : implode(" | ", $protocols));
			}

			if (!count($addrs))  CLI::DisplayError("No whitelisted IP addresses found.");
			$ipaddr = CLI::GetLimitedUserInputWithArgs($args, "ip", "IP address", false, "Available IP addresses:", $addrs, true, $suppressoutput);
		}

		return $ipaddr;
	}

	function GetSecurityToken()
	{
		global $suppressoutput, $args, $config;

		if ($suppressoutput || CLI::CanGetUserInputWithArgs($args, "token"))  $token = CLI::GetUserInputWithArgs($args, "token", "Token", false, "", $suppressoutput);
		else
		{
			$tokens = array();
			$tokens2 = array();
			foreach ($config["tokens"] as $token => $protocols)
			{
				$tokens[] = $token . "\n" . ($protocols === true ? "All protocols" : implode(" | ", $protocols)) . "\n\n";
				$tokens2[] = $token;
			}

			if (!count($tokens))  CLI::DisplayError("No security tokens found.");
			$num = CLI::GetLimitedUserInputWithArgs($args, "token", "Token", false, "Available tokens:", $tokens, true, $suppressoutput);

			$token = $tokens2[$num];
		}

		return $token;
	}

	if ($cmdgroup === "whitelist")
	{
		if ($cmd === "list")
		{
			$result = array(
				"success" => true,
				"whitelist" => $config["whitelist"]
			);

			CLI::DisplayResult($result);
		}
		else if ($cmd === "add")
		{
			CLI::ReinitArgs($args, array("ip", "proto"));

			$ipaddr = CLI::GetUserInputWithArgs($args, "ip", "IP address", false, "", $suppressoutput);
			$protocol = CLI::GetUserInputWithArgs($args, "proto", "Protocol", "", "Leave blank to allow all protocols", $suppressoutput);

			$config["whitelist"][$ipaddr] = ($protocol === "" ? true : array($protocol));

			SaveConfig();

			$result = array(
				"success" => true,
				"ip" => $ipaddr,
				"protocol" => ($protocol === "" ? true : $protocol)
			);

			CLI::DisplayResult($result);
		}
		else if ($cmd === "add-protocol")
		{
			CLI::ReinitArgs($args, array("ip", "proto"));

			$ipaddr = GetIPAddress();

			$protocol = CLI::GetUserInputWithArgs($args, "proto", "Protocol", "", "Leave blank to allow all protocols", $suppressoutput);

			if ($protocol === "")  $config["whitelist"][$ipaddr] = true;
			else if (!is_array($config["whitelist"][$ipaddr]))  $config["whitelist"][$ipaddr] = true;
			else if (!in_array($protocol, $config["whitelist"][$ipaddr]))  $config["whitelist"][$ipaddr][] = $protocol;

			SaveConfig();

			$result = array(
				"success" => true,
				"ip" => $ipaddr,
				"protocol" => ($protocol === "" ? true : $protocol)
			);

			CLI::DisplayResult($result);
		}
		else if ($cmd === "remove-protocol")
		{
			CLI::ReinitArgs($args, array("ip", "proto"));

			$ipaddr = GetIPAddress();

			if (!is_array($config["whitelist"][$ipaddr]))  $config["whitelist"][$ipaddr] = array();
			else
			{
				if (!count($config["whitelist"][$ipaddr]))  CLI::DisplayError("No protocols found for the IP address.");

				$num = CLI::GetLimitedUserInputWithArgs($args, "proto", "Protocol", false, "Available protocols:", $tokens, true, $suppressoutput);

				array_splice($config["whitelist"][$ipaddr], $num, 1);
			}

			SaveConfig();

			$result = array(
				"success" => true,
				"ip" => $ipaddr
			);

			CLI::DisplayResult($result);
		}
		else if ($cmd === "remove")
		{
			CLI::ReinitArgs($args, array("ip"));

			$ipaddr = GetIPAddress();

			unset($config["whitelist"][$ipaddr]);

			SaveConfig();

			$result = array(
				"success" => true
			);

			CLI::DisplayResult($result);
		}
	}
	else if ($cmdgroup === "tokens")
	{
		if ($cmd === "list")
		{
			$result = array(
				"success" => true,
				"tokens" => $config["tokens"]
			);

			CLI::DisplayResult($result);
		}
		else if ($cmd === "add")
		{
			CLI::ReinitArgs($args, array("proto"));

			require_once $rootpath . "/support/random.php";

			$rng = new CSPRNG();

			$token = $rng->GenerateToken();
			$protocol = CLI::GetUserInputWithArgs($args, "proto", "Protocol", "", "Leave blank to allow all protocols", $suppressoutput);

			$config["tokens"][$token] = ($protocol === "" ? true : array($protocol));

			SaveConfig();

			$result = array(
				"success" => true,
				"token" => $token,
				"protocol" => ($protocol === "" ? true : $protocol)
			);

			CLI::DisplayResult($result);
		}
		else if ($cmd === "add-protocol")
		{
			CLI::ReinitArgs($args, array("token", "proto"));

			$token = GetSecurityToken();

			$protocol = CLI::GetUserInputWithArgs($args, "proto", "Protocol", "", "Leave blank to allow all protocols", $suppressoutput);

			if ($protocol === "")  $config["tokens"][$ipaddr] = true;
			else if (!is_array($config["tokens"][$ipaddr]))  $config["tokens"][$ipaddr] = true;
			else if (!in_array($protocol, $config["tokens"][$ipaddr]))  $config["tokens"][$ipaddr][] = $protocol;

			SaveConfig();

			$result = array(
				"success" => true,
				"token" => $token,
				"protocol" => ($protocol === "" ? true : $protocol)
			);

			CLI::DisplayResult($result);
		}
		else if ($cmd === "remove-protocol")
		{
			CLI::ReinitArgs($args, array("token", "proto"));

			$token = GetSecurityToken();

			if (!is_array($config["tokens"][$ipaddr]))  $config["tokens"][$ipaddr] = array();
			else
			{
				if (!count($config["tokens"][$ipaddr]))  CLI::DisplayError("No protocols found for the authority security token.");

				$num = CLI::GetLimitedUserInputWithArgs($args, "proto", "Protocol", false, "Available protocols:", $tokens, true, $suppressoutput);

				array_splice($config["tokens"][$ipaddr], $num, 1);
			}

			SaveConfig();

			$result = array(
				"success" => true,
				"token" => $token
			);

			CLI::DisplayResult($result);
		}
		else if ($cmd === "remove")
		{
			CLI::ReinitArgs($args, array("token"));

			$token = GetSecurityToken();

			unset($config["tokens"][$token]);

			SaveConfig();

			$result = array(
				"success" => true
			);

			CLI::DisplayResult($result);
		}
	}
	else if ($cmdgroup === "origins")
	{
		if ($cmd === "list")
		{
			$result = array(
				"success" => true,
				"origins" => $config["origins"]
			);

			CLI::DisplayResult($result);
		}
		else if ($cmd === "add")
		{
			CLI::ReinitArgs($args, array("origin"));

			$origin = CLI::GetUserInputWithArgs($args, "origin", "Origin", false, "", $suppressoutput);

			if (!in_array($origin, $config["origins"]))  $config["origins"][] = $origin;

			SaveConfig();

			$result = array(
				"success" => true,
				"origin" => $origin
			);

			CLI::DisplayResult($result);
		}
		else if ($cmd === "remove")
		{
			CLI::ReinitArgs($args, array("origin"));

			if (!count($config["origins"]))  CLI::DisplayError("No origins found.");

			$num = CLI::GetLimitedUserInputWithArgs($args, "origin", "Origin", false, "Available origins:", $config["origins"], true, $suppressoutput);

			array_splice($config["origins"], $num, 1);

			SaveConfig();

			$result = array(
				"success" => true
			);

			CLI::DisplayResult($result);
		}
	}
	else if ($cmdgroup === "service")
	{
		if ($cmd === "install")
		{
			// Verify root on *NIX.
			if (function_exists("posix_geteuid"))
			{
				$uid = posix_geteuid();
				if ($uid !== 0)  CLI::DisplayError("The installer must be run as the 'root' user (UID = 0) to install the system service on *NIX hosts.");

				// Create the system user/group.
				ob_start();
				system("useradd -r -s /bin/false " . escapeshellarg("php-drc"));
				$output = ob_get_contents() . "\n";
				ob_end_clean();
			}

			// Make sure the configuration is readable by the user.
			SaveConfig();

			if (function_exists("posix_geteuid"))  @chgrp($rootpath . "/config.dat", "php-drc");

			// Install the system service.
			$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/server.php") . " install";

			ob_start();
			system($cmd);
			$output .= ob_get_contents();
			ob_end_clean();

			$result = array(
				"success" => true,
				"output" => $output
			);

			CLI::DisplayResult($result);
		}
		else if ($cmd === "remove")
		{
			$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/server.php") . " uninstall";

			ob_start();
			system($cmd);
			$output = ob_get_contents();
			ob_end_clean();

			$result = array(
				"success" => true,
				"output" => $output
			);

			CLI::DisplayResult($result);
		}
	}
?>