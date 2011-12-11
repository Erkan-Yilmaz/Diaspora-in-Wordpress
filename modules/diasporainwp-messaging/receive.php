<?php

	$log = '';

	function decrypt_data($data, $iv, $key) {
		$cypher = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '', MCRYPT_MODE_CBC, '');

		// initialize encryption handle
		if (mcrypt_generic_init($cypher, $key, $iv) != -1) {
			// decrypt
			$decrypted = mdecrypt_generic($cypher, $data);

			// clean up
			mcrypt_generic_deinit($cypher);
			mcrypt_module_close($cypher);

			return $decrypted;
		}

		return false;
	}

	$post = $_POST['xml'];
	$xml = trim(urldecode($post));
	
	$log .= "xml: $xml\n";
	
	$dom = new DOMDocument();
	$me_ns = "http://salmon-protocol.org/ns/magic-env";
	$dom->loadXML($xml);
	$encrypted_header = $dom->getElementsByTagName("encrypted_header")->item(0)->nodeValue;
	$data = $dom->getElementsByTagNameNS($me_ns, "data")->item(0)->nodeValue;
	$sig = $dom->getElementsByTagNameNS($me_ns, "sig")->item(0)->nodeValue;

	$encrypted_header = json_decode(base64_decode($encrypted_header));

	$aes_key = base64_decode($encrypted_header->aes_key);
	$private_key = openssl_pkey_get_private(get_user_meta(1, "diasporawp_private_key", true));
	$decrypted = "";
	openssl_private_decrypt($aes_key, &$decrypted, $private_key); 
	$aes_key = json_decode($decrypted);

	$log .= "decrypted: $decrypted\n";

	$decrypted_header = decrypt_data(
		base64_decode($encrypted_header->ciphertext),
		base64_decode($aes_key->iv), 
		base64_decode($aes_key->key));

	$log .= "decrypted_header: $decrypted_header\n";

	$dom = new DOMDocument();
	$dom->loadXML($decrypted_header);
	$iv = $dom->getElementsByTagName("iv")->item(0)->nodeValue;
	$aes_key = $dom->getElementsByTagName("aes_key")->item(0)->nodeValue;

	$data = decrypt_data(
		base64_decode(base64_decode($data)),
		base64_decode($iv), 
		base64_decode($aes_key));

	$log .= "data: $data\n";

	$startpos = strpos($data, "<XML>");
	$endpos = strpos($data, "</XML>");

	$data = substr($data, $startpos + 5, $endpos - $startpos - 5);

	global $wpdb;
	$table_name = $wpdb->prefix . "diw_contacts";
	
	$dom = new DOMDocument();
	$dom->loadXML($data);
	$elements = $dom->getElementsByTagName("request");
	if( $elements->length > 0 ) {
		$item = $elements->item(0);
		$sender_handle = $item->getElementsByTagName("sender_handle")->item(0)->nodeValue;
		//echo "sender_handle: $sender_handle<br/>";
		$wpdb->insert($table_name, array('diaspora_handle' => $sender_handle));
		$log .= "add contact: $sender_handle\n";
	}
	$elements = $dom->getElementsByTagName("retraction");
	if( $elements->length > 0 ) {
		$item = $elements->item(0);
		$sender_handle = $item->getElementsByTagName("diaspora_handle")->item(0)->nodeValue;
		//echo "sender_handle: $sender_handle<br/>";
		$wpdb->query("DELETE FROM $table_name WHERE diaspora_handle='$sender_handle';");
		$log .= "remove contact: $sender_handle\n";
	}

	file_put_contents('log.txt', $log, FILE_APPEND);

?>