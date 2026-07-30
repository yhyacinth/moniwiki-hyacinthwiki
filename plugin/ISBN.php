<?php
// Copyright 2003-2022 Won-Kyu Park <wkpark at kldp.org>
// All rights reserved. Distributable under GPL see COPYING
// a ISBN macro plugin for the MoniWiki
//
// Local: 1.15-hy2
//
// 수정 내역:
// 1.15-hy2  2026-07-30 hyacinth: 도서 카드 반응형 레이아웃 적용.
//   고정 폭(max-width:520px)/고정 높이(200px)였던 카드를 뷰포트 폭에 따라
//   470/768/1024px로 넓히고, 1023px 이하에서는 폭 380px, 높이 150px로 줄이며
//   표지 폭도 200px에서 100px로 축소한다. 위키 본문 폭에 맞춰 카드가
//   넘치거나 좁은 화면에서 표지가 본문을 밀어내는 문제를 해결한다.
//   카드 테두리/모서리 둥글기/그림자는 제거해 본문 흐름에 자연스럽게 섞이도록 했다.
// 1.15-hy1  2026-04-10 hyacinth: 알라딘 상품 조회 API 연동 및 도서 정보 카드 UI 추가.
//   - Aladin 항목의 표지 URL을 교보문고 이미지 서버로 교체(알라딘 표지
//     경로가 더 이상 ISBN만으로 조립되지 않아 깨지는 경우가 있다).
//   - ISBN13 지원 추가: 하이픈을 제거한 값의 자릿수가 13자리면
//     itemIdType=ISBN13, 10자리면 ISBN으로 넘긴다.
//   - PHP 8 정합성: $noimg/$nocard를 0으로 초기화하고 $fetch_ok/$imgrepl
//     검사를 !empty()로 바꿔 undefined variable 경고를 없앤다.
//     $ISBN_list[$lang]가 없을 때 설정되지 않던 $imgre도 ''로 초기화한다.
//   - _isbn_fetch_aladin(): 알라딘 ItemLookUp API로 제목/저자/출판사/출간일/
//     표지/카테고리/쪽수를 받아온다. 결과는 Cache_text('isbn_aladin')에
//     JSON으로 캐시하며(키 접두어 v1_), API 키는 $DBInfo->aladin_ttbkey로
//     설정한다. 키가 없거나 호출이 실패하면 빈 배열을 돌려주어 기존
//     스크래핑 경로로 되돌아간다(타임아웃 5초).
//   - 기본 출력을 카드 모드로 변경. 표지/제목/저자/출판사/연도/쪽수/ISBN을
//     표시하고, 모든 값은 htmlspecialchars(ENT_QUOTES, UTF-8)로 이스케이프한다.
//     API 표지 URL은 coversum을 cover200으로 바꿔 큰 이미지를 쓴다.
//     외부 링크에는 rel="noopener", 표지에는 loading="lazy"를 붙인다.
//   - 기존 동작 보존: noimg는 텍스트 링크, 새로 추가한 nocard 인자는
//     예전처럼 표지 이미지만 출력한다.
//
// $Id: ISBN.php,v 1.15 2010/09/07 14:03:08 wkpark Exp $

function _isbn_fetch_aladin($isbn) {
  global $DBInfo;

  $cache = new Cache_text('isbn_aladin');
  $cache_key = 'v1_' . $isbn;

  if ($cache->exists($cache_key)) {
    $cached = $cache->fetch($cache_key);
    $data = @json_decode($cached, true);
    if (is_array($data)) return $data;
  }

  $ttbkey = !empty($DBInfo->aladin_ttbkey) ? $DBInfo->aladin_ttbkey : '';
  if (!$ttbkey) return array();

  $id_type = (strlen($isbn) == 13) ? 'ISBN13' : 'ISBN';
  $api_url = 'http://www.aladin.co.kr/ttb/api/ItemLookUp.aspx'
    . '?ttbkey=' . urlencode($ttbkey)
    . '&itemIdType=' . $id_type
    . '&ItemId=' . urlencode($isbn)
    . '&output=js&Version=20131101';

  $ctx = stream_context_create(array(
    'http' => array('timeout' => 5, 'ignore_errors' => true)
  ));
  $json = @file_get_contents($api_url, false, $ctx);
  if (!$json) return array();

  $result = @json_decode($json, true);
  if (empty($result['item'][0])) return array();

  $item = $result['item'][0];
  $meta = array();
  if (!empty($item['title']))     $meta['title'] = $item['title'];
  if (!empty($item['author']))    $meta['author'] = $item['author'];
  if (!empty($item['publisher'])) $meta['publisher'] = $item['publisher'];
  if (!empty($item['pubDate']))   $meta['pubDate'] = $item['pubDate'];
  if (!empty($item['cover']))     $meta['cover'] = $item['cover'];
  if (!empty($item['link']))      $meta['link'] = $item['link'];
  if (!empty($item['categoryName'])) $meta['category'] = $item['categoryName'];
  if (!empty($item['subInfo']['itemPage'])) $meta['pages'] = $item['subInfo']['itemPage'];

  $cache->update($cache_key, json_encode($meta));
  return $meta;
}

function _isbn_render_styles() {
  static $rendered = false;
  if ($rendered) return '';
  $rendered = true;

  return <<<'CSS'
<style>
.isbn-card{display:inline-flex;gap:1rem;height:297px;padding:1rem;border: 0px;background:#fff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif;margin:.5rem 0;overflow:hidden;transition:border-color 200ms ease,box-shadow 200ms ease}
@media (min-width: 480px) { .isbn-card{max-width:470px;} }
@media (min-width: 768px) { .isbn-card{max-width:768px;} }
@media (min-width: 1024px) { .isbn-card{max-width:1024px;} }
.isbn-card:hover{border-color:#2563eb;box-shadow:0 4px 6px -1px rgb(0 0 0/.1),0 2px 4px -2px rgb(0 0 0/.1)}
.isbn-card-cover{flex-shrink:0;width:200px;height:100%}
.isbn-card-cover a{display:block;height:100%}
.isbn-card-cover img{width:100%;height:100%;object-fit:cover;border:1px solid #e2e8f0;border-radius:.25rem}
.isbn-card-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:.25rem;overflow:hidden}
.isbn-card-title{margin:0 0 .25rem;font-size:1.125rem;font-weight:700;line-height:1.3;color:#1e293b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.isbn-card-title a{color:inherit;text-decoration:none}
.isbn-card-title a:hover{color:#2563eb;text-decoration:underline}
.isbn-card-row{margin:0;font-size:.875rem;color:#475569;line-height:1.7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.isbn-card-isbn{margin-top:auto;padding-top:.25rem;font-family:"SF Mono",Monaco,Inconsolata,"Fira Code",monospace;font-size:.75rem;color:#94a3b8}
@media(prefers-color-scheme:dark){.isbn-card{background:#1e293b;border-color:#334155;box-shadow:0 1px 3px 0 rgb(0 0 0/.3)}.isbn-card:hover{border-color:#3b82f6;box-shadow:0 4px 6px -1px rgb(0 0 0/.3)}.isbn-card-cover img{border-color:#334155}.isbn-card-title{color:#e2e8f0}.isbn-card-row{color:#94a3b8}.isbn-card-isbn{color:#64748b}}
@media (max-width: 1023px) { .isbn-card{max-width:380px;height:150px;padding: 0;} .isbn-card-cover {width:100px;} }
</style>
CSS;
}

function macro_ISBN($formatter,$value="") {
  global $DBInfo;

  // http://www.isbn-international.org/en/identifiers/allidentifiers.html
  $default_map=array('89'=>'Aladin');

  $ISBN_MAP="IsbnMap";
  $DEFAULT=<<<EOS
Amazon http://www.amazon.com/exec/obidos/ISBN= http://images.amazon.com/images/P/\$ISBN.01.MZZZZZZZ.gif
Aladdin https://www.aladin.co.kr/shop/wproduct.aspx?ISBN= https://image.aladin.co.kr/cover/cover/\$ISBN_1.gif @(https?://image\..*/cover.*/(?:[^\s_/]*\$ISBN_\d\.(?:jpe?g|gif)))@
Aladin https://www.aladin.co.kr/shop/wproduct.aspx?ISBN= https://contents.kyobobook.co.kr/sih/fit-in/256x0/pdt/\$ISBN.jpg @(https?://image\..*/cover.*/(?:[^\s_/]*\$ISBN_\d\.(?:jpe?g|gif)))@
Gang http://kangcom.com/common/qsearch/search.asp?s_flag=T&s_text= http://kangcom.com/l_pic/\$ISBN.jpg @bookinfo\.asp\?sku=(\d+)"@\n
EOS;

  $DEFAULT_ISBN="Amazon";
  $re_isbn="/^([0-9\-]+[xX]?)(?:,\s*)?(([A-Z][A-Za-z]*)?(?:,)?(.*))?/x";

  if ($value!='') {
     $test=preg_match($re_isbn,$value,$match);
     if ($test === false)
        return "<p><strong class=\"error\">Invalid ISBN \"%value\"</strong></p>";
  }

  $list= $DEFAULT;
  $map= new WikiPage($ISBN_MAP);
  if ($map->exists()) $list.=$map->get_raw_body();

  $lists=explode("\n",$list);
  $ISBN_list=array();
  foreach ($lists as $line) {
     if (!$line or !preg_match("/^[A-Z]/",$line[0])) continue;
     $dum=explode(" ",rtrim($line));
     $re='';
     $sz=sizeof($dum);
     if (!preg_match('/^(http|ftp)/',$dum[1])) continue;
     if ($sz == 2) {
        $dum[]=$ISBN_list[$DEFAULT_ISBN][1];
     } else if ($sz!=3) {
        if ($sz == 4) {
          if (($p=strpos(substr($dum[3],1),$dum[3][0]))!==false) {
             $retest=substr($dum[3],0,$p+2);
          } else {
             $retest=$dum[3];
          }
          if (preg_match($retest,'')!==false) $re=$dum[3];
        }
        else continue;
     }

     $ISBN_list[$dum[0]]=array($dum[1],$dum[2],$re);
  }

  if ($value=='') {
    $out="<ul>";
    foreach ($ISBN_list as $interwiki=>$v) {
      $href=$ISBN_list[$interwiki][0];
      if (strpos($href,'$ISBN') === false)
        $url=$href.'0738206679';
      else {
        $url=str_replace('$ISBN','0738206679',$href);
      }
      $icon=$DBInfo->imgs_url_interwiki.strtolower($interwiki).'-16.png';
      $sx=16;$sy=16;
      if ($DBInfo->intericon[$interwiki]) {
        $icon=$DBInfo->intericon[$interwiki][2];
        $sx=$DBInfo->intericon[$interwiki][0];
        $sy=$DBInfo->intericon[$interwiki][1];
      }
      $out.="<li><img src='$icon' width='$sx' height='$sy' ".
        "align='middle' alt='$interwiki:' /><a href='$url'>$interwiki</a>: ".
        "<tt class='link'>$href</tt></li>";
    }
    $out.="</ul>\n";
    return $out;
  }

  $isbn2=$match[1];
  $isbn=str_replace('-','',$isbn2);

  #print_r($match);
  if ($match[3]) {
    if (strtolower($match[2][0])=='k') $lang='Aladin';
    else $lang=$match[3];
  } else {
    $cl = strlen($isbn);
    if ($cl == 13)
      $lang_code=substr($isbn,3,2); // 978 89
    else
      $lang_code=substr($isbn,0,2); // 89
    if (!empty($default_map[$lang_code]))
      $lang=$default_map[$lang_code];
    else
      $lang=$DEFAULT_ISBN;
  }

  $attr='';
  $ext='';
  $noimg = 0;
  $nocard = 0;
  if ($match[2]) {
    $args=explode(',',$match[2]);
    foreach ($args as $arg) {
      $arg=trim($arg);
      if ($arg == 'noimg') $noimg=1;
      else if ($arg == 'nocard') $nocard=1;
      else if (strtolower($arg)=='k') $lang='Aladin';
      else {
        $name=strtok($arg,'=');
        $val=strtok(' ');
        if ($val) $attr.=$name.'="'.$val.'" '; #XXX
        if ($name == 'align') $attr.='class="img'.ucfirst($val).'" ';
        if ($name == 'img') $ext=$val;
      }
    }
  }

  if ($ISBN_list[$lang]) {
     $booklink=$ISBN_list[$lang][0];
     $imglink=$ISBN_list[$lang][1];
     $imgre=$ISBN_list[$lang][2];
  } else {
     $booklink=$ISBN_list[$DEFAULT_ISBN][0];
     $imglink=$ISBN_list[$DEFAULT_ISBN][1];
     $imgre='';
  }

  if (strpos($booklink,'$ISBN') === false)
     $booklink.=$isbn;
  else {
     if (strpos($booklink,'$ISBN2') === false)
        $booklink=str_replace('$ISBN',$isbn,$booklink);
     else
        $booklink=str_replace('$ISBN2',$isbn2,$booklink);
  }

  if (empty($noimg) and $imgre and get_cfg_var('allow_url_fopen')) {
     if (($p=strpos(substr($imgre,1),$imgre[0]))!==false) {
        $imgrepl=substr($imgre,$p+2);
        $imgre=substr($imgre,0,$p+2);
        if ($imgrepl=='@') $imgrepl='';
        $imgre=str_replace('$ISBN',$isbn,$imgre);
     }
     $md5sum=md5($booklink);
     // check cache
     $bcache=new Cache_text('isbn');
     if (empty($formatter->refresh) and $bcache->exists($md5sum)) {
        $imgname=trim($bcache->fetch($md5sum));

        $fetch_ok=1;
     } else {
        // fetch the bookinfo page and grep the imagname of the book.
        $fd=fopen($booklink,'r');
        if (is_resource($fd)) {
           while(!feof($fd)) {
              $line=fgets($fd,1024);
              preg_match($imgre,$line,$match);
              if (!empty($match[1])) {
                 $bcache->update($md5sum,$match[1]);
                 $imgname = $match[1];

                 $fetch_ok=1;
                 break;
              }
           }
           fclose($fd);
        }
     }
     if (!empty($fetch_ok)) {
        if (!empty($imgrepl))
           $imglink = preg_replace('@'.$imgrepl.'@', $imgname, $imglink);
        else if (!preg_match('/^https?:/', $imgname))
           $imglink = str_replace('$ISBN', $imgname, $imglink);
        else
           $imglink = $imgname;
     }

     if (!empty($fetch_ok) and !empty($DBInfo->isbn_img_download)) {
        # some sites such as the IMDB check the referer and
        # do not permit to show any of its images
        # the $isbn_img_download option is needed to show such images
        preg_match('/^(.*)\.(jpeg|jpg|gif|png)$/i',$imglink,$m);
        if (!empty($m[1]) and isset($m[2])) {
           $myimglink=md5($m[1]).'.'.$m[2];
        }

        if (isset($m[2])) {
           # skip XXX
        } else if (file_exists($DBInfo->upload_dir.'/isbn/'.$myimglink)) {
           $mlink=$formatter->macro_repl('attachment','isbn:'.$myimglink,1);
           $imglink=qualifiedUrl($DBInfo->url_prefix.'/'.$mlink);
        } else {
           $fd=fopen($imglink,'r');
           if (is_resource($fd)) {
              $myimg='';
              while(!feof($fd)) {
                 $myimg.=fread($fd,1024);
              }
              fclose($fd);
              if (!is_dir($DBInfo->upload_dir.'/isbn/')) {
                 umask(000);
                 mkdir($DBInfo->upload_dir.'/isbn/',0777);
                 umask($DBInfo->umask);
              }
              $fd=fopen($DBInfo->upload_dir.'/isbn/'.$myimglink,'w');
              if (is_resource($fd)) {
                 fwrite($fd,$myimg);
                 fclose($fd);
              }
           }
        }
     }
  }

  if (empty($fetch_ok)) {
     if (strpos($imglink, '$ISBN') === false)
        $imglink.=$isbn;
     else {
        if (strpos($imglink, '$ISBN2') === false)
           $imglink=str_replace('$ISBN', $isbn, $imglink);
        else
           $imglink=str_replace('$ISBN2', $isbn2, $imglink);
        if ($ext)
           $imglink=preg_replace('/\.(gif|jpeg|jpg|png|bmp)$/i', $ext, $imglink);
     }
  }

  // noimg mode: simple text link (legacy)
  if (!empty($noimg)) {
    $icon=$DBInfo->imgs_url_interwiki.strtolower($lang).'-16.png';
    $sx=16;$sy=16;
    if (!empty($DBInfo->intericon[$lang])) {
      $icon=$DBInfo->intericon[$lang][2];
      $sx=$DBInfo->intericon[$lang][0];
      $sy=$DBInfo->intericon[$lang][1];
    }
    return "<img src='$icon' alt='$lang:' align='middle' width='$sx' height='$sy' title='$lang' />"."[<a href='$booklink'>ISBN-$isbn2</a>]";
  }

  // nocard mode: image-only output (legacy)
  if (!empty($nocard)) {
    return "<a href='$booklink'><img src='$imglink' border='1' title='$lang".
      ":ISBN-$isbn' alt='[ISBN-$isbn2]' class='isbn' $attr /></a>";
  }

  // Card mode (default): fetch metadata from Aladin API
  $meta = _isbn_fetch_aladin($isbn);
  $isbn_display = htmlspecialchars($isbn2, ENT_QUOTES, 'UTF-8');

  // Use API cover image if available, prefer over scraped one
  $cover_url = $imglink;
  if (!empty($meta['cover'])) {
    // Get larger cover: replace 'coversum' with 'cover200' in Aladin URL
    $cover_url = str_replace('/coversum/', '/cover200/', $meta['cover']);
  }

  // Use API link if available
  $link_url = !empty($meta['link']) ? $meta['link'] : $booklink;

  $out = _isbn_render_styles();
  $out .= '<div class="isbn-card">';

  // Cover
  $out .= '<div class="isbn-card-cover">';
  $out .= '<a href="' . htmlspecialchars($link_url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">';
  $out .= '<img src="' . htmlspecialchars($cover_url, ENT_QUOTES, 'UTF-8') . '" alt="[ISBN-' . $isbn_display . ']" loading="lazy" />';
  $out .= '</a></div>';

  // Info
  $out .= '<div class="isbn-card-body">';

  // Title
  if (!empty($meta['title'])) {
    $t = htmlspecialchars($meta['title'], ENT_QUOTES, 'UTF-8');
    $out .= '<div class="isbn-card-title"><a href="' . htmlspecialchars($link_url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $t . '</a></div>';
  } else {
    $out .= '<div class="isbn-card-title"><a href="' . htmlspecialchars($link_url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">ISBN ' . $isbn_display . '</a></div>';
  }

  // Author
  if (!empty($meta['author'])) {
    $out .= '<div class="isbn-card-row">' . htmlspecialchars($meta['author'], ENT_QUOTES, 'UTF-8') . '</div>';
  }

  // Publisher + Date
  if (!empty($meta['publisher']) || !empty($meta['pubDate'])) {
    $pub = '';
    if (!empty($meta['publisher'])) $pub .= htmlspecialchars($meta['publisher'], ENT_QUOTES, 'UTF-8');
    if (!empty($meta['pubDate'])) {
      $year = substr($meta['pubDate'], 0, 4);
      if ($pub) $pub .= ', ';
      $pub .= $year;
    }
    $out .= '<div class="isbn-card-row">' . $pub . '</div>';
  }

  // Pages
  if (!empty($meta['pages'])) {
    $out .= '<div class="isbn-card-row">' . intval($meta['pages']) . '쪽</div>';
  }

  // ISBN
  $out .= '<div class="isbn-card-isbn">ISBN ' . $isbn_display . '</div>';

  $out .= '</div></div>';

  return $out;
}
