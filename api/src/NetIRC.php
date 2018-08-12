<?php namespace IRC;

ini_set('display_errors', 1);
error_reporting(E_ALL);
class NetIRC {
    /**
     * Associative array containing the options for this IRC instance
     *   'server'    => 'localhost'       // The server to connect to
     *   'port'      => 6667,             // The port of the IRC server
     *   'nick'      => 'Net_IRC',        // The nick for the client
     *   'realname'  => 'Net_IRC Bot',    // The real name for the client
     *   'identd'    => 'myident',        // The identd for the client
     *   'host'      => '10.10.11.2',     // The host of the client
     *   'log_types' => array(0, 1, 2, 3) // The type of logs
     *
     * @var array $options
     * @see Net_IRC::log_types
     */
    var $options   = array();

    /**
     * logging error types
     * 0 fatal
     * 1 warning
     * 2 notice
     * 3 informative
     * 4 debug
     * 5 debug++
     * @var array $log_types
     * @see Net_IRC::logTypes()
     */
    var $log_types = array(0, 1, 2, 3, 4);

    var $buffer    = array();

    /**
     * Array with statistics about the connection
     *
     * @var array $stats
     */
    var $stats     = array();

    /**
     * Connects to a IRC server. This method will opens the socket to the server,
     * issue a USER and NICK command, wait for the MOTD message and call the
     * even_connect() method if exists
     *
     * @param array $options The parameters of the connection
     * @see Net_IRC::options
     * @return true or false
     */
    function connect($options) {
        // XXX Check options
        if (isset($options['log_types'])) {
            $this->logTypes($options['log_types']);
        }

        $this->log(3, "connecting to {$options['server']}:{$options['port']}");
        $sd = fsockopen($options['server'], $options['port'],
            $errno, $errstr, 5);
        if (!$sd) {
            $this->log(0, "could not connect $errstr ($errno)");
            return false;
        }
        $this->log(3, "connected");
        $this->socket = $sd;
        $this->initStats();
        $this->command('USER '.
            $options['identd'] . ' '.
            $options['host']   . ' '.
            $options['server'] . ' '.
            ':' . $options['realname']);
        $this->command('NICK ' . $options['nick']);
        while($this->readEvent(true) == 'MOTD');
        socket_set_blocking($sd, false);
        $this->callback('CONNECT', false);
        $this->options = $options;
        return true;
    }


    function log($level, $message) {
        if (in_array($level, $this->log_types)) {
            print date('H:i:s') . " " . trim($message) . "\n";
            flush();
        }
    }

    /**
     * Disconnects from a IRC by sending the QUIT command
     */
    function disconnect() {
        $this->command('QUIT');
        // XXX This seems not to work, analyze why
        //fclose($this->socket);
        //$this->socket = null;
    }
    function isConnected() {
        return !feof($this->socket);
    }
    /**
     * Transform code messages returned by the server to text events
     *
     * @param int    $code  Search the value for a code
     * @param string $value Search the code for a value
     */
    function getEvent($code = null, $handler = null) {
        static $events;
        if (empty($events)) {
            $events = array(
                376 => 'MOTD',
                422 => 'MOTD',
                366 => 'NAMES',
                318 => 'WHOIS',
                433 => 'ERR_NICKNAMEINUSE'
            );
        }
        if ($code) {
            return isset($events[$code]) ? $events[$code] : $code;
        }
        foreach ($events as $k => $e) {
            if ($handler == $e) {
                return $events[$k];
            }
        }
        return false;
    }
    /**
     * Send a IRC command to the server
     *
     * @param string $command The full command for sending
     * @return bool True or false depending on the write() response
     * @see Net_IRC::write()
     */
    function command($command) {
        return $this->write(trim($command));
    }
    /**
     * Writes a command to the openned socket
     *
     * @param string $command The full command for sending
     * @return bool True on success or False if the socket is not open
     */
    function write($command) {
        if (feof($this->socket)) {
            $this->log(0, 'Write Disconnected');
            $this->callback('DISCONNECT', false);
            return false;
        }
        if ($command && !fputs($this->socket, $command . "\r\n")) {
            $this->log(1, "could not write to socket");
            return false;
        } else {
            $this->log(4, "<- $command");
            $this->updateStats('tx');
        }
        return true;
    }
    
    /**
     * Reads from the socket
     *
     * @param bool $once Wait until there is info to read
     * @return mixed False on socket error, null on no data, string the data
     */
    function read($block = false) {
        if (feof($this->socket)) {
            $this->log(0, 'Read Disconnected');
            $this->callback('DISCONNECT', false);
            return false;
        }
        do {
            $receive = rtrim(fgets($this->socket, 1024));
            if (!$receive) {
                if (!$block) {
                    return null;
                } else {
                    usleep(500000); // Half second is enough interactive
                    continue;
                }
            } else {
                $this->updateStats('rx');
                $this->log(4, "-> $receive");
            }
        } while (!$receive);
        return $receive;
    }
    /**
     * Reads from the socket and call the event handler. It will automatically
     * return server PINGs
     *
     * @param bool $block When TRUE it will read until there is data
     * @return mixed  - FALSE on socket read errors
     *                 - NULL on no data in the socket (only when $block=false)
     *                 - STRING the event called
     */
    function readEvent($block = false) {
        while ($response = $this->read($block)) {
            $result = $this->parseResponse($response);
            $event  = $result[0];
            if (is_numeric($event)) {
                $event = $this->getEvent($event);
            }
            $this->updateEventStats($event, $result[1]);
            $this->callback($event, $result[1]);
            // Automatically answer server PINGs
            if ($event == 'PING') {
                continue;
            } else {
                break;
            }
        }
        // read() can return the response or null on no response. False means
        // error on socket read
        switch ($response) {
            case null:  return true;
            case false: return false;
            default:    return $event;
        }
    }
    /**
     * Loop forever reading server commands and calling the properly event handlers
     */
    function loopRead() {
        while($this->readEvent(true));
    }
    /**
     * Parse a message comming from the IRC server. It will always return
     * this data structure:
     *
     * array($command, array($origin, $orighost, $target, $params))
     *
     *   $command  -> The command
     *   $origin   -> The nick which sents the command
     *   $orighost -> The full identd of the host which sent the command
     *   $target   -> The destination of the message
     *   $params   -> The rest of the IRC message
     *
     * The value of some of this params may be null depending on the response
     *
     * @param string $response The message returned sent from the server
     * @return array The parsed response
     */
    function parseResponse($response) {
        /*
         <message>  ::= [':' <prefix> <SPACE> ] <command> <params> <crlf>
                          $origin!$orighost $command $target $params
        */
        $message = explode(' ', $response, 2);
        if ($message[0]{0} == ':') {
            // parse prefix
            $prefix = substr($message[0], 1);
            if (strpos($prefix, '!') !== false) {
                list($origin, $orighost) = explode('!', $prefix, 2);
            } else {
                $origin   = $prefix;
                $orighost = null;
            }
            list($command, $rest) = explode(' ', $message[1], 2);
            // foo :bar
            if (strpos($rest, ' :') !== false) {
                list($target, $params) = explode(' :', $rest, 2);
                // :bar
            } elseif ($rest{0} == ':') {
                $target = substr($rest, 1);
                $params = null;
                // foo
            } else {
                $target = $rest;
                $params = null;
            }
        } else {
            $origin   = null;
            $orighost = null;
            $command  = $message[0];
            $target   = null;
            $params   = substr($message[1], 1);
        }
        return array($command, array($origin, $orighost, $target, $params));
    }
    /**
     * Calls the function that handles the given command (in the form:
     * "event_$command"). If the function is not present, will call
     * the "fallback" function, which has always to be present.
     *
     * @param string $command One word with the function to call
     * @param array  $params  The params for the event handler
     */
    function callback($command, $params = array()) {
        $method = "event_$command";
        if (method_exists($this, $method)) {
            $this->log(5, "Calling callback $method");
            return call_user_func_array(array(&$this, $method), $params);
        }
        if ($params) {
            $this->log(4, "Method $method not provided, calling fallback");
            return call_user_func_array(array(&$this, 'fallback'), $params);
        }
    }
    /**
     * Updates the internal stats
     *
     * @param string type 'rx' or 'tx'
     * @access private
     */
    // XXX rename to _updateStats()
    function updateStats($type = 'rx') {
        $this->stats[$type . '_idle_since'] = time();
    }
    /**
     * Updates the stats for a certain event
     *
     * @access private
     */
    // XXX This should be enhanced to be able to track the different
    //     kinds of flood attacks (maybe in a different class)
    // XXX Introduce stats levels (none, normal, full)
    function updateEventStats($event, $args = array()) {
        $this->log(5, "Updating event $event");
        if (!isset($this->stats['events'][$event])) {
            $this->stats['events'][$event] = array();
            $this->stats['events'][$event]['times']    = 1;
            $this->stats['events'][$event]['interval'] = 1;
            $this->stats['events'][$event]['last'] = time();
            return;
        }
        $event = &$this->stats['events'][$event];
        $event['times'] += 1;
        // XXX make a configurable param
        $int = 60;
        if ((time() - $event['last']) < $int) {
            $event['interval'] += 1;
        } else {
            $event['interval'] = 0;
        }
        $this->log(5, "event interval: " . $event['interval']);
        array_pop($args);
        foreach ($args as $k => $v) {
            if ($v) {
                $event[$k][$v] = isset($event[$k][$v]) ? $event[$k][$v] + 1 : 1;
                // To avoid stats flooding we only track the last 30 different ones
                if (count($event[$k]) > 30) {
                    $this->log(5, "Dropping key ($k) param");
                    array_shift($event[$k]);
                }
            }
        }
        $event['last'] = time();
    }

    /**
     * Initialize the stats
     *
     * @access private
     */
    // XXX Rename to _initStats()
    function initStats() {
        $this->stats['started'] = time();
        $this->stats['rx_idle'] = 0;
        $this->stats['rx_idle_since'] = time();
        $this->stats['tx_idle'] = 0;
        $this->stats['tx_idle_since'] = time();
        $this->stats['events']  = array();
    }

    /**
     * Get the internal stats of the connection. Params accepted for $label:
     * - rx_idle:      The seconds passed since the last time we received a
     *                 message from the server
     * - rx_idle_since: The last date (timestamp) we received a message from the server
     * - tx_idle       The seconds since the last time we sent a message
     * - tx_idle_since: The last time we sent a message to the server
     * - started:      The date (timestamp) when the socket was openned
     * - running:      The amount of seconds since the start time
     *
     * @param mixed $label The string label to return or null for retuning
     *                      the full stats array
     * @return mixed Array or int depending on the $label parameter
     */
    function getStats($label = null) {
        $this->stats['rx_idle'] = time() - $this->stats['rx_idle_since'];
        $this->stats['tx_idle'] = time() - $this->stats['tx_idle_since'];
        $this->stats['running'] = time() - $this->stats['started'];
        if ($label) {
            return isset($this->stats[$label]) ? $this->stats[$label] : false;
        }
        return $this->stats;
    }

    /**
     * Sets which type of messages will be logged
     *
     * @param mixed $codes int one code or array multiple codes
     */
    function logTypes($codes = array()) {
        settype($codes, 'array');
        $this->log_types = $codes;
    }

    /**
     * Gets an option of this instance
     *
     * @param  string $options The option to retrieve
     * @return string The value of the option
     * @see Net_IRC::options
     */
    function getOption($option) {
        return isset($this->options[$option]) ? $this->options[$option] : null;
    }

    /**
     * Method to feed the class with external information we want to
     * access from inside (for avoiding the "global" uglyness)
     *
     */
    function setExtra($extra) {
        $this->extra = $extra;
    }

    function getExtra($label = null) {
        if ($label) {
            return isset($this->extra[$label]) ? $this->extra[$label] : null;
        }
        return $this->extra;
    }
}

$n = new NetIRC();
$n->connect(array(
    'server'    => 'irc.lycaeum.fun',       // The server to connect to
    'port'      => 6667,             // The port of the IRC server
    'nick'      => 'towbot',        // The nick for the client
    'realname'  => 'towbot',    // The real name for the client
    'identd'    => 'towbot',        // The identd for the client
    'host'      => 'irc.lycaeum.fun',     // The host of the client
    'log_types' => 0 // The type of logs
));

do {
    $line = readline("> ");
    $n->write($line);
} while ($line != '!quit');