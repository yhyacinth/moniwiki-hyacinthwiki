<?php
// $Id: atom.php,v 0.2 2025/07/31 hyacinth $
// SPDX-License-Identifier: GPL-2.0-only
// License: GPLv2; see COPYING.
//
// 2026-10-16 hyacinth: Atom feed summary and publication date improvements.
// - Use content as a fallback when summary is absent; handle HTML and XHTML.
// - Render plain-text summaries below titles, limited to 3 lines and 240 UTF-8 characters.
// - Show published dates, falling back to updated, in the feed's original timezone.
// - Format dates as "Fri, 25 Sep 2026 22:38" with muted text and vertical padding.
// - Preserve nodescription/list/show options and correctly handle XML entities.

class WikiAtomParser {

   var $insideitem = false;
   var $show_description = true;
   var $tag = "";
   var $title = "";
   var $description = "";
   var $description_type = "text";
   var $content = "";
   var $content_type = "text";
   var $link = "";
   var $date = "";
   var $updated = "";
   var $status = "";
   var $depth = 0;
   var $entry_depth = 0;

   function WikiAtomParser() {
   }

   function startElement($parser, $tagName, $attrs) {
       ++$this->depth;
       if ($this->insideitem) {
           if ($this->depth != $this->entry_depth + 1) {
               // Keep collecting text inside XHTML summaries and content.
               if (in_array($tagName, array('P', 'DIV', 'BR', 'LI', 'TR')))
                   $this->characterData($parser, " ");
               return;
           }
           if ($tagName == "LINK") { // self-closing tag
             if ((!isset($attrs['REL']) || $attrs['REL'] == "alternate") && isset($attrs['HREF'])) {
               $this->link = $attrs['HREF'];
             }
           }
           if ($tagName == "SUMMARY")
               $this->description_type = isset($attrs['TYPE']) ? strtolower($attrs['TYPE']) : 'text';
           elseif ($tagName == "CONTENT")
               $this->content_type = isset($attrs['TYPE']) ? strtolower($attrs['TYPE']) : 'text';
           $this->tag = $tagName;
       } elseif ($tagName == "ENTRY") {
           $this->insideitem = true;
           $this->entry_depth = $this->depth;
       } elseif ($tagName == "IMAGE") {
           if (!empty($attrs['RDF:RESOURCE']))
           print "<img src=\"".$attrs['RDF:RESOURCE']."\"><br />";
       }
   }

   function endElement($parser, $tagName) {
       if ($tagName == "ENTRY" && $this->depth == $this->entry_depth) {
         if ($this->insideitem) {
           if ($this->status) print "[$this->status] ";
           printf("<a href='%s' target='_self'>%s</a>",
             htmlspecialchars(trim($this->link), ENT_QUOTES, 'UTF-8'),
             htmlspecialchars(trim($this->title), ENT_QUOTES, 'UTF-8'));
           if ($this->show_description) {
             $summary = $this->getSummary();
             if ($summary !== '')
               printf('<p class="atom-summary" style="margin: 0.35em 0 0.75em; font-size: 0.9em; line-height: 1.5; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; max-height: 4.5em; overflow: hidden;">%s</p>',
                 htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'));
           }
           foreach (array($this->date, $this->updated) as $date) {
             if (trim($date) === '') continue;
             try {
               // Preserve the feed's timezone and prefer published over updated.
               $timestamp = new DateTime(trim($date));
               printf('<span class="atom-date" style="display: block; font-size: 12px; color: #999;">%s</span>',
                 htmlspecialchars($timestamp->format('D, d M Y H:i'), ENT_QUOTES, 'UTF-8'));
               break;
             } catch (Exception $e) {
               // Try updated if published is invalid; omit unreadable dates.
             }
           }
           printf("<br />\n");
           $this->title = "";
           $this->description = "";
           $this->description_type = "text";
           $this->content = "";
           $this->content_type = "text";
           $this->link = "";
           $this->date = "";
           $this->updated = "";
           $this->status = "";
           $this->insideitem = false;
         }
       }
       if ($this->insideitem) {
           if ($this->depth == $this->entry_depth + 1)
               $this->tag = "";
           elseif (in_array($tagName, array('P', 'DIV', 'LI', 'TR')))
               $this->characterData($parser, " ");
       } else {
           $this->tag = "";
       }
       --$this->depth;
   }

   function getSummary() {
       $text = trim($this->description);
       $type = $this->description_type;
       if ($text === '') {
           $text = trim($this->content);
           $type = $this->content_type;
       }
       if ($type == 'html' || $type == 'text/html') {
           $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1\s*>/is', '', $text);
           $text = preg_replace('/<\/?(?:p|div|br|li|tr|h[1-6])\b[^>]*>/i', ' ', $text);
           $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
       } elseif ($type != 'text' && $type != 'text/plain' && $type != 'xhtml') {
           return '';
       }
       $text = trim(preg_replace('/[\s\x{00a0}]+/u', ' ', $text));
       // Count UTF-8 characters without requiring the mbstring extension.
       if (preg_match('/^(.{240})./us', $text, $match))
           $text = rtrim($match[1]).'...';
       return $text;
   }

   function characterData($parser, $data) {
       if ($this->insideitem) {
           switch ($this->tag) {
               case "TITLE":
               $this->title .= $data;
               break;
               case "SUMMARY":
               $this->description .= $data;
               break;
               case "CONTENT":
               $this->content .= $data;
               break;
               case "LINK":
               #$this->link .= $data;
               break;
               case "PUBLISHED":
               $this->date .= $data;
               break;
               case "UPDATED":
               $this->updated .= $data;
               break;
               case "WIKI:STATUS":
               $this->status .= $data;
               break;
           }
           #print $this->tag."/";
       }
   }
}

function macro_Atom($formatter,$value) {
  global $DBInfo;

  // Usage: [[ATOM(https://martinfowler.com/feed.atom,list=5)]]
  // list=N shows N entries initially; the remaining entries can be expanded.
  $opt = array();
  $args = explode(',', $value, 2);
  $src = trim($args[0]);
  if (isset($args[1])) {
    $opt_raw = preg_split('/[\s,]+/', trim(preg_replace('/\s*=\s*/', '=', $args[1])), -1, PREG_SPLIT_NO_EMPTY);

    foreach($opt_raw as $optset) {
      $optval = explode('=', $optset, 2);
      $opt[$optval[0]] = isset($optval[1]) ? $optval[1] : true;
    }
  }

  // Create an XML parser
  $xml_parser = xml_parser_create();

  $atom_parser = new WikiAtomParser();
  if (array_key_exists("nodescription", $opt)) {
    $atom_parser->show_description = false;
  }
  xml_set_object($xml_parser,$atom_parser);
  xml_set_element_handler($xml_parser, "startElement", "endElement");
  xml_set_character_data_handler($xml_parser, "characterData");

  $key=_rawurlencode($src);

  $cache= new Cache_text("atom");
  // Caching for 7140 seconds (2 hours.)
  if (!$cache->exists($key) or (time() > $cache->mtime($key) + 7140)) {
    $URL_parsed = parse_url($src);
    
    if ($URL_parsed["scheme"] == "https") {
        $xml_data = directlyCallingCurl($src);
    } else {
        $host = $URL_parsed["host"];
        $port = $URL_parsed["port"];
        if ($port == 0)
          $port = 80;

        $path = $URL_parsed["path"];
        if ($URL_parsed["query"] != "")
          $path .= "?".$URL_parsed["query"];

        $out = "GET $path HTTP/1.0\r\nHost: $host\r\n\r\n";

        $fp = fsockopen($host, $port, $errno, $errstr, 30);

        if (!$fp)
          return ("[[Atom(ERR: not a valid URL! $src)]]");

        fputs($fp, $out);

        $body = false;
        while (!feof($fp)) {
          $data = fgets($fp, 4096);
          if ($body == false) {
            if (preg_match('/Location: http:\/\/(.[^\/]*)(.*)/',$data,$m)) {
              fclose($fp);
              $host = $m[1];
              $path = $m[2];
              $out = "GET $path HTTP/1.0\r\nHost: $host\r\n\r\n";
              echo $out;

              $fp = fsockopen($host, $port, $errno, $errstr, 30);
              fputs($fp, $out);
              continue;
            }
          } else {
            $xml_data .= $data;
          }
          if ($data == "\r\n")
            $body = true;
        }
        fclose($fp);
    }
    $cache->update($key,$xml_data);
  } else {
    $xml_data=$cache->fetch($key);
  }

  list($line)=explode("\n",$xml_data,2);
  preg_match("/\sencoding=?(\"|')([^'\"]+)/",$line,$match);
  if ($match) $charset=strtoupper($match[2]);
  else $charset='UTF-8';
  // override $charset for php5
  if ((int)phpversion() >= 5) $charset='UTF-8';

  // XXX: Post-processing(for xml format error, etc. ..)
  $xml_data=preg_replace('/&(?!amp;|lt;|gt;|quot;|apos;|#\d+;|#x[0-9a-fA-F]+;)/','&amp;',$xml_data);
  // remove invalid characters
  $xml_data=str_replace("","",$xml_data);

  ob_start();
  $ret= xml_parse($xml_parser, $xml_data, true);

  if (!$ret) {
    ob_end_clean();
    return (sprintf("[[Atom(XML error: %s at line %d)]]",  
      xml_error_string(xml_get_error_code($xml_parser)),  
      xml_get_current_line_number($xml_parser)));
  }
  $out=ob_get_contents();
  ob_end_clean();
  xml_parser_free($xml_parser);

  if (function_exists('iconv') and strtoupper($DBInfo->charset) != $charset) {
    $new=iconv($charset,$DBInfo->charset,$out);
    if ($new !== false) $out = $new;
  }

  $list_num = 10;
  if (isset($opt["list"]) && is_string($opt["list"]) &&
      preg_match('/^[0-9]+$/D', $opt["list"]) && (int)$opt["list"] > 0) {
      $list_num = (int)$opt["list"];
  }

  if (!array_key_exists("all", $opt)) {
    $unique_id = uniqid('u', true);
    // Split string based on <br /> tag
    $lines = explode('<br />', $out);
    // Split after the requested number of entries (10 by default).
    $first_part = implode('<br />', array_slice($lines, 0, $list_num));
    $second_part = implode('<br />', array_slice($lines, $list_num));

    $out = <<<HTML
    <div id="content">
        <div class="visible-content">
            $first_part
        </div>
HTML;
    if (5 < strlen($second_part)) {
        $out .= <<<HTML
        <div class="hidden-content" id="hidden-content-$unique_id">
            $second_part
        </div>
        <span class="more-button" onclick="toggleContent('$unique_id')">...more</span>
HTML;
    }
    $out .= "</div>";
    $out .= <<<JS
    <style>
        .hidden-content {
            display: none;
        }
        .more-button {
            color: gray;
            cursor: pointer;
            text-decoration: underline;
            display: inline-block;
            margin-top: 0px;
        }
    </style>
    <script>
        function toggleContent(uniqueId) {
            const hiddenContent = document.getElementById('hidden-content-' + uniqueId);
            const button = document.querySelector('.more-button');
            const isHidden = window.getComputedStyle(hiddenContent).display === 'none';
            if (isHidden) {
                hiddenContent.style.display = 'block';
                button.textContent = '...less';
            } else {
                hiddenContent.style.display = 'none';
                button.textContent = '...more';
            }
        }
    </script>
JS;
  }

  if (!isset($opt["show"])) {
    return $out;
  } else {
    if ($opt["show"] <= 0)
      return $out;

    $lines = explode("<br />", $out);
    $limit = $opt["show"] < count($lines) ? $opt["show"] : count($lines) - 1;
    $lines_comp = '';
    for ($i = 0; $i < $limit; ++$i) {
      $lines_comp .= $lines[$i] . "<br />";
    }
    return $lines_comp;
  }
}

function do_atom($formatter,$options) {
  global $DBInfo;
  global $_release;
  define('ATOM_DEFAULT_DAYS',7);

  $days=$DBInfo->rc_days ? $DBInfo->rc_days:ATOM_DEFAULT_DAYS;
  $options['quick']=1;
  if ($options['c']) $options['items']=$options['c'];
  $lines= $DBInfo->editlog_raw_lines($days,$options);

  $URL=qualifiedURL($formatter->prefix);
  $img_url=qualifiedURL($DBInfo->logo_img);

  $url=qualifiedUrl($formatter->link_url($DBInfo->frontpage));
  $surl=qualifiedUrl($formatter->link_url($options['page'].'?action=atom'));
  $channel=<<<CHANNEL
  <title>$DBInfo->sitename</title>
  <link href="$url"></link>
  <link rel="self" type="application/atom+xml" href="$surl" />
  <subtitle>RecentChanges at $DBInfo->sitename</subtitle>
  <generator version="$_release">MoniWiki Atom feeder</generator>\n
CHANNEL;
  $items="";

  $ratchet_day= FALSE;
  if (!$lines) $lines=array();
  foreach ($lines as $line) {
    $parts= explode("\t", $line);
    $page_name= $DBInfo->keyToPagename($parts[0]);
    $addr= $parts[1];
    $ed_time= $parts[2];
    $user= $parts[4];
    $user_uri='';
    if ($DBInfo->hasPage($user)) {
      $user_uri= $formatter->link_url(_rawurlencode($user),"",$user);
      $user_uri='<uri>'.$user_uri.'</uri>';
    }
    $log= _stripslashes($parts[5]);
    $act= rtrim($parts[6]);

    $url=qualifiedUrl($formatter->link_url(_rawurlencode($page_name)));
    $diff_url=qualifiedUrl($formatter->link_url(_rawurlencode($page_name),'?action=diff'));

    $extra="<br /><a href='$diff_url'>"._("show changes")."</a>\n";
    $content='';
    if (!$DBInfo->hasPage($page_name)) {
      $status='Deleted';
      $content="<content type='html'><a href='$url'>$page_name</a> is deleted</content>\n";
    } else {
      $status='Updated';
      if ($options['diffs']) {
        $p=new WikiPage($page_name);
        $f=new Formatter($p);
        $options['raw']=1;
        $options['nomsg']=1;
        $html=$f->macro_repl('Diff','',$options);
        if (!$html) {
          ob_start();
          $f->send_page('',array('fixpath'=>1));
          #$f->send_page('');
          $html=ob_get_contents();
          ob_end_clean();
          $extra='';
        }
        $content="  <content type='xhtml'><div xmlns='http://www.w3.org/1999/xhtml'>$html</content>\n";
      } else if ($log) {
        $html=str_replace('&','&amp;',$log);
        $content="<content type='text'>".$html."</content>\n";
      } else {
        $content="<content type='text'>Updated</content>\n";
      }
    }
    $zone = '+00:00';
    $date = gmdate("Y-m-d\TH:i:s",$ed_time).$zone;
    if (!isset($updated)) $updated=$date;
    #$datetag = gmdate("YmdHis",$ed_time);

    $valid_page_name=str_replace('&','&amp;',$page_name);
    $items.="<entry>\n";
    $items.="  <title>$valid_page_name</title>\n";
    $items.="  <link href='$url'></link>\n";
    $items.='  '.$content;
    $items.="  <author><name>$user</name>$user_uri</author>\n";
    $items.="  <updated>$date</updated>\n";
    $items.="  <contributor><name>$user</name>$user_uri</contributor>\n";
    $items.="</entry>\n";
  }
  $updated="  <updated>$updated</updated>\n";

  $new="";
  if ($options['oe'] and (strtolower($options['oe']) != $DBInfo->charset)) {
    $charset=$options['oe'];
    if (function_exists('iconv')) {
      $out=$head.$channel.$items.$form;
      $new=iconv($DBInfo->charset,$charset,$out);
      if (!$new) $charset=$DBInfo->charset;
    }
  } else $charset=$DBInfo->charset;

  $head=<<<HEAD
<?xml version="1.0" encoding="$charset"?>
<!--<?xml-stylesheet href="$DBInfo->url_prefix/css/_feed.css" type="text/css"?>-->
<feed xmlns="http://www.w3.org/2005/Atom">
<!--
    Add "diffs=1" to add change diffs to the description of each items.
    Add "oe=utf-8" to convert the charset of this rss to UTF-8.
-->\n
HEAD;
  header("Content-Type: application/xml");
  if ($new) print $head.$new;
  else print $head.$channel.$updated.$items.$form;
  print "</feed>\n";
}
?>
