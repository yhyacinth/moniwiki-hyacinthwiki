<?php
// from http://www.sitepoint.com/examples/phpxml/sitepointcover-oo.php.txt
// Public Domain ?
// $Id: rss.php,v 1.7 2010/08/23 09:15:23 wkpark Exp $
// $Id: rss.php,v patch use fsockopen hyacinth Exp $
class WikiRSSParser {

   var $insideitem = false;
   var $tag = "";
   var $title = "";
   var $description = "";
   var $link = "";
   var $date = "";

   function WikiRSSParser() {
   }

   function startElement($parser, $tagName, $attrs) {
       if ($this->insideitem) {
           $this->tag = $tagName;
       } elseif ($tagName == "ITEM") {
           $this->insideitem = true;
       } elseif ($tagName == "IMAGE") {
           if (!empty($attrs['RDF:RESOURCE']))
           print "<img src=\"".$attrs['RDF:RESOURCE']."\"><br />";
       }
   }

   function endElement($parser, $tagName) {
       if ($tagName == "ITEM") {
           if ($this->status) print "[$this->status] ";
           printf("<a href='%s' target='_content'>%s</a>",
             trim($this->link),
             html_entity_decode(htmlspecialchars(trim($this->title))));
           #printf("<p>%s</p>",
           #  htmlspecialchars(trim($this->description)));
           if ($this->date) {
             $date=trim($this->date);
             $date[10]=" ";
             # 2003-07-11T12:08:33+09:00
             # http://www.w3.org/TR/NOTE-datetime
             $zone=str_replace(":","",substr($date,19));
             $time=strtotime(substr($date,0,19).$zone);
             $date=date("@ m-d [h:i a]",$time);
             printf(" %s<br />\n", htmlspecialchars(trim($date)));
           } else
             printf("<br />\n");
           $this->title = "";
           $this->description = "";
           $this->link = "";
           $this->date = "";
           $this->status = "";
           $this->insideitem = false;
       }
   }

   function characterData($parser, $data) {
       if ($this->insideitem) {
           switch ($this->tag) {
               case "TITLE":
               $this->title .= $data;
               break;
               case "DESCRIPTION":
               $this->description .= $data;
               break;
               case "LINK":
               $this->link .= $data;
               break;
               case "DC:DATE":
               #$this->date .= $data;
               break;
               case "WIKI:STATUS":
               $this->status .= $data;
               break;
           }
           #print $this->tag."/";
       }
   }
}

function do_Rss($formatter,$options) {
  if ($options['url']) {
    print '<font size="-1">';
    print macro_Rss($formatter,$options['url']);
    print '</font>';
  }
  return;
}

function macro_Rss($formatter,$value) {
  global $DBInfo;

  $xml_parser = xml_parser_create();

  $rss_parser = new WikiRSSParser();
  xml_set_object($xml_parser,$rss_parser);
  xml_set_element_handler($xml_parser, "startElement", "endElement");
  xml_set_character_data_handler($xml_parser, "characterData");

  // get opt
  if (preg_match("/(.[^,]*),(.*)/", $value, $matches))
  {
    $src = $matches[1];
    $opt_raw = preg_split("/[\s,]+/", $matches[2]);

    foreach($opt_raw as $optset) {
      $optval = preg_split("/[\s=]+/", $optset);
      $opt[$optval[0]] = $optval[1];
    }
  }
  else
  {
    $src = $value;
  }

  $key=_rawurlencode($src);

  $cache= new Cache_text("rss");
  # refresh rss each 3480 second (58*60) 58 min.
  if (!$cache->exists($key) or (time() > $cache->mtime($key) + 3480)) {
#  if (1) { // no cache
    $URL_parsed = parse_url($src);

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
      return ("[[RSS(ERR: not a valid URL! $value)]]");

    fputs($fp, $out);
    $body = false;
    while (!feof($fp)) {
      $data = fgets($fp, 4096);
      if ($body == false) {
        if (preg_match('/Location: http:\/\/(.[^\/]*)(.*)/',$data,$m))
        {
          fclose($fp);
          $host = $m[1];
          $path = $m[2];
          $out = "GET $path HTTP/1.0\r\nHost: $host\r\n\r\n";

          $fp = fsockopen($host, $port, $errno, $errstr, 30);
          fputs($fp, $out);
          continue;
        }
      }
      else {
        $xml_data .= $data;
      }

      if (preg_match('/<rss version=/',$data) or
          preg_match('/<\?xml version=/',$data)) {
        $xml_data .= $data;
        $body = true;
      }
    }
    fclose($fp);
    $cache->update($key,$xml_data);
  } else {
    $xml_data=$cache->fetch($key);
  }

  list($line,$dummy)=explode("\n",$xml_data,2);
  preg_match("/\sencoding=?(\"|')([^'\"]+)/",$line,$match);
  if ($match) $charset=strtoupper($match[2]);
  else $charset='UTF-8';
  # override $charset for php5
  if ((int)phpversion() >= 5) $charset='UTF-8';

  $xml_data=str_replace("&","&amp;",$xml_data);

  // exceptions for xml format error
  // rss.naver.com, rss tag is twice coming out (...)
  $xml_data=preg_replace("/<rss.*\n<rss/m","<rss",$xml_data); 
  // document element error
  $xml_data=preg_replace("/<\/rss.*\n0/m","</rss>",$xml_data); 
  $xml_data=str_replace("","",$xml_data); // invalid characters
  $xml_data=preg_replace("/<p>.[^<]*<\/p>/","",$xml_data); // delete contents

  ob_start();
  $ret= xml_parse($xml_parser, $xml_data);

  if (!$ret) {
    ob_end_clean();
    return (sprintf("[[RSS(XML error: %s at line %d)]]",  
      xml_error_string(xml_get_error_code($xml_parser)),  
      xml_get_current_line_number($xml_parser)));
  }
  $out=ob_get_contents();
  ob_end_clean();
  xml_parser_free($xml_parser);

  #  if (strtolower(str_replace("-","",$options['oe'])) == 'euckr')
  if (function_exists('iconv') and strtoupper($DBInfo->charset) != $charset) {
    $new=iconv($charset,$DBInfo->charset,$out);
    if ($new !== false) return $new;
  }

  if (empty($opt["list"]))
  {
    return $out;
  }
  else
  {
    if ($opt["list"] <= 0)
      return $out;

    $lines = explode("<br />", $out);
    $limit = $opt["list"] < count($lines) ? $opt["list"] : count($lines) - 1;
    for ($i = 0; $i < $limit; ++$i) {
      $lines_comp .= $lines[$i] . "<br />";
    }
    return $lines_comp;
  }
}

?>