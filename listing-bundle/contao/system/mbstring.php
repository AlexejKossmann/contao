<?php if (!defined('TL_ROOT')) die('You can not access this file directly!');

/**
 * TYPOlight webCMS
 * Copyright (C) 2005-2009 Leo Feyer
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 2.1 of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at http://www.gnu.org/licenses/.
 *
 * PHP version 5
 * @copyright  Leo Feyer 2005-2009
 * @author     Leo Feyer <leo@typolight.org>
 * @package    System
 * @license    LGPL
 * @filesource
 */


/**
 * This file contains some UTF-8 helper functions that allow to run TYPOlight
 * without the mbstring extension. It is based on the UTF-8 library written
 * by Andreas Gohr <andi@splitbrain.org> which is part of the DokuWiki project.
 * Visit http://www.splitbrain.org/projects/dokuwiki to get the original file.
 *
 * This library supports the following functions:
 * - utf8_chr
 * - utf8_convert_encoding
 * - utf8_decode_entities
 * - utf8_detect_encoding
 * - utf8_romanize
 * - utf8_strlen
 * - utf8_strpos
 * - utf8_strrchr
 * - utf8_strrpos
 * - utf8_strstr
 * - utf8_strtolower
 * - utf8_strtoupper
 * - utf8_substr
 *
 * A few functions are based on the UTF-8 library written by Niels Leenheer
 * and Andy Matsubara which is part of the Zen Photo web photo album project.
 * Visit http://www.zenphoto.org to get the original file.
 */


/**
 * Check whether we can use mbstring
 */
define('USE_MBSTRING', function_exists('mb_strlen'));

if (USE_MBSTRING)
	mb_internal_encoding('UTF-8');


/**
 * Return a specific character
 *
 * Unicode version of chr() that handles UTF-8 characters. It is basically
 * used as callback function for utf8_decode_entities().
 * @param integer
 * @return string
 */
function utf8_chr($dec)
{
	if ($dec < 128)
		return chr($dec);

    if ($dec < 2048)
    	return chr(($dec >> 6) + 192) . chr(($dec & 63) + 128);

    if ($dec < 65536)
    	return chr(($dec >> 12) + 224) . chr((($dec >> 6) & 63) + 128) . chr(($dec & 63) + 128);

    if ($dec < 2097152)
    	return chr(($dec >> 18) + 240) . chr((($dec >> 12) & 63) + 128) . chr((($dec >> 6) & 63) + 128) . chr(($dec & 63) + 128);

    return '';
}


/**
 * Convert character encoding
 *
 * Use utf8_decode() to convert UTF-8 to ISO-8859-1, otherwise use iconv()
 * or mb_convert_encoding(). Return the original string if none of these
 * libraries is available.
 * @param string
 * @param string
 * @param string
 * @return string
 */
function utf8_convert_encoding($str, $to, $from=null)
{
	if (!$str)
		return '';

	if (!$from)
		$from = utf8_detect_encoding($str);

	if ($from == $to)
		return $str;

	if ($from == 'UTF-8' && $to == 'ISO-8859-1')
		return utf8_decode($str);

	if ($from == 'ISO-8859-1' && $to == 'UTF-8')
		return utf8_encode($str);

	if (USE_MBSTRING)
	{
		@mb_substitute_character('none');
		return @mb_convert_encoding($str, $to, $from);
	}

	if (function_exists('iconv'))
	{
		if (strlen($iconv = @iconv($from, $to . '//IGNORE', $str)))
			return $iconv;

		return @iconv($from, $to, $str);
	}

	return $str;
}


/**
 * Convert all unicode entities to their applicable characters
 *
 * Calls utf8_chr() to convert unicode entities. HTML entities like '&nbsp;'
 * or '&quot;' will not be decoded.
 * @param string
 * @return string
 */
function utf8_decode_entities($str)
{
	$search = array('~&#x([0-9a-f]+);~ei', '~&#([0-9]+);~e');
	$replace = array('utf8_chr(hexdec("\\1"))', 'utf8_chr(\\1)');

	return preg_replace($search, $replace, $str);
}


/**
 * Detect the encoding of a string
 *
 * Use mb_detect_encoding() if available since it seems to be about 20 times
 * faster than using ereg() or preg_match().
 * @param string
 * @return string
 */
function utf8_detect_encoding($str)
{
	if (USE_MBSTRING)
		return mb_detect_encoding($str, array('ASCII', 'ISO-2022-JP', 'UTF-8', 'EUC-JP', 'ISO-8859-1'));

	if (!preg_match("/[\x80-\xFF]/", $str))
	{
		if (!preg_match("/\x1B/", $str))
			return 'ASCII';

		return 'ISO-2022-JP';
	}

	if (preg_match("/^([\x01-\x7F]|[\xC0-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF][\x80-\xBF])+$/", $str) == 1)
		return 'UTF-8';

	if (preg_match("/^([\x01-\x7F]|\x8E[\xA0-\xDF]|\x8F[xA1-\xFE][\xA1-\xFE]|[\xA1-\xFE][\xA1-\xFE])+$/", $str) == 1)
		return 'EUC-JP';

	return 'ISO-8859-1';
}


/**
 * Romanize a string
 *
 * Use the UTF-8 lookup table to replace non ascii characters with their
 * respective roman character.
 * @param string
 * @return string
 */
function utf8_romanize($str)
{
	global $UTF8_LOOKUP_TABLE;
	return strtr(utf8_convert_encoding($str, 'UTF-8'), $UTF8_LOOKUP_TABLE['romanize']);
}


/**
 * Determine the number of characters of a string
 *
 * Use mb_strlen() if available since it seems to be the fastes way to
 * determine the string length. Otherwise decode the string (will convert
 * non ISO-8859-1 characters to '?') and use strlen().
 * @param string
 * @return integer
 */
function utf8_strlen($str)
{
	if (USE_MBSTRING)
		return mb_strlen($str);

	return strlen(utf8_decode($str));
}


/**
 * Find the position of the first occurence of a string in another string
 *
 * Use mb_strpos() if available. Otherwise combine strpos() and utf8_strlen()
 * to detect the numeric position of the first occurrence.
 * @param string
 * @param string
 * @param integer
 * @return integer
 */
function utf8_strpos($haystack, $needle, $offset=0)
{
	if (USE_MBSTRING)
	{
		if ($offset === 0)
			return mb_strpos($haystack, $needle);

		return mb_strpos($haystack, $needle, $offset);
	}

	$comp = 0;
	$length = null;

	while (is_null($length) || $length < $offset)
	{
		$pos = strpos($haystack, $needle, $offset + $comp);

		if ($pos === false)
			return false;

		$length = utf8_strlen(substr($haystack, 0, $pos));

		if ($length < $offset)
			$comp = $pos - $length;
	}

	return $length;
}


/**
 * Find the last occurrence of a character in a string
 *
 * Use mb_strrchr() if available since it seems to be about eight times
 * faster than combining utf8_substr() and utf8_strrpos().
 * @param string
 * @param string
 * @return string
 */
function utf8_strrchr($haystack, $needle)
{
	if (USE_MBSTRING)
		return mb_strrchr($haystack, $needle);

	$pos = utf8_strrpos($haystack, $needle);

	if ($pos === false)
		return false;

	return utf8_substr($haystack, $pos);
}


/**
 * Find the position of the last occurrence of a string in another string
 *
 * Use mb_strrpos() if available since it is about twice as fast as our
 * workaround. Otherwise use utf8_strlen() to determine the position.
 * @param string
 * @param string
 * @return mixed
 */
function utf8_strrpos($haystack, $needle)
{
	if (USE_MBSTRING)
		return mb_strrpos($haystack, $needle);

	$pos = strrpos($haystack, $needle);

	if ($pos === false)
		return false;

	return utf8_strlen(substr($haystack, 0, $pos));
}


/**
 * Find the first occurrence of a string in another string
 *
 * Use mb_strstr() if available since it seems to be about eight times
 * faster than combining utf8_substr() and utf8_strpos().
 * @param string
 * @param string
 * @return string
 */
function utf8_strstr($haystack, $needle)
{
	if (USE_MBSTRING)
		return mb_strstr($haystack, $needle);

	$pos = utf8_strpos($haystack, $needle);

	if ($pos === false)
		return false;

	return utf8_substr($haystack, $pos);
}


/**
 * Make a string lowercase
 *
 * Use mb_strtolower() if available, although our workaround does not seem
 * to be significantly slower.
 * @param string
 * @return string
 */
function utf8_strtolower($str)
{
	if (USE_MBSTRING)
		return mb_strtolower($str, utf8_detect_encoding($str));

	global $UTF8_LOOKUP_TABLE;
	return strtr($str, $UTF8_LOOKUP_TABLE['strtolower']);
}


/**
 * Make a string uppercase
 *
 * Use mb_strtoupper() if available, although our workaround does not seem
 * to be significantly slower.
 * @param string
 * @return string
 */
function utf8_strtoupper($str)
{
	if (USE_MBSTRING)
		return mb_strtoupper($str, utf8_detect_encoding($str));

	global $UTF8_LOOKUP_TABLE;
	return strtr($str, $UTF8_LOOKUP_TABLE['strtoupper']);
}


/**
 * Return part of a string
 *
 * Use mb_substr() if available since it is about three times faster than
 * our workaround. Otherwise, use PCRE regular expressions with 'u' flag.
 * Thanks to Andreas Gohr <andi@splitbrain.org> for this wonderful algorithm
 * which is the fastes workaround I could find on the internet.
 * @param string
 * @param integer
 * @param integer
 * @return string
 */
function utf8_substr($str, $start, $length=null)
{
	if (USE_MBSTRING)
	{
		if (is_null($length))
			return mb_substr($str, $start);

		return mb_substr($str, $start, $length);
	}

	$str = (string) $str;
	$start = (int) $start;

	if (!is_null($length))
		$length = (int) $length;

	// Handle trivial cases
	if ($length === 0)
		return '';

	if ($start < 0 && $length < 0 && $length < $start)
		return '';

	$start_pattern = '';
	$length_pattern = '';

	// Normalise -ve offsets
	if ($start < 0)
	{
		$strlen = strlen(utf8_decode($str));
		$start = $strlen + $start;

		if ($start < 0)
			$start = 0;
	}

	// Establish a pattern for offset
	if ($start > 0)
	{
		$Ox = (int) ($start / 65535);
		$Oy = $start % 65535;

		if ($Ox)
			$start_pattern = '(?:.{65535}){'.$Ox.'}';

		$start_pattern = '^(?:'.$start_pattern.'.{'.$Oy.'})';
	}

	// Anchor the pattern if offset == 0
	else
	{
		$start_pattern = '^';
	}

	// Establish a pattern for length
	if (is_null($length))
	{
		$length_pattern = '(.*)$';
	}
	else
	{
		if (!isset($strlen))
			$strlen = strlen(utf8_decode($str));

		if ($start > $strlen)
			return '';

		if ($length > 0)
		{
			// Reduce any length that would go passed the end of the string
			$length = min($strlen-$start, $length);

			$Lx = (int) ($length / 65535);
			$Ly = $length % 65535;

			if ($Lx)
				$length_pattern = '(?:.{65535}){'.$Lx.'}';

			$length_pattern = '('.$length_pattern.'.{'.$Ly.'})';
		}
		else if ($length < 0)
		{
			if ($length < ($start - $strlen))
				return '';

			$Lx = (int) ((-$length) / 65535);
			$Ly = (-$length) % 65535;

			if ($Lx)
				$length_pattern = '(?:.{65535}){'.$Lx.'}';

			$length_pattern = '(.*)(?:'.$length_pattern.'.{'.$Ly.'})$';
		}
	}

	$match = array();

	if (!preg_match('#'.$start_pattern.$length_pattern.'#us', $str, $match))
		return '';

	return $match[1];
}


/**
 * Define global UTF-8 case lookup tables
 *
 * For some reason, strtr() works much faster if we define all lookup tables
 * in one global variable. Having each table in a seperate variable made the
 * function take twice as long (!) on my system.
 */
$UTF8_LOOKUP_TABLE = array
(
	'strtoupper' => array
	(
		"ｚ"=>"Ｚ","ｙ"=>"Ｙ","ｘ"=>"Ｘ","ｗ"=>"Ｗ","ｖ"=>"Ｖ","ｕ"=>"Ｕ","ｔ"=>"Ｔ","ｓ"=>"Ｓ","ｒ"=>"Ｒ","ｑ"=>"Ｑ",
		"ｐ"=>"Ｐ","ｏ"=>"Ｏ","ｎ"=>"Ｎ","ｍ"=>"Ｍ","ｌ"=>"Ｌ","ｋ"=>"Ｋ","ｊ"=>"Ｊ","ｉ"=>"Ｉ","ｈ"=>"Ｈ","ｇ"=>"Ｇ",
		"ｆ"=>"Ｆ","ｅ"=>"Ｅ","ｄ"=>"Ｄ","ｃ"=>"Ｃ","ｂ"=>"Ｂ","ａ"=>"Ａ","ῳ"=>"ῼ","ῥ"=>"Ῥ","ῡ"=>"Ῡ","ῑ"=>"Ῑ",
		"ῐ"=>"Ῐ","ῃ"=>"ῌ","ι"=>"Ι","ᾳ"=>"ᾼ","ᾱ"=>"Ᾱ","ᾰ"=>"Ᾰ","ᾧ"=>"ᾯ","ᾦ"=>"ᾮ","ᾥ"=>"ᾭ","ᾤ"=>"ᾬ",
		"ᾣ"=>"ᾫ","ᾢ"=>"ᾪ","ᾡ"=>"ᾩ","ᾗ"=>"ᾟ","ᾖ"=>"ᾞ","ᾕ"=>"ᾝ","ᾔ"=>"ᾜ","ᾓ"=>"ᾛ","ᾒ"=>"ᾚ","ᾑ"=>"ᾙ",
		"ᾐ"=>"ᾘ","ᾇ"=>"ᾏ","ᾆ"=>"ᾎ","ᾅ"=>"ᾍ","ᾄ"=>"ᾌ","ᾃ"=>"ᾋ","ᾂ"=>"ᾊ","ᾁ"=>"ᾉ","ᾀ"=>"ᾈ","ώ"=>"Ώ",
		"ὼ"=>"Ὼ","ύ"=>"Ύ","ὺ"=>"Ὺ","ό"=>"Ό","ὸ"=>"Ὸ","ί"=>"Ί","ὶ"=>"Ὶ","ή"=>"Ή","ὴ"=>"Ὴ","έ"=>"Έ",
		"ὲ"=>"Ὲ","ά"=>"Ά","ὰ"=>"Ὰ","ὧ"=>"Ὧ","ὦ"=>"Ὦ","ὥ"=>"Ὥ","ὤ"=>"Ὤ","ὣ"=>"Ὣ","ὢ"=>"Ὢ","ὡ"=>"Ὡ",
		"ὗ"=>"Ὗ","ὕ"=>"Ὕ","ὓ"=>"Ὓ","ὑ"=>"Ὑ","ὅ"=>"Ὅ","ὄ"=>"Ὄ","ὃ"=>"Ὃ","ὂ"=>"Ὂ","ὁ"=>"Ὁ","ὀ"=>"Ὀ",
		"ἷ"=>"Ἷ","ἶ"=>"Ἶ","ἵ"=>"Ἵ","ἴ"=>"Ἴ","ἳ"=>"Ἳ","ἲ"=>"Ἲ","ἱ"=>"Ἱ","ἰ"=>"Ἰ","ἧ"=>"Ἧ","ἦ"=>"Ἦ",
		"ἥ"=>"Ἥ","ἤ"=>"Ἤ","ἣ"=>"Ἣ","ἢ"=>"Ἢ","ἡ"=>"Ἡ","ἕ"=>"Ἕ","ἔ"=>"Ἔ","ἓ"=>"Ἓ","ἒ"=>"Ἒ","ἑ"=>"Ἑ",
		"ἐ"=>"Ἐ","ἇ"=>"Ἇ","ἆ"=>"Ἆ","ἅ"=>"Ἅ","ἄ"=>"Ἄ","ἃ"=>"Ἃ","ἂ"=>"Ἂ","ἁ"=>"Ἁ","ἀ"=>"Ἀ","ỹ"=>"Ỹ",
		"ỷ"=>"Ỷ","ỵ"=>"Ỵ","ỳ"=>"Ỳ","ự"=>"Ự","ữ"=>"Ữ","ử"=>"Ử","ừ"=>"Ừ","ứ"=>"Ứ","ủ"=>"Ủ","ụ"=>"Ụ",
		"ợ"=>"Ợ","ỡ"=>"Ỡ","ở"=>"Ở","ờ"=>"Ờ","ớ"=>"Ớ","ộ"=>"Ộ","ỗ"=>"Ỗ","ổ"=>"Ổ","ồ"=>"Ồ","ố"=>"Ố",
		"ỏ"=>"Ỏ","ọ"=>"Ọ","ị"=>"Ị","ỉ"=>"Ỉ","ệ"=>"Ệ","ễ"=>"Ễ","ể"=>"Ể","ề"=>"Ề","ế"=>"Ế","ẽ"=>"Ẽ",
		"ẻ"=>"Ẻ","ẹ"=>"Ẹ","ặ"=>"Ặ","ẵ"=>"Ẵ","ẳ"=>"Ẳ","ằ"=>"Ằ","ắ"=>"Ắ","ậ"=>"Ậ","ẫ"=>"Ẫ","ẩ"=>"Ẩ",
		"ầ"=>"Ầ","ấ"=>"Ấ","ả"=>"Ả","ạ"=>"Ạ","ẛ"=>"Ṡ","ẕ"=>"Ẕ","ẓ"=>"Ẓ","ẑ"=>"Ẑ","ẏ"=>"Ẏ","ẍ"=>"Ẍ",
		"ẋ"=>"Ẋ","ẉ"=>"Ẉ","ẇ"=>"Ẇ","ẅ"=>"Ẅ","ẃ"=>"Ẃ","ẁ"=>"Ẁ","ṿ"=>"Ṿ","ṽ"=>"Ṽ","ṻ"=>"Ṻ","ṹ"=>"Ṹ",
		"ṷ"=>"Ṷ","ṵ"=>"Ṵ","ṳ"=>"Ṳ","ṱ"=>"Ṱ","ṯ"=>"Ṯ","ṭ"=>"Ṭ","ṫ"=>"Ṫ","ṩ"=>"Ṩ","ṧ"=>"Ṧ","ṥ"=>"Ṥ",
		"ṣ"=>"Ṣ","ṡ"=>"Ṡ","ṟ"=>"Ṟ","ṝ"=>"Ṝ","ṛ"=>"Ṛ","ṙ"=>"Ṙ","ṗ"=>"Ṗ","ṕ"=>"Ṕ","ṓ"=>"Ṓ","ṑ"=>"Ṑ",
		"ṏ"=>"Ṏ","ṍ"=>"Ṍ","ṋ"=>"Ṋ","ṉ"=>"Ṉ","ṇ"=>"Ṇ","ṅ"=>"Ṅ","ṃ"=>"Ṃ","ṁ"=>"Ṁ","ḿ"=>"Ḿ","ḽ"=>"Ḽ",
		"ḻ"=>"Ḻ","ḹ"=>"Ḹ","ḷ"=>"Ḷ","ḵ"=>"Ḵ","ḳ"=>"Ḳ","ḱ"=>"Ḱ","ḯ"=>"Ḯ","ḭ"=>"Ḭ","ḫ"=>"Ḫ","ḩ"=>"Ḩ",
		"ḧ"=>"Ḧ","ḥ"=>"Ḥ","ḣ"=>"Ḣ","ḡ"=>"Ḡ","ḟ"=>"Ḟ","ḝ"=>"Ḝ","ḛ"=>"Ḛ","ḙ"=>"Ḙ","ḗ"=>"Ḗ","ḕ"=>"Ḕ",
		"ḓ"=>"Ḓ","ḑ"=>"Ḑ","ḏ"=>"Ḏ","ḍ"=>"Ḍ","ḋ"=>"Ḋ","ḉ"=>"Ḉ","ḇ"=>"Ḇ","ḅ"=>"Ḅ","ḃ"=>"Ḃ","ḁ"=>"Ḁ",
		"ֆ"=>"Ֆ","օ"=>"Օ","ք"=>"Ք","փ"=>"Փ","ւ"=>"Ւ","ց"=>"Ց","ր"=>"Ր","տ"=>"Տ","վ"=>"Վ","ս"=>"Ս",
		"ռ"=>"Ռ","ջ"=>"Ջ","պ"=>"Պ","չ"=>"Չ","ո"=>"Ո","շ"=>"Շ","ն"=>"Ն","յ"=>"Յ","մ"=>"Մ","ճ"=>"Ճ",
		"ղ"=>"Ղ","ձ"=>"Ձ","հ"=>"Հ","կ"=>"Կ","ծ"=>"Ծ","խ"=>"Խ","լ"=>"Լ","ի"=>"Ի","ժ"=>"Ժ","թ"=>"Թ",
		"ը"=>"Ը","է"=>"Է","զ"=>"Զ","ե"=>"Ե","դ"=>"Դ","գ"=>"Գ","բ"=>"Բ","ա"=>"Ա","ԏ"=>"Ԏ","ԍ"=>"Ԍ",
		"ԋ"=>"Ԋ","ԉ"=>"Ԉ","ԇ"=>"Ԇ","ԅ"=>"Ԅ","ԃ"=>"Ԃ","ԁ"=>"Ԁ","ӹ"=>"Ӹ","ӵ"=>"Ӵ","ӳ"=>"Ӳ","ӱ"=>"Ӱ",
		"ӯ"=>"Ӯ","ӭ"=>"Ӭ","ӫ"=>"Ӫ","ө"=>"Ө","ӧ"=>"Ӧ","ӥ"=>"Ӥ","ӣ"=>"Ӣ","ӡ"=>"Ӡ","ӟ"=>"Ӟ","ӝ"=>"Ӝ",
		"ӛ"=>"Ӛ","ә"=>"Ә","ӗ"=>"Ӗ","ӕ"=>"Ӕ","ӓ"=>"Ӓ","ӑ"=>"Ӑ","ӎ"=>"Ӎ","ӌ"=>"Ӌ","ӊ"=>"Ӊ","ӈ"=>"Ӈ",
		"ӆ"=>"Ӆ","ӄ"=>"Ӄ","ӂ"=>"Ӂ","ҿ"=>"Ҿ","ҽ"=>"Ҽ","һ"=>"Һ","ҹ"=>"Ҹ","ҷ"=>"Ҷ","ҵ"=>"Ҵ","ҳ"=>"Ҳ",
		"ұ"=>"Ұ","ү"=>"Ү","ҭ"=>"Ҭ","ҫ"=>"Ҫ","ҩ"=>"Ҩ","ҧ"=>"Ҧ","ҥ"=>"Ҥ","ң"=>"Ң","ҡ"=>"Ҡ","ҟ"=>"Ҟ",
		"ҝ"=>"Ҝ","қ"=>"Қ","ҙ"=>"Ҙ","җ"=>"Җ","ҕ"=>"Ҕ","ғ"=>"Ғ","ґ"=>"Ґ","ҏ"=>"Ҏ","ҍ"=>"Ҍ","ҋ"=>"Ҋ",
		"ҁ"=>"Ҁ","ѿ"=>"Ѿ","ѽ"=>"Ѽ","ѻ"=>"Ѻ","ѹ"=>"Ѹ","ѷ"=>"Ѷ","ѵ"=>"Ѵ","ѳ"=>"Ѳ","ѱ"=>"Ѱ","ѯ"=>"Ѯ",
		"ѭ"=>"Ѭ","ѫ"=>"Ѫ","ѩ"=>"Ѩ","ѧ"=>"Ѧ","ѥ"=>"Ѥ","ѣ"=>"Ѣ","ѡ"=>"Ѡ","џ"=>"Џ","ў"=>"Ў","ѝ"=>"Ѝ",
		"ќ"=>"Ќ","ћ"=>"Ћ","њ"=>"Њ","љ"=>"Љ","ј"=>"Ј","ї"=>"Ї","і"=>"І","ѕ"=>"Ѕ","є"=>"Є","ѓ"=>"Ѓ",
		"ђ"=>"Ђ","ё"=>"Ё","ѐ"=>"Ѐ","я"=>"Я","ю"=>"Ю","э"=>"Э","ь"=>"Ь","ы"=>"Ы","ъ"=>"Ъ","щ"=>"Щ",
		"ш"=>"Ш","ч"=>"Ч","ц"=>"Ц","х"=>"Х","ф"=>"Ф","у"=>"У","т"=>"Т","с"=>"С","р"=>"Р","п"=>"П",
		"о"=>"О","н"=>"Н","м"=>"М","л"=>"Л","к"=>"К","й"=>"Й","и"=>"И","з"=>"З","ж"=>"Ж","е"=>"Е",
		"д"=>"Д","г"=>"Г","в"=>"В","б"=>"Б","а"=>"А","ϵ"=>"Ε","ϲ"=>"Σ","ϱ"=>"Ρ","ϰ"=>"Κ","ϯ"=>"Ϯ",
		"ϭ"=>"Ϭ","ϫ"=>"Ϫ","ϩ"=>"Ϩ","ϧ"=>"Ϧ","ϥ"=>"Ϥ","ϣ"=>"Ϣ","ϡ"=>"Ϡ","ϟ"=>"Ϟ","ϝ"=>"Ϝ","ϛ"=>"Ϛ",
		"ϙ"=>"Ϙ","ϖ"=>"Π","ϕ"=>"Φ","ϑ"=>"Θ","ϐ"=>"Β","ώ"=>"Ώ","ύ"=>"Ύ","ό"=>"Ό","ϋ"=>"Ϋ","ϊ"=>"Ϊ",
		"ω"=>"Ω","ψ"=>"Ψ","χ"=>"Χ","φ"=>"Φ","υ"=>"Υ","τ"=>"Τ","σ"=>"Σ","ς"=>"Σ","ρ"=>"Ρ","π"=>"Π",
		"ο"=>"Ο","ξ"=>"Ξ","ν"=>"Ν","μ"=>"Μ","λ"=>"Λ","κ"=>"Κ","ι"=>"Ι","θ"=>"Θ","η"=>"Η","ζ"=>"Ζ",
		"ε"=>"Ε","δ"=>"Δ","γ"=>"Γ","β"=>"Β","α"=>"Α","ί"=>"Ί","ή"=>"Ή","έ"=>"Έ","ά"=>"Ά","ʒ"=>"Ʒ",
		"ʋ"=>"Ʋ","ʊ"=>"Ʊ","ʈ"=>"Ʈ","ʃ"=>"Ʃ","ʀ"=>"Ʀ","ɵ"=>"Ɵ","ɲ"=>"Ɲ","ɯ"=>"Ɯ","ɩ"=>"Ɩ","ɨ"=>"Ɨ",
		"ɣ"=>"Ɣ","ɛ"=>"Ɛ","ə"=>"Ə","ɗ"=>"Ɗ","ɖ"=>"Ɖ","ɔ"=>"Ɔ","ɓ"=>"Ɓ","ȳ"=>"Ȳ","ȱ"=>"Ȱ","ȯ"=>"Ȯ",
		"ȭ"=>"Ȭ","ȫ"=>"Ȫ","ȩ"=>"Ȩ","ȧ"=>"Ȧ","ȥ"=>"Ȥ","ȣ"=>"Ȣ","ȟ"=>"Ȟ","ȝ"=>"Ȝ","ț"=>"Ț","ș"=>"Ș",
		"ȗ"=>"Ȗ","ȕ"=>"Ȕ","ȓ"=>"Ȓ","ȑ"=>"Ȑ","ȏ"=>"Ȏ","ȍ"=>"Ȍ","ȋ"=>"Ȋ","ȉ"=>"Ȉ","ȇ"=>"Ȇ","ȅ"=>"Ȅ",
		"ȃ"=>"Ȃ","ȁ"=>"Ȁ","ǿ"=>"Ǿ","ǽ"=>"Ǽ","ǻ"=>"Ǻ","ǹ"=>"Ǹ","ǵ"=>"Ǵ","ǳ"=>"ǲ","ǯ"=>"Ǯ","ǭ"=>"Ǭ",
		"ǫ"=>"Ǫ","ǩ"=>"Ǩ","ǧ"=>"Ǧ","ǥ"=>"Ǥ","ǣ"=>"Ǣ","ǡ"=>"Ǡ","ǟ"=>"Ǟ","ǝ"=>"Ǝ","ǜ"=>"Ǜ","ǚ"=>"Ǚ",
		"ǘ"=>"Ǘ","ǖ"=>"Ǖ","ǔ"=>"Ǔ","ǒ"=>"Ǒ","ǐ"=>"Ǐ","ǎ"=>"Ǎ","ǌ"=>"ǋ","ǉ"=>"ǈ","ǆ"=>"ǅ","ƿ"=>"Ƿ",
		"ƽ"=>"Ƽ","ƹ"=>"Ƹ","ƶ"=>"Ƶ","ƴ"=>"Ƴ","ư"=>"Ư","ƭ"=>"Ƭ","ƨ"=>"Ƨ","ƥ"=>"Ƥ","ƣ"=>"Ƣ","ơ"=>"Ơ",
		"ƞ"=>"Ƞ","ƙ"=>"Ƙ","ƕ"=>"Ƕ","ƒ"=>"Ƒ","ƌ"=>"Ƌ","ƈ"=>"Ƈ","ƅ"=>"Ƅ","ƃ"=>"Ƃ","ſ"=>"S","ž"=>"Ž",
		"ż"=>"Ż","ź"=>"Ź","ŷ"=>"Ŷ","ŵ"=>"Ŵ","ų"=>"Ų","ű"=>"Ű","ů"=>"Ů","ŭ"=>"Ŭ","ū"=>"Ū","ũ"=>"Ũ",
		"ŧ"=>"Ŧ","ť"=>"Ť","ţ"=>"Ţ","š"=>"Š","ş"=>"Ş","ŝ"=>"Ŝ","ś"=>"Ś","ř"=>"Ř","ŗ"=>"Ŗ","ŕ"=>"Ŕ",
		"œ"=>"Œ","ő"=>"Ő","ŏ"=>"Ŏ","ō"=>"Ō","ŋ"=>"Ŋ","ň"=>"Ň","ņ"=>"Ņ","ń"=>"Ń","ł"=>"Ł","ŀ"=>"Ŀ",
		"ľ"=>"Ľ","ļ"=>"Ļ","ĺ"=>"Ĺ","ķ"=>"Ķ","ĵ"=>"Ĵ","ĳ"=>"Ĳ","ı"=>"I","į"=>"Į","ĭ"=>"Ĭ","ī"=>"Ī",
		"ĩ"=>"Ĩ","ħ"=>"Ħ","ĥ"=>"Ĥ","ģ"=>"Ģ","ġ"=>"Ġ","ğ"=>"Ğ","ĝ"=>"Ĝ","ě"=>"Ě","ę"=>"Ę","ė"=>"Ė",
		"ĕ"=>"Ĕ","ē"=>"Ē","đ"=>"Đ","ď"=>"Ď","č"=>"Č","ċ"=>"Ċ","ĉ"=>"Ĉ","ć"=>"Ć","ą"=>"Ą","ă"=>"Ă",
		"ā"=>"Ā","ÿ"=>"Ÿ","þ"=>"Þ","ý"=>"Ý","ü"=>"Ü","û"=>"Û","ú"=>"Ú","ù"=>"Ù","ø"=>"Ø","ö"=>"Ö",
		"õ"=>"Õ","ô"=>"Ô","ó"=>"Ó","ò"=>"Ò","ñ"=>"Ñ","ð"=>"Ð","ï"=>"Ï","î"=>"Î","í"=>"Í","ì"=>"Ì",
		"ë"=>"Ë","ê"=>"Ê","é"=>"É","è"=>"È","ç"=>"Ç","æ"=>"Æ","å"=>"Å","ä"=>"Ä","ã"=>"Ã","â"=>"Â",
		"á"=>"Á","à"=>"À","µ"=>"Μ","z"=>"Z","y"=>"Y","x"=>"X","w"=>"W","v"=>"V","u"=>"U","t"=>"T",
		"s"=>"S","r"=>"R","q"=>"Q","p"=>"P","o"=>"O","n"=>"N","m"=>"M","l"=>"L","k"=>"K","j"=>"J",
		"i"=>"I","h"=>"H","g"=>"G","f"=>"F","e"=>"E","d"=>"D","c"=>"C","b"=>"B","a"=>"A"
	),
	'strtolower' => array
	(
		"Ｚ"=>"ｚ","Ｙ"=>"ｙ","Ｘ"=>"ｘ","Ｗ"=>"ｗ","Ｖ"=>"ｖ","Ｕ"=>"ｕ","Ｔ"=>"ｔ","Ｓ"=>"ｓ","Ｒ"=>"ｒ","Ｑ"=>"ｑ",
		"Ｐ"=>"ｐ","Ｏ"=>"ｏ","Ｎ"=>"ｎ","Ｍ"=>"ｍ","Ｌ"=>"ｌ","Ｋ"=>"ｋ","Ｊ"=>"ｊ","Ｉ"=>"ｉ","Ｈ"=>"ｈ","Ｇ"=>"ｇ",
		"Ｆ"=>"ｆ","Ｅ"=>"ｅ","Ｄ"=>"ｄ","Ｃ"=>"ｃ","Ｂ"=>"ｂ","Ａ"=>"ａ","ῼ"=>"ῳ","Ῥ"=>"ῥ","Ῡ"=>"ῡ","Ῑ"=>"ῑ",
		"Ῐ"=>"ῐ","ῌ"=>"ῃ","Ι"=>"ι","ᾼ"=>"ᾳ","Ᾱ"=>"ᾱ","Ᾰ"=>"ᾰ","ᾯ"=>"ᾧ","ᾮ"=>"ᾦ","ᾭ"=>"ᾥ","ᾬ"=>"ᾤ",
		"ᾫ"=>"ᾣ","ᾪ"=>"ᾢ","ᾩ"=>"ᾡ","ᾟ"=>"ᾗ","ᾞ"=>"ᾖ","ᾝ"=>"ᾕ","ᾜ"=>"ᾔ","ᾛ"=>"ᾓ","ᾚ"=>"ᾒ","ᾙ"=>"ᾑ",
		"ᾘ"=>"ᾐ","ᾏ"=>"ᾇ","ᾎ"=>"ᾆ","ᾍ"=>"ᾅ","ᾌ"=>"ᾄ","ᾋ"=>"ᾃ","ᾊ"=>"ᾂ","ᾉ"=>"ᾁ","ᾈ"=>"ᾀ","Ώ"=>"ώ",
		"Ὼ"=>"ὼ","Ύ"=>"ύ","Ὺ"=>"ὺ","Ό"=>"ό","Ὸ"=>"ὸ","Ί"=>"ί","Ὶ"=>"ὶ","Ή"=>"ή","Ὴ"=>"ὴ","Έ"=>"έ",
		"Ὲ"=>"ὲ","Ά"=>"ά","Ὰ"=>"ὰ","Ὧ"=>"ὧ","Ὦ"=>"ὦ","Ὥ"=>"ὥ","Ὤ"=>"ὤ","Ὣ"=>"ὣ","Ὢ"=>"ὢ","Ὡ"=>"ὡ",
		"Ὗ"=>"ὗ","Ὕ"=>"ὕ","Ὓ"=>"ὓ","Ὑ"=>"ὑ","Ὅ"=>"ὅ","Ὄ"=>"ὄ","Ὃ"=>"ὃ","Ὂ"=>"ὂ","Ὁ"=>"ὁ","Ὀ"=>"ὀ",
		"Ἷ"=>"ἷ","Ἶ"=>"ἶ","Ἵ"=>"ἵ","Ἴ"=>"ἴ","Ἳ"=>"ἳ","Ἲ"=>"ἲ","Ἱ"=>"ἱ","Ἰ"=>"ἰ","Ἧ"=>"ἧ","Ἦ"=>"ἦ",
		"Ἥ"=>"ἥ","Ἤ"=>"ἤ","Ἣ"=>"ἣ","Ἢ"=>"ἢ","Ἡ"=>"ἡ","Ἕ"=>"ἕ","Ἔ"=>"ἔ","Ἓ"=>"ἓ","Ἒ"=>"ἒ","Ἑ"=>"ἑ",
		"Ἐ"=>"ἐ","Ἇ"=>"ἇ","Ἆ"=>"ἆ","Ἅ"=>"ἅ","Ἄ"=>"ἄ","Ἃ"=>"ἃ","Ἂ"=>"ἂ","Ἁ"=>"ἁ","Ἀ"=>"ἀ","Ỹ"=>"ỹ",
		"Ỷ"=>"ỷ","Ỵ"=>"ỵ","Ỳ"=>"ỳ","Ự"=>"ự","Ữ"=>"ữ","Ử"=>"ử","Ừ"=>"ừ","Ứ"=>"ứ","Ủ"=>"ủ","Ụ"=>"ụ",
		"Ợ"=>"ợ","Ỡ"=>"ỡ","Ở"=>"ở","Ờ"=>"ờ","Ớ"=>"ớ","Ộ"=>"ộ","Ỗ"=>"ỗ","Ổ"=>"ổ","Ồ"=>"ồ","Ố"=>"ố",
		"Ỏ"=>"ỏ","Ọ"=>"ọ","Ị"=>"ị","Ỉ"=>"ỉ","Ệ"=>"ệ","Ễ"=>"ễ","Ể"=>"ể","Ề"=>"ề","Ế"=>"ế","Ẽ"=>"ẽ",
		"Ẻ"=>"ẻ","Ẹ"=>"ẹ","Ặ"=>"ặ","Ẵ"=>"ẵ","Ẳ"=>"ẳ","Ằ"=>"ằ","Ắ"=>"ắ","Ậ"=>"ậ","Ẫ"=>"ẫ","Ẩ"=>"ẩ",
		"Ầ"=>"ầ","Ấ"=>"ấ","Ả"=>"ả","Ạ"=>"ạ","Ṡ"=>"ẛ","Ẕ"=>"ẕ","Ẓ"=>"ẓ","Ẑ"=>"ẑ","Ẏ"=>"ẏ","Ẍ"=>"ẍ",
		"Ẋ"=>"ẋ","Ẉ"=>"ẉ","Ẇ"=>"ẇ","Ẅ"=>"ẅ","Ẃ"=>"ẃ","Ẁ"=>"ẁ","Ṿ"=>"ṿ","Ṽ"=>"ṽ","Ṻ"=>"ṻ","Ṹ"=>"ṹ",
		"Ṷ"=>"ṷ","Ṵ"=>"ṵ","Ṳ"=>"ṳ","Ṱ"=>"ṱ","Ṯ"=>"ṯ","Ṭ"=>"ṭ","Ṫ"=>"ṫ","Ṩ"=>"ṩ","Ṧ"=>"ṧ","Ṥ"=>"ṥ",
		"Ṣ"=>"ṣ","Ṡ"=>"ṡ","Ṟ"=>"ṟ","Ṝ"=>"ṝ","Ṛ"=>"ṛ","Ṙ"=>"ṙ","Ṗ"=>"ṗ","Ṕ"=>"ṕ","Ṓ"=>"ṓ","Ṑ"=>"ṑ",
		"Ṏ"=>"ṏ","Ṍ"=>"ṍ","Ṋ"=>"ṋ","Ṉ"=>"ṉ","Ṇ"=>"ṇ","Ṅ"=>"ṅ","Ṃ"=>"ṃ","Ṁ"=>"ṁ","Ḿ"=>"ḿ","Ḽ"=>"ḽ",
		"Ḻ"=>"ḻ","Ḹ"=>"ḹ","Ḷ"=>"ḷ","Ḵ"=>"ḵ","Ḳ"=>"ḳ","Ḱ"=>"ḱ","Ḯ"=>"ḯ","Ḭ"=>"ḭ","Ḫ"=>"ḫ","Ḩ"=>"ḩ",
		"Ḧ"=>"ḧ","Ḥ"=>"ḥ","Ḣ"=>"ḣ","Ḡ"=>"ḡ","Ḟ"=>"ḟ","Ḝ"=>"ḝ","Ḛ"=>"ḛ","Ḙ"=>"ḙ","Ḗ"=>"ḗ","Ḕ"=>"ḕ",
		"Ḓ"=>"ḓ","Ḑ"=>"ḑ","Ḏ"=>"ḏ","Ḍ"=>"ḍ","Ḋ"=>"ḋ","Ḉ"=>"ḉ","Ḇ"=>"ḇ","Ḅ"=>"ḅ","Ḃ"=>"ḃ","Ḁ"=>"ḁ",
		"Ֆ"=>"ֆ","Օ"=>"օ","Ք"=>"ք","Փ"=>"փ","Ւ"=>"ւ","Ց"=>"ց","Ր"=>"ր","Տ"=>"տ","Վ"=>"վ","Ս"=>"ս",
		"Ռ"=>"ռ","Ջ"=>"ջ","Պ"=>"պ","Չ"=>"չ","Ո"=>"ո","Շ"=>"շ","Ն"=>"ն","Յ"=>"յ","Մ"=>"մ","Ճ"=>"ճ",
		"Ղ"=>"ղ","Ձ"=>"ձ","Հ"=>"հ","Կ"=>"կ","Ծ"=>"ծ","Խ"=>"խ","Լ"=>"լ","Ի"=>"ի","Ժ"=>"ժ","Թ"=>"թ",
		"Ը"=>"ը","Է"=>"է","Զ"=>"զ","Ե"=>"ե","Դ"=>"դ","Գ"=>"գ","Բ"=>"բ","Ա"=>"ա","Ԏ"=>"ԏ","Ԍ"=>"ԍ",
		"Ԋ"=>"ԋ","Ԉ"=>"ԉ","Ԇ"=>"ԇ","Ԅ"=>"ԅ","Ԃ"=>"ԃ","Ԁ"=>"ԁ","Ӹ"=>"ӹ","Ӵ"=>"ӵ","Ӳ"=>"ӳ","Ӱ"=>"ӱ",
		"Ӯ"=>"ӯ","Ӭ"=>"ӭ","Ӫ"=>"ӫ","Ө"=>"ө","Ӧ"=>"ӧ","Ӥ"=>"ӥ","Ӣ"=>"ӣ","Ӡ"=>"ӡ","Ӟ"=>"ӟ","Ӝ"=>"ӝ",
		"Ӛ"=>"ӛ","Ә"=>"ә","Ӗ"=>"ӗ","Ӕ"=>"ӕ","Ӓ"=>"ӓ","Ӑ"=>"ӑ","Ӎ"=>"ӎ","Ӌ"=>"ӌ","Ӊ"=>"ӊ","Ӈ"=>"ӈ",
		"Ӆ"=>"ӆ","Ӄ"=>"ӄ","Ӂ"=>"ӂ","Ҿ"=>"ҿ","Ҽ"=>"ҽ","Һ"=>"һ","Ҹ"=>"ҹ","Ҷ"=>"ҷ","Ҵ"=>"ҵ","Ҳ"=>"ҳ",
		"Ұ"=>"ұ","Ү"=>"ү","Ҭ"=>"ҭ","Ҫ"=>"ҫ","Ҩ"=>"ҩ","Ҧ"=>"ҧ","Ҥ"=>"ҥ","Ң"=>"ң","Ҡ"=>"ҡ","Ҟ"=>"ҟ",
		"Ҝ"=>"ҝ","Қ"=>"қ","Ҙ"=>"ҙ","Җ"=>"җ","Ҕ"=>"ҕ","Ғ"=>"ғ","Ґ"=>"ґ","Ҏ"=>"ҏ","Ҍ"=>"ҍ","Ҋ"=>"ҋ",
		"Ҁ"=>"ҁ","Ѿ"=>"ѿ","Ѽ"=>"ѽ","Ѻ"=>"ѻ","Ѹ"=>"ѹ","Ѷ"=>"ѷ","Ѵ"=>"ѵ","Ѳ"=>"ѳ","Ѱ"=>"ѱ","Ѯ"=>"ѯ",
		"Ѭ"=>"ѭ","Ѫ"=>"ѫ","Ѩ"=>"ѩ","Ѧ"=>"ѧ","Ѥ"=>"ѥ","Ѣ"=>"ѣ","Ѡ"=>"ѡ","Џ"=>"џ","Ў"=>"ў","Ѝ"=>"ѝ",
		"Ќ"=>"ќ","Ћ"=>"ћ","Њ"=>"њ","Љ"=>"љ","Ј"=>"ј","Ї"=>"ї","І"=>"і","Ѕ"=>"ѕ","Є"=>"є","Ѓ"=>"ѓ",
		"Ђ"=>"ђ","Ё"=>"ё","Ѐ"=>"ѐ","Я"=>"я","Ю"=>"ю","Э"=>"э","Ь"=>"ь","Ы"=>"ы","Ъ"=>"ъ","Щ"=>"щ",
		"Ш"=>"ш","Ч"=>"ч","Ц"=>"ц","Х"=>"х","Ф"=>"ф","У"=>"у","Т"=>"т","С"=>"с","Р"=>"р","П"=>"п",
		"О"=>"о","Н"=>"н","М"=>"м","Л"=>"л","К"=>"к","Й"=>"й","И"=>"и","З"=>"з","Ж"=>"ж","Е"=>"е",
		"Д"=>"д","Г"=>"г","В"=>"в","Б"=>"б","А"=>"а","Ε"=>"ϵ","Σ"=>"ϲ","Ρ"=>"ϱ","Κ"=>"ϰ","Ϯ"=>"ϯ",
		"Ϭ"=>"ϭ","Ϫ"=>"ϫ","Ϩ"=>"ϩ","Ϧ"=>"ϧ","Ϥ"=>"ϥ","Ϣ"=>"ϣ","Ϡ"=>"ϡ","Ϟ"=>"ϟ","Ϝ"=>"ϝ","Ϛ"=>"ϛ",
		"Ϙ"=>"ϙ","Π"=>"ϖ","Φ"=>"ϕ","Θ"=>"ϑ","Β"=>"ϐ","Ώ"=>"ώ","Ύ"=>"ύ","Ό"=>"ό","Ϋ"=>"ϋ","Ϊ"=>"ϊ",
		"Ω"=>"ω","Ψ"=>"ψ","Χ"=>"χ","Φ"=>"φ","Υ"=>"υ","Τ"=>"τ","Σ"=>"σ","Σ"=>"ς","Ρ"=>"ρ","Π"=>"π",
		"Ο"=>"ο","Ξ"=>"ξ","Ν"=>"ν","Μ"=>"μ","Λ"=>"λ","Κ"=>"κ","Ι"=>"ι","Θ"=>"θ","Η"=>"η","Ζ"=>"ζ",
		"Ε"=>"ε","Δ"=>"δ","Γ"=>"γ","Β"=>"β","Α"=>"α","Ί"=>"ί","Ή"=>"ή","Έ"=>"έ","Ά"=>"ά","Ʒ"=>"ʒ",
		"Ʋ"=>"ʋ","Ʊ"=>"ʊ","Ʈ"=>"ʈ","Ʃ"=>"ʃ","Ʀ"=>"ʀ","Ɵ"=>"ɵ","Ɲ"=>"ɲ","Ɯ"=>"ɯ","Ɩ"=>"ɩ","Ɨ"=>"ɨ",
		"Ɣ"=>"ɣ","Ɛ"=>"ɛ","Ə"=>"ə","Ɗ"=>"ɗ","Ɖ"=>"ɖ","Ɔ"=>"ɔ","Ɓ"=>"ɓ","Ȳ"=>"ȳ","Ȱ"=>"ȱ","Ȯ"=>"ȯ",
		"Ȭ"=>"ȭ","Ȫ"=>"ȫ","Ȩ"=>"ȩ","Ȧ"=>"ȧ","Ȥ"=>"ȥ","Ȣ"=>"ȣ","Ȟ"=>"ȟ","Ȝ"=>"ȝ","Ț"=>"ț","Ș"=>"ș",
		"Ȗ"=>"ȗ","Ȕ"=>"ȕ","Ȓ"=>"ȓ","Ȑ"=>"ȑ","Ȏ"=>"ȏ","Ȍ"=>"ȍ","Ȋ"=>"ȋ","Ȉ"=>"ȉ","Ȇ"=>"ȇ","Ȅ"=>"ȅ",
		"Ȃ"=>"ȃ","Ȁ"=>"ȁ","Ǿ"=>"ǿ","Ǽ"=>"ǽ","Ǻ"=>"ǻ","Ǹ"=>"ǹ","Ǵ"=>"ǵ","ǲ"=>"ǳ","Ǯ"=>"ǯ","Ǭ"=>"ǭ",
		"Ǫ"=>"ǫ","Ǩ"=>"ǩ","Ǧ"=>"ǧ","Ǥ"=>"ǥ","Ǣ"=>"ǣ","Ǡ"=>"ǡ","Ǟ"=>"ǟ","Ǝ"=>"ǝ","Ǜ"=>"ǜ","Ǚ"=>"ǚ",
		"Ǘ"=>"ǘ","Ǖ"=>"ǖ","Ǔ"=>"ǔ","Ǒ"=>"ǒ","Ǐ"=>"ǐ","Ǎ"=>"ǎ","ǋ"=>"ǌ","ǈ"=>"ǉ","ǅ"=>"ǆ","Ƿ"=>"ƿ",
		"Ƽ"=>"ƽ","Ƹ"=>"ƹ","Ƶ"=>"ƶ","Ƴ"=>"ƴ","Ư"=>"ư","Ƭ"=>"ƭ","Ƨ"=>"ƨ","Ƥ"=>"ƥ","Ƣ"=>"ƣ","Ơ"=>"ơ",
		"Ƞ"=>"ƞ","Ƙ"=>"ƙ","Ƕ"=>"ƕ","Ƒ"=>"ƒ","Ƌ"=>"ƌ","Ƈ"=>"ƈ","Ƅ"=>"ƅ","Ƃ"=>"ƃ","S"=>"ſ","Ž"=>"ž",
		"Ż"=>"ż","Ź"=>"ź","Ŷ"=>"ŷ","Ŵ"=>"ŵ","Ų"=>"ų","Ű"=>"ű","Ů"=>"ů","Ŭ"=>"ŭ","Ū"=>"ū","Ũ"=>"ũ",
		"Ŧ"=>"ŧ","Ť"=>"ť","Ţ"=>"ţ","Š"=>"š","Ş"=>"ş","Ŝ"=>"ŝ","Ś"=>"ś","Ř"=>"ř","Ŗ"=>"ŗ","Ŕ"=>"ŕ",
		"Œ"=>"œ","Ő"=>"ő","Ŏ"=>"ŏ","Ō"=>"ō","Ŋ"=>"ŋ","Ň"=>"ň","Ņ"=>"ņ","Ń"=>"ń","Ł"=>"ł","Ŀ"=>"ŀ",
		"Ľ"=>"ľ","Ļ"=>"ļ","Ĺ"=>"ĺ","Ķ"=>"ķ","Ĵ"=>"ĵ","Ĳ"=>"ĳ","I"=>"ı","Į"=>"į","Ĭ"=>"ĭ","Ī"=>"ī",
		"Ĩ"=>"ĩ","Ħ"=>"ħ","Ĥ"=>"ĥ","Ģ"=>"ģ","Ġ"=>"ġ","Ğ"=>"ğ","Ĝ"=>"ĝ","Ě"=>"ě","Ę"=>"ę","Ė"=>"ė",
		"Ĕ"=>"ĕ","Ē"=>"ē","Đ"=>"đ","Ď"=>"ď","Č"=>"č","Ċ"=>"ċ","Ĉ"=>"ĉ","Ć"=>"ć","Ą"=>"ą","Ă"=>"ă",
		"Ā"=>"ā","Ÿ"=>"ÿ","Þ"=>"þ","Ý"=>"ý","Ü"=>"ü","Û"=>"û","Ú"=>"ú","Ù"=>"ù","Ø"=>"ø","Ö"=>"ö",
		"Õ"=>"õ","Ô"=>"ô","Ó"=>"ó","Ò"=>"ò","Ñ"=>"ñ","Ð"=>"ð","Ï"=>"ï","Î"=>"î","Í"=>"í","Ì"=>"ì",
		"Ë"=>"ë","Ê"=>"ê","É"=>"é","È"=>"è","Ç"=>"ç","Æ"=>"æ","Å"=>"å","Ä"=>"ä","Ã"=>"ã","Â"=>"â",
		"Á"=>"á","À"=>"à","Μ"=>"µ","Z"=>"z","Y"=>"y","X"=>"x","W"=>"w","V"=>"v","U"=>"u","T"=>"t",
		"S"=>"s","R"=>"r","Q"=>"q","P"=>"p","O"=>"o","N"=>"n","M"=>"m","L"=>"l","K"=>"k","J"=>"j",
		"I"=>"i","H"=>"h","G"=>"g","F"=>"f","E"=>"e","D"=>"d","C"=>"c","B"=>"b","A"=>"a"
	),
	'romanize' => array
	(
		// Lower accents
		'à'=>'a','ô'=>'o','ď'=>'d','ḟ'=>'f','ë'=>'e','š'=>'s','ơ'=>'o','ß'=>'ss','ă'=>'a','ř'=>'r',
		'ț'=>'t','ň'=>'n','ā'=>'a','ķ'=>'k','ŝ'=>'s','ỳ'=>'y','ņ'=>'n','ĺ'=>'l','ħ'=>'h','ṗ'=>'p',
		'ó'=>'o','ú'=>'u','ě'=>'e','é'=>'e','ç'=>'c','ẁ'=>'w','ċ'=>'c','õ'=>'o','ṡ'=>'s','ø'=>'o',
		'ģ'=>'g','ŧ'=>'t','ș'=>'s','ė'=>'e','ĉ'=>'c','ś'=>'s','î'=>'i','ű'=>'u','ć'=>'c','ę'=>'e',
		'ŵ'=>'w','ṫ'=>'t','ū'=>'u','č'=>'c','ö'=>'oe','è'=>'e','ŷ'=>'y','ą'=>'a','ł'=>'l','ų'=>'u',
		'ů'=>'u','ş'=>'s','ğ'=>'g','ļ'=>'l','ƒ'=>'f','ž'=>'z','ẃ'=>'w','ḃ'=>'b','å'=>'a','ì'=>'i',
		'ï'=>'i','ḋ'=>'d','ť'=>'t','ŗ'=>'r','ä'=>'ae','í'=>'i','ŕ'=>'r','ê'=>'e','ü'=>'ue','ò'=>'o',
		'ē'=>'e','ñ'=>'n','ń'=>'n','ĥ'=>'h','ĝ'=>'g','đ'=>'d','ĵ'=>'j','ÿ'=>'y','ũ'=>'u','ŭ'=>'u',
		'ư'=>'u','ţ'=>'t','ý'=>'y','ő'=>'o','â'=>'a','ľ'=>'l','ẅ'=>'w','ż'=>'z','ī'=>'i','ã'=>'a',
		'ġ'=>'g','ṁ'=>'m','ō'=>'o','ĩ'=>'i','ù'=>'u','į'=>'i','ź'=>'z','á'=>'a','û'=>'u','þ'=>'th',
		'ð'=>'dh','æ'=>'ae','µ'=>'u','ĕ'=>'e',

		// Upper accents
		'À'=>'A','Ô'=>'O','Ď'=>'D','Ḟ'=>'F','Ë'=>'E','Š'=>'S','Ơ'=>'O','Ă'=>'A','Ř'=>'R','Ț'=>'T',
		'Ň'=>'N','Ā'=>'A','Ķ'=>'K','Ŝ'=>'S','Ỳ'=>'Y','Ņ'=>'N','Ĺ'=>'L','Ħ'=>'H','Ṗ'=>'P','Ó'=>'O',
		'Ú'=>'U','Ě'=>'E','É'=>'E','Ç'=>'C','Ẁ'=>'W','Ċ'=>'C','Õ'=>'O','Ṡ'=>'S','Ø'=>'O','Ģ'=>'G',
		'Ŧ'=>'T','Ș'=>'S','Ė'=>'E','Ĉ'=>'C','Ś'=>'S','Î'=>'I','Ű'=>'U','Ć'=>'C','Ę'=>'E','Ŵ'=>'W',
		'Ṫ'=>'T','Ū'=>'U','Č'=>'C','Ö'=>'Oe','È'=>'E','Ŷ'=>'Y','Ą'=>'A','Ł'=>'L','Ų'=>'U','Ů'=>'U',
		'Ş'=>'S','Ğ'=>'G','Ļ'=>'L','Ƒ'=>'F','Ž'=>'Z','Ẃ'=>'W','Ḃ'=>'B','Å'=>'A','Ì'=>'I','Ï'=>'I',
		'Ḋ'=>'D','Ť'=>'T','Ŗ'=>'R','Ä'=>'Ae','Í'=>'I','Ŕ'=>'R','Ê'=>'E','Ü'=>'Ue','Ò'=>'O','Ē'=>'E',
		'Ñ'=>'N','Ń'=>'N','Ĥ'=>'H','Ĝ'=>'G','Đ'=>'D','Ĵ'=>'J','Ÿ'=>'Y','Ũ'=>'U','Ŭ'=>'U','Ư'=>'U',
		'Ţ'=>'T','Ý'=>'Y','Ő'=>'O','Â'=>'A','Ľ'=>'L','Ẅ'=>'W','Ż'=>'Z','Ī'=>'I','Ã'=>'A','Ġ'=>'G',
		'Ṁ'=>'M','Ō'=>'O','Ĩ'=>'I','Ù'=>'U','Į'=>'I','Ź'=>'Z','Á'=>'A','Û'=>'U','Þ'=>'Th','Ð'=>'Dh',
		'Æ'=>'Ae','Ĕ'=>'E',

		// Russian cyrillic
		'а'=>'a','А'=>'A','б'=>'b','Б'=>'B','в'=>'v','В'=>'V','г'=>'g','Г'=>'G','д'=>'d','Д'=>'D',
		'е'=>'e','Е'=>'E','ё'=>'jo','Ё'=>'Jo','ж'=>'zh','Ж'=>'Zh','з'=>'z','З'=>'Z','и'=>'i','И'=>'I',
		'й'=>'j','Й'=>'J','к'=>'k','К'=>'K','л'=>'l','Л'=>'L','м'=>'m','М'=>'M','н'=>'n','Н'=>'N',
		'о'=>'o','О'=>'O','п'=>'p','П'=>'P','р'=>'r','Р'=>'R','с'=>'s','С'=>'S','т'=>'t','Т'=>'T',
		'у'=>'u','У'=>'U','ф'=>'f','Ф'=>'F','х'=>'x','Х'=>'X','ц'=>'c','Ц'=>'C','ч'=>'ch','Ч'=>'Ch',
		'ш'=>'sh','Ш'=>'Sh','щ'=>'sch','Щ'=>'Sch','ъ'=>'','Ъ'=>'','ы'=>'y','Ы'=>'Y','ь'=>'','Ь'=>'',
		'э'=>'eh','Э'=>'Eh','ю'=>'ju','Ю'=>'Ju','я'=>'ja','Я'=>'Ja',

		// Ukrainian cyrillic
		'Ґ'=>'Gh','ґ'=>'gh','Є'=>'Je','є'=>'je','І'=>'I','і'=>'i','Ї'=>'Ji','ї'=>'ji',

		// Georgian
		'ა'=>'a','ბ'=>'b','გ'=>'g','დ'=>'d','ე'=>'e','ვ'=>'v','ზ'=>'z','თ'=>'th','ი'=>'i','კ'=>'p',
		'ლ'=>'l','მ'=>'m','ნ'=>'n','ო'=>'o','პ'=>'p','ჟ'=>'zh','რ'=>'r','ს'=>'s','ტ'=>'t','უ'=>'u',
		'ფ'=>'ph','ქ'=>'kh','ღ'=>'gh','ყ'=>'q','შ'=>'sh','ჩ'=>'ch','ც'=>'c','ძ'=>'dh','წ'=>'w','ჭ'=>'j',
		'ხ'=>'x','ჯ'=>'jh','ჰ'=>'xh',

		// Sanskrit
		'अ'=>'a','आ'=>'ah','इ'=>'i','ई'=>'ih','उ'=>'u','ऊ'=>'uh','ऋ'=>'ry','ॠ'=>'ryh','ऌ'=>'ly','ॡ'=>'lyh',
		'ए'=>'e','ऐ'=>'ay','ओ'=>'o','औ'=>'aw','अं'=>'amh','अः'=>'aq','क'=>'k','ख'=>'kh','ग'=>'g','घ'=>'gh',
		'ङ'=>'nh','च'=>'c','छ'=>'ch','ज'=>'j','झ'=>'jh','ञ'=>'ny','ट'=>'tq','ठ'=>'tqh','ड'=>'dq','ढ'=>'dqh',
		'ण'=>'nq','त'=>'t','थ'=>'th','द'=>'d','ध'=>'dh','न'=>'n','प'=>'p','फ'=>'ph','ब'=>'b','भ'=>'bh',
		'म'=>'m','य'=>'z','र'=>'r','ल'=>'l','व'=>'v','श'=>'sh','ष'=>'sqh','स'=>'s','ह'=>'x',

		// Hebrew
		'א'=>'a', 'ב'=>'b','ג'=>'g','ד'=>'d','ה'=>'h','ו'=>'v','ז'=>'z','ח'=>'kh','ט'=>'th','י'=>'y',
		'ך'=>'h','כ'=>'k','ל'=>'l','ם'=>'m','מ'=>'m','ן'=>'n','נ'=>'n','ס'=>'s','ע'=>'ah','ף'=>'f',
		'פ'=>'p','ץ'=>'c','צ'=>'c','ק'=>'q','ר'=>'r','ש'=>'sh','ת'=>'t',

		// Arabic
		'ا'=>'a','ب'=>'b','ت'=>'t','ث'=>'th','ج'=>'g','ح'=>'xh','خ'=>'x','د'=>'d','ذ'=>'dh','ر'=>'r',
		'ز'=>'z','س'=>'s','ش'=>'sh','ص'=>'s\'','ض'=>'d\'','ط'=>'t\'','ظ'=>'z\'','ع'=>'y','غ'=>'gh',
		'ف'=>'f','ق'=>'q','ك'=>'k','ل'=>'l','م'=>'m','ن'=>'n','ه'=>'x\'','و'=>'u','ي'=>'i',

		// Japanese hiragana
		'あ'=>'a','え'=>'e','い'=>'i','お'=>'o','う'=>'u','ば'=>'ba','べ'=>'be','び'=>'bi','ぼ'=>'bo','ぶ'=>'bu',
		'し'=>'ci','だ'=>'da','で'=>'de','ぢ'=>'di','ど'=>'do','づ'=>'du','ふぁ'=>'fa','ふぇ'=>'fe','ふぃ'=>'fi','ふぉ'=>'fo',
		'ふ'=>'fu','が'=>'ga','げ'=>'ge','ぎ'=>'gi','ご'=>'go','ぐ'=>'gu','は'=>'ha','へ'=>'he','ひ'=>'hi','ほ'=>'ho',
		'ふ'=>'hu','じゃ'=>'ja','じぇ'=>'je','じ'=>'ji','じょ'=>'jo','じゅ'=>'ju','か'=>'ka','け'=>'ke','き'=>'ki','こ'=>'ko',
		'く'=>'ku','ら'=>'la','れ'=>'le','り'=>'li','ろ'=>'lo','る'=>'lu','ま'=>'ma','め'=>'me','み'=>'mi','も'=>'mo',
		'む'=>'mu','な'=>'na','ね'=>'ne','に'=>'ni','の'=>'no','ぬ'=>'nu','ぱ'=>'pa','ぺ'=>'pe','ぴ'=>'pi','ぽ'=>'po',
		'ぷ'=>'pu','ら'=>'ra','れ'=>'re','り'=>'ri','ろ'=>'ro','る'=>'ru','さ'=>'sa','せ'=>'se','し'=>'si','そ'=>'so',
		'す'=>'su','た'=>'ta','て'=>'te','ち'=>'ti','と'=>'to','つ'=>'tu','ヴぁ'=>'va','ヴぇ'=>'ve','ヴぃ'=>'vi','ヴぉ'=>'vo',
		'ヴ'=>'vu','わ'=>'wa','うぇ'=>'we','うぃ'=>'wi','を'=>'wo','や'=>'ya','いぇ'=>'ye','い'=>'yi','よ'=>'yo','ゆ'=>'yu',
		'ざ'=>'za','ぜ'=>'ze','じ'=>'zi','ぞ'=>'zo','ず'=>'zu','びゃ'=>'bya','びぇ'=>'bye','びぃ'=>'byi','びょ'=>'byo','びゅ'=>'byu',
		'ちゃ'=>'cha','ちぇ'=>'che','ち'=>'chi','ちょ'=>'cho','ちゅ'=>'chu','ちゃ'=>'cya','ちぇ'=>'cye','ちぃ'=>'cyi','ちょ'=>'cyo',
		'ちゅ'=>'cyu','でゃ'=>'dha','でぇ'=>'dhe','でぃ'=>'dhi','でょ'=>'dho','でゅ'=>'dhu','どぁ'=>'dwa','どぇ'=>'dwe','どぃ'=>'dwi',
		'どぉ'=>'dwo','どぅ'=>'dwu','ぢゃ'=>'dya','ぢぇ'=>'dye','ぢぃ'=>'dyi','ぢょ'=>'dyo','ぢゅ'=>'dyu','ぢ'=>'dzi','ふぁ'=>'fwa',
		'ふぇ'=>'fwe','ふぃ'=>'fwi','ふぉ'=>'fwo','ふぅ'=>'fwu','ふゃ'=>'fya','ふぇ'=>'fye','ふぃ'=>'fyi','ふょ'=>'fyo','ふゅ'=>'fyu',
		'ぎゃ'=>'gya','ぎぇ'=>'gye','ぎぃ'=>'gyi','ぎょ'=>'gyo','ぎゅ'=>'gyu','ひゃ'=>'hya','ひぇ'=>'hye','ひぃ'=>'hyi','ひょ'=>'hyo',
		'ひゅ'=>'hyu','じゃ'=>'jya','じぇ'=>'jye','じぃ'=>'jyi','じょ'=>'jyo','じゅ'=>'jyu','きゃ'=>'kya','きぇ'=>'kye','きぃ'=>'kyi',
		'きょ'=>'kyo','きゅ'=>'kyu','りゃ'=>'lya','りぇ'=>'lye','りぃ'=>'lyi','りょ'=>'lyo','りゅ'=>'lyu','みゃ'=>'mya','みぇ'=>'mye',
		'みぃ'=>'myi','みょ'=>'myo','みゅ'=>'myu','ん'=>'n','にゃ'=>'nya','にぇ'=>'nye','にぃ'=>'nyi','にょ'=>'nyo','にゅ'=>'nyu',
		'ぴゃ'=>'pya','ぴぇ'=>'pye','ぴぃ'=>'pyi','ぴょ'=>'pyo','ぴゅ'=>'pyu','りゃ'=>'rya','りぇ'=>'rye','りぃ'=>'ryi','りょ'=>'ryo',
		'りゅ'=>'ryu','しゃ'=>'sha','しぇ'=>'she','し'=>'shi','しょ'=>'sho','しゅ'=>'shu','すぁ'=>'swa','すぇ'=>'swe','すぃ'=>'swi',
		'すぉ'=>'swo','すぅ'=>'swu','しゃ'=>'sya','しぇ'=>'sye','しぃ'=>'syi','しょ'=>'syo','しゅ'=>'syu','てゃ'=>'tha','てぇ'=>'the',
		'てぃ'=>'thi','てょ'=>'tho','てゅ'=>'thu','つゃ'=>'tsa','つぇ'=>'tse','つぃ'=>'tsi','つょ'=>'tso','つ'=>'tsu','とぁ'=>'twa',
		'とぇ'=>'twe','とぃ'=>'twi','とぉ'=>'two','とぅ'=>'twu','ちゃ'=>'tya','ちぇ'=>'tye','ちぃ'=>'tyi','ちょ'=>'tyo','ちゅ'=>'tyu',
		'ヴゃ'=>'vya','ヴぇ'=>'vye','ヴぃ'=>'vyi','ヴょ'=>'vyo','ヴゅ'=>'vyu','うぁ'=>'wha','うぇ'=>'whe','うぃ'=>'whi','うぉ'=>'who',
		'うぅ'=>'whu','ゑ'=>'wye','ゐ'=>'wyi','じゃ'=>'zha','じぇ'=>'zhe','じぃ'=>'zhi','じょ'=>'zho','じゅ'=>'zhu','じゃ'=>'zya',
		'じぇ'=>'zye','じぃ'=>'zyi','じょ'=>'zyo','じゅ'=>'zyu',

		// Japanese katakana
		'ア'=>'a','エ'=>'e','イ'=>'i','オ'=>'o','ウ'=>'u','バ'=>'ba','ベ'=>'be','ビ'=>'bi','ボ'=>'bo','ブ'=>'bu',
		'シ'=>'ci','ダ'=>'da','デ'=>'de','ヂ'=>'di','ド'=>'do','ヅ'=>'du','ファ'=>'fa','フェ'=>'fe','フィ'=>'fi','フォ'=>'fo',
		'フ'=>'fu','ガ'=>'ga','ゲ'=>'ge','ギ'=>'gi','ゴ'=>'go','グ'=>'gu','ハ'=>'ha','ヘ'=>'he','ヒ'=>'hi','ホ'=>'ho',
		'フ'=>'hu','ジャ'=>'ja','ジェ'=>'je','ジ'=>'ji','ジョ'=>'jo','ジュ'=>'ju','カ'=>'ka','ケ'=>'ke','キ'=>'ki','コ'=>'ko',
		'ク'=>'ku','ラ'=>'la','レ'=>'le','リ'=>'li','ロ'=>'lo','ル'=>'lu','マ'=>'ma','メ'=>'me','ミ'=>'mi','モ'=>'mo',
		'ム'=>'mu','ナ'=>'na','ネ'=>'ne','ニ'=>'ni','ノ'=>'no','ヌ'=>'nu','パ'=>'pa','ペ'=>'pe','ピ'=>'pi','ポ'=>'po',
		'プ'=>'pu','ラ'=>'ra','レ'=>'re','リ'=>'ri','ロ'=>'ro','ル'=>'ru','サ'=>'sa','セ'=>'se','シ'=>'si','ソ'=>'so',
		'ス'=>'su','タ'=>'ta','テ'=>'te','チ'=>'ti','ト'=>'to','ツ'=>'tu','ヴァ'=>'va','ヴェ'=>'ve','ヴィ'=>'vi','ヴォ'=>'vo',
		'ヴ'=>'vu','ワ'=>'wa','ウェ'=>'we','ウィ'=>'wi','ヲ'=>'wo','ヤ'=>'ya','イェ'=>'ye','イ'=>'yi','ヨ'=>'yo','ユ'=>'yu',
		'ザ'=>'za','ゼ'=>'ze','ジ'=>'zi','ゾ'=>'zo','ズ'=>'zu','ビャ'=>'bya','ビェ'=>'bye','ビィ'=>'byi','ビョ'=>'byo',
		'ビュ'=>'byu','チャ'=>'cha','チェ'=>'che','チ'=>'chi','チョ'=>'cho','チュ'=>'chu','チャ'=>'cya','チェ'=>'cye','チィ'=>'cyi',
		'チョ'=>'cyo','チュ'=>'cyu','デャ'=>'dha','デェ'=>'dhe','ディ'=>'dhi','デョ'=>'dho','デュ'=>'dhu','ドァ'=>'dwa','ドェ'=>'dwe',
		'ドィ'=>'dwi','ドォ'=>'dwo','ドゥ'=>'dwu','ヂャ'=>'dya','ヂェ'=>'dye','ヂィ'=>'dyi','ヂョ'=>'dyo','ヂュ'=>'dyu','ヂ'=>'dzi',
		'ファ'=>'fwa','フェ'=>'fwe','フィ'=>'fwi','フォ'=>'fwo','フゥ'=>'fwu','フャ'=>'fya','フェ'=>'fye','フィ'=>'fyi','フョ'=>'fyo',
		'フュ'=>'fyu','ギャ'=>'gya','ギェ'=>'gye','ギィ'=>'gyi','ギョ'=>'gyo','ギュ'=>'gyu','ヒャ'=>'hya','ヒェ'=>'hye','ヒィ'=>'hyi',
		'ヒョ'=>'hyo','ヒュ'=>'hyu','ジャ'=>'jya','ジェ'=>'jye','ジィ'=>'jyi','ジョ'=>'jyo','ジュ'=>'jyu','キャ'=>'kya','キェ'=>'kye',
		'キィ'=>'kyi','キョ'=>'kyo','キュ'=>'kyu','リャ'=>'lya','リェ'=>'lye','リィ'=>'lyi','リョ'=>'lyo','リュ'=>'lyu','ミャ'=>'mya',
		'ミェ'=>'mye','ミィ'=>'myi','ミョ'=>'myo','ミュ'=>'myu','ン'=>'n','ニャ'=>'nya','ニェ'=>'nye','ニィ'=>'nyi','ニョ'=>'nyo',
		'ニュ'=>'nyu','ピャ'=>'pya','ピェ'=>'pye','ピィ'=>'pyi','ピョ'=>'pyo','ピュ'=>'pyu','リャ'=>'rya','リェ'=>'rye','リィ'=>'ryi',
		'リョ'=>'ryo','リュ'=>'ryu','シャ'=>'sha','シェ'=>'she','シ'=>'shi','ショ'=>'sho','シュ'=>'shu','スァ'=>'swa','スェ'=>'swe',
		'スィ'=>'swi','スォ'=>'swo','スゥ'=>'swu','シャ'=>'sya','シェ'=>'sye','シィ'=>'syi','ショ'=>'syo','シュ'=>'syu','テャ'=>'tha',
		'テェ'=>'the','ティ'=>'thi','テョ'=>'tho','テュ'=>'thu','ツャ'=>'tsa','ツェ'=>'tse','ツィ'=>'tsi','ツョ'=>'tso','ツ'=>'tsu',
		'トァ'=>'twa','トェ'=>'twe','トィ'=>'twi','トォ'=>'two','トゥ'=>'twu','チャ'=>'tya','チェ'=>'tye','チィ'=>'tyi','チョ'=>'tyo',
		'チュ'=>'tyu','ヴャ'=>'vya','ヴェ'=>'vye','ヴィ'=>'vyi','ヴョ'=>'vyo','ヴュ'=>'vyu','ウァ'=>'wha','ウェ'=>'whe','ウィ'=>'whi',
		'ウォ'=>'who','ウゥ'=>'whu','ヱ'=>'wye','ヰ'=>'wyi','ジャ'=>'zha','ジェ'=>'zhe','ジィ'=>'zhi','ジョ'=>'zho','ジュ'=>'zhu',
		'ジャ'=>'zya','ジェ'=>'zye','ジィ'=>'zyi','ジョ'=>'zyo','ジュ'=>'zyu',

		// Greek
		'Γ'=>'G','Δ'=>'E','Θ'=>'Th','Λ'=>'L','Ξ'=>'X','Π'=>'P','Σ'=>'S','Φ'=>'F','Ψ'=>'Ps','γ'=>'g',
		'δ'=>'e','θ'=>'th','λ'=>'l','ξ'=>'x','π'=>'p','σ'=>'s','φ'=>'f','ψ'=>'ps',

		// Thai
		'ก'=>'k','ข'=>'kh','ฃ'=>'kh','ค'=>'kh','ฅ'=>'kh','ฆ'=>'kh','ง'=>'ng','จ'=>'ch','ฉ'=>'ch','ช'=>'ch',
		'ซ'=>'s','ฌ'=>'ch','ญ'=>'y','ฎ'=>'d','ฏ'=>'t','ฐ'=>'th','ฑ'=>'d','ฒ'=>'th','ณ'=>'n','ด'=>'d',
		'ต'=>'t','ถ'=>'th','ท'=>'th','ธ'=>'th','น'=>'n','บ'=>'b','ป'=>'p','ผ'=>'ph','ฝ'=>'f','พ'=>'ph',
		'ฟ'=>'f','ภ'=>'ph','ม'=>'m','ย'=>'y','ร'=>'r','ฤ'=>'rue','ฤๅ'=>'rue','ล'=>'l','ฦ'=>'lue','ฦๅ'=>'lue',
		'ว'=>'w','ศ'=>'s','ษ'=>'s','ส'=>'s','ห'=>'h','ฬ'=>'l','ฮ'=>'h','ะ'=>'a','–ั'=>'a','รร'=>'a','า'=>'a',
		'รร'=>'an','ำ'=>'am','–ิ'=>'i','–ี'=>'i','–ึ'=>'ue','–ื'=>'ue','–ุ'=>'u','–ู'=>'u','เะ'=>'e',
		'เ–็'=>'e','เ'=>'e','แะ'=>'ae','แ'=>'ae','โะ'=>'o','โ'=>'o','เาะ'=>'o','อ'=>'o','เอะ'=>'oe','เ–ิ'=>'oe',
		'เอ'=>'oe','เ–ียะ'=>'ia','เ–ีย'=>'ia','เ–ือะ'=>'uea','เ–ือ'=>'uea','–ัวะ'=>'ua','–ัว'=>'ua',
		'ว'=>'ua','ใ'=>'ai','ไ'=>'ai','–ัย'=>'ai','ไย'=>'ai','าย'=>'ai','เา'=>'ao','าว'=>'ao','–ุย'=>'ui',
		'โย'=>'oi','อย'=>'oi','เย'=>'oei','เ–ือย'=>'ueai','วย'=>'uai','–ิว'=>'io','เ–็ว'=>'eo','เว'=>'eo',
		'แ–็ว'=>'aeo','แว'=>'aeo','เ–ียว'=>'iao',

		// Korean
		'ㄱ'=>'k','ㅋ'=>'kh','ㄲ'=>'kk','ㄷ'=>'t','ㅌ'=>'th','ㄸ'=>'tt','ㅂ'=>'p','ㅍ'=>'ph','ㅃ'=>'pp','ㅈ'=>'c','ㅊ'=>'ch',
		'ㅉ'=>'cc','ㅅ'=>'s','ㅆ'=>'ss','ㅎ'=>'h','ㅇ'=>'ng','ㄴ'=>'n','ㄹ'=>'l','ㅁ'=>'m', 'ㅏ'=>'a','ㅓ'=>'e','ㅗ'=>'o',
		'ㅜ'=>'wu','ㅡ'=>'u','ㅣ'=>'i','ㅐ'=>'ay','ㅔ'=>'ey','ㅚ'=>'oy','ㅘ'=>'wa','ㅝ'=>'we','ㅟ'=>'wi','ㅙ'=>'way',
		'ㅞ'=>'wey','ㅢ'=>'uy','ㅑ'=>'ya','ㅕ'=>'ye','ㅛ'=>'oy','ㅠ'=>'yu','ㅒ'=>'yay','ㅖ'=>'yey'
	)
);

?>