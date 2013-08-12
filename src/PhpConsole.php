<?php

/**
 * This file is part of the Tracy (https://tracy.nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Tracy;


/**
 * PHP console.
 */
class PhpConsole
{
	const MAGIC = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

	public $context = [];


	public function start(array $context = [])
	{
		$this->context = $context;

		ignore_user_abort(TRUE);
		set_time_limit(0);
		ini_set('html_errors', 0);
		$tracyAssets = __DIR__ . '/../vendor/tracy/tracy/src/Tracy/assets';
		$addr = $_SERVER['SERVER_ADDR'];
		$addr = (strpos($addr, ':') === FALSE ? $addr : "[$addr]") . ':' . rand(30000, 40000);

		ob_start();
		require __DIR__ . '/assets/console.phtml';
		header('Content-Length: ' . ob_get_length());
		header('Connection: close');
		ob_end_flush();
		flush();

		$this->listen($addr);
	}


	public function listen($addr)
	{
		$server = stream_socket_server("tcp://$addr", $errno, $error);
		if (!$server) {
			throw new \Exception("Unable to create server: $error");
		}

		$client = stream_socket_accept($server, 3);
		if ($client) {
			$this->connect($client);
		}
		fclose($server);
	}


	public function connect($client)
	{
		$headers = stream_get_line($client, 65535, "\r\n\r\n");
		if (!preg_match('#^Sec-WebSocket-Key: (\S+)#mi', $headers, $match)) {
			return;
		}
		fwrite($client, "HTTP/1.1 101 Switching Protocols\r\n"
			. "Upgrade: websocket\r\n"
			. "Connection: Upgrade\r\n"
			. "Sec-WebSocket-Accept: " . base64_encode(sha1($match[1] . self::MAGIC, TRUE))
			. "\r\n\r\n"
			. $this->encode('Hello, I am PHP ' . PHP_VERSION));

		for(;;) {
			$s = fread($client, 65535);
			if (!$s) {
				break;
			}
			$s = $this->decode($s);
			$s = $this->response($s);
			fwrite($client, $this->encode($s));
		}

		fclose($client);
	}


	private function response(/*$s*/)
	{
		$_out = '';
		set_error_handler(function($severity, $message) use (& $_out) {
			$_out .= '<i>' . htmlspecialchars($message) . "</i>\n";
		});
		extract($this->context, EXTR_SKIP);
		ob_start();
		try {
			$_res = eval('return ' . func_get_arg(0) . ';');
			$_out .= ob_get_clean() ?: Dumper::toHtml($_res);
		} catch (\Throwable $_ex) {
			$_out .= ob_get_clean()
				. "<i>" . htmlspecialchars(get_class($_ex) . ': ' . $_ex->getMessage()) . "</i>\n";
		}
		restore_error_handler();
		$this->context = get_defined_vars();
		return $_out;
	}


	private function decode($frame)
	{
		$len = ord($frame[1]) & 127;
		if ($len === 126) {
			$ofs = 8;
		} elseif ($len === 127) {
			$ofs = 14;
		} else {
			$ofs = 6;
		}

		$text = '';
		for ($i = $ofs; $i < strlen($frame); $i++) {
			$text .= $frame[$i] ^ $frame[$ofs - 4 + ($i - $ofs) % 4];
		}
		return $text;
	}


	private function encode($text)
	{
		$b = 129; // FIN + text frame
		$len = strlen($text);
		if ($len < 126) {
			return pack('CC', $b, $len) . $text;
		} elseif ($len < 65536) {
			return pack('CCn', $b, 126, $len) . $text;
		} else {
			return pack('CCNN', $b, 127, 0, $len) . $text;
		}
	}

}
