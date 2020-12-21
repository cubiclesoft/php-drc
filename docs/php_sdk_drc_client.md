Data Relay Center (DRC) PHP Client SDK: 'sdks/php/support/sdk_drc_client.php'
=============================================================================

The DRC PHP client SDK extends the [CubicleSoft WebSocket class](https://github.com/cubiclesoft/ultimate-web-scraper/blob/master/docs/websocket.md) to handle clients joining and leaving channels and routing messages to clients on a channel.

DRCClient::Reset()
------------------

Access:  public

Parameters:  None.

Returns:  Nothing.

This function calls the parent Reset() function and initializes the class for tracking channel information.

DRCClient::Read($finished = true, $wait = false)
------------------------------------------------

Access:  public

Parameters:

* $finished - A boolean indicating whether or not to return finished messages (default is true).
* $wait - A boolean indicating whether or not to wait until a message has arrived that meets the rest of the function call criteria (default is false).

Returns:  An array containing the results of the call.

This function calls the parent Read() function, decodes the response data from the DRC server as JSON, and processes channel joins and leaves.

DRCClient::CreateToken($authtoken, $channelname, $protocol, $clientmode, $extra = array(), $wait = false, $makeauth = false)
----------------------------------------------------------------------------------------------------------------------------

Access:  public

Parameters:

* $authtoken - A boolean of false for no token (e.g. IP whitelist) or a string containing a security token.
* $channelname - A string containing the name of the channel the token will be valid for.
* $protocol - A string containing the protocol the token will be valid for.
* $clientmode - An integer containing one of DRCClient::CM_RECV_ONLY, DRCClient::CM_SEND_TO_AUTHS, DRCClient::CM_SEND_TO_ANY to control who the client that uses the generated token can send commands to.
* $extra - An array containing key-value pairs that will be associated with the client that uses the generated token (Default is array()).
* $wait - A boolean that indicates whether or not to wait for completion or an integer containing the number of seconds for a timeout (Default is false).
* $makeauth - A boolean that indicates whether or not to make the generated token an authority for the channel (Default is false).

Returns:  A standard array of information.

This function tells the DRC server to generate a temporary grant token for a specific channel.  Only permanent authorities can generate grant tokens.  That is, granted authorities via makeauth can't use their temporary token to generate grant tokens.

DRCClient::JoinChannel($channelname, $protocol, $token, $wait = false, $allowipauth = true)
-------------------------------------------------------------------------------------------

Access:  public

Parameters:

* $channelname - A string containing the name of the channel the token will be valid for.
* $protocol - A string containing the protocol the token will be valid for.
* $token - A boolean of false for no token (e.g. IP whitelist) or a string containing a security or temporary grant token.
* $wait - A boolean that indicates whether or not to wait for completion or an integer containing the number of seconds for a timeout (Default is false).
* $allowipauth - A boolean that indicates whether or not to allow the automatic authority IP address check (Default is true).  Useful for clients that want to connect as a non-authority.

Returns:  A standard array of information.

This function joins the specified channel.

DRCClient::GetChannels()
------------------------

Access:  public

Parameters:  None.

Returns:  An array containing the currently joined channels and clients.

This function returns the list of joined channels and clients.

DRCClient::GetChannel($channel)
-------------------------------

Access:  public

Parameters:

* $channel - An integer containing a channel number.

Returns:  An array containing the channel information and clients on success, false otherwise.

This function returns information about a specific joined channel and connected clients.

DRCClient::GetClientID()
------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the client ID on success, false otherwise.

This function returns the client ID of this client.  The client ID is discoverable after joining a channel.

DRCClient::SetExtra($channel, $id, $extra = array(), $wait = false)
-------------------------------------------------------------------

Access:  public

Parameters:

* $channel - An integer containing a channel number.
* $id - An integer containing the client ID to set extra information for.
* $extra - An array containing key-value pairs that will be associated with the client (Default is array()).
* $wait - A boolean that indicates whether or not to wait for completion or an integer containing the number of seconds for a timeout (Default is false).

Returns:  A standard array of information.

This function sets extra information for the specified client ID.  Only authorities can set extra information.  This is usually done while generating a token but, for example, could be used to change a nickname in a channel.

DRCClient::SendCommand($channel, $cmd, $to, $options = array(), $wait = false, $waitcmd = false)
------------------------------------------------------------------------------------------------

Access:  public

Parameters:

* $channel - An integer containing a channel number.
* $cmd - A string containing the command to send.
* $to - An integer containing the client ID to send to or -1 to broadcast the command to all allowed clients.
* $options - An array containing additional key-value pairs to send (Default is array()).
* $wait - A boolean that indicates whether or not to wait for completion or an integer containing the number of seconds for a timeout (Default is false).
* $waitcmd - A string containing a command to wait for in response or a boolean of false to just send the command (Default is false).

Returns:  A standard array of information.

This function sends a command to one or more clients on the channel.  A command is whatever the application wants it to be but should be associated with the protocol used for the channel.  Only authorities and clients with sending privileges can send commands.

DRCClient::GetRandomAuthClientID($channel)
------------------------------------------

Access:  public

Parameters:

* $channel - An integer containing a channel number.

Returns:  An integer containing the ID of an authority client on success, a boolean of false otherwise.

This function selects a random authority client from the available connected clients and returns it.  Useful for distributing load among a set of authorities on a single channel.

DRCClient::SendCommandToAuthClients($channel, $cmd, $options = array(), $wait = false)
--------------------------------------------------------------------------------------

Access:  public

Parameters:

* $channel - An integer containing a channel number.
* $cmd - A string containing the command to send.
* $options - An array containing additional key-value pairs to send (Default is array()).
* $wait - A boolean that indicates whether or not to wait for completion or an integer containing the number of seconds for a timeout (Default is false).

Returns:  A standard array of information.

This function sends a command to all authority clients on the channel.  A command is whatever the application wants it to be but should be associated with the protocol used for the channel.  Only authorities and clients with sending privileges can send commands.

DRCClient::LeaveChannel($channel, $wait = false)
------------------------------------------------

Access:  public

Parameters:

* $channel - An integer containing a channel number.
* $wait - A boolean that indicates whether or not to wait for completion or an integer containing the number of seconds for a timeout (Default is false).

Returns:  A standard array of information.

This function leaves a channel.
