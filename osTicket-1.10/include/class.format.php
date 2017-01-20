<?php
/*********************************************************************
    class.format.php

    Collection of helper function used for formatting

    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

include_once INCLUDE_DIR.'class.charset.php';
require_once INCLUDE_DIR.'class.variable.php';

class Format {


    function file_size($bytes) {

        if(!is_numeric($bytes))
            return $bytes;
        if($bytes<1024)
            return $bytes.' bytes';
        if($bytes < (900<<10))
            return round(($bytes/1024),1).' kb';

        return round(($bytes/1048576),1).' mb';
    }

    function filesize2bytes($size) {
        switch (substr($size, -1)) {
        case 'M': case 'm': return (int)$size <<= 20;
        case 'K': case 'k': return (int)$size <<= 10;
        case 'G': case 'g': return (int)$size <<= 30;
        }

        return $size;
    }

    function mimedecode($text, $encoding='UTF-8') {

        if(function_exists('imap_mime_header_decode')
                && ($parts = imap_mime_header_decode($text))) {
            $str ='';
            foreach ($parts as $part)
                $str.= Charset::transcode($part->text, $part->charset, $encoding);

            $text = $str;
        } elseif($text[0] == '=' && function_exists('iconv_mime_decode')) {
            $text = iconv_mime_decode($text, 0, $encoding);
        } elseif(!strcasecmp($encoding, 'utf-8')
                && function_exists('imap_utf8')) {
            $text = imap_utf8($text);
        }

        return $text;
    }

    /**
     * Decodes filenames given in the content-disposition header according
     * to RFC5987, such as filename*=utf-8''filename.png. Note that the
     * language sub-component is defined in RFC5646, and that the filename
     * is URL encoded (in the charset specified)
     */
    function decodeRfc5987($filename) {
        $match = array();
        if (preg_match("/([\w!#$%&+^_`{}~-]+)'([\w-]*)'(.*)$/",
                $filename, $match))
            // XXX: Currently we don't care about the language component.
            //      The  encoding hint is sufficient.
            return Charset::utf8(urldecode($match[3]), $match[1]);
        else
            return $filename;
    }

    /**
     * Json Encoder
     *
     */
    function json_encode($what) {
        require_once (INCLUDE_DIR.'class.json.php');
        return JsonDataEncoder::encode($what);
    }

	function phone($phone) {

		$stripped= preg_replace("/[^0-9]/", "", $phone);
		if(strlen($stripped) == 7)
			return preg_replace("/([0-9]{3})([0-9]{4})/", "$1-$2",$stripped);
		elseif(strlen($stripped) == 10)
			return preg_replace("/([0-9]{3})([0-9]{3})([0-9]{4})/", "($1) $2-$3",$stripped);
		else
			return $phone;
	}

    function truncate($string,$len,$hard=false) {

        if(!$len || $len>strlen($string))
            return $string;

        $string = substr($string,0,$len);

        return $hard?$string:(substr($string,0,strrpos($string,' ')).' ...');
    }

    function strip_slashes($var) {
        return is_array($var)?array_map(array('Format','strip_slashes'),$var):stripslashes($var);
    }

    function wrap($text, $len=75) {
        return $len ? wordwrap($text, $len, "\n", true) : $text;
    }

    function html_balance($html, $remove_empty=true) {
        if (!extension_loaded('dom'))
            return $html;

        if (!trim($html))
            return $html;

        $doc = new DomDocument();
        $xhtml = '<?xml encoding="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>'
            // Wrap the content in a <div> because libxml would use a <p>
            . "<div>$html</div>";
        $doc->encoding = 'utf-8';
        $doc->preserveWhitespace = false;
        $doc->recover = true;
        if (false === @$doc->loadHTML($xhtml))
            return $html;

        if ($remove_empty) {
            // Remove empty nodes
            $xpath = new DOMXPath($doc);
            static $eE = array('area'=>1, 'br'=>1, 'col'=>1, 'embed'=>1,
                    'iframe' => 1, 'hr'=>1, 'img'=>1, 'input'=>1,
                    'isindex'=>1, 'param'=>1);
            do {
                $done = true;
                $nodes = $xpath->query('//*[not(text()) and not(node())]');
                foreach ($nodes as $n) {
                    if (isset($eE[$n->nodeName]))
                        continue;
                    $n->parentNode->removeChild($n);
                    $done = false;
                }
            } while (!$done);
        }

        static $phpversion;
        if (!isset($phpversion))
            $phpversion = phpversion();

        $body = $doc->getElementsByTagName('body');
        if (!$body->length)
            return $html;

        if ($phpversion > '5.3.6') {
            $html = $doc->saveHTML($doc->getElementsByTagName('body')->item(0)->firstChild);
        }
        else {
            $html = $doc->saveHTML();
            $html = preg_replace('`^<!DOCTYPE.+?>|<\?xml .+?>|</?html>|</?body>|</?head>|<meta .+?/?>`', '', $html); # <?php
        }
        return preg_replace('`^<div>|</div>$`', '', trim($html));
    }

    function html($html, $config=array()) {
        require_once(INCLUDE_DIR.'htmLawed.php');
        $spec = false;
        if (isset($config['spec']))
            $spec = $config['spec'];

        // Add in htmLawed defaults
        $config += array(
            'balance' => 1,
        );

        // Attempt to balance using libxml. htmLawed will corrupt HTML with
        // balancing to fix improper HTML at the same time. For instance,
        // some email clients may wrap block elements inside inline
        // elements. htmLawed will change such block elements to inlines to
        // make the HTML correct.
        if ($config['balance'] && extension_loaded('dom')) {
            $html = self::html_balance($html);
            $config['balance'] = 0;
        }

        return htmLawed($html, $config, $spec);
    }

    function html2text($html, $width=74, $tidy=true) {

        if (!$html)
            return $html;


        # Tidy html: decode, balance, sanitize tags
        if($tidy)
            $html = Format::html(Format::htmldecode($html), array('balance' => 1));

        # See if advanced html2text is available (requires xml extension)
        if (function_exists('convert_html_to_text')
                && extension_loaded('dom')
                && ($text = convert_html_to_text($html, $width)))
                return $text;

        # Try simple html2text  - insert line breaks after new line tags.
        $html = preg_replace(
                array(':<br ?/?\>:i', ':(</div>)\s*:i', ':(</p>)\s*:i'),
                array("\n", "$1\n", "$1\n\n"),
                $html);

        # Strip tags, decode html chars and wrap resulting text.
        return Format::wrap(
                Format::htmldecode( Format::striptags($html, false)),
                $width);
    }

    static function __html_cleanup($el, $attributes=0) {
        static $eE = array('area'=>1, 'br'=>1, 'col'=>1, 'embed'=>1,
            'hr'=>1, 'img'=>1, 'input'=>1, 'isindex'=>1, 'param'=>1);

        // We're dealing with closing tag
        if ($attributes === 0)
            return "</{$el}>";

        // Remove iframe and embed without src (perhaps striped by spec)
        // It would be awesome to rickroll such entry :)
        if (in_array($el, array('iframe', 'embed'))
                && (!isset($attributes['src']) || empty($attributes['src'])))
            return '';

        // Clean unexpected class values
        if (isset($attributes['class'])) {
            $classes = explode(' ', $attributes['class']);
            foreach ($classes as $i=>$a)
                // Unset all unsupported style classes -- anything but M$
                if (strpos($a, 'Mso') !== 0)
                    unset($classes[$i]);
            if ($classes)
                $attributes['class'] = implode(' ', $classes);
            else
                unset($attributes['class']);
        }
        // Clean browser-specific style attributes
        if (isset($attributes['style'])) {
            $styles = preg_split('/;\s*/S', html_entity_decode($attributes['style']));
            $props = array();
            foreach ($styles as $i=>&$s) {
                @list($prop, $val) = explode(':', $s);
                if (isset($props[$prop])) {
                    unset($styles[$i]);
                    continue;
                }
                $props[$prop] = true;
                // Remove unset or browser-specific style rules
                if (!$val || !$prop || $prop[0] == '-' || substr($prop, 0, 4) == 'mso-')
                    unset($styles[$i]);
                // Remove quotes of properties without enclosed space
                if (!strpos($val, ' '))
                    $val = str_replace('"','', $val);
                else
                    $val = str_replace('"',"'", $val);
                $s = "$prop:".trim($val);
            }
            unset($s);
            if ($styles)
                $attributes['style'] = Format::htmlchars(implode(';', $styles));
            else
                unset($attributes['style']);
        }
        $at = '';
        if (is_array($attributes)) {
            foreach ($attributes as $k=>$v)
                $at .= " $k=\"$v\"";
            return "<{$el}{$at}".(isset($eE[$el])?" /":"").">";
        }
        else {
            return "</{$el}>";
        }
    }

    function safe_html($html, $options=array()) {

        $options = array_merge(array(
                    // Balance html tags
                    'balance' => 1,
                    // Decoding special html char like &lt; and &gt; which
                    // can be used to skip cleaning
                    'decode' => true
                    ),
                $options);

        if ($options['decode'])
            $html = Format::htmldecode($html);

        // Remove HEAD and STYLE sections
        $html = preg_replace(
            array(':<(head|style|script).+?</\1>:is', # <head> and <style> sections
                  ':<!\[[^]<]+\]>:',            # <![if !mso]> and friends
                  ':<!DOCTYPE[^>]+>:',          # <!DOCTYPE ... >
                  ':<\?[^>]+>:',                # <?xml version="1.0" ... >
                  ':<html[^>]+:i',              # drop html attributes
            ),
            array('', '', '', '', '<html'),
            $html);

        // HtmLawed specific config only
        $config = array(
            'safe' => 1, //Exclude applet, embed, iframe, object and script tags.
            'balance' => $options['balance'],
            'comment' => 1, //Remove html comments (OUTLOOK LOVE THEM)
            'tidy' => -1,
            'deny_attribute' => 'id',
            'schemes' => 'href: aim, feed, file, ftp, gopher, http, https, irc, mailto, news, nntp, sftp, ssh, telnet; *:file, http, https; src: cid, http, https, data',
            'hook_tag' => function($e, $a=0) { return Format::__html_cleanup($e, $a); },
            'elements' => '*+iframe',
            'spec' =>
            'iframe=-*,height,width,type,style,src(match="`^(https?:)?//(www\.)?(youtube|dailymotion|vimeo)\.com/`i"),frameborder'.($options['spec'] ? '; '.$options['spec'] : ''),
        );

        return Format::html($html, $config);
    }

    function localizeInlineImages($text) {
        // Change file.php urls back to content-id's
        return preg_replace(
            '`src="(?:https?:/)?(?:/[^/"]+)*?/file\\.php\\?(?:\w+=[^&]+&(?:amp;)?)*?key=([^&]+)[^"]*`',
            'src="cid:$1', $text);
    }

    function sanitize($text, $striptags=false, $spec=false) {

        //balance and neutralize unsafe tags.
        $text = Format::safe_html($text, array('spec' => $spec));

        $text = self::localizeInlineImages($text);

        //If requested - strip tags with decoding disabled.
        return $striptags?Format::striptags($text, false):$text;
    }

    function htmlchars($var, $sanitize = false) {
        static $phpversion = null;

        if (is_array($var))
            return array_map(array('Format', 'htmlchars'), $var);

        if ($sanitize)
            $var = Format::sanitize($var);

        if (!isset($phpversion))
            $phpversion = phpversion();

        $flags = ENT_COMPAT;
        if ($phpversion >= '5.4.0')
            $flags |= ENT_HTML401;

        try {
            return htmlspecialchars( (string) $var, $flags, 'UTF-8', false);
        } catch(Exception $e) {
            return $var;
        }
    }

    function htmldecode($var) {

        if(is_array($var))
            return array_map(array('Format','htmldecode'), $var);

        $flags = ENT_COMPAT;
        if (phpversion() >= '5.4.0')
            $flags |= ENT_HTML401;

        return htmlspecialchars_decode($var, $flags);
    }

    function input($var) {
        return Format::htmlchars($var);
    }

    //Format text for display..
    function display($text, $inline_images=true, $balance=true) {
        // Make showing offsite images optional
        $text = preg_replace_callback('/<img ([^>]*)(src="http[^"]+")([^>]*)\/>/',
            function($match) {
                // Drop embedded classes -- they don't refer to ours
                $match = preg_replace('/class="[^"]*"/', '', $match);
                return sprintf('<span %s class="non-local-image" data-%s %s></span>',
                    $match[1], $match[2], $match[3]);
            },
            $text);

        if ($balance)
            $text = self::html_balance($text, false);

        // make urls clickable.
        $text = Format::clickableurls($text);

        if ($inline_images)
            return self::viewableImages($text);

        return $text;
    }

    function striptags($var, $decode=true) {

        if(is_array($var))
            return array_map(array('Format','striptags'), $var, array_fill(0, count($var), $decode));

        return strip_tags($decode?Format::htmldecode($var):$var);
    }

    //make urls clickable. Mainly for display
    function clickableurls($text, $target='_blank') {
        global $ost;

        // Find all text between tags
        return preg_replace_callback(':^[^<]+|>[^<]+:',
            function($match) {
                // Scan for things that look like URLs
                return preg_replace_callback(
                    '`(?<!>)(((f|ht)tp(s?)://|(?<!//)www\.)([-+~%/.\w]+)(?:[-?#+=&;%@.\w]*)?)'
                   .'|(\b[_\.0-9a-z-]+@([0-9a-z][0-9a-z-]+\.)+[a-z]{2,4})`',
                    function ($match) {
                        if ($match[1]) {
                            while (in_array(substr($match[1], -1),
                                    array('.','?','-',':',';'))) {
                                $match[9] = substr($match[1], -1) . $match[9];
                                $match[1] = substr($match[1], 0, strlen($match[1])-1);
                            }
                            if (strpos($match[2], '//') === false) {
                                $match[1] = 'http://' . $match[1];
                            }

                            return sprintf('<a href="%s">%s</a>%s',
                                $match[1], $match[1], $match[9]);
                        } elseif ($match[6]) {
                            return sprintf('<a href="mailto:%1$s" target="_blank">%1$s</a>',
                                $match[6]);
                        }
                    },
                    $match[0]);
            },
            $text);
    }

    function stripEmptyLines($string) {
        return preg_replace("/\n{3,}/", "\n\n", trim($string));
    }


    function viewableImages($html, $script=false) {
        $cids = $images = array();
        return preg_replace_callback('/"cid:([\w._-]{32})"/',
        function($match) use ($script, $images) {
            if (!($file = AttachmentFile::lookup($match[1])))
                return $match[0];
            return sprintf('"%s" data-cid="%s"',
                $file->getDownloadUrl(false, 'inline', $script), $match[1]);
        }, $html);
    }


    /**
     * Thanks, http://us2.php.net/manual/en/function.implode.php
     * Implode an array with the key and value pair giving
     * a glue, a separator between pairs and the array
     * to implode.
     * @param string $glue The glue between key and value
     * @param string $separator Separator between pairs
     * @param array $array The array to implode
     * @return string The imploded array
    */
    function array_implode( $glue, $separator, $array ) {

        if ( !is_array( $array ) ) return $array;

        $string = array();
        foreach ( $array as $key => $val ) {
            if ( is_array( $val ) )
                $val = implode( ',', $val );

            $string[] = "{$key}{$glue}{$val}";
        }

        return implode( $separator, $string );
    }

    /* elapsed time */
    function elapsedTime($sec) {

        if(!$sec || !is_numeric($sec)) return "";

        $days = floor($sec / 86400);
        $hrs = floor(bcmod($sec,86400)/3600);
        $mins = round(bcmod(bcmod($sec,86400),3600)/60);
        if($days > 0) $tstring = $days . 'd,';
        if($hrs > 0) $tstring = $tstring . $hrs . 'h,';
        $tstring =$tstring . $mins . 'm';

        return $tstring;
    }

    function __formatDate($timestamp, $format, $fromDb, $dayType, $timeType,
            $strftimeFallback, $timezone, $user=false) {
        global $cfg;
        static $cache;

        if (!$timestamp)
            return '';

        if ($fromDb)
            $timestamp = Misc::db2gmtime($timestamp);

        if (class_exists('IntlDateFormatter')) {
            $locale = Internationalization::getCurrentLocale($user);
            $key = "{$locale}:{$dayType}:{$timeType}:{$timezone}:{$format}";
            if (!isset($cache[$key])) {
                // Setting up the IntlDateFormatter is pretty expensive, so
                // cache it since there aren't many variations of the
                // arguments passed to the constructor
                $cache[$key] = $formatter = new IntlDateFormatter(
                    $locale,
                    $dayType,
                    $timeType,
                    $timezone,
                    IntlDateFormatter::GREGORIAN,
                    $format ?: null
                );
                if ($cfg->isForce24HourTime()) {
                    $format = str_replace(array('a', 'h'), array('', 'H'),
                        $formatter->getPattern());
                    $formatter->setPattern($format);
                }
            }
            else {
                $formatter = $cache[$key];
            }
            return $formatter->format($timestamp);
        }
        // Fallback using strftime
        static $user_timezone;
        if (!isset($user_timezone))
            $user_timezone = new DateTimeZone($cfg->getTimezone() ?: date_default_timezone_get());

        $format = self::getStrftimeFormat($format);
        // Properly convert to user local time
        if (!($time = DateTime::createFromFormat('U', $timestamp, new DateTimeZone('UTC'))))
           return '';

        $offset = $user_timezone->getOffset($time);
        $timestamp = $time->getTimestamp() + $offset;
        return strftime($format ?: $strftimeFallback, $timestamp);
    }

    function parseDate($date, $format=false) {
        global $cfg;

        if (class_exists('IntlDateFormatter')) {
            $formatter = new IntlDateFormatter(
                Internationalization::getCurrentLocale(),
                null,
                null,
                null,
                IntlDateFormatter::GREGORIAN,
                $format ?: null
            );
            if ($cfg->isForce24HourTime()) {
                $format = str_replace(array('a', 'h'), array('', 'H'),
                    $formatter->getPattern());
                $formatter->setPattern($format);
            }
            return $formatter->parse($date);
        }
        // Fallback using strtotime
        return strtotime($date);
    }

    function time($timestamp, $fromDb=true, $format=false, $timezone=false, $user=false) {
        global $cfg;

        return self::__formatDate($timestamp,
            $format ?: $cfg->getTimeFormat(), $fromDb,
            IDF_NONE, IDF_SHORT,
            '%X', $timezone ?: $cfg->getTimezone(), $user);
    }

    function date($timestamp, $fromDb=true, $format=false, $timezone=false, $user=false) {
        global $cfg;

        return self::__formatDate($timestamp,
            $format ?: $cfg->getDateFormat(), $fromDb,
            IDF_SHORT, IDF_NONE,
            '%x', $timezone ?: $cfg->getTimezone(), $user);
    }

    function datetime($timestamp, $fromDb=true, $timezone=false, $user=false) {
        global $cfg;

        return self::__formatDate($timestamp,
                $cfg->getDateTimeFormat(), $fromDb,
                IDF_SHORT, IDF_SHORT,
                '%x %X', $timezone ?: $cfg->getTimezone(), $user);
    }

    function daydatetime($timestamp, $fromDb=true, $timezone=false, $user=false) {
        global $cfg;

        return self::__formatDate($timestamp,
                $cfg->getDayDateTimeFormat(), $fromDb,
                IDF_FULL, IDF_SHORT,
                '%x %X', $timezone ?: $cfg->getTimezone(), $user);
    }

    function getStrftimeFormat($format) {
        static $codes, $ids;

        if (!isset($codes)) {
            // This array is flipped because of duplicated formats on the
            // intl side due to slight differences in the libraries
            $codes = array(
            '%d' => 'dd',
            '%a' => 'EEE',
            '%e' => 'd',
            '%A' => 'EEEE',
            '%w' => 'e',
            '%w' => 'c',
            '%z' => 'D',

            '%V' => 'w',

            '%B' => 'MMMM',
            '%m' => 'MM',
            '%b' => 'MMM',

            '%g' => 'Y',
            '%G' => 'Y',
            '%Y' => 'y',
            '%y' => 'yy',

            '%P' => 'a',
            '%l' => 'h',
            '%k' => 'H',
            '%I' => 'hh',
            '%H' => 'HH',
            '%M' => 'mm',
            '%S' => 'ss',

            '%z' => 'ZZZ',
            '%Z' => 'z',
            );

            $flipped = array_flip($codes);
            krsort($flipped);

            // Also establish a list of ids, so we can do a creative replacement
            // without clobbering the common letters in the formats
            $keys = array_keys($flipped);
            $ids = array_combine($keys, array_map('chr', array_flip($keys)));

            // Now create an array from the id codes back to strftime codes
            $codes = array_combine($ids, $flipped);
        }
        // $ids => array(intl => #id)
        // $codes => array(#id => strftime)
        $format = str_replace(array_keys($ids), $ids, $format);
        $format = str_replace($ids, $codes, $format);

        return preg_replace_callback('`[\x00-\x1f]`',
            function($m) use ($ids) {
                return $ids[ord($m[0])];
            },
            $format
        );
    }

    // Thanks, http://stackoverflow.com/a/2955878/1025836
    /* static */
    function slugify($text) {
        // replace non letter or digits by -
        $text = preg_replace('~[^\p{L}\p{N}]+~u', '-', $text);

        // trim
        $text = trim($text, '-');

        // lowercase
        $text = strtolower($text);

        return (empty($text)) ? 'n-a' : $text;
    }

    /**
     * Parse RFC 2397 formatted data strings. Format according to the RFC
     * should look something like:
     *
     * data:[type/subtype][;charset=utf-8][;base64],data
     *
     * Parameters:
     * $data - (string) RFC2397 formatted data string
     * $output_encoding - (string:optional) Character set the input data
     *      should be encoded to.
     * $always_convert - (bool|default:true) If the input data string does
     *      not specify an input encding, assume iso-8859-1. If this flag is
     *      set, the output will always be transcoded to the declared
     *      output_encoding, if set.
     *
     * Returs:
     * array (data=>parsed and transcoded data string, type=>MIME type
     * declared in the data string or text/plain otherwise)
     *
     * References:
     * http://www.ietf.org/rfc/rfc2397.txt
     */
    function parseRfc2397($data, $output_encoding=false, $always_convert=true) {
        if (substr($data, 0, 5) != "data:")
            return array('data'=>$data, 'type'=>'text/plain');

        $data = substr($data, 5);
        list($meta, $contents) = explode(",", $data, 2);
        if ($meta)
            list($type, $extra) = explode(";", $meta, 2);
        else
            $extra = '';
        if (!isset($type) || !$type)
            $type = 'text/plain';

        $parameters = explode(";", $extra);

        # Handle 'charset' hint in $extra, such as
        # data:text/plain;charset=iso-8859-1,Blah
        # Convert to utf-8 since it's the encoding scheme for the database.
        $charset = ($always_convert) ? 'iso-8859-1' : false;
        foreach ($parameters as $p) {
            list($param, $value) = explode('=', $extra);
            if ($param == 'charset')
                $charset = $value;
            elseif ($param == 'base64')
                $contents = base64_decode($contents);
        }
        if ($output_encoding && $charset)
            $contents = Charset::transcode($contents, $charset, $output_encoding);

        return array(
            'data' => $contents,
            'type' => $type
        );
    }

    // Performs Unicode normalization (where possible) and splits words at
    // difficult word boundaries (for far eastern languages)
    function searchable($text, $lang=false) {
        global $cfg;

        if (function_exists('normalizer_normalize')) {
            // Normalize text input :: remove diacritics and such
            $text = normalizer_normalize($text, Normalizer::FORM_C);
        }

        if (false && class_exists('IntlBreakIterator')) {
            // Split by word boundaries
            if ($tokenizer = IntlBreakIterator::createWordInstance(
                    $lang ?: ($cfg ? $cfg->getPrimaryLanguage() : 'en_US'))
            ) {
                $tokenizer->setText($text);
                $tokens = array();
                foreach ($tokenizer as $token)
                    $tokens[] = $token;
                $text = implode(' ', $tokens);
            }
        }
        else {
            // Approximate word boundaries from Unicode chart at
            // http://www.unicode.org/reports/tr29/#Word_Boundaries

            // Punt for now

            // Drop extraneous whitespace
            $text = preg_replace('/(\s)\s+/u', '$1', $text);

            // Drop leading and trailing whitespace
            $text = trim($text);
        }
        return $text;
    }

    function relativeTime($to, $from=false, $granularity=1) {
        if (!$to)
            return false;
        $timestamp = $to;
        if (gettype($timestamp) === 'string')
            $timestamp = strtotime($timestamp);
        $from = $from ?: Misc::gmtime();
        if (gettype($timestamp) === 'string')
            $from = strtotime($from);
        $timeDiff = $from - $timestamp;
        $absTimeDiff = abs($timeDiff);

        // Roll back to the nearest multiple of $granularity
        $absTimeDiff -= $absTimeDiff % $granularity;

        // within 2 seconds
        if ($absTimeDiff <= 2) {
          return $timeDiff >= 0 ? __('just now') : __('now');
        }

        // within a minute
        if ($absTimeDiff < 60) {
          return sprintf($timeDiff >= 0 ? __('%d seconds ago') : __('in %d seconds'), $absTimeDiff);
        }

        // within 2 minutes
        if ($absTimeDiff < 120) {
          return sprintf($timeDiff >= 0 ? __('about a minute ago') : __('in about a minute'));
        }

        // within an hour
        if ($absTimeDiff < 3600) {
          return sprintf($timeDiff >= 0 ? __('%d minutes ago') : __('in %d minutes'), $absTimeDiff / 60);
        }

        // within 2 hours
        if ($absTimeDiff < 7200) {
          return ($timeDiff >= 0 ? __('about an hour ago') : __('in about an hour'));
        }

        // within 24 hours
        if ($absTimeDiff < 86400) {
          return sprintf($timeDiff >= 0 ? __('%d hours ago') : __('in %d hours'), $absTimeDiff / 3600);
        }

        // within 2 days
        $days2 = 2 * 86400;
        if ($absTimeDiff < $days2) {
            // XXX: yesterday / tomorrow?
          return $absTimeDiff >= 0 ? __('yesterday') : __('tomorrow');
        }

        // within 29 days
        $days29 = 29 * 86400;
        if ($absTimeDiff < $days29) {
          return sprintf($timeDiff >= 0 ? __('%d days ago') : __('in %d days'), $absTimeDiff / 86400);
        }

        // within 60 days
        $days60 = 60 * 86400;
        if ($absTimeDiff < $days60) {
          return ($timeDiff >= 0 ? __('about a month ago') : __('in about a month'));
        }

        $currTimeYears = date('Y', $from);
        $timestampYears = date('Y', $timestamp);
        $currTimeMonths = $currTimeYears * 12 + date('n', $from);
        $timestampMonths = $timestampYears * 12 + date('n', $timestamp);

        // within a year
        $monthDiff = $currTimeMonths - $timestampMonths;
        if ($monthDiff < 12 && $monthDiff > -12) {
          return sprintf($monthDiff >= 0 ? __('%d months ago') : __('in %d months'), abs($monthDiff));
        }

        $yearDiff = $currTimeYears - $timestampYears;
        if ($yearDiff < 2 && $yearDiff > -2) {
          return $yearDiff >= 0 ? __('a year ago') : __('in a year');
        }

        return sprintf($yearDiff >= 0 ? __('%d years ago') : __('in %d years'), abs($yearDiff));
    }
}

if (!class_exists('IntlDateFormatter')) {
    define('IDF_NONE', 0);
    define('IDF_SHORT', 1);
    define('IDF_FULL', 2);
}
else {
    define('IDF_NONE', IntlDateFormatter::NONE);
    define('IDF_SHORT', IntlDateFormatter::SHORT);
    define('IDF_FULL', IntlDateFormatter::FULL);
}

class FormattedLocalDate
implements TemplateVariable {
    var $date;
    var $timezone;
    var $fromdb;

    function __construct($date, $timezone=false, $user=false, $fromdb=true) {
        $this->date = $date;
        $this->timezone = $timezone;
        $this->user = $user;
        $this->fromdb = $fromdb;
    }

    function asVar() {
        return $this->getVar('long');
    }

    function __toString() {
        return $this->asVar();
    }

    function getVar($what) {
        // TODO: Rebase date format so that locale is discovered HERE.

        switch ($what) {
        case 'short':
            return Format::date($this->date, $this->fromdb, false, $this->timezone, $this->user);
        case 'long':
            return Format::datetime($this->date, $this->fromdb, $this->timezone, $this->user);
        case 'time':
            return Format::time($this->date, $this->fromdb, false, $this->timezone, $this->user);
        case 'full':
            return Format::daydatetime($this->date, $this->fromdb, $this->timezone, $this->user);
        }
    }

    static function getVarScope() {
        return array(
            'full' => 'Expanded date, e.g. day, month dd, yyyy',
            'long' => 'Date and time, e.g. d/m/yyyy hh:mm',
            'short' => 'Date only, e.g. d/m/yyyy',
            'time' => 'Time only, e.g. hh:mm',
        );
    }
}

class FormattedDate
extends FormattedLocalDate {
    function asVar() {
        return $this->getVar('system')->asVar();
    }

    function __toString() {
        global $cfg;
        return (string) new FormattedLocalDate($this->date, $cfg->getTimezone(), false, $this->fromdb);
    }

    function getVar($what, $context=null) {
        global $cfg;

        if ($rv = parent::getVar($what, $context))
            return $rv;

        switch ($what) {
        case 'user':
            // Fetch $recipient from the context and find that user's time zone
            if ($context && ($recipient = $context->getObj('recipient'))) {
                $tz = $recipient->getTimezone() ?: $cfg->getDefaultTimezone();
                return new FormattedLocalDate($this->date, $tz, $recipient);
            }
            // Don't resolve the variable until correspondance is sent out
            return false;
        case 'system':
            return new FormattedLocalDate($this->date, $cfg->getDefaultTimezone());
        }
    }

    function getHumanize() {
        return Format::relativeTime(Misc::db2gmtime($this->date));
    }

    static function getVarScope() {
        return parent::getVarScope() + array(
            'humanize' => 'Humanized time, e.g. about an hour ago',
            'user' => array(
                'class' => 'FormattedLocalDate', 'desc' => "Localize to recipient's time zone and locale"),
            'system' => array(
                'class' => 'FormattedLocalDate', 'desc' => 'Localize to system default time zone'),
        );
    }
}
?>
