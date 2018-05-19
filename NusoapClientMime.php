<?php
namespace Modules\NuSoap;
/*
$Id: nusoapmime.php,v 1.13 2015/05/18 20:15:08 snichol Exp $

NuSOAP - Web Services Toolkit for PHP

Copyright (c) 2002 NuSphere Corporation

This library is free software; you can redistribute it and/or
modify it under the terms of the GNU Lesser General Public
License as published by the Free Software Foundation; either
version 2.1 of the License, or (at your option) any later version.

This library is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
Lesser General Public License for more details.

You should have received a copy of the GNU Lesser General Public
License along with this library; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

The NuSOAP project home is:
http://sourceforge.net/projects/nusoap/

The primary support for NuSOAP is the mailing list:
nusoap-general@lists.sourceforge.net

If you have any questions or comments, please email:

Dietrich Ayala
dietrich@ganx4.com
http://dietrich.ganx4.com/nusoap

NuSphere Corporation
http://www.nusphere.com

*/

/*require_once('nusoap.php');*/
/* PEAR Mail_MIME library */
class Mail_mimeDecode
{
    /**
     * The raw email to decode
     *
     * @var    string
     * @access private
     */
    var $_input;
    /**
     * The header part of the input
     *
     * @var    string
     * @access private
     */
    var $_header;
    /**
     * The body part of the input
     *
     * @var    string
     * @access private
     */
    var $_body;
    /**
     * If an error occurs, this is used to store the message
     *
     * @var    string
     * @access private
     */
    var $_error;
    /**
     * Flag to determine whether to include bodies in the
     * returned object.
     *
     * @var    boolean
     * @access private
     */
    var $_include_bodies;
    /**
     * Flag to determine whether to decode bodies
     *
     * @var    boolean
     * @access private
     */
    var $_decode_bodies;
    /**
     * Flag to determine whether to decode headers
     *
     * @var    boolean
     * @access private
     */
    var $_decode_headers;
    /**
     * Flag to determine whether to include attached messages
     * as body in the returned object. Depends on $_include_bodies
     *
     * @var    boolean
     * @access private
     */
    var $_rfc822_bodies;
    /**
     * Constructor.
     *
     * Sets up the object, initialise the variables, and splits and
     * stores the header and body of the input.
     *
     * @param string The input to decode
     * @access public
     */
    function Mail_mimeDecode($input)
    {
        list($header, $body)   = $this->_splitBodyHeader($input);
        $this->_input          = $input;
        $this->_header         = $header;
        $this->_body           = $body;
        $this->_decode_bodies  = false;
        $this->_include_bodies = true;
        $this->_rfc822_bodies  = false;
    }
    /**
     * Begins the decoding process. If called statically
     * it will create an object and call the decode() method
     * of it.
     *
     * @param array An array of various parameters that determine
     *              various things:
     *              include_bodies - Whether to include the body in the returned
     *                               object.
     *              decode_bodies  - Whether to decode the bodies
     *                               of the parts. (Transfer encoding)
     *              decode_headers - Whether to decode headers
     *
     *              input          - If called statically, this will be treated
     *                               as the input
     *              charset        - convert all data to this charset
     * @return object Decoded results
     * @access public
     */
    function decode($params = null)
    {
        // determine if this method has been called statically
        $isStatic = empty($this) || !is_a($this, __CLASS__);
        // Have we been called statically?
	// If so, create an object and pass details to that.
        if ($isStatic AND isset($params['input'])) {
            $obj = new Mail_mimeDecode($params['input']);
            $structure = $obj->decode($params);
        // Called statically but no input
        } elseif ($isStatic) {
            return $this->raiseError('Called statically and no input given');
        // Called via an object
        } else {
            $this->_include_bodies = isset($params['include_bodies']) ?
	                             $params['include_bodies'] : false;
            $this->_decode_bodies  = isset($params['decode_bodies']) ?
	                             $params['decode_bodies']  : false;
            $this->_decode_headers = isset($params['decode_headers']) ?
	                             $params['decode_headers'] : false;
            $this->_rfc822_bodies  = isset($params['rfc_822bodies']) ?
	                             $params['rfc_822bodies']  : false;
            $this->_charset = isset($params['charset']) ?
                                 strtolower($params['charset']) : 'utf-8';
            if (is_string($this->_decode_headers) && !function_exists('iconv')) {
                 $this->raiseError('header decode conversion requested, however iconv is missing');
            }
            $structure = $this->_decode($this->_header, $this->_body);
            if ($structure === false) {
                $structure = $this->raiseError($this->_error);
            }
        }
        return $structure;
    }
    /**
     * Performs the decoding. Decodes the body string passed to it
     * If it finds certain content-types it will call itself in a
     * recursive fashion
     *
     * @param string Header section
     * @param string Body section
     * @return object Results of decoding process
     * @access private
     */
    function _decode($headers, $body, $default_ctype = 'text/plain')
    {
        $return = new stdClass;
        $return->headers = array();
        $headers = $this->_parseHeaders($headers);
        foreach ($headers as $value) {
            $value['value'] =  $this->_decodeHeader($value['value']);
            if (isset($return->headers[strtolower($value['name'])]) AND !is_array($return->headers[strtolower($value['name'])])) {
                $return->headers[strtolower($value['name'])]   = array($return->headers[strtolower($value['name'])]);
                $return->headers[strtolower($value['name'])][] = $value['value'];
            } elseif (isset($return->headers[strtolower($value['name'])])) {
                $return->headers[strtolower($value['name'])][] = $value['value'];
            } else {
                $return->headers[strtolower($value['name'])] = $value['value'];
            }
        }
        foreach ($headers as $key => $value) {
            $headers[$key]['name'] = strtolower($headers[$key]['name']);
            switch ($headers[$key]['name']) {
                case 'content-type':
                    $content_type = $this->_parseHeaderValue($headers[$key]['value']);
                    if (preg_match('/([0-9a-z+.-]+)\/([0-9a-z+.-]+)\; name=\"([0-9a-z+.-]+)/i', $headers[$key]['value'], $regs)) {
                        $return->ctype_primary   = $regs[1];
                        $return->ctype_secondary = $regs[2];
                        $return->filename = $regs[3];
                    }
                    elseif (preg_match('/([0-9a-z+.-]+)\/([0-9a-z+.-]+)/i', $content_type['value'], $regs)) {
                        $return->ctype_primary   = $regs[1];
                        $return->ctype_secondary = $regs[2];
                    }
                    if (isset($content_type['other'])) {
                        foreach($content_type['other'] as $p_name => $p_value) {
                            $return->ctype_parameters[$p_name] = $p_value;
                        }
                    }
                    break;
                case 'content-disposition':
                    $content_disposition = $this->_parseHeaderValue($headers[$key]['value']);
                    $return->disposition   = $content_disposition['value'];
                    if (isset($content_disposition['other'])) {
                        foreach($content_disposition['other'] as $p_name => $p_value) {
                            $return->d_parameters[$p_name] = $p_value;
                        }
                    }
                    break;
                case 'content-transfer-encoding':
                    $content_transfer_encoding = $this->_parseHeaderValue($headers[$key]['value']);
                    break;
            }
        }
        if (isset($content_type)) {
            switch (strtolower($content_type['value'])) {
                case 'text/plain':
                    $encoding = isset($content_transfer_encoding) ? $content_transfer_encoding['value'] : '7bit';
                    $charset = isset($return->ctype_parameters['charset']) ? $return->ctype_parameters['charset'] : $this->_charset;
                    $this->_include_bodies ? $return->body = ($this->_decode_bodies ? $this->_decodeBody($body, $encoding, $charset, true) : $body) : null;
                    break;
                case 'text/html':
                    $encoding = isset($content_transfer_encoding) ? $content_transfer_encoding['value'] : '7bit';
                    $charset = isset($return->ctype_parameters['charset']) ? $return->ctype_parameters['charset'] : $this->_charset;
                    $this->_include_bodies ? $return->body = ($this->_decode_bodies ? $this->_decodeBody($body, $encoding, $charset, true) : $body) : null;
                    break;
                case 'multipart/signed': // PGP
                case 'multipart/encrypted': // #190 encrypted parts will be treated as normal ones
                case 'multipart/parallel':
                case 'multipart/appledouble': // Appledouble mail
                case 'multipart/report': // RFC1892
                case 'multipart/digest':
                case 'multipart/alternative':
                case 'multipart/related':
                case 'multipart/relative': //#20431 - android
                case 'multipart/mixed':
                case 'application/vnd.wap.multipart.related':
                    if(!isset($content_type['other']['boundary'])){
                        $this->_error = 'No boundary found for ' . $content_type['value'] . ' part';
                        return false;
                    }
                    $default_ctype = (strtolower($content_type['value']) === 'multipart/digest') ? 'message/rfc822' : 'text/plain';
                    $parts = $this->_boundarySplit($body, $content_type['other']['boundary']);
                    for ($i = 0; $i < count($parts); $i++) {
                        list($part_header, $part_body) = $this->_splitBodyHeader($parts[$i]);
                        $part = $this->_decode($part_header, $part_body, $default_ctype);
                        if($part === false)
                            $part = $this->raiseError($this->_error);
                        $return->parts[] = $part;
                    }
                    break;
                case 'message/rfc822':
                case 'message/delivery-status': // #bug #18693
                    if ($this->_rfc822_bodies) {
                        $encoding = isset($content_transfer_encoding) ? $content_transfer_encoding['value'] : '7bit';
                        $charset = isset($return->ctype_parameters['charset']) ? $return->ctype_parameters['charset'] : $this->_charset;
                        $return->body = ($this->_decode_bodies ? $this->_decodeBody($body, $encoding, $charset, false) : $body);
                    }
                    $obj = new Mail_mimeDecode($body);
                    $return->parts[] = $obj->decode(array('include_bodies' => $this->_include_bodies,
                                                          'decode_bodies'  => $this->_decode_bodies,
                                                          'decode_headers' => $this->_decode_headers));
                    unset($obj);
                    // #213, KD 2015-06-29 - Always inline them because there is no "type" to them (they're text)
                    $return->disposition = 'inline';
                    break;
                    // #190, KD 2015-06-09 - Add type for S/MIME Encrypted messages; these must have the filename set explicitly (it won't work otherwise)
                        //and then falls through for the rest on purpose.
                case 'application/x-pkcs7-mime':
                case 'application/pkcs7-mime':
                    if (!isset($content_transfer_encoding['value'])) {
                        $content_transfer_encoding['value'] = 'base64';
                    }
                    // if there is no explicit charset, then don't try to convert to default charset, and make sure that only text mimetypes are converted
                    $charset = (isset($return->ctype_parameters['charset']) && ((isset($return->ctype_primary) && $return->ctype_primary == 'text') || !isset($return->ctype_primary)) ) ? $return->ctype_parameters['charset'] : '';
                    $part->body = ($this->_decode_bodies ? $this->_decodeBody($body, $content_transfer_encoding['value'], $charset, false) : $body);
                    $ctype = explode('/', strtolower($content_type['value']));
                    $part->ctype_parameters['name'] = 'smime.p7m';
                    $part->ctype_primary = $ctype[0];
                    $part->ctype_secondary = $ctype[1];
                    $part->d_parameters['size'] = strlen($part->body);
                    $return->parts[] = $part;
                    // Fall through intentionally
                default:
                    if(!isset($content_transfer_encoding['value']))
                        $content_transfer_encoding['value'] = '7bit';
                    // if there is no explicit charset, then don't try to convert to default charset, and make sure that only text mimetypes are converted
                    $charset = (isset($return->ctype_parameters['charset']) && ((isset($return->ctype_primary) && $return->ctype_primary == 'text') || !isset($return->ctype_primary)) )? $return->ctype_parameters['charset']: '';
                    $this->_include_bodies ? $return->body = ($this->_decode_bodies ? $this->_decodeBody($body, $content_transfer_encoding['value'], $charset, false) : $body) : null;
                    break;
            }
        } else {
            $ctype = explode('/', $default_ctype);
            $return->ctype_primary   = $ctype[0];
            $return->ctype_secondary = $ctype[1];
            $this->_include_bodies ? $return->body = ($this->_decode_bodies ? $this->_decodeBody($body) : $body) : null;
        }
        return $return;
    }
    /**
     * Given the output of the above function, this will return an
     * array of references to the parts, indexed by mime number.
     *
     * @param  object $structure   The structure to go through
     * @param  string $mime_number Internal use only.
     * @return array               Mime numbers
     */
    function &getMimeNumbers(&$structure, $no_refs = false, $mime_number = '', $prepend = '')
    {
        $return = array();
        if (!empty($structure->parts)) {
            if ($mime_number != '') {
                $structure->mime_id = $prepend . $mime_number;
                $return[$prepend . $mime_number] = &$structure;
            }
            for ($i = 0; $i < count($structure->parts); $i++) {
                if (!empty($structure->headers['content-type']) AND substr(strtolower($structure->headers['content-type']), 0, 8) == 'message/') {
                    $prepend      = $prepend . $mime_number . '.';
                    $_mime_number = '';
                } else {
                    $_mime_number = ($mime_number == '' ? $i + 1 : sprintf('%s.%s', $mime_number, $i + 1));
                }
                $arr = &Mail_mimeDecode::getMimeNumbers($structure->parts[$i], $no_refs, $_mime_number, $prepend);
                foreach ($arr as $key => $val) {
                    $no_refs ? $return[$key] = '' : $return[$key] = &$arr[$key];
                }
            }
        } else {
            if ($mime_number == '') {
                $mime_number = '1';
            }
            $structure->mime_id = $prepend . $mime_number;
            $no_refs ? $return[$prepend . $mime_number] = '' : $return[$prepend . $mime_number] = &$structure;
        }
        return $return;
    }
    /**
     * Given a string containing a header and body
     * section, this function will split them (at the first
     * blank line) and return them.
     *
     * @param string Input to split apart
     * @return array Contains header and body section
     * @access private
     */
    function _splitBodyHeader($input)
    {
        if (preg_match("/^(.*?)\r?\n\r?\n(.*)/s", $input, $match)) {
            return array($match[1], $match[2]);
        }
        // bug #17325 - empty bodies are allowed. - we just check that at least one line
        // of headers exist..
        if (count(explode("\n",$input))) {
            return array($input, '');
        }
        $this->_error = 'Could not split header and body';
        return false;
    }
    /**
     * Parse headers given in $input and return
     * as assoc array.
     *
     * @param string Headers to parse
     * @return array Contains parsed headers
     * @access private
     */
    function _parseHeaders($input)
    {
        if ($input !== '') {
            // Unfold the input
            $input   = preg_replace("/\r?\n/", "\r\n", $input);
            //#7065 - wrapping.. with encoded stuff.. - probably not needed,
            // wrapping space should only get removed if the trailing item on previous line is a
            // encoded character
            $input   = preg_replace("/=\r\n(\t| )+/", '=', $input);
            $input   = preg_replace("/\r\n(\t| )+/", ' ', $input);
            $headers = explode("\r\n", trim($input));
            $got_start = false;
            foreach ($headers as $value) {
                if (!$got_start) {
                    // munge headers for mbox style from
                    if ($value[0] == '>') {
                        $value = substring($value, 1); // remove mbox >
                    }
                    if (substr($value,0,5) == 'From ') {
                        $value = 'Return-Path: ' . substr($value, 5);
                    } else {
                        $got_start = true;
                    }
                }
                $hdr_name = substr($value, 0, $pos = strpos($value, ':'));
                $hdr_value = substr($value, $pos+1);
                if($hdr_value[0] == ' ') {
                    $hdr_value = substr($hdr_value, 1);
                }
                $return[] = array(
                                  'name'  => $hdr_name,
                                  'value' =>  $hdr_value
                                 );
            }
        } else {
            $return = array();
        }
        return $return;
    }
    /**
     * Function to parse a header value,
     * extract first part, and any secondary
     * parts (after ;) This function is not as
     * robust as it could be. Eg. header comments
     * in the wrong place will probably break it.
     *
     * Extra things this can handle
     *   filename*0=......
     *   filename*1=......
     *
     *  This is where lines are broken in, and need merging.
     *
     *   filename*0*=ENC'lang'urlencoded data.
     *   filename*1*=ENC'lang'urlencoded data.
     *
     *
     *
     * @param string Header value to parse
     * @return array Contains parsed result
     * @access private
     */
    function _parseHeaderValue($input)
    {
         if (($pos = strpos($input, ';')) === false) {
            $input = $this->_decodeHeader($input);
            $return['value'] = trim($input);
            return $return;
        }
        $value = substr($input, 0, $pos);
        $value = $this->_decodeHeader($value);
        $return['value'] = trim($value);
        $input = trim(substr($input, $pos+1));
        if (!strlen($input) > 0) {
            return $return;
        }
        // at this point input contains xxxx=".....";zzzz="...."
        // since we are dealing with quoted strings, we need to handle this properly..
        $i = 0;
        $l = strlen($input);
        $key = '';
        $val = false; // our string - including quotes..
        $q = false; // in quote..
        $lq = ''; // last quote..
        while ($i < $l) {
            $c = $input[$i];
            //var_dump(array('i'=>$i,'c'=>$c,'q'=>$q, 'lq'=>$lq, 'key'=>$key, 'val' =>$val));
            $escaped = false;
            if ($c == '\\') {
                $i++;
                if ($i == $l-1) { // end of string.
                    break;
                }
                $escaped = true;
                $c = $input[$i];
            }
            // state - in key..
            if ($val === false) {
                if (!$escaped && $c == '=') {
                    $val = '';
                    $key = trim($key);
                    $i++;
                    continue;
                }
                if (!$escaped && $c == ';') {
                    if ($key) { // a key without a value..
                        $key= trim($key);
                        $return['other'][$key] = '';
                    }
                    $key = '';
                }
                $key .= $c;
                $i++;
                continue;
            }
            // state - in value.. (as $val is set..)
            if ($q === false) {
                // not in quote yet.
                if ((!strlen($val) || $lq !== false) && $c == ' ' ||  $c == "\t") {
                    $i++;
                    continue; // skip leading spaces after '=' or after '"'
                }
                // do not de-quote 'xxx*= itesm..
                $key_is_trans = $key[strlen($key)-1] == '*';
                if (!$key_is_trans && !$escaped && ($c == '"' || $c == "'")) {
                    // start quoted area..
                    $q = $c;
                    // in theory should not happen raw text in value part..
                    // but we will handle it as a merged part of the string..
                    $val = !strlen(trim($val)) ? '' : trim($val);
                    $i++;
                    continue;
                }
                // got end....
                if (!$escaped && $c == ';') {
                    $return['other'][$key] = trim($val);
                    $val = false;
                    $key = '';
                    $lq = false;
                    $i++;
                    continue;
                }
                $val .= $c;
                $i++;
                continue;
            }
            // state - in quote..
            if (!$escaped && $c == $q) {  // potential exit state..
                // end of quoted string..
                $lq = $q;
                $q = false;
                $i++;
                continue;
            }
            // normal char inside of quoted string..
            $val.= $c;
            $i++;
        }
        // do we have anything left..
        if (strlen(trim($key)) || $val !== false) {
            $val = trim($val);
            $return['other'][$key] = $val;
        }
        $clean_others = array();
        // merge added values. eg. *1[*]
        foreach($return['other'] as $key =>$val) {
            if (preg_match('/\*[0-9]+\**$/', $key)) {
                $key = preg_replace('/(.*)\*[0-9]+(\**)$/', '\1\2', $key);
                if (isset($clean_others[$key])) {
                    $clean_others[$key] .= $val;
                    continue;
                }
            }
            $clean_others[$key] = $val;
        }
        // handle language translation of '*' ending others.
        foreach( $clean_others as $key =>$val) {
            if ( $key[strlen($key)-1] != '*') {
                $clean_others[strtolower($key)] = $val;
                continue;
            }
            unset($clean_others[$key]);
            $key = substr($key,0,-1);
            //extended-initial-value := [charset] "'" [language] "'"
            //              extended-other-values
            $match = array();
            $info = preg_match("/^([^']+)'([^']*)'(.*)$/", $val, $match);
            $clean_others[$key] = urldecode($match[3]);
            $clean_others[strtolower($key)] = $clean_others[$key];
            $clean_others[strtolower($key).'-charset'] = $match[1];
            $clean_others[strtolower($key).'-language'] = $match[2];
        }
        $return['other'] = $clean_others;
        // decode values.
        foreach($return['other'] as $key =>$val) {
            $charset = isset($return['other'][$key . '-charset']) ?
                $return['other'][$key . '-charset']  : false;
            $return['other'][$key] = $this->_decodeHeader($val, $charset);
        }
        return $return;
    }
    /**
     * This function splits the input based
     * on the given boundary
     *
     * @param string Input to parse
     * @return array Contains array of resulting mime parts
     * @access private
     */
    function _boundarySplit($input, $boundary, $eatline = false)
    {
        $parts = array();
        $bs_possible = substr($boundary, 2, -2);
        $bs_check = '\"' . $bs_possible . '\"';
        if ($boundary == $bs_check) {
            $boundary = $bs_possible;
        }
        // eatline is used by multipart/signed.
        $tmp = $eatline ?
            preg_split("/\r?\n--".preg_quote($boundary, '/')."(|--)\n/", $input) :
            preg_split("/--".preg_quote($boundary, '/')."((?=\s)|--)/", $input);
        $len = count($tmp) -1;
        for ($i = 1; $i < $len; $i++) {
            if (strlen(trim($tmp[$i]))) {
                $parts[] = $tmp[$i];
            }
        }
        // add the last part on if it does not end with the 'closing indicator'
        if (!empty($tmp[$len]) && strlen(trim($tmp[$len])) && $tmp[$len][0] != '-') {
            $parts[] = $tmp[$len];
        }
        return $parts;
    }
    /**
     * Given a header, this function will decode it
     * according to RFC2047. Probably not *exactly*
     * conformant, but it does pass all the given
     * examples (in RFC2047).
     *
     * @param string Input header value to decode
     * @return string Decoded header value
     * @access private
     */
    function _decodeHeader($input)
    {
        if (!$this->_decode_headers) {
            return $input;
        }
        // Remove white space between encoded-words
        $input = preg_replace('/(=\?[^?]+\?(q|b)\?[^?]*\?=)(\s)+=\?/i', '\1=?', $input);
        $encodedwords = false;
        $charset = '';
        // For each encoded-word...
        while (preg_match('/(=\?([^?]+)\?(q|b)\?([^?]*)\?=)/i', $input, $matches)) {
            $encodedwords = true;
            $encoded = $matches[1];
            $charset = $matches[2];
            $encoding = $matches[3];
            $text = $matches[4];
            switch (strtolower($encoding)) {
                case 'b':
                    $text = base64_decode($text);
                    break;
                case 'q':
                    $text = str_replace('_', ' ', $text);
                    preg_match_all('/=([a-f0-9]{2})/i', $text, $matches);
                    foreach ($matches[1] as $value)
                        $text = str_replace('=' . $value, chr(hexdec($value)), $text);
                    break;
            }
            $text = $this->_autoconvert_encoding($text, $charset);
            $input = str_replace($encoded, $text, $input);
        }
        if (!$encodedwords) {
            $input = $this->_autoconvert_encoding($input, $charset);
        }
        return $input;
    }
    /**
     * Given a body string and an encoding type,
     * this function will decode and return it.
     *
     * @param  string Input body to decode
     * @param  string Encoding type to use.
     * @param  string Charset
     * @param  boolean Must try to autodetect the real charset used
     * @return string Decoded body
     * @access private
     */
    function _decodeBody($input, $encoding = '7bit', $charset = '', $detectCharset =  true)
    {
        switch (strtolower($encoding)) {
            case 'quoted-printable':
                $input = $this->_quotedPrintableDecode($input);
                break;
            case 'base64':
                $input = base64_decode($input);
                break;
            case '7bit':
            case 'binary':
            case '8bit':
            default:
                break;
        }
        return $detectCharset ? $this->_autoconvert_encoding($input, $charset) : $input;
    }
    /**
     * Error handler dummy for _autoconvert_encoding
     *
     * @param integer $errno
     * @param string $errstr
     * @return boolean true
     * @access public static
     */
    static function _iconv_notice_handler($errno, $errstr) {
        return true;
    }
    /**
     * Autoconvert the text from any encoding. THIS WILL NEVER WORK 100%.
     * Will ignore the E_NOTICE for iconv when detecting ilegal charsets
     *
     * @param string $input Input string to convert
     * @param string $supposed_encoding Encoding that the text is possibly using
     * @return string Converted string
     * @access private
     */
    function _autoconvert_encoding($input, $supposed_encoding = "UTF-8") {
        $input_converted = $input;
        if (function_exists("mb_detect_order")) {
            $mb_order = array_merge(array($supposed_encoding), mb_detect_order());
            set_error_handler('Mail_mimeDecode::_iconv_notice_handler');
            // Default value in case of error
            $detected_encoding = $supposed_encoding;
            try {
                $detected_encoding = mb_detect_encoding($input, $mb_order, true);
                // In some cases mb_detect_encoding returns an empty string
                if ($detected_encoding === false || strlen($detected_encoding) == 0) {
                    $detected_encoding = $supposed_encoding;
                }
                $input_converted = iconv($detected_encoding, $this->_charset, $input);
            }
            catch(Exception $ex) {
                $this->raiseError($ex->getMessage());
            }
            restore_error_handler();
            if ($input_converted === false || mb_strlen($input_converted, $this->_charset) !== mb_strlen($input, $detected_encoding)) {
                ZLog::Write(LOGLEVEL_DEBUG, "Mail_mimeDecode()::_autoconvert_encoding(): Text cannot be correctly decoded, using original text. This will be ok if the part is not text, otherwise expect encoding errors");
                $input_converted = $input;
            }
        }
        return $input_converted;
    }
    /**
     * Given a quoted-printable string, this
     * function will decode and return it.
     *
     * @param  string Input body to decode
     * @return string Decoded body
     * @access private
     */
    function _quotedPrintableDecode($input)
    {
        // Remove soft line breaks
        $input = preg_replace("/=\r?\n/", '', $input);
        // Replace encoded characters
        $cb = create_function('$matches',  ' return chr(hexdec($matches[0]));');
        $input = preg_replace_callback( '/=([a-f0-9]{2})/i', $cb, $input);
        return $input;
    }
    /**
     * Checks the input for uuencoded files and returns
     * an array of them. Can be called statically, eg:
     *
     * $files =& Mail_mimeDecode::uudecode($some_text);
     *
     * It will check for the begin 666 ... end syntax
     * however and won't just blindly decode whatever you
     * pass it.
     *
     * @param  string Input body to look for attahcments in
     * @return array  Decoded bodies, filenames and permissions
     * @access public
     * @author Unknown
     */
    function &uudecode($input)
    {
        // Find all uuencoded sections
        preg_match_all("/begin ([0-7]{3}) (.+)\r?\n(.+)\r?\nend/Us", $input, $matches);
        for ($j = 0; $j < count($matches[3]); $j++) {
            $str      = $matches[3][$j];
            $filename = $matches[2][$j];
            $fileperm = $matches[1][$j];
            $file = '';
            $str = preg_split("/\r?\n/", trim($str));
            $strlen = count($str);
            for ($i = 0; $i < $strlen; $i++) {
                $pos = 1;
                $d = 0;
                $len=(int)(((ord(substr($str[$i],0,1)) -32) - ' ') & 077);
                while (($d + 3 <= $len) AND ($pos + 4 <= strlen($str[$i]))) {
                    $c0 = (ord(substr($str[$i],$pos,1)) ^ 0x20);
                    $c1 = (ord(substr($str[$i],$pos+1,1)) ^ 0x20);
                    $c2 = (ord(substr($str[$i],$pos+2,1)) ^ 0x20);
                    $c3 = (ord(substr($str[$i],$pos+3,1)) ^ 0x20);
                    $file .= chr(((($c0 - ' ') & 077) << 2) | ((($c1 - ' ') & 077) >> 4));
                    $file .= chr(((($c1 - ' ') & 077) << 4) | ((($c2 - ' ') & 077) >> 2));
                    $file .= chr(((($c2 - ' ') & 077) << 6) |  (($c3 - ' ') & 077));
                    $pos += 4;
                    $d += 3;
                }
                if (($d + 2 <= $len) && ($pos + 3 <= strlen($str[$i]))) {
                    $c0 = (ord(substr($str[$i],$pos,1)) ^ 0x20);
                    $c1 = (ord(substr($str[$i],$pos+1,1)) ^ 0x20);
                    $c2 = (ord(substr($str[$i],$pos+2,1)) ^ 0x20);
                    $file .= chr(((($c0 - ' ') & 077) << 2) | ((($c1 - ' ') & 077) >> 4));
                    $file .= chr(((($c1 - ' ') & 077) << 4) | ((($c2 - ' ') & 077) >> 2));
                    $pos += 3;
                    $d += 2;
                }
                if (($d + 1 <= $len) && ($pos + 2 <= strlen($str[$i]))) {
                    $c0 = (ord(substr($str[$i],$pos,1)) ^ 0x20);
                    $c1 = (ord(substr($str[$i],$pos+1,1)) ^ 0x20);
                    $file .= chr(((($c0 - ' ') & 077) << 2) | ((($c1 - ' ') & 077) >> 4));
                }
            }
            $files[] = array('filename' => $filename, 'fileperm' => $fileperm, 'filedata' => $file);
        }
        return $files;
    }
    /**
     * Get all parts in the message with specified type and concatenate them together, unless the
     * Content-Disposition is 'attachment', in which case the text is apparently an attachment
     *
     * @param string        $message        mimedecode message(part)
     * @param string        $message        message subtype
     * @param string        &$body          body reference
     * @param boolean       $replace_nr     replace \n\r with \n
     *
     * @return void
     * @access public
     */
    static function getBodyRecursive($message, $subtype, &$body, $replace_nr = false) {
        if(!isset($message->ctype_primary)) return;
        if(strcasecmp($message->ctype_primary, "text") == 0 && strcasecmp($message->ctype_secondary, $subtype) == 0 && isset($message->body)) {
            if ($replace_nr) {
                $body .= str_replace("\n", "\r\n", str_replace("\r", "", $message->body));
            }
            else {
                $body .= $message->body;
            }
        }
        if(strcasecmp($message->ctype_primary,"multipart")==0 && isset($message->parts) && is_array($message->parts)) {
            foreach($message->parts as $part) {
                // Check testing/samples/m1009.txt
                // Content-Type: text/plain; charset=us-ascii; name="hareandtoroise.txt" Content-Transfer-Encoding: 7bit Content-Disposition: inline; filename="hareandtoroise.txt"
                // We don't want to show that file text (outlook doesn't show it), so if we have content-disposition we don't apply recursivity
                if(!isset($part->disposition))  {
                    Mail_mimeDecode::getBodyRecursive($part, $subtype, $body, $replace_nr);
                }
            }
        }
    }
    /**
     * getSendArray() returns the arguments required for Mail::send()
     * used to build the arguments for a mail::send() call
     *
     * Usage:
     * $mailtext = Full email (for example generated by a template)
     * $decoder = new Mail_mimeDecode($mailtext);
     * $parts =  $decoder->getSendArray();
     * if (!PEAR::isError($parts) {
     *     list($recipents,$headers,$body) = $parts;
     *     $mail = Mail::factory('smtp');
     *     $mail->send($recipents,$headers,$body);
     * } else {
     *     echo $parts->message;
     * }
     * @return mixed   array of recipeint, headers,body or Pear_Error
     * @access public
     * @author Alan Knowles <alan@akbkhome.com>
     */
    function getSendArray()
    {
        // prevent warning if this is not set
        $this->_decode_headers = FALSE;
        $headerlist =$this->_parseHeaders($this->_header);
        $to = "";
        if (!$headerlist) {
            return $this->raiseError("Message did not contain headers");
        }
        foreach($headerlist as $item) {
            $header[$item['name']] = $item['value'];
            switch (strtolower($item['name'])) {
                case "to":
                case "cc":
                case "bcc":
                    $to .= ",".$item['value'];
                default:
                   break;
            }
        }
        if ($to == "") {
            return $this->raiseError("Message did not contain any recipents");
        }
        $to = substr($to,1);
        return array($to,$header,$this->_body);
    }
    /**
     * Returns a xml copy of the output of
     * Mail_mimeDecode::decode. Pass the output in as the
     * argument. This function can be called statically. Eg:
     *
     * $output = $obj->decode();
     * $xml    = Mail_mimeDecode::getXML($output);
     *
     * The DTD used for this should have been in the package. Or
     * alternatively you can get it from cvs, or here:
     * http://www.phpguru.org/xmail/xmail.dtd.
     *
     * @param  object Input to convert to xml. This should be the
     *                output of the Mail_mimeDecode::decode function
     * @return string XML version of input
     * @access public
     */
    function getXML($input)
    {
        $crlf    =  "\r\n";
        $output  = '<?xml version=\'1.0\'?>' . $crlf .
                   '<!DOCTYPE email SYSTEM "http://www.phpguru.org/xmail/xmail.dtd">' . $crlf .
                   '<email>' . $crlf .
                   Mail_mimeDecode::_getXML($input) .
                   '</email>';
        return $output;
    }
    /**
     * Function that does the actual conversion to xml. Does a single
     * mimepart at a time.
     *
     * @param  object  Input to convert to xml. This is a mimepart object.
     *                 It may or may not contain subparts.
     * @param  integer Number of tabs to indent
     * @return string  XML version of input
     * @access private
     */
    function _getXML($input, $indent = 1)
    {
        $htab    =  "\t";
        $crlf    =  "\r\n";
        $output  =  '';
        $headers = @(array)$input->headers;
        foreach ($headers as $hdr_name => $hdr_value) {
            // Multiple headers with this name
            if (is_array($headers[$hdr_name])) {
                for ($i = 0; $i < count($hdr_value); $i++) {
                    $output .= Mail_mimeDecode::_getXML_helper($hdr_name, $hdr_value[$i], $indent);
                }
            // Only one header of this sort
            } else {
                $output .= Mail_mimeDecode::_getXML_helper($hdr_name, $hdr_value, $indent);
            }
        }
        if (!empty($input->parts)) {
            for ($i = 0; $i < count($input->parts); $i++) {
                $output .= $crlf . str_repeat($htab, $indent) . '<mimepart>' . $crlf .
                           Mail_mimeDecode::_getXML($input->parts[$i], $indent+1) .
                           str_repeat($htab, $indent) . '</mimepart>' . $crlf;
            }
        } elseif (isset($input->body)) {
            $output .= $crlf . str_repeat($htab, $indent) . '<body><![CDATA[' .
                       $input->body . ']]></body>' . $crlf;
        }
        return $output;
    }
    /**
     * Helper function to _getXML(). Returns xml of a header.
     *
     * @param  string  Name of header
     * @param  string  Value of header
     * @param  integer Number of tabs to indent
     * @return string  XML version of input
     * @access private
     */
    function _getXML_helper($hdr_name, $hdr_value, $indent)
    {
        $htab   = "\t";
        $crlf   = "\r\n";
        $return = '';
        $new_hdr_value = ($hdr_name != 'received') ? Mail_mimeDecode::_parseHeaderValue($hdr_value) : array('value' => $hdr_value);
        $new_hdr_name  = str_replace(' ', '-', ucwords(str_replace('-', ' ', $hdr_name)));
        // Sort out any parameters
        if (!empty($new_hdr_value['other'])) {
            foreach ($new_hdr_value['other'] as $paramname => $paramvalue) {
                $params[] = str_repeat($htab, $indent) . $htab . '<parameter>' . $crlf .
                            str_repeat($htab, $indent) . $htab . $htab . '<paramname>' . htmlspecialchars($paramname) . '</paramname>' . $crlf .
                            str_repeat($htab, $indent) . $htab . $htab . '<paramvalue>' . htmlspecialchars($paramvalue) . '</paramvalue>' . $crlf .
                            str_repeat($htab, $indent) . $htab . '</parameter>' . $crlf;
            }
            $params = implode('', $params);
        } else {
            $params = '';
        }
        $return = str_repeat($htab, $indent) . '<header>' . $crlf .
                  str_repeat($htab, $indent) . $htab . '<headername>' . htmlspecialchars($new_hdr_name) . '</headername>' . $crlf .
                  str_repeat($htab, $indent) . $htab . '<headervalue>' . htmlspecialchars($new_hdr_value['value']) . '</headervalue>' . $crlf .
                  $params .
                  str_repeat($htab, $indent) . '</header>' . $crlf;
        return $return;
    }
    /**
     * Z-Push helper for error logging
     * removing PEAR dependency
     *
     * @param  string  debug message
     * @return boolean always false as there was an error
     * @access private
     */
    function raiseError($message) {
        ZLog::Write(LOGLEVEL_ERROR, "mimeDecode error: ". $message);
        return false;
    }
} // End of class
/**
 * The Mail_mimePart class is used to create MIME E-mail messages
 *
 * This class enables you to manipulate and build a mime email
 * from the ground up. The Mail_Mime class is a userfriendly api
 * to this class for people who aren't interested in the internals
 * of mime mail.
 * This class however allows full control over the email.
 *
 * Compatible with PHP versions 4 and 5
 *
 * LICENSE: This LICENSE is in the BSD license style.
 * Copyright (c) 2002-2003, Richard Heyes <richard@phpguru.org>
 * Copyright (c) 2003-2006, PEAR <pear-group@php.net>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or
 * without modification, are permitted provided that the following
 * conditions are met:
 *
 * - Redistributions of source code must retain the above copyright
 *   notice, this list of conditions and the following disclaimer.
 * - Redistributions in binary form must reproduce the above copyright
 *   notice, this list of conditions and the following disclaimer in the
 *   documentation and/or other materials provided with the distribution.
 * - Neither the name of the authors, nor the names of its contributors
 *   may be used to endorse or promote products derived from this
 *   software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF
 * THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @category  Mail
 * @package   Mail_Mime
 * @author    Richard Heyes  <richard@phpguru.org>
 * @author    Cipriano Groenendal <cipri@php.net>
 * @author    Sean Coates <sean@php.net>
 * @author    Aleksander Machniak <alec@php.net>
 * @copyright 2003-2006 PEAR <pear-group@php.net>
 * @license   http://www.opensource.org/licenses/bsd-license.php BSD License
 * @version   CVS: $Id$
 * @link      http://pear.php.net/package/Mail_mime
 */
/**
 * Z-Push changes
 *
 * removed PEAR dependency by implementing own raiseError()
 *
 * Reference implementation used:
 * http://download.pear.php.net/package/Mail_Mime-1.8.9.tgz
 *
 *
 */
/**
 * The Mail_mimePart class is used to create MIME E-mail messages
 *
 * This class enables you to manipulate and build a mime email
 * from the ground up. The Mail_Mime class is a userfriendly api
 * to this class for people who aren't interested in the internals
 * of mime mail.
 * This class however allows full control over the email.
 *
 * @category  Mail
 * @package   Mail_Mime
 * @author    Richard Heyes  <richard@phpguru.org>
 * @author    Cipriano Groenendal <cipri@php.net>
 * @author    Sean Coates <sean@php.net>
 * @author    Aleksander Machniak <alec@php.net>
 * @copyright 2003-2006 PEAR <pear-group@php.net>
 * @license   http://www.opensource.org/licenses/bsd-license.php BSD License
 * @version   Release: @package_version@
 * @link      http://pear.php.net/package/Mail_mime
 */
class Mail_mimePart
{
    /**
    * The encoding type of this part
    *
    * @var string
    * @access private
    */
    var $_encoding;
    /**
    * An array of subparts
    *
    * @var array
    * @access private
    */
    var $_subparts;
    /**
    * The output of this part after being built
    *
    * @var string
    * @access private
    */
    var $_encoded;
    /**
    * Headers for this part
    *
    * @var array
    * @access private
    */
    var $_headers;
    /**
    * The body of this part (not encoded)
    *
    * @var string
    * @access private
    */
    var $_body;
    /**
    * The location of file with body of this part (not encoded)
    *
    * @var string
    * @access private
    */
    var $_body_file;
    /**
    * The end-of-line sequence
    *
    * @var string
    * @access private
    */
    var $_eol = "\r\n";
    /**
    * Constructor.
    *
    * Sets up the object.
    *
    * @param string $body   The body of the mime part if any.
    * @param array  $params An associative array of optional parameters:
    *     content_type      - The content type for this part eg multipart/mixed
    *     encoding          - The encoding to use, 7bit, 8bit,
    *                         base64, or quoted-printable
    *     charset           - Content character set
    *     cid               - Content ID to apply
    *     disposition       - Content disposition, inline or attachment
    *     filename          - Filename parameter for content disposition
    *     description       - Content description
    *     name_encoding     - Encoding of the attachment name (Content-Type)
    *                         By default filenames are encoded using RFC2231
    *                         Here you can set RFC2047 encoding (quoted-printable
    *                         or base64) instead
    *     filename_encoding - Encoding of the attachment filename (Content-Disposition)
    *                         See 'name_encoding'
    *     headers_charset   - Charset of the headers e.g. filename, description.
    *                         If not set, 'charset' will be used
    *     eol               - End of line sequence. Default: "\r\n"
    *     headers           - Hash array with additional part headers. Array keys can be
    *                         in form of <header_name>:<parameter_name>
    *     body_file         - Location of file with part's body (instead of $body)
    *
    * @access public
    */
    function __construct($body = '', $params = array())
    {
        if (!empty($params['eol'])) {
            $this->_eol = $params['eol'];
        } else if (defined('MAIL_MIMEPART_CRLF')) { // backward-copat.
            $this->_eol = MAIL_MIMEPART_CRLF;
        }
        // Additional part headers
        if (!empty($params['headers']) && is_array($params['headers'])) {
            $headers = $params['headers'];
        }
        foreach ($params as $key => $value) {
            switch ($key) {
            case 'encoding':
                $this->_encoding = $value;
                $headers['Content-Transfer-Encoding'] = $value;
                break;
            case 'cid':
                $headers['Content-ID'] = '<' . $value . '>';
                break;
            case 'location':
                $headers['Content-Location'] = $value;
                break;
            case 'body_file':
                $this->_body_file = $value;
                break;
            // for backward compatibility
            case 'dfilename':
                $params['filename'] = $value;
                break;
            }
        }
        // Default content-type
        if (empty($params['content_type'])) {
            $params['content_type'] = 'text/plain';
        }
        // Content-Type
        $headers['Content-Type'] = $params['content_type'];
        if (!empty($params['charset'])) {
            $charset = "charset={$params['charset']}";
            // place charset parameter in the same line, if possible
            if ((strlen($headers['Content-Type']) + strlen($charset) + 16) <= 76) {
                $headers['Content-Type'] .= '; ';
            } else {
                $headers['Content-Type'] .= ';' . $this->_eol . ' ';
            }
            $headers['Content-Type'] .= $charset;
            // Default headers charset
            if (!isset($params['headers_charset'])) {
                $params['headers_charset'] = $params['charset'];
            }
        }
        // header values encoding parameters
        $h_charset  = !empty($params['headers_charset']) ? $params['headers_charset'] : 'US-ASCII';
        $h_language = !empty($params['language']) ? $params['language'] : null;
        $h_encoding = !empty($params['name_encoding']) ? $params['name_encoding'] : null;
        if (!empty($params['filename'])) {
            $headers['Content-Type'] .= ';' . $this->_eol;
            $headers['Content-Type'] .= $this->_buildHeaderParam(
                'name', $params['filename'], $h_charset, $h_language, $h_encoding
            );
        }
        // Content-Disposition
        if (!empty($params['disposition'])) {
            $headers['Content-Disposition'] = $params['disposition'];
            if (!empty($params['filename'])) {
                $headers['Content-Disposition'] .= ';' . $this->_eol;
                $headers['Content-Disposition'] .= $this->_buildHeaderParam(
                    'filename', $params['filename'], $h_charset, $h_language,
                    !empty($params['filename_encoding']) ? $params['filename_encoding'] : null
                );
            }
            // add attachment size
            $size = $this->_body_file ? filesize($this->_body_file) : strlen($body);
            if ($size) {
                $headers['Content-Disposition'] .= ';' . $this->_eol . ' size=' . $size;
            }
        }
        if (!empty($params['description'])) {
            $headers['Content-Description'] = $this->encodeHeader(
                'Content-Description', $params['description'], $h_charset, $h_encoding,
                $this->_eol
            );
        }
        // Search and add existing headers' parameters
        foreach ($headers as $key => $value) {
            $items = explode(':', $key);
            if (count($items) == 2) {
                $header = $items[0];
                $param  = $items[1];
                if (isset($headers[$header])) {
                    $headers[$header] .= ';' . $this->_eol;
                }
                $headers[$header] .= $this->_buildHeaderParam(
                    $param, $value, $h_charset, $h_language, $h_encoding
                );
                unset($headers[$key]);
            }
        }
        // Default encoding
        if (!isset($this->_encoding)) {
            $this->_encoding = '7bit';
        }
        // Assign stuff to member variables
        $this->_encoded  = array();
        $this->_headers  = $headers;
        $this->_body     = $body;
    }
    /**
     * Encodes and returns the email. Also stores
     * it in the encoded member variable
     *
     * @param string $boundary Pre-defined boundary string
     *
     * @return An associative array containing two elements,
     *         body and headers. The headers element is itself
     *         an indexed array. On error returns PEAR error object.
     * @access public
     */
    function encode($boundary=null)
    {
        $encoded =& $this->_encoded;
        if (count($this->_subparts)) {
            $boundary = $boundary ? $boundary : '=_' . md5(rand() . microtime());
            $eol = $this->_eol;
            $this->_headers['Content-Type'] .= ";$eol boundary=\"$boundary\"";
            $encoded['body'] = '';
            for ($i = 0; $i < count($this->_subparts); $i++) {
                $encoded['body'] .= '--' . $boundary . $eol;
                $tmp = $this->_subparts[$i]->encode();
                if ($this->_isError($tmp)) {
                    return $tmp;
                }
                foreach ($tmp['headers'] as $key => $value) {
                    $encoded['body'] .= $key . ': ' . $value . $eol;
                }
                $encoded['body'] .= $eol . $tmp['body'] . $eol;
            }
            $encoded['body'] .= '--' . $boundary . '--' . $eol;
        } else if ($this->_body) {
            $encoded['body'] = $this->_getEncodedData($this->_body, $this->_encoding);
        } else if ($this->_body_file) {
            // Temporarily reset magic_quotes_runtime for file reads and writes
            if ($magic_quote_setting = get_magic_quotes_runtime()) {
                @ini_set('magic_quotes_runtime', 0);
            }
            $body = $this->_getEncodedDataFromFile($this->_body_file, $this->_encoding);
            if ($magic_quote_setting) {
                @ini_set('magic_quotes_runtime', $magic_quote_setting);
            }
            if ($this->_isError($body)) {
                return $body;
            }
            $encoded['body'] = $body;
        } else {
            $encoded['body'] = '';
        }
        // Add headers to $encoded
        $encoded['headers'] =& $this->_headers;
        return $encoded;
    }
    /**
     * Encodes and saves the email into file. File must exist.
     * Data will be appended to the file.
     *
     * @param string  $filename  Output file location
     * @param string  $boundary  Pre-defined boundary string
     * @param boolean $skip_head True if you don't want to save headers
     *
     * @return array An associative array containing message headers
     *               or PEAR error object
     * @access public
     * @since 1.6.0
     */
    function encodeToFile($filename, $boundary=null, $skip_head=false)
    {
        if (file_exists($filename) && !is_writable($filename)) {
            $err = $this->_raiseError('File is not writeable: ' . $filename);
            return $err;
        }
        if (!($fh = fopen($filename, 'ab'))) {
            $err = $this->_raiseError('Unable to open file: ' . $filename);
            return $err;
        }
        // Temporarily reset magic_quotes_runtime for file reads and writes
        if ($magic_quote_setting = get_magic_quotes_runtime()) {
            @ini_set('magic_quotes_runtime', 0);
        }
        $res = $this->_encodePartToFile($fh, $boundary, $skip_head);
        fclose($fh);
        if ($magic_quote_setting) {
            @ini_set('magic_quotes_runtime', $magic_quote_setting);
        }
        return $this->_isError($res) ? $res : $this->_headers;
    }
    /**
     * Encodes given email part into file
     *
     * @param string  $fh        Output file handle
     * @param string  $boundary  Pre-defined boundary string
     * @param boolean $skip_head True if you don't want to save headers
     *
     * @return array True on sucess or PEAR error object
     * @access private
     */
    function _encodePartToFile($fh, $boundary=null, $skip_head=false)
    {
        $eol = $this->_eol;
        if (count($this->_subparts)) {
            $boundary = $boundary ? $boundary : '=_' . md5(rand() . microtime());
            $this->_headers['Content-Type'] .= ";$eol boundary=\"$boundary\"";
        }
        if (!$skip_head) {
            foreach ($this->_headers as $key => $value) {
                fwrite($fh, $key . ': ' . $value . $eol);
            }
            $f_eol = $eol;
        } else {
            $f_eol = '';
        }
        if (count($this->_subparts)) {
            for ($i = 0; $i < count($this->_subparts); $i++) {
                fwrite($fh, $f_eol . '--' . $boundary . $eol);
                $res = $this->_subparts[$i]->_encodePartToFile($fh);
                if ($this->_isError($res)) {
                    return $res;
                }
                $f_eol = $eol;
            }
            fwrite($fh, $eol . '--' . $boundary . '--' . $eol);
        } else if ($this->_body) {
            fwrite($fh, $f_eol . $this->_getEncodedData($this->_body, $this->_encoding));
        } else if ($this->_body_file) {
            fwrite($fh, $f_eol);
            $res = $this->_getEncodedDataFromFile(
                $this->_body_file, $this->_encoding, $fh
            );
            if ($this->_isError($res)) {
                return $res;
            }
        }
        return true;
    }
    /**
     * Adds a subpart to current mime part and returns
     * a reference to it
     *
     * @param string $body   The body of the subpart, if any.
     * @param array  $params The parameters for the subpart, same
     *                       as the $params argument for constructor.
     *
     * @return Mail_mimePart A reference to the part you just added. In PHP4, it is
     *                       crucial if using multipart/* in your subparts that
     *                       you use =& in your script when calling this function,
     *                       otherwise you will not be able to add further subparts.
     * @access public
     */
    function &addSubpart($body, $params)
    {
        $this->_subparts[] = $part = new Mail_mimePart($body, $params);
        return $part;
    }
    /**
     * Returns encoded data based upon encoding passed to it
     *
     * @param string $data     The data to encode.
     * @param string $encoding The encoding type to use, 7bit, base64,
     *                         or quoted-printable.
     *
     * @return string
     * @access private
     */
    function _getEncodedData($data, $encoding)
    {
        switch ($encoding) {
        case 'quoted-printable':
            return $this->_quotedPrintableEncode($data);
            break;
        case 'base64':
            return rtrim(chunk_split(base64_encode($data), 76, $this->_eol));
            break;
        case '8bit':
        case 'binary':
        case '7bit':
        default:
            return $data;
        }
    }
    /**
     * Returns encoded data based upon encoding passed to it
     *
     * @param string   $filename Data file location
     * @param string   $encoding The encoding type to use, 7bit, base64,
     *                           or quoted-printable.
     * @param resource $fh       Output file handle. If set, data will be
     *                           stored into it instead of returning it
     *
     * @return string Encoded data or PEAR error object
     * @access private
     */
    function _getEncodedDataFromFile($filename, $encoding, $fh=null)
    {
        if (!is_readable($filename)) {
            $err = $this->_raiseError('Unable to read file: ' . $filename);
            return $err;
        }
        if (!($fd = fopen($filename, 'rb'))) {
            $err = $this->_raiseError('Could not open file: ' . $filename);
            return $err;
        }
        $data = '';
        switch ($encoding) {
        case 'quoted-printable':
            while (!feof($fd)) {
                $buffer = $this->_quotedPrintableEncode(fgets($fd));
                if ($fh) {
                    fwrite($fh, $buffer);
                } else {
                    $data .= $buffer;
                }
            }
            break;
        case 'base64':
            while (!feof($fd)) {
                // Should read in a multiple of 57 bytes so that
                // the output is 76 bytes per line. Don't use big chunks
                // because base64 encoding is memory expensive
                $buffer = fread($fd, 57 * 9198); // ca. 0.5 MB
                $buffer = base64_encode($buffer);
                $buffer = chunk_split($buffer, 76, $this->_eol);
                if (feof($fd)) {
                    $buffer = rtrim($buffer);
                }
                if ($fh) {
                    fwrite($fh, $buffer);
                } else {
                    $data .= $buffer;
                }
            }
            break;
        case '8bit':
        case '7bit':
        default:
            while (!feof($fd)) {
                $buffer = fread($fd, 1048576); // 1 MB
                if ($fh) {
                    fwrite($fh, $buffer);
                } else {
                    $data .= $buffer;
                }
            }
        }
        fclose($fd);
        if (!$fh) {
            return $data;
        }
    }
    /**
     * Encodes data to quoted-printable standard.
     *
     * @param string $input    The data to encode
     * @param int    $line_max Optional max line length. Should
     *                         not be more than 76 chars
     *
     * @return string Encoded data
     *
     * @access private
     */
    function _quotedPrintableEncode($input , $line_max = 76)
    {
        $eol = $this->_eol;
        /*
        // imap_8bit() is extremely fast, but doesn't handle properly some characters
        if (function_exists('imap_8bit') && $line_max == 76) {
            $input = preg_replace('/\r?\n/', "\r\n", $input);
            $input = imap_8bit($input);
            if ($eol != "\r\n") {
                $input = str_replace("\r\n", $eol, $input);
            }
            return $input;
        }
        */
        $lines  = preg_split("/\r?\n/", $input);
        $escape = '=';
        $output = '';
        while (list($idx, $line) = each($lines)) {
            $newline = '';
            $i = 0;
            while (isset($line[$i])) {
                $char = $line[$i];
                $dec  = ord($char);
                $i++;
                if (($dec == 32) && (!isset($line[$i]))) {
                    // convert space at eol only
                    $char = '=20';
                } elseif ($dec == 9 && isset($line[$i])) {
                    ; // Do nothing if a TAB is not on eol
                } elseif (($dec == 61) || ($dec < 32) || ($dec > 126)) {
                    $char = $escape . sprintf('%02X', $dec);
                } elseif (($dec == 46) && (($newline == '')
                    || ((strlen($newline) + strlen("=2E")) >= $line_max))
                ) {
                    // Bug #9722: convert full-stop at bol,
                    // some Windows servers need this, won't break anything (cipri)
                    // Bug #11731: full-stop at bol also needs to be encoded
                    // if this line would push us over the line_max limit.
                    $char = '=2E';
                }
                // Note, when changing this line, also change the ($dec == 46)
                // check line, as it mimics this line due to Bug #11731
                // EOL is not counted
                if ((strlen($newline) + strlen($char)) >= $line_max) {
                    // soft line break; " =\r\n" is okay
                    $output  .= $newline . $escape . $eol;
                    $newline  = '';
                }
                $newline .= $char;
            } // end of for
            $output .= $newline . $eol;
            unset($lines[$idx]);
        }
        // Don't want last crlf
        $output = substr($output, 0, -1 * strlen($eol));
        return $output;
    }
    /**
     * Encodes the parameter of a header.
     *
     * @param string $name      The name of the header-parameter
     * @param string $value     The value of the paramter
     * @param string $charset   The characterset of $value
     * @param string $language  The language used in $value
     * @param string $encoding  Parameter encoding. If not set, parameter value
     *                          is encoded according to RFC2231
     * @param int    $maxLength The maximum length of a line. Defauls to 75
     *
     * @return string
     *
     * @access private
     */
    function _buildHeaderParam($name, $value, $charset=null, $language=null,
        $encoding=null, $maxLength=75
    ) {
        // RFC 2045:
        // value needs encoding if contains non-ASCII chars or is longer than 78 chars
        if (!preg_match('#[^\x20-\x7E]#', $value)) {
            $token_regexp = '#([^\x21\x23-\x27\x2A\x2B\x2D'
                . '\x2E\x30-\x39\x41-\x5A\x5E-\x7E])#';
            if (!preg_match($token_regexp, $value)) {
                // token
                if (strlen($name) + strlen($value) + 3 <= $maxLength) {
                    return " {$name}={$value}";
                }
            } else {
                // quoted-string
                $quoted = addcslashes($value, '\\"');
                if (strlen($name) + strlen($quoted) + 5 <= $maxLength) {
                    return " {$name}=\"{$quoted}\"";
                }
            }
        }
        // RFC2047: use quoted-printable/base64 encoding
        if ($encoding == 'quoted-printable' || $encoding == 'base64') {
            return $this->_buildRFC2047Param($name, $value, $charset, $encoding);
        }
        // RFC2231:
        $encValue = preg_replace_callback(
            '/([^\x21\x23\x24\x26\x2B\x2D\x2E\x30-\x39\x41-\x5A\x5E-\x7E])/',
            array($this, '_encodeReplaceCallback'), $value
        );
        $value = "$charset'$language'$encValue";
        $header = " {$name}*={$value}";
        if (strlen($header) <= $maxLength) {
            return $header;
        }
        $preLength = strlen(" {$name}*0*=");
        $maxLength = max(16, $maxLength - $preLength - 3);
        $maxLengthReg = "|(.{0,$maxLength}[^\%][^\%])|";
        $headers = array();
        $headCount = 0;
        while ($value) {
            $matches = array();
            $found = preg_match($maxLengthReg, $value, $matches);
            if ($found) {
                $headers[] = " {$name}*{$headCount}*={$matches[0]}";
                $value = substr($value, strlen($matches[0]));
            } else {
                $headers[] = " {$name}*{$headCount}*={$value}";
                $value = '';
            }
            $headCount++;
        }
        $headers = implode(';' . $this->_eol, $headers);
        return $headers;
    }
    /**
     * Encodes header parameter as per RFC2047 if needed
     *
     * @param string $name      The parameter name
     * @param string $value     The parameter value
     * @param string $charset   The parameter charset
     * @param string $encoding  Encoding type (quoted-printable or base64)
     * @param int    $maxLength Encoded parameter max length. Default: 76
     *
     * @return string Parameter line
     * @access private
     */
    function _buildRFC2047Param($name, $value, $charset,
        $encoding='quoted-printable', $maxLength=76
    ) {
        // WARNING: RFC 2047 says: "An 'encoded-word' MUST NOT be used in
        // parameter of a MIME Content-Type or Content-Disposition field",
        // but... it's supported by many clients/servers
        $quoted = '';
        if ($encoding == 'base64') {
            $value = base64_encode($value);
            $prefix = '=?' . $charset . '?B?';
            $suffix = '?=';
            // 2 x SPACE, 2 x '"', '=', ';'
            $add_len = strlen($prefix . $suffix) + strlen($name) + 6;
            $len = $add_len + strlen($value);
            while ($len > $maxLength) {
                // We can cut base64-encoded string every 4 characters
                $real_len = floor(($maxLength - $add_len) / 4) * 4;
                $_quote = substr($value, 0, $real_len);
                $value = substr($value, $real_len);
                $quoted .= $prefix . $_quote . $suffix . $this->_eol . ' ';
                $add_len = strlen($prefix . $suffix) + 4; // 2 x SPACE, '"', ';'
                $len = strlen($value) + $add_len;
            }
            $quoted .= $prefix . $value . $suffix;
        } else {
            // quoted-printable
            $value = $this->encodeQP($value);
            $prefix = '=?' . $charset . '?Q?';
            $suffix = '?=';
            // 2 x SPACE, 2 x '"', '=', ';'
            $add_len = strlen($prefix . $suffix) + strlen($name) + 6;
            $len = $add_len + strlen($value);
            while ($len > $maxLength) {
                $length = $maxLength - $add_len;
                // don't break any encoded letters
                if (preg_match("/^(.{0,$length}[^\=][^\=])/", $value, $matches)) {
                    $_quote = $matches[1];
                }
                $quoted .= $prefix . $_quote . $suffix . $this->_eol . ' ';
                $value = substr($value, strlen($_quote));
                $add_len = strlen($prefix . $suffix) + 4; // 2 x SPACE, '"', ';'
                $len = strlen($value) + $add_len;
            }
            $quoted .= $prefix . $value . $suffix;
        }
        return " {$name}=\"{$quoted}\"";
    }
    /**
     * Encodes a header as per RFC2047
     *
     * @param string $name     The header name
     * @param string $value    The header data to encode
     * @param string $charset  Character set name
     * @param string $encoding Encoding name (base64 or quoted-printable)
     * @param string $eol      End-of-line sequence. Default: "\r\n"
     *
     * @return string          Encoded header data (without a name)
     * @access public
     * @since 1.6.1
     */
    function encodeHeader($name, $value, $charset='ISO-8859-1',
        $encoding='quoted-printable', $eol="\r\n"
    ) {
        // Structured headers
        $comma_headers = array(
            'from', 'to', 'cc', 'bcc', 'sender', 'reply-to',
            'resent-from', 'resent-to', 'resent-cc', 'resent-bcc',
            'resent-sender', 'resent-reply-to',
            'mail-reply-to', 'mail-followup-to',
            'return-receipt-to', 'disposition-notification-to',
        );
        $other_headers = array(
            'references', 'in-reply-to', 'message-id', 'resent-message-id',
        );
        $name = strtolower($name);
        if (in_array($name, $comma_headers)) {
            $separator = ',';
        } else if (in_array($name, $other_headers)) {
            $separator = ' ';
        }
        if (!$charset) {
            $charset = 'ISO-8859-1';
        }
        // Structured header (make sure addr-spec inside is not encoded)
        if (!empty($separator)) {
            // Simple e-mail address regexp
            $email_regexp = '([^\s<]+|("[^\r\n"]+"))@\S+';
            $parts = Mail_mimePart::_explodeQuotedString("[\t$separator]", $value);
            $value = '';
            foreach ($parts as $part) {
                $part = preg_replace('/\r?\n[\s\t]*/', $eol . ' ', $part);
                $part = trim($part);
                if (!$part) {
                    continue;
                }
                if ($value) {
                    $value .= $separator == ',' ? $separator . ' ' : ' ';
                } else {
                    $value = $name . ': ';
                }
                // let's find phrase (name) and/or addr-spec
                if (preg_match('/^<' . $email_regexp . '>$/', $part)) {
                    $value .= $part;
                } else if (preg_match('/^' . $email_regexp . '$/', $part)) {
                    // address without brackets and without name
                    $value .= $part;
                } else if (preg_match('/<*' . $email_regexp . '>*$/', $part, $matches)) {
                    // address with name (handle name)
                    $address = $matches[0];
                    $word = str_replace($address, '', $part);
                    $word = trim($word);
                    // check if phrase requires quoting
                    if ($word) {
                        // non-ASCII: require encoding
                        if (preg_match('#([^\s\x21-\x7E]){1}#', $word)) {
                            if ($word[0] == '"' && $word[strlen($word)-1] == '"') {
                                // de-quote quoted-string, encoding changes
                                // string to atom
                                $search = array("\\\"", "\\\\");
                                $replace = array("\"", "\\");
                                $word = str_replace($search, $replace, $word);
                                $word = substr($word, 1, -1);
                            }
                            // find length of last line
                            if (($pos = strrpos($value, $eol)) !== false) {
                                $last_len = strlen($value) - $pos;
                            } else {
                                $last_len = strlen($value);
                            }
                            $word = Mail_mimePart::encodeHeaderValue(
                                $word, $charset, $encoding, $last_len, $eol
                            );
                        } else if (($word[0] != '"' || $word[strlen($word)-1] != '"')
                            && preg_match('/[\(\)\<\>\\\.\[\]@,;:"]/', $word)
                        ) {
                            // ASCII: quote string if needed
                            $word = '"'.addcslashes($word, '\\"').'"';
                        }
                    }
                    $value .= $word.' '.$address;
                } else {
                    // addr-spec not found, don't encode (?)
                    $value .= $part;
                }
                // RFC2822 recommends 78 characters limit, use 76 from RFC2047
                $value = wordwrap($value, 76, $eol . ' ');
            }
            // remove header name prefix (there could be EOL too)
            $value = preg_replace(
                '/^'.$name.':('.preg_quote($eol, '/').')* /', '', $value
            );
        } else {
            // Unstructured header
            // non-ASCII: require encoding
            if (preg_match('#([^\s\x21-\x7E]){1}#', $value)) {
                if ($value[0] == '"' && $value[strlen($value)-1] == '"') {
                    // de-quote quoted-string, encoding changes
                    // string to atom
                    $search = array("\\\"", "\\\\");
                    $replace = array("\"", "\\");
                    $value = str_replace($search, $replace, $value);
                    $value = substr($value, 1, -1);
                }
                $value = Mail_mimePart::encodeHeaderValue(
                    $value, $charset, $encoding, strlen($name) + 2, $eol
                );
            } else if (strlen($name.': '.$value) > 78) {
                // ASCII: check if header line isn't too long and use folding
                $value = preg_replace('/\r?\n[\s\t]*/', $eol . ' ', $value);
                $tmp = wordwrap($name.': '.$value, 78, $eol . ' ');
                $value = preg_replace('/^'.$name.':\s*/', '', $tmp);
                // hard limit 998 (RFC2822)
                $value = wordwrap($value, 998, $eol . ' ', true);
            }
        }
        return $value;
    }
    /**
     * Explode quoted string
     *
     * @param string $delimiter Delimiter expression string for preg_match()
     * @param string $string    Input string
     *
     * @return array            String tokens array
     * @access private
     */
    function _explodeQuotedString($delimiter, $string)
    {
        $result = array();
        $strlen = strlen($string);
        for ($q=$p=$i=0; $i < $strlen; $i++) {
            if ($string[$i] == "\""
                && (empty($string[$i-1]) || $string[$i-1] != "\\")
            ) {
                $q = $q ? false : true;
            } else if (!$q && preg_match("/$delimiter/", $string[$i])) {
                $result[] = substr($string, $p, $i - $p);
                $p = $i + 1;
            }
        }
        $result[] = substr($string, $p);
        return $result;
    }
    /**
     * Encodes a header value as per RFC2047
     *
     * @param string $value      The header data to encode
     * @param string $charset    Character set name
     * @param string $encoding   Encoding name (base64 or quoted-printable)
     * @param int    $prefix_len Prefix length. Default: 0
     * @param string $eol        End-of-line sequence. Default: "\r\n"
     *
     * @return string            Encoded header data
     * @access public
     * @since 1.6.1
     */
    function encodeHeaderValue($value, $charset, $encoding, $prefix_len=0, $eol="\r\n")
    {
        // #17311: Use multibyte aware method (requires mbstring extension)
        if ($result = Mail_mimePart::encodeMB($value, $charset, $encoding, $prefix_len, $eol)) {
            return $result;
        }
        // Generate the header using the specified params and dynamicly
        // determine the maximum length of such strings.
        // 75 is the value specified in the RFC.
        $encoding = $encoding == 'base64' ? 'B' : 'Q';
        $prefix = '=?' . $charset . '?' . $encoding .'?';
        $suffix = '?=';
        $maxLength = 75 - strlen($prefix . $suffix);
        $maxLength1stLine = $maxLength - $prefix_len;
        if ($encoding == 'B') {
            // Base64 encode the entire string
            $value = base64_encode($value);
            // We can cut base64 every 4 characters, so the real max
            // we can get must be rounded down.
            $maxLength = $maxLength - ($maxLength % 4);
            $maxLength1stLine = $maxLength1stLine - ($maxLength1stLine % 4);
            $cutpoint = $maxLength1stLine;
            $output = '';
            while ($value) {
                // Split translated string at every $maxLength
                $part = substr($value, 0, $cutpoint);
                $value = substr($value, $cutpoint);
                $cutpoint = $maxLength;
                // RFC 2047 specifies that any split header should
                // be separated by a CRLF SPACE.
                if ($output) {
                    $output .= $eol . ' ';
                }
                $output .= $prefix . $part . $suffix;
            }
            $value = $output;
        } else {
            // quoted-printable encoding has been selected
            $value = Mail_mimePart::encodeQP($value);
            // This regexp will break QP-encoded text at every $maxLength
            // but will not break any encoded letters.
            $reg1st = "|(.{0,$maxLength1stLine}[^\=][^\=])|";
            $reg2nd = "|(.{0,$maxLength}[^\=][^\=])|";
            if (strlen($value) > $maxLength1stLine) {
                // Begin with the regexp for the first line.
                $reg = $reg1st;
                $output = '';
                while ($value) {
                    // Split translated string at every $maxLength
                    // But make sure not to break any translated chars.
                    $found = preg_match($reg, $value, $matches);
                    // After this first line, we need to use a different
                    // regexp for the first line.
                    $reg = $reg2nd;
                    // Save the found part and encapsulate it in the
                    // prefix & suffix. Then remove the part from the
                    // $value_out variable.
                    if ($found) {
                        $part = $matches[0];
                        $len = strlen($matches[0]);
                        $value = substr($value, $len);
                    } else {
                        $part = $value;
                        $value = '';
                    }
                    // RFC 2047 specifies that any split header should
                    // be separated by a CRLF SPACE
                    if ($output) {
                        $output .= $eol . ' ';
                    }
                    $output .= $prefix . $part . $suffix;
                }
                $value = $output;
            } else {
                $value = $prefix . $value . $suffix;
            }
        }
        return $value;
    }
    /**
     * Encodes the given string using quoted-printable
     *
     * @param string $str String to encode
     *
     * @return string     Encoded string
     * @access public
     * @since 1.6.0
     */
    function encodeQP($str)
    {
        // Bug #17226 RFC 2047 restricts some characters
        // if the word is inside a phrase, permitted chars are only:
        // ASCII letters, decimal digits, "!", "*", "+", "-", "/", "=", and "_"
        // "=",  "_",  "?" must be encoded
        $regexp = '/([\x22-\x29\x2C\x2E\x3A-\x40\x5B-\x60\x7B-\x7E\x80-\xFF])/';
        $str = preg_replace_callback(
            $regexp, array('Mail_mimePart', '_qpReplaceCallback'), $str
        );
        return str_replace(' ', '_', $str);
    }
    /**
     * Encodes the given string using base64 or quoted-printable.
     * This method makes sure that encoded-word represents an integral
     * number of characters as per RFC2047.
     *
     * @param string $str        String to encode
     * @param string $charset    Character set name
     * @param string $encoding   Encoding name (base64 or quoted-printable)
     * @param int    $prefix_len Prefix length. Default: 0
     * @param string $eol        End-of-line sequence. Default: "\r\n"
     *
     * @return string     Encoded string
     * @access public
     * @since 1.8.0
     */
    function encodeMB($str, $charset, $encoding, $prefix_len=0, $eol="\r\n")
    {
        if (!function_exists('mb_substr') || !function_exists('mb_strlen')) {
            return;
        }
        $encoding = $encoding == 'base64' ? 'B' : 'Q';
        // 75 is the value specified in the RFC
        $prefix = '=?' . $charset . '?'.$encoding.'?';
        $suffix = '?=';
        $maxLength = 75 - strlen($prefix . $suffix);
        // A multi-octet character may not be split across adjacent encoded-words
        // So, we'll loop over each character
        // mb_stlen() with wrong charset will generate a warning here and return null
        $length      = mb_strlen($str, $charset);
        $result      = '';
        $line_length = $prefix_len;
        if ($encoding == 'B') {
            // base64
            $start = 0;
            $prev  = '';
            for ($i=1; $i<=$length; $i++) {
                // See #17311
                $chunk = mb_substr($str, $start, $i-$start, $charset);
                $chunk = base64_encode($chunk);
                $chunk_len = strlen($chunk);
                if ($line_length + $chunk_len == $maxLength || $i == $length) {
                    if ($result) {
                        $result .= "\n";
                    }
                    $result .= $chunk;
                    $line_length = 0;
                    $start = $i;
                } else if ($line_length + $chunk_len > $maxLength) {
                    if ($result) {
                        $result .= "\n";
                    }
                    if ($prev) {
                        $result .= $prev;
                    }
                    $line_length = 0;
                    $start = $i - 1;
                } else {
                    $prev = $chunk;
                }
            }
        } else {
            // quoted-printable
            // see encodeQP()
            $regexp = '/([\x22-\x29\x2C\x2E\x3A-\x40\x5B-\x60\x7B-\x7E\x80-\xFF])/';
            for ($i=0; $i<=$length; $i++) {
                $char = mb_substr($str, $i, 1, $charset);
                // RFC recommends underline (instead of =20) in place of the space
                // that's one of the reasons why we're not using iconv_mime_encode()
                if ($char == ' ') {
                    $char = '_';
                    $char_len = 1;
                } else {
                    $char = preg_replace_callback(
                        $regexp, array('Mail_mimePart', '_qpReplaceCallback'), $char
                    );
                    $char_len = strlen($char);
                }
                if ($line_length + $char_len > $maxLength) {
                    if ($result) {
                        $result .= "\n";
                    }
                    $line_length = 0;
                }
                $result      .= $char;
                $line_length += $char_len;
            }
        }
        if ($result) {
            $result = $prefix
                .str_replace("\n", $suffix.$eol.' '.$prefix, $result).$suffix;
        }
        return $result;
    }
    /**
     * Callback function to replace extended characters (\x80-xFF) with their
     * ASCII values (RFC2047: quoted-printable)
     *
     * @param array $matches Preg_replace's matches array
     *
     * @return string        Encoded character string
     * @access private
     */
    function _qpReplaceCallback($matches)
    {
        return sprintf('=%02X', ord($matches[1]));
    }
    /**
     * Callback function to replace extended characters (\x80-xFF) with their
     * ASCII values (RFC2231)
     *
     * @param array $matches Preg_replace's matches array
     *
     * @return string        Encoded character string
     * @access private
     */
    function _encodeReplaceCallback($matches)
    {
        return sprintf('%%%02X', ord($matches[1]));
    }
    /**
     * PEAR::isError implementation
     *
     * @param mixed $data Object
     *
     * @return bool True if object is an instance of PEAR_Error
     * @access private
     */
    function _isError($data)
    {
        // PEAR::isError() is not PHP 5.4 compatible (see Bug #19473)
        //if (is_object($data) && is_a($data, 'PEAR_Error')) {
        //    return true;
        //}
        //return false;
        return $data === false;
    }
    /**
     * Z-Push helper for error logging
     * removing PEAR dependency
     *
     * @param  string  debug message
     * @return boolean always false as there was an error
     * @access private
     */
    function _raiseError($message) {
        ZLog::Write(LOGLEVEL_ERROR, "mimePart error: ". $message);
        return false;
    }
} // End of class

/**
* nusoap_client_mime client supporting MIME attachments defined at
* http://www.w3.org/TR/SOAP-attachments.  It depends on the PEAR Mail_MIME library.
*
* @author   Scott Nichol <snichol@users.sourceforge.net>
* @author	Thanks to Guillaume and Henning Reich for posting great attachment code to the mail list
 * @author   Yamir Ramirez <ysramire@gmail.com>
* @version  $Id: nusoapmime.php,v 1.13 2015/05/18 20:15:08 snichol Exp $
* @access   public
*/
class NusoapClientMime extends NusoapClient {
	/**
	 * @var array Each array element in the return is an associative array with keys
	 * data, filename, contenttype, cid
	 * @access private
	 */
	var $requestAttachments = array();
	/**
	 * @var array Each array element in the return is an associative array with keys
	 * data, filename, contenttype, cid
	 * @access private
	 */
	var $responseAttachments;
	/**
	 * @var string
	 * @access private
	 */
	var $mimeContentType;
	
	/**
	* adds a MIME attachment to the current request.
	*
	* If the $data parameter contains an empty string, this method will read
	* the contents of the file named by the $filename parameter.
	*
	* If the $cid parameter is false, this method will generate the cid.
	*
	* @param string $data The data of the attachment
	* @param string $filename The filename of the attachment (default is empty string)
	* @param string $contenttype The MIME Content-Type of the attachment (default is application/octet-stream)
	* @param string $cid The content-id (cid) of the attachment (default is false)
	* @return string The content-id (cid) of the attachment
	* @access public
	*/
	function addAttachment($data, $filename = '', $contenttype = 'application/octet-stream', $cid = false) {
		if (! $cid) {
			$cid = md5(uniqid(time()));
		}

		$info['data'] = $data;
		$info['filename'] = $filename;
		$info['contenttype'] = $contenttype;
		$info['cid'] = $cid;
		
		$this->requestAttachments[] = $info;

		return $cid;
	}

	/**
	* clears the MIME attachments for the current request.
	*
	* @access public
	*/
	function clearAttachments() {
		$this->requestAttachments = array();
	}

	/**
	* gets the MIME attachments from the current response.
	*
	* Each array element in the return is an associative array with keys
	* data, filename, contenttype, cid.  These keys correspond to the parameters
	* for addAttachment.
	*
	* @return array The attachments.
	* @access public
	*/
	function getAttachments() {
		return $this->responseAttachments;
	}

	/**
	* gets the HTTP body for the current request.
	*
	* @param string $soapmsg The SOAP payload
	* @return string The HTTP body, which includes the SOAP payload
	* @access private
	*/
	function getHTTPBody($soapmsg) {
		if (count($this->requestAttachments) > 0) {
			$params['content_type'] = 'multipart/related; type="text/xml"';
			$mimeMessage = new Mail_mimePart('', $params);
			unset($params);

			$params['content_type'] = 'text/xml';
			$params['encoding']     = '8bit';
			$params['charset']      = $this->soap_defencoding;
			$mimeMessage->addSubpart($soapmsg, $params);
			
			foreach ($this->requestAttachments as $att) {
				unset($params);

				$params['content_type'] = $att['contenttype'];
				$params['encoding']     = 'base64';
				$params['disposition']  = 'attachment';
				$params['dfilename']    = $att['filename'];
				$params['cid']          = $att['cid'];

				if ($att['data'] == '' && $att['filename'] <> '') {
					if ($fd = fopen($att['filename'], 'rb')) {
						$data = fread($fd, filesize($att['filename']));
						fclose($fd);
					} else {
						$data = '';
					}
					$mimeMessage->addSubpart($data, $params);
				} else {
					$mimeMessage->addSubpart($att['data'], $params);
				}
			}

			$output = $mimeMessage->encode();
			$mimeHeaders = $output['headers'];
	
			foreach ($mimeHeaders as $k => $v) {
				$this->debug("MIME header $k: $v");
				if (strtolower($k) == 'content-type') {
					// PHP header() seems to strip leading whitespace starting
					// the second line, so force everything to one line
					$this->mimeContentType = str_replace("\r\n", " ", $v);
				}
			}
			return $output['body'];
		}
		return parent::getHTTPBody($soapmsg);
	}
	
	/**
	* gets the HTTP content type for the current request.
	*
	* Note: getHTTPBody must be called before this.
	*
	* @return string the HTTP content type for the current request.
	* @access private
	*/
	function getHTTPContentType() {
		if (count($this->requestAttachments) > 0) {
			return $this->mimeContentType;
		}
		return parent::getHTTPContentType();
	}
	
	/**
	* gets the HTTP content type charset for the current request.
	* returns false for non-text content types.
	*
	* Note: getHTTPBody must be called before this.
	*
	* @return string the HTTP content type charset for the current request.
	* @access private
	*/
	function getHTTPContentTypeCharset() {
		if (count($this->requestAttachments) > 0) {
			return false;
		}
		return parent::getHTTPContentTypeCharset();
	}

	/**
	* processes SOAP message returned from server
	*
	* @param	array	$headers	The HTTP headers
	* @param	string	$data		unprocessed response data from server
	* @return	mixed	value of the message, decoded into a PHP type
	* @access   private
	*/
    function parseResponse($headers, $data) {
		$this->debug('Entering parseResponse() for payload of length ' . strlen($data) . ' and type of ' . $headers['content-type']);
		$this->responseAttachments = array();
		if (strstr($headers['content-type'], 'multipart/related')) {
			$this->debug('Decode multipart/related');
			$input = '';
			foreach ($headers as $k => $v) {
				$input .= "$k: $v\r\n";
			}
			$params['input'] = $input . "\r\n" . $data;
			$params['include_bodies'] = true;
			$params['decode_bodies'] = true;
			$params['decode_headers'] = true;
			
			$structure = Mail_mimeDecode::decode($params);

			foreach ($structure->parts as $part) {
				if (!isset($part->disposition) && (strstr($part->headers['content-type'], 'text/xml'))) {
					$this->debug('Have root part of type ' . $part->headers['content-type']);
					$root = $part->body;
					$return = parent::parseResponse($part->headers, $part->body);
				} else {
					$this->debug('Have an attachment of type ' . $part->headers['content-type']);
					$info['data'] = $part->body;
					$info['filename'] = isset($part->d_parameters['filename']) ? $part->d_parameters['filename'] : '';
					$info['contenttype'] = $part->headers['content-type'];
					$info['cid'] = $part->headers['content-id'];
					$this->responseAttachments[] = $info;
				}
			}
		
			if (isset($return)) {
				$this->responseData = $root;
				return $return;
			}
			
			$this->setError('No root part found in multipart/related content');
			return '';
		}
		$this->debug('Not multipart/related');
		return parent::parseResponse($headers, $data);
	}
}

/*
 *	For backwards compatiblity, define soapclientmime unless the PHP SOAP extension is loaded.
 */
if (!extension_loaded('soap')) {
	class soapclientmime extends NusoapClientMime {
	}
}


?>
