<?php
	// Data Relay Center support functions.
	// (C) 2019 CubicleSoft.  All Rights Reserved.

	function DRC_LoadConfig()
	{
		global $rootpath;

		$config = @json_decode(file_get_contents($rootpath . "/config.dat"), true);
		if (!is_array($config))  $config = array();

		$defaults = array(
			"whitelist" => array("127.0.0.1" => true),
			"tokens" => array(),
			"origins" => array()
		);

		return $config + $defaults;
	}
?>