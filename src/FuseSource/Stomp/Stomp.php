<?php

namespace FuseSource\Stomp;

use FuseSource\Stomp\Exception\StompException;
use FuseSource\Stomp\Message\Map;

/**
 *
 * Copyright 2005-2006 The Apache Software Foundation
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/* vim: set expandtab tabstop=3 shiftwidth=3: */

/**
 * A Stomp Connection
 *
 *
 * @package Stomp
 * @author Hiram Chirino <hiram@hiramchirino.com>
 * @author Dejan Bosanac <dejan@nighttale.net>
 * @author Michael Caplan <mcaplan@labnet.net>
 * @version $Revision: 43 $
 */
class Stomp
{
    /**
     * Perform request synchronously
     *
     * @var boolean
     */
    public $sync = false;

    /**
     * Default prefetch size
     *
     * @var int
     */
	public $prefetchSize = 1;

	/**
     * Client id used for durable subscriptions
     *
     * @var string
     */
	public $clientId = null;

    /**
    * Vendor flavouring (AMQ or RMQ at the moment)
    */
    public $brokerVendor = 'AMQ';

    protected $_brokerUri = null;
    protected $_socket = null;
    protected $_hosts = array();
    protected $_params = array();
    protected $_subscriptions = array();
    protected $_defaultPort = 61613;
    protected $_currentHost = - 1;
    protected $_attempts = 10;
    protected $_username = '';
    protected $_password = '';
    protected $_sessionId;
    protected $_read_timeout_seconds = 60;
    protected $_read_timeout_milliseconds = 0;
    protected $_connect_timeout_seconds = 60;
    protected $_waitbuf = array();

    /**
     * Constructor
     *
     * @param string $brokerUri Broker URL
     * @throws StompException
     */
    public function __construct ($brokerUri)
    {
        $this->_brokerUri = $brokerUri;
        $this->_init();
    }
    /**
     * Initialize connection
     *
     * @throws StompException
     */
    protected function _init ()
    {
        $pattern = "|^(([a-zA-Z0-9]+)://)+\(*([a-zA-Z0-9\.:/i,-]+)\)*\??([a-zA-Z0-9=&]*)$|i";
        if (preg_match($pattern, $this->_brokerUri, $regs)) {
            $scheme = $regs[2];
            $hosts = $regs[3];
            $params = $regs[4];
            if ($scheme != "failover") {
                $this->_processUrl($this->_brokerUri);
            } else {
                $urls = explode(",", $hosts);
                foreach ($urls as $url) {
                    $this->_processUrl($url);
                }
            }
            if ($params != null) {
                parse_str($params, $this->_params);
            }
        } else {
            throw new StompException("Bad Broker URL {$this->_brokerUri}");
        }
    }
    /**
     * Process broker URL
     *
     * @param string $url Broker URL
     * @throws StompException
     * @return boolean
     */
    protected function _processUrl ($url)
    {
        $parsed = parse_url($url);
        if ($parsed) {
            array_push($this->_hosts, array($parsed['host'] , $parsed['port'] , $parsed['scheme']));
        } else {
            throw new StompException("Bad Broker URL $url");
        }
    }
    /**
     * Make socket connection to the server
     *
     * @throws StompException
     */
    protected function _makeConnection ()
    {
        if (count($this->_hosts) == 0) {
            throw new StompException("No broker defined");
        }

        // force disconnect, if previous established connection exists
        $this->disconnect();

        $i = $this->_currentHost;
        $att = 0;
        $connected = false;
        $connect_errno = null;
        $connect_errstr = null;

        while (! $connected && $att ++ < $this->_attempts) {
            if (isset($this->_params['randomize']) && $this->_params['randomize'] == 'true') {
                $i = rand(0, count($this->_hosts) - 1);
            } else {
                $i = ($i + 1) % count($this->_hosts);
            }
            $broker = $this->_hosts[$i];
            $host = $broker[0];
            $port = $broker[1];
            $scheme = $broker[2];
            if ($port == null) {
                $port = $this->_defaultPort;
            }
            if ($this->_socket != null) {
                fclose($this->_socket);
                $this->_socket = null;
            }
            $this->_socket = @fsockopen($scheme . '://' . $host, $port, $connect_errno, $connect_errstr, $this->_connect_timeout_seconds);
            if (!is_resource($this->_socket) && $att >= $this->_attempts && !array_key_exists($i + 1, $this->_hosts)) {
                throw new StompException("Could not connect to $host:$port ($att/{$this->_attempts})");
            } else if (is_resource($this->_socket)) {
                $connected = true;
                $this->_currentHost = $i;
                break;
            }
        }
        if (! $connected) {
            throw new StompException("Could not connect to a broker");
        }
    }
    /**
     * Connect to server
     *
     * @param string $username
     * @param string $password
     * @return boolean
     * @throws StompException
     */
    public function connect ($username = '', $password = '')
    {
        $this->_makeConnection();
        if ($username != '') {
            $this->_username = $username;
        }
        if ($password != '') {
            $this->_password = $password;
        }
		$headers = array('login' => $this->_username , 'passcode' => $this->_password);
		if ($this->clientId != null) {
			$headers["client-id"] = $this->clientId;
		}
		$frame = new Frame("CONNECT", $headers);
        $this->_writeFrame($frame);
        $frame = $this->readFrame();

        if ($frame instanceof Frame && $frame->command == 'CONNECTED') {
            $this->_sessionId = $frame->headers["session"];
            if (isset($frame->headers['server']) && false !== stristr(trim($frame->headers['server']), 'rabbitmq')) {
                $this->brokerVendor = 'RMQ';
            }
            return true;
        } else {
            if ($frame instanceof Frame) {
                throw new StompException("Unexpected command: {$frame->command}", 0, $frame->body);
            } else {
                throw new StompException("Connection not acknowledged");
            }
        }
    }

    /**
     * Check if client session has ben established
     *
     * @return boolean
     */
    public function isConnected ()
    {
        return !empty($this->_sessionId) && is_resource($this->_socket);
    }
    /**
     * Current stomp session ID
     *
     * @return string
     */
    public function getSessionId()
    {
        return $this->_sessionId;
    }
    /**
     * Send a message to a destination in the messaging system
     *
     * @param string $destination Destination queue
     * @param string|Frame $msg Message
     * @param array $properties
     * @param boolean $sync Perform request synchronously
     * @return boolean
     */
    public function send ($destination, $msg, $properties = array(), $sync = null)
    {
        if ($msg instanceof Frame) {
            $msg->headers['destination'] = $destination;
            if (is_array($properties)) $msg->headers = array_merge($msg->headers, $properties);
            $frame = $msg;
        } else {
            $headers = $properties;
            $headers['destination'] = $destination;
            $frame = new Frame('SEND', $headers, $msg);
        }
        $this->_prepareReceipt($frame, $sync);
        $this->_writeFrame($frame);
        return $this->_waitForReceipt($frame, $sync);
    }
    /**
     * Prepair frame receipt
     *
     * @param Frame $frame
     * @param boolean $sync
     */
    protected function _prepareReceipt (Frame $frame, $sync)
    {
        $receive = $this->sync;
        if ($sync !== null) {
            $receive = $sync;
        }
        if ($receive == true) {
            $frame->headers['receipt'] = md5(microtime());
        }
    }
    /**
     * Wait for receipt
     *
     * @param Frame $frame
     * @param boolean $sync
     * @return boolean
     * @throws StompException
     */
    protected function _waitForReceipt (Frame $frame, $sync)
    {

        $receive = $this->sync;
        if ($sync !== null) {
            $receive = $sync;
        }
        if ($receive == true) {
            $id = (isset($frame->headers['receipt'])) ? $frame->headers['receipt'] : null;
            if ($id == null) {
                return true;
            }

            $buf = array();
            while(true) {
                $frame = $this->readFrame();
                if ($frame == false) {
                    if (!empty($buf)) {
                        $this->_waitbuf = $buf;
                        return false;
                    }
                }
                if ($frame instanceof Frame && $frame->command == 'RECEIPT') {
                    if ($frame->headers['receipt-id'] == $id) {
                        $this->_waitbuf = $buf;
                        return true;
                    } else {
                        throw new StompException("Unexpected receipt id {$frame->headers['receipt-id']}", 0, $frame->body);
                    }
                } else {
                    $buf[] = $frame;
                }
            }
        }
        return true;
    }
    /**
     * Register to listen to a given destination
     *
     * @param string $destination Destination queue
     * @param array $properties
     * @param boolean $sync Perform request synchronously
     * @return boolean
     * @throws StompException
     */
    public function subscribe ($destination, $properties = null, $sync = null)
    {
        $headers = array('ack' => 'client');
        if ($this->brokerVendor == 'AMQ') {
		  $headers['activemq.prefetchSize'] = $this->prefetchSize;
        } else if ($this->brokerVendor == 'RMQ') {
            $headers['prefetch-count'] = $this->prefetchSize;
        }
		
        if ($this->clientId != null) {
            if ($this->brokerVendor == 'AMQ') {
                $headers['activemq.subscriptionName'] = $this->clientId;
            } else if ($this->brokerVendor == 'RMQ') {
                $headers['id'] = $this->clientId;
            }
        }

        if (isset($properties)) {
            foreach ($properties as $name => $value) {
                $headers[$name] = $value;
            }
        }
        $headers['destination'] = $destination;
        $frame = new Frame('SUBSCRIBE', $headers);
        $this->_prepareReceipt($frame, $sync);
        $this->_writeFrame($frame);
        if ($this->_waitForReceipt($frame, $sync) == true) {
            $this->_subscriptions[$destination] = $properties;
            return true;
        } else {
            return false;
        }
    }
    /**
     * Remove an existing subscription
     *
     * @param string $destination
     * @param array $properties
     * @param boolean $sync Perform request synchronously
     * @return boolean
     * @throws StompException
     */
    public function unsubscribe ($destination, $properties = null, $sync = null)
    {
        $headers = array();
        if (isset($properties)) {
            foreach ($properties as $name => $value) {
                $headers[$name] = $value;
            }
        }
        if ($this->clientId != null) {
            if ($this->brokerVendor == 'RMQ') {
                $headers['id'] = $this->clientId;
            }
        }
        $headers['destination'] = $destination;
        $frame = new Frame('UNSUBSCRIBE', $headers);
        $this->_prepareReceipt($frame, $sync);
        $this->_writeFrame($frame);
        if ($this->_waitForReceipt($frame, $sync) == true) {
            unset($this->_subscriptions[$destination]);
            return true;
        } else {
            return false;
        }
    }
    /**
     * Start a transaction
     *
     * @param string $transactionId
     * @param boolean $sync Perform request synchronously
     * @return boolean
     * @throws StompException
     */
    public function begin ($transactionId = null, $sync = null)
    {
        $headers = array();
        if (isset($transactionId)) {
            $headers['transaction'] = $transactionId;
        }
        $frame = new Frame('BEGIN', $headers);
        $this->_prepareReceipt($frame, $sync);
        $this->_writeFrame($frame);
        return $this->_waitForReceipt($frame, $sync);
    }
    /**
     * Commit a transaction in progress
     *
     * @param string $transactionId
     * @param boolean $sync Perform request synchronously
     * @return boolean
     * @throws StompException
     */
    public function commit ($transactionId = null, $sync = null)
    {
        $headers = array();
        if (isset($transactionId)) {
            $headers['transaction'] = $transactionId;
        }
        $frame = new Frame('COMMIT', $headers);
        $this->_prepareReceipt($frame, $sync);
        $this->_writeFrame($frame);
        return $this->_waitForReceipt($frame, $sync);
    }
    /**
     * Roll back a transaction in progress
     *
     * @param string $transactionId
     * @param boolean $sync Perform request synchronously
     */
    public function abort ($transactionId = null, $sync = null)
    {
        $headers = array();
        if (isset($transactionId)) {
            $headers['transaction'] = $transactionId;
        }
        $frame = new Frame('ABORT', $headers);
        $this->_prepareReceipt($frame, $sync);
        $this->_writeFrame($frame);
        return $this->_waitForReceipt($frame, $sync);
    }
    /**
     * Acknowledge consumption of a message from a subscription
	 * Note: This operation is always asynchronous
     *
     * @param string|Frame $messageMessage ID
     * @param string $transactionId
     * @return boolean
     * @throws StompException
     */
    public function ack ($message, $transactionId = null)
    {
        if ($message instanceof Frame) {
            $headers = $message->headers;
            if (isset($transactionId)) {
                $headers['transaction'] = $transactionId;
            }

            if ($this->brokerVendor == 'RMQ') {
                unset($headers['content-length']);
            }
            $frame = new Frame('ACK', $headers);
            $this->_writeFrame($frame);
            return true;
        } else {
            $headers = array();
            if (isset($transactionId)) {
                $headers['transaction'] = $transactionId;
            }
            $headers['message-id'] = $message;
            $frame = new Frame('ACK', $headers);
            $this->_writeFrame($frame);
            return true;
        }
    }
    /**
     * Graceful disconnect from the server
     *
     */
    public function disconnect ()
    {
		$headers = array();

		if ($this->clientId != null) {
			$headers["client-id"] = $this->clientId;
		}

        if (is_resource($this->_socket)) {
            $this->_writeFrame(new Frame('DISCONNECT', $headers));
            fclose($this->_socket);
        }
        $this->_socket = null;
        $this->_sessionId = null;
        $this->_currentHost = -1;
        $this->_subscriptions = array();
        $this->_username = '';
        $this->_password = '';
        $this->_waitbuf = array();
    }
    /**
     * Write frame to server
     *
     * @param Frame $stompFrame
     */
    protected function _writeFrame (Frame $stompFrame)
    {
        if (!is_resource($this->_socket)) {
            throw new StompException('Socket connection hasn\'t been established');
        }

        $data = $stompFrame->__toString();
        $r = fwrite($this->_socket, $data, strlen($data));
        if ($r === false || $r == 0) {
            $this->_reconnect();
            $this->_writeFrame($stompFrame);
        }
    }

    /**
     * Set timeout to wait for content to read
     *
     * @param int $seconds_to_wait  Seconds to wait for a frame
     * @param int $milliseconds Milliseconds to wait for a frame
     */
    public function setReadTimeout($seconds, $milliseconds = 0)
    {
        $this->_read_timeout_seconds = $seconds;
        $this->_read_timeout_milliseconds = $milliseconds;
    }

    /**
     * Read response frame from server
     *
     * @return Frame False when no frame to read
     */
    public function readFrame ()
    {
        if (!empty($this->_waitbuf)) {
            return array_shift($this->_waitbuf);
        }

        if (!$this->hasFrameToRead()) {
            return false;
        }

        $rb = 1024;
        $data = '';
        $end = false;

        do {
            $read = fgets($this->_socket, $rb);
            if ($read === false || $read === "") {
                $this->_reconnect();
                return $this->readFrame();
            }
            $data .= $read;
            if (strpos($data, "\x00") !== false) {
                $end = true;
                $data = trim($data, "\n");
            }
            $len = strlen($data);
        } while ($len < 2 || $end == false);

        list ($header, $body) = explode("\n\n", $data, 2);
        $header = explode("\n", $header);
        $headers = array();
        $command = null;
        foreach ($header as $v) {
            if (isset($command)) {
                list ($name, $value) = explode(':', $v, 2);
                $headers[$name] = $value;
            } else {
                $command = $v;
            }
        }
        $frame = new Frame($command, $headers, trim($body));
        if (isset($frame->headers['transformation']) && $frame->headers['transformation'] == 'jms-map-json') {
            return new Map($frame);
        } else {
            return $frame;
        }
        return $frame;
    }

    /**
     * Check if there is a frame to read
     *
     * @return boolean
     */
    public function hasFrameToRead()
    {
        $read = array($this->_socket);
        $write = null;
        $except = null;

        $has_frame_to_read = @stream_select($read, $write, $except, $this->_read_timeout_seconds, $this->_read_timeout_milliseconds);

        if ($has_frame_to_read !== false)
            $has_frame_to_read = count($read);


        if ($has_frame_to_read === false) {
            throw new StompException('Check failed to determine if the socket is readable');
        } else if ($has_frame_to_read > 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Reconnects and renews subscriptions (if there were any)
     * Call this method when you detect connection problems
     */
    protected function _reconnect ()
    {
        $subscriptions = $this->_subscriptions;

        $this->connect($this->_username, $this->_password);
        foreach ($subscriptions as $dest => $properties) {
            $this->subscribe($dest, $properties);
        }
    }
    /**
     * Graceful object desruction
     *
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
