<?php
// UpNote.php - UpNote shared note macro for MoniWiki
// Embeds publicly shared UpNote notes in wiki pages.
//
// Author:   Hyacinth <y_hyacinth@hotmail.com>
// Homepage: https://hyacinth.kr
// Version:  1.0
// License:  Public Domain (plugin code)
//
// Usage:
//   [[upnote(UpNote Shared URL)]]
//   [[upnote(UpNote Shared URL, title=True, signature=True)]]
//
// Options (both default to False):
//   title=True      Include the note title.
//   signature=True  Append a link to the original shared note.
//
// Requirements:
//   PHP DOM extension and either the PHP cURL extension or a curl executable
//   compatible with the server OS. External curl requires PHP proc_open.
//   Deploy local/upnote-cacert.pem alongside this plugin for TLS verification.
//
// Optional config.php settings:
//   $upnote_cainfo = '/absolute/path/to/cacert.pem';
//   $upnote_curl_binary = 'C:/tools/curl/bin/curl.exe';
//   Setting upnote_curl_binary uses external curl instead of PHP's cURL extension.
//
// CA bundle source and updates: https://curl.se/docs/caextract.html
// The CA bundle is separately licensed under MPL 2.0: https://mozilla.org/MPL/2.0/

function _upnote_valid_url($url) {
    return preg_match('#\Ahttps://getupnote\.com/share/notes/[A-Za-z0-9_-]+/[A-Za-z0-9_-]+/?(?:\?[^\s<>"\x00-\x20]*)?(?:\#[^\s<>"\x00-\x20]*)?\z#i', $url);
}

function _upnote_tls_diagnostics($ch, $ca_file) {
    $version = curl_version();
    $verify = defined('CURLINFO_SSL_VERIFYRESULT') ?
        curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT) : 'unavailable';
    $digest = function_exists('hash_file') ? hash_file('sha256', $ca_file) : 'unavailable';
    return ' [UpNote TLSdiag=1; PHP='.PHP_VERSION.
        '; cURL='.$version['version'].'; TLS='.$version['ssl_version'].
        '; errno='.curl_errno($ch).'; verify='.$verify.
        '; CA_SHA256='.$digest.'; UTC='.gmdate('Y-m-d H:i:s').']';
}

function _upnote_curl_request($url, $ca_file, $remaining, &$status, &$header_size, &$error) {
    if (!function_exists('curl_init')) {
        $error = 'PHP cURL extension is required';
        return false;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(3, $remaining));
    curl_setopt($ch, CURLOPT_TIMEOUT, $remaining);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_CAINFO, $ca_file);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_USERAGENT, 'MoniWiki UpNote macro');
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    if ($response === false) {
        $error = 'HTTPS request failed: '.curl_error($ch).
            _upnote_tls_diagnostics($ch, $ca_file);
    }
    curl_close($ch);
    return $response;
}

function _upnote_curl_config_value($value) {
    // curl's config format has its own escaping rules. Values are sent on
    // stdin, so URL query characters never become shell command text.
    return '"'.str_replace(array('\\', '"', "\r", "\n"),
        array('\\\\', '\\"', '\\r', '\\n'), $value).'"';
}

function _upnote_binary_request($binary, $url, $ca_file, $remaining, &$status, &$header_size, &$error) {
    if (!function_exists('proc_open')) {
        $error = 'external cURL requires PHP proc_open';
        return false;
    }
    $executable = realpath($binary);
    if ($executable === false || !is_file($executable)) {
        $error = 'configured $upnote_curl_binary is not a curl executable';
        return false;
    }

    $windows = strtoupper(substr(PHP_OS, 0, 3)) == 'WIN';
    // PHP 5.3 cannot pass an argv array to proc_open. On Windows, bypass
    // cmd.exe entirely, including percent-variable expansion and shell quoting.
    $command = ($windows ? '"'.$executable.'"' : escapeshellarg($executable)).
        ' --disable --config -';
    $config = "silent\nshow-error\ninclude\ncompressed\nproto = \"=https\"\n".
        'url = '._upnote_curl_config_value($url)."\n".
        'cacert = '._upnote_curl_config_value($ca_file)."\n".
        'connect-timeout = '.min(3, $remaining)."\nmax-time = ".$remaining."\n".
        'user-agent = "MoniWiki UpNote macro"'."\n".
        'write-out = "\nUPNOTE_META:%{http_code}:%{size_header}\n"'."\n";

    // A temporary stderr file avoids pipe deadlocks on legacy Windows PHP.
    $stderr = tmpfile();
    if ($stderr === false) {
        $error = 'could not create external cURL error stream';
        return false;
    }
    $descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => $stderr);
    $process = @proc_open($command, $descriptors, $pipes, null, null,
        array('bypass_shell' => true, 'suppress_errors' => true));
    if (!is_resource($process)) {
        fclose($stderr);
        $error = 'could not start external cURL; check executable compatibility and permissions';
        return false;
    }
    fwrite($pipes[0], $config);
    fclose($pipes[0]);
    $response = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $exit_code = proc_close($process);
    rewind($stderr);
    $details = stream_get_contents($stderr, 2048);
    fclose($stderr);
    if ($exit_code != 0) {
        $error = 'external cURL failed (exit '.$exit_code.'): '.trim($details);
        return false;
    }
    if (!preg_match('/\nUPNOTE_META:([0-9]{3}):([0-9]+)\n\z/', $response, $match)) {
        $error = 'external cURL returned no HTTP status';
        return false;
    }
    $status = (int)$match[1];
    $header_size = (int)$match[2];
    return substr($response, 0, -strlen($match[0]));
}

function _upnote_fetch($url, &$error) {
    global $DBInfo;

    // Legacy hosts may have a missing or outdated system CA store. Use a
    // maintained root bundle while retaining peer and hostname verification.
    $ca_file = !empty($DBInfo->upnote_cainfo) ? $DBInfo->upnote_cainfo :
        dirname(dirname(__FILE__)).'/local/upnote-cacert.pem';
    if (!is_file($ca_file) || !is_readable($ca_file)) {
        $error = 'CA bundle not readable; upload local/upnote-cacert.pem or set $upnote_cainfo in config.php';
        return false;
    }

    // Share requests, including redirects, have a single ten-second budget.
    $started = microtime(true);
    for ($redirects = 0; $redirects <= 3; $redirects++) {
        $remaining = 10 - (int)ceil(microtime(true) - $started);
        if ($remaining <= 0) {
            $error = 'request timed out';
            return false;
        }

        $status = 0;
        $header_size = 0;
        if (!empty($DBInfo->upnote_curl_binary)) {
            $response = _upnote_binary_request($DBInfo->upnote_curl_binary, $url,
                $ca_file, $remaining, $status, $header_size, $error);
        } else {
            $response = _upnote_curl_request($url, $ca_file, $remaining,
                $status, $header_size, $error);
        }
        if ($response === false) return false;
        if ($status == 200) {
            // PHP 5.3 returns false for substr at the end of a string.
            $body = substr($response, $header_size);
            return $body === false ? '' : $body;
        }
        if (in_array($status, array(301, 302, 303, 307, 308))) {
            $headers = substr($response, 0, $header_size);
            if (!preg_match('/^Location:\s*([^\r\n]+)/mi', $headers, $match)) {
                $error = 'redirect has no Location header';
                return false;
            }
            $url = trim($match[1]);
            if (substr($url, 0, 1) == '/' && substr($url, 0, 2) != '//') {
                $url = 'https://getupnote.com'.$url;
            }
            if (!_upnote_valid_url($url)) {
                $error = 'redirect is not an UpNote shared URL';
                return false;
            }
            continue;
        }
        $error = 'HTTP '.$status;
        return false;
    }

    $error = 'too many redirects';
    return false;
}

function _upnote_render($html, $show_title, &$error) {
    if (!class_exists('DOMDocument')) {
        $error = 'PHP DOM extension is required';
        return false;
    }
    if (trim($html) === '') {
        $error = 'request content empty';
        return false;
    }

    $document = new DOMDocument('1.0', 'UTF-8');
    $previous_errors = libxml_use_internal_errors(true);
    $source = '<?xml encoding="UTF-8">'.$html;
    if (PHP_VERSION_ID < 50400) {
        // PHP 5.3 accepts no loadHTML options argument. Disable external
        // entity loading separately and restore the previous libxml setting.
        $previous_loader = libxml_disable_entity_loader(true);
        $loaded = $document->loadHTML($source);
        libxml_disable_entity_loader($previous_loader);
    } else {
        $loaded = $document->loadHTML($source, LIBXML_NONET);
    }
    libxml_clear_errors();
    libxml_use_internal_errors($previous_errors);
    if (!$loaded) {
        $error = 'could not parse shared note';
        return false;
    }

    $xpath = new DOMXPath($document);
    $editors = $xpath->query('//div[@id="shine-editor"]');
    if (!$editors->length) {
        $error = 'shared note content not found';
        return false;
    }
    $editor = $editors->item(0);
    if (!$show_title) {
        // UpNote currently uses h2; older shared pages used a div title.
        foreach ($editor->childNodes as $child) {
            if ($child->nodeType == XML_ELEMENT_NODE) {
                if (in_array(strtolower($child->nodeName), array('h1', 'h2', 'div'))) {
                    $editor->removeChild($child);
                }
                break;
            }
        }
    }

    foreach ($editor->getElementsByTagName('img') as $image) {
        $image->setAttribute('class', trim($image->getAttribute('class').' en-media'));
        if (!$image->hasAttribute('alt')) $image->setAttribute('alt', 'img');
    }
    foreach ($editor->getElementsByTagName('a') as $link) {
        $link->setAttribute('class', trim($link->getAttribute('class').' externalLink'));
    }

    // Return only the editor, excluding the UpNote page header and scripts.
    return '<div class="content-wrapper light-theme">'.$document->saveHTML($editor).'</div>';
}

function macro_UpNote($formatter, $value) {
    $parts = explode(',', html_entity_decode(trim($value), ENT_QUOTES, 'UTF-8'));
    $url = trim(array_shift($parts));
    $show_title = false;
    $show_signature = false;
    foreach ($parts as $option) {
        $pair = explode('=', $option, 2);
        if (count($pair) != 2) continue;
        $key = strtolower(trim($pair[0]));
        $enabled = strcasecmp(trim($pair[1]), 'True') == 0;
        if ($key == 'title') $show_title = $enabled;
        if ($key == 'signature') $show_signature = $enabled;
    }

    if (!_upnote_valid_url($url)) {
        return '[[UpNote(ERR: not a valid UpNote shared URL)]]';
    }

    $error = '';
    $html = _upnote_fetch($url, $error);
    $content = $html === false ? false : _upnote_render($html, $show_title, $error);
    if ($content === false) {
        return '[[UpNote(ERR: '.htmlspecialchars($error, ENT_QUOTES, 'UTF-8').')]]';
    }

    if ($show_signature) {
        $escaped_url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $content .= '<div class="entry-meta" style="line-break: anywhere">-- Visit <img src="/moniwiki/imgs/upnote_circle_icon.png" style="height: 17px; position: relative; top: 4px"> <span style="font-weight: 600">UpNote</span> Origin <a class="externalLink" href="'.$escaped_url.'">'.$escaped_url.'</a></div>';
    }

    return '<link rel="stylesheet" href="/moniwiki/css/upnoteShine.css" type="text/css">'.
        '<link rel="stylesheet" href="/moniwiki/css/upnoteHighlight.light.min.css" type="text/css">'.
        '<script defer="defer" src="/moniwiki/local/mathjax-full.js"></script>'.
        '<script defer="defer" src="/moniwiki/local/upnoteShare.js"></script>'.$content;
}
