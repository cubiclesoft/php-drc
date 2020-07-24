Data Relay Center (DRC) Javascript Client SDK: 'sdks/js/drc_client.js'
======================================================================

The DRC Javascript client SDK handles clients joining and leaving channels and routing messages to and from clients on a channel.

DRCClient::addEventListener(eventname, callback)
------------------------------------------------

Access:  public

Parameters:

* eventname - A string containing one of 'connect', 'error', 'joined', 'left', 'message', 'unhandled', 'disconnect'.
* callback - A function containing a callback to trigger for the event.

Returns:  Nothing.

This function registers a DOM-style event listener for DRC-specific events.

DRCClient::removeEventListener(eventname, callback)
---------------------------------------------------

Access:  public

Parameters:

* eventname - A string containing one of 'connect', 'error', 'joined', 'left', 'message', 'unhandled', 'disconnect'.
* callback - A function containing a callback to cease triggering for the event.

Returns:  Nothing.

This function unregisters a DOM-style event listener for DRC-specific events.

DRCClient::CreateToken(authtoken, channelname, protocol, clientmode, extra)
---------------------------------------------------------------------------

Access:  public

Parameters:

* authtoken - A boolean of false for no token (e.g. IP whitelist) or a string containing a security token.
* channelname - A string containing the name of the channel the token will be valid for.
* protocol - A string containing the protocol the token will be valid for.
* clientmode - An integer containing one of 0 (CM_RECV_ONLY), 1 (CM_SEND_TO_AUTHS), 2 (CM_SEND_TO_ANY) to control who the client that uses the generated token can send commands to.
* extra - An array containing key-value pairs that will be associated with the client that uses the generated token.

Returns:  Nothing.

This function tells the DRC server to generate a temporary grant token for a specific channel.  Only authorities can generate grant tokens.

DRCClient::JoinChannel(channelname, protocol, authtoken, allowipauth)
---------------------------------------------------------------------

Access:  public

Parameters:

* channelname - A string containing the name of the channel the token will be valid for.
* protocol - A string containing the protocol the token will be valid for.
* authtoken - A boolean of false for no token (e.g. IP whitelist) or a string containing a security or temporary grant token (Default is null and not sent).
* allowipauth - A boolean that indicates whether or not to allow the automatic authority IP address check (Default is null and not sent).  Useful for clients that want to connect as a non-authority.

Returns:  Nothing.

This function joins the specified channel.  A temporary grant token is almost always used in the context of a web browser.

DRCClient::GetChannels()
------------------------

Access:  public

Parameters:  None.

Returns:  An array containing the currently joined channels and clients.

This function returns the list of joined channels and clients.

DRCClient::GetChannel(channel)
------------------------------

Access:  public

Parameters:

* channel - An integer containing a channel number.

Returns:  An array containing the channel information and clients on success, false otherwise.

This function returns information about a specific joined channel and connected clients.

DRCClient::GetClientID()
------------------------

Access:  public

Parameters:  None.

Returns:  An integer containing the client ID on success, false otherwise.

This function returns the client ID of this client.  The client ID is discoverable after joining a channel.

DRCClient::SetExtra(channel, id, extra)
---------------------------------------

Access:  public

Parameters:

* channel - An integer containing a channel number.
* id - An integer containing the client ID to set extra information for.
* extra - An array containing key-value pairs that will be associated with the client.

Returns:  Nothing.

This function sets extra information for the specified client ID.  Only authorities can set extra information.  This is usually done while generating a token but, for example, could be used to change a nickname in a channel.

DRCClient::SendCommand(channel, cmd, to, options = null)
--------------------------------------------------------

Access:  public

Parameters:

* channel - An integer containing a channel number.
* cmd - A string containing the command to send.
* to - An integer containing the client ID to send to or -1 to broadcast the command to all allowed clients.
* options - An array containing additional key-value pairs to send (Default is null).

Returns:  Nothing.

This function sends a command to one or more clients on the channel.  A command is whatever the application wants it to be but should be associated with the protocol used for the channel.  Only authorities and clients with sending privileges can send commands.

DRCClient::GetRandomAuthClientID(channel)
-----------------------------------------

Access:  public

Parameters:

* channel - An integer containing a channel number.

Returns:  An integer containing the ID of an authority client on success, a boolean of false otherwise.

This function selects a random authority client from the available connected clients and returns it.  Useful for distributing load among a set of authorities on a single channel.

DRCClient::SendCommandToAuthClients(channel, cmd, options = null)
-----------------------------------------------------------------

Access:  public

Parameters:

* channel - An integer containing a channel number.
* cmd - A string containing the command to send.
* options - An array containing additional key-value pairs to send (Default is null).

Returns:  Nothing.

This function sends a command to all authority clients on the channel.  A command is whatever the application wants it to be but should be associated with the protocol used for the channel.  Only authorities and clients with sending privileges can send commands.

DRCClient::LeaveChannel(channel)
--------------------------------

Access:  public

Parameters:

* $channel - An integer containing a channel number.

Returns:  Nothing.

This function leaves a channel.
