<?php
/*
Copyright 2009 Dominic Sayers
	dominic_sayers@hotmail.com
	http://www.dominicsayers.com

Version 0.5

This source file is subject to the Common Public Attribution License Version 1.0 (CPAL) license.
The license terms are available through the world-wide-web at http://www.opensource.org/licenses/cpal_1.0
*/
function is_email ($email, $checkDNS = false) {
	//	Check that $email is a valid address
	//		(http://tools.ietf.org/html/rfc3696)
	//		(http://tools.ietf.org/html/rfc5322#section-3.4.1)
	//		(http://tools.ietf.org/html/rfc5321#section-4.1.3)
	//		(http://tools.ietf.org/html/rfc4291#section-2.2)
	//		(http://tools.ietf.org/html/rfc1123#section-2.1)

	//	the upper limit on address lengths should normally be considered to be 256
	//		(http://www.rfc-editor.org/errata_search.php?rfc=3696)
	if (strlen($email) > 256)	return false;	//	Too long

	//	Contemporary email addresses consist of a "local part" separated from
	//	a "domain part" (a fully-qualified domain name) by an at-sign ("@").
	//		(http://tools.ietf.org/html/rfc3696#section-3)
	$index = strrpos($email,'@');

	if ($index === false)		return false;	//	No at-sign
	if ($index === 0)			return false;	//	No local part
	if ($index > 64)			return false;	//	Local part too long

	$localPart		= substr($email, 0, $index);
	$domain			= substr($email, $index + 1);
	$domainLength	= strlen($domain);
	
	if ($domainLength === 0)	return false;	//	No domain part
	if ($domainLength > 255)	return false;	//	Domain part too long

	//	Let's check the local part for RFC compliance...
	//
	//	Period (".") may...appear, but may not be used to start or end the
	//	local part, nor may two or more consecutive periods appear.
	//		(http://tools.ietf.org/html/rfc3696#section-3)
	if (preg_match('/^\\.|\\.\\.|\\.$/', $localPart) > 0)				return false;	//	Dots in wrong place

	//	Any ASCII graphic (printing) character other than the
	//	at-sign ("@"), backslash, double quote, comma, or square brackets may
	//	appear without quoting.  If any of that list of excluded characters
	//	are to appear, they must be quoted
	//		(http://tools.ietf.org/html/rfc3696#section-3)
	if (preg_match('/^"(?:.)*"$/', $localPart) > 0) {
		//	Local part is a quoted string
		if (preg_match('/(?:.)+[^\\\\]"(?:.)+/', $localPart) > 0)		return false;	//	Unescaped quote character inside quoted string
	} else {
		if (preg_match('/[ @\\[\\]\\\\",]/', $localPart) > 0)
			//	Check all excluded characters are escaped
			$stripped = preg_replace('/\\\\[ @\\[\\]\\\\",]/', '', $localPart);
			if (preg_match('/[ @\\[\\]\\\\",]/', $stripped) > 0)		return false;	//	Unquoted excluded characters
	}

	//	Now let's check the domain part...

	//	The domain name can also be replaced by an IP address in square brackets
	//		(http://tools.ietf.org/html/rfc3696#section-3)
	//		(http://tools.ietf.org/html/rfc5321#section-4.1.3)
	//		(http://tools.ietf.org/html/rfc4291#section-2.2)
	if (preg_match('/^\\[(.)+]$/', $domain) === 1) {
		//	It's an address-literal
		$addressLiteral = substr($domain, 1, $domainLength - 2);
		$matchesIP		= array();
		
		//	Extract IPv4 part from the end of the address-literal (if there is one)
		if (preg_match('/\\b(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/', $addressLiteral, $matchesIP) > 0) {
			$index = strrpos($addressLiteral, $matchesIP[0]);
			
			if ($index === 0) {
				//	Nothing there except a valid IPv4 address, so...
				return true;
			} else {
				//	Assume it's an attempt at a mixed address (IPv6 + IPv4)
				if ($addressLiteral[$index - 1] !== ':')			return false;	//	Character preceding IPv4 address must be ':'
				if (substr($addressLiteral, 0, 5) !== 'IPv6:')		return false;	//	RFC5321 section 4.1.3

				$IPv6 = substr($addressLiteral, 5, ($index ===7) ? 2 : $index - 6);
				$groupMax = 6;
			}
		} else {
			//	It must be an attempt at pure IPv6
			if (substr($addressLiteral, 0, 5) !== 'IPv6:')			return false;	//	RFC5321 section 4.1.3
			$IPv6 = substr($addressLiteral, 5);
			$groupMax = 8;
		}

		$groupCount	= preg_match_all('/^[0-9a-fA-F]{0,4}|\\:[0-9a-fA-F]{0,4}|(.)/', $IPv6, $matchesIP);
		$index		= strpos($IPv6,'::');

		if ($index === false) {
			//	We need exactly the right number of groups
			if ($groupCount !== $groupMax)							return false;	//	RFC5321 section 4.1.3
		} else {
			if ($index !== strrpos($IPv6,'::'))						return false;	//	More than one '::'
			$groupMax = ($index === 0 || $index === (strlen($IPv6) - 2)) ? $groupMax : $groupMax - 1;
			if ($groupCount > $groupMax)							return false;	//	Too many IPv6 groups in address
		}

		//	Check for unmatched characters
		array_multisort($matchesIP[1], SORT_DESC);
		if ($matchesIP[1][0] !== '')									return false;	//	Illegal characters in address

		//	It's a valid IPv6 address, so...
		return true;
	} else {
		//	It's a domain name...

		//	The syntax of a legal Internet host name was specified in RFC-952
		//	One aspect of host name syntax is hereby changed: the
		//	restriction on the first character is relaxed to allow either a
		//	letter or a digit.
		//		(http://tools.ietf.org/html/rfc1123#section-2.1)
		//
		//	NB RFC 1123 updates RFC 1035, but this is not currently apparent from reading RFC 1035.
		//
		//	Most common applications, including email and the Web, will generally not permit...escaped strings
		//		(http://tools.ietf.org/html/rfc3696#section-2)
		//
		//	Characters outside the set of alphabetic characters, digits, and hyphen MUST NOT appear in domain name
		//	labels for SMTP clients or servers
		//		(http://tools.ietf.org/html/rfc5321#section-4.1.2)
		//
		//	RFC5321 precludes the use of a trailing dot in a domain name for SMTP purposes
		//		(http://tools.ietf.org/html/rfc5321#section-4.1.2)
		$matches	= array();
		$groupCount	= preg_match_all('/(?:[0-9a-zA-Z][0-9a-zA-Z-]{0,61}[0-9a-zA-Z]|[a-zA-Z])(?:\\.|$)|(.)/', $domain, $matches);
		$level		= count($matches[0]);

		if ($level == 1)											return false;	//	Mail host can't be a TLD

		$TLD = $matches[0][$level - 1];
		if (substr($TLD, strlen($TLD) - 1, 1) === '.')				return false;	//	TLD can't end in a dot
		if (preg_match('/^[0-9]+$/', $TLD) > 0)						return false;	//	TLD can't be all-numeric

		//	Check for unmatched characters
		array_multisort($matches[1], SORT_DESC);
		if ($matches[1][0] !== '')							return false;	//	Illegal characters in domain, or label longer than 63 characters

		//	Check DNS?
		if ($checkDNS && function_exists('checkdnsrr')) {
			if (!(checkdnsrr($domain, 'A') || checkdnsrr($domain, 'MX'))) {
																	return false;	//	Domain doesn't actually exist
			}
		}

		//	Eliminate all other factors, and the one which remains must be the truth.
		//		(Sherlock Holmes, The Sign of Four)
		return true;
	}
}

function unitTest ($email, $reason = '') {
	$expected	= ($reason === '') ? true : false;
	$valid		= is_email($email);
	$not		= ($valid) ? '' : ' not';
	$unexpected	= ($valid !== $expected) ? ' <b>This was unexpected!</b>' : '';
	$reason		= ($reason === '') ? "" : " Reason: $reason";
	
	return "The address <i>$email</i> is$not valid.$unexpected$reason<br />\n";
}

function runTests () {
	//	Email validator test cases (Dominic Sayers, January 2009)
	//	Valid addresses
	echo unitTest('first.last@example.com');
	echo unitTest('1234567890123456789012345678901234567890123456789012345678901234@example.com');
	echo unitTest('"first last"@example.com');
	echo unitTest('"first\\"last"@example.com');	//	Not totally sure whether this is valid or not
	echo unitTest('first\\@last@example.com');
	echo unitTest('"first@last"@example.com');
	echo unitTest('first\\\\last@example.com');	//	Note that \ is escaped even in single-quote strings, so this is testing "first\\last"@example.com
	echo unitTest('first.last@x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x2345');
	echo unitTest('first.last@[12.34.56.78]');
	echo unitTest('first.last@[IPv6:::12.34.56.78]');
	echo unitTest('first.last@[IPv6:1111:2222:3333::4444:12.34.56.78]');
	echo unitTest('first.last@[IPv6:1111:2222:3333:4444:5555:6666:12.34.56.78]');
	echo unitTest('first.last@[IPv6:::1111:2222:3333:4444:5555:6666]');
	echo unitTest('first.last@[IPv6:1111:2222:3333::4444:5555:6666]');
	echo unitTest('first.last@[IPv6:1111:2222:3333:4444:5555:6666::]');
	echo unitTest('first.last@[IPv6:1111:2222:3333:4444:5555:6666:7777:8888]');
	echo unitTest('first.last@x23456789012345678901234567890123456789012345678901234567890123.example.com');
	echo unitTest('first.last@1xample.com');
	echo unitTest('first.last@123.example.com');
	
	//	Invalid addresses
	echo unitTest('first.last', "No @");
	echo unitTest('@example.com', "No local part");
	echo unitTest('12345678901234567890123456789012345678901234567890123456789012345@example.com', "Local part more than 64 characters");
	echo unitTest('.first.last@example.com', "Local part starts with a dot");
	echo unitTest('first.last.@example.com', "Local part ends with a dot");
	echo unitTest('first..last@example.com', "Local part has consecutive dots");
	echo unitTest('"first"last"@example.com', "Local part contains unescaped excluded characters");
	echo unitTest('first\\\\@last@example.com', "Local part contains unescaped excluded characters");
	echo unitTest('first.last@', "No domain");
	echo unitTest('first.last@x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456789.x23456', "Domain exceeds 255 chars");
	echo unitTest('first.last@[.12.34.56.78]', "Only char that can precede IPv4 address is ':'");
	echo unitTest('first.last@[12.34.56.789]', "Can't be interpreted as IPv4 so IPv6 tag is missing");
	echo unitTest('first.last@[::12.34.56.78]', "IPv6 tag is missing");
	echo unitTest('first.last@[IPv5:::12.34.56.78]', "IPv6 tag is wrong");
	echo unitTest('first.last@[IPv6:1111:2222:3333::4444:5555:12.34.56.78]', "Too many IPv6 groups (4 max)");
	echo unitTest('first.last@[IPv6:1111:2222:3333:4444:5555:12.34.56.78]', "Not enough IPv6 groups");
	echo unitTest('first.last@[IPv6:1111:2222:3333:4444:5555:6666:7777:12.34.56.78]', "Too many IPv6 groups (6 max)");
	echo unitTest('first.last@[IPv6:1111:2222:3333:4444:5555:6666:7777]', "Not enough IPv6 groups");
	echo unitTest('first.last@[IPv6:1111:2222:3333:4444:5555:6666:7777:8888:9999]', "Too many IPv6 groups (8 max)");
	echo unitTest('first.last@[IPv6:1111:2222::3333::4444:5555:6666]', "Too many '::' (can be none or one)");
	echo unitTest('first.last@[IPv6:1111:2222:3333::4444:5555:6666:7777]', "Too many IPv6 groups (6 max)");
	echo unitTest('first.last@[IPv6:1111:2222:333x::4444:5555]', "x is not valid in an IPv6 address");
	echo unitTest('first.last@[IPv6:1111:2222:33333::4444:5555]', "33333 is not a valid group in an IPv6 address");
	echo unitTest('first.last@example.123', "TLD can't be all digits");
	echo unitTest('first.last@com', "Mail host must be second- or lower level");
	echo unitTest('first.last@-xample.com', "Label can't begin with a hyphen");
	echo unitTest('first.last@exampl-.com', "Label can't end with a hyphen");
	echo unitTest('first.last@x234567890123456789012345678901234567890123456789012345678901234.example.com', "Label can't be longer than 63 octets");
	
	//	Test cases from RFC3696 (February 2004, http://tools.ietf.org/html/rfc3696#section-3)
	echo unitTest('Abc\\@def@example.com');
	echo unitTest('Fred\\ Bloggs@example.com');
	echo unitTest('Joe.\\\\Blow@example.com');
	echo unitTest('"Abc@def"@example.com');
	echo unitTest('"Fred Bloggs"@example.com');
	echo unitTest('user+mailbox@example.com');
	echo unitTest('customer/department=shipping@example.com');
	echo unitTest('$A12345@example.com');
	echo unitTest('!def!xyz%abc@example.com');
	echo unitTest('_somename@example.com');
	
	//	Test cases from Doug Lovell (LinuxJournal, June 2007, http://www.linuxjournal.com/article/9585)
	echo unitTest("dclo@us.ibm.com");
	echo unitTest("abc\\@def@example.com");
	echo unitTest("abc\\\\@example.com");
	echo unitTest("Fred\\ Bloggs@example.com");
	echo unitTest("Joe.\\\\Blow@example.com");
	echo unitTest("\"Abc@def\"@example.com");
	echo unitTest("\"Fred Bloggs\"@example.com");
	echo unitTest("customer/department=shipping@example.com");
	echo unitTest("\$A12345@example.com");
	echo unitTest("!def!xyz%abc@example.com");
	echo unitTest("_somename@example.com");
	echo unitTest("user+mailbox@example.com");
	echo unitTest("peter.piper@example.com");
	echo unitTest("Doug\\ \\\"Ace\\\"\\ Lovell@example.com");
	echo unitTest("\"Doug \\\"Ace\\\" L.\"@example.com");
	echo unitTest("abc@def@example.com", "Doug Lovell says this should fail");
	echo unitTest("abc\\\\@def@example.com", "Doug Lovell says this should fail");
	echo unitTest("abc\\@example.com", "Doug Lovell says this should fail");
	echo unitTest("@example.com", "Doug Lovell says this should fail");
	echo unitTest("doug@", "Doug Lovell says this should fail");
	echo unitTest("\"qu@example.com", "Doug Lovell says this should fail");
	echo unitTest("ote\"@example.com", "Doug Lovell says this should fail");
	echo unitTest(".dot@example.com", "Doug Lovell says this should fail");
	echo unitTest("dot.@example.com", "Doug Lovell says this should fail");
	echo unitTest("two..dot@example.com", "Doug Lovell says this should fail");
	echo unitTest("\"Doug \"Ace\" L.\"@example.com", "Doug Lovell says this should fail");
	echo unitTest("Doug\\ \\\"Ace\\\"\\ L\\.@example.com", "Doug Lovell says this should fail");
	echo unitTest("hello world@example.com", "Doug Lovell says this should fail");
	echo unitTest("gatsby@f.sc.ot.t.f.i.tzg.era.l.d.", "Doug Lovell says this should fail");
}
?>
