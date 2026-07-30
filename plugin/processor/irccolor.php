<?php
// Copyright 2015-2026 Daehyeon Kim (hyacinth)
// All rights reserved. Distributable under GPL see COPYING
// an irccolor processor plugin for the MoniWiki
//
// Author: Daehyeon Kim (hyacinth)
// Since: 2015-02-26
// Name: irccolor processor
// Description: render raw IRC client log lines as colored HTML
// URL: https://hyacinth.kr/moniwiki/wiki.php/IrccolorMacro
// Release: 0.2
// License: GPL v2
//
// Usage: paste log lines straight out of an IRC client, control codes and all.
//
//   {{{#!irccolor
//   [21:03] <hyacinth> chat
//   [21:04] <hyacinth> chat
//   }}}
//
// 수정 내역:
// 0.2  2026-07-30 hyacinth: 줄 단위 루프와 규칙 테이블 재생성을 제거.
//   - patterns/replacements 두 배열을 pattern => replacement 맵 하나로
//     합쳐 _irccolor_rules()로 옮기고 static에 담아 요청당 한 번만 만든다.
//   - $value[0]/$value[1] 접근을 substr()로, $out/$line 미초기화 사용을
//     정리해 undefined offset/variable 경고를 없앤다.
// 0.1  2015-02-26 hyacinth: 최초 작성.
//
// $Id: irccolor.php,v 0.2 2026/07/30 13:51:22 hyacinth Exp $

// IRC control codes: \x02 bold, \x03 color, \x08 backspace (HexChat wraps
// log nicks in them), \x0f reset. Rules are applied in order, so the
// client specific formats come first and the bare color codes last.
function _irccolor_rules() {
    $B = "\x02";
    $C = "\x03";
    $H = "\x08";
    $O = "\x0f";

    return array(
        "/(\[\d\d:\d\d\]) (\*.*)/"
            => "$1 <font color='#009400'>$2</font>", // timestamped action
        // HexChat
        "/{$C}20{$H}<{$H}(.*){$H}>{$H}{$O}{$C}30/"
            => "<<font color='red'>$1</font>>", // Me
        "/{$C}18{$H}<{$H}(.*){$C}18{$H}>{$H}{$O}/"
            => "<<font color='#000080'>$1</font>>", // Oper
        "/{$C}23(.*){$C}23(.*)/"
            => "<font color='#CE5C00'>$1$2</font>", // Joined
        "/{$C}24(.*)/"
            => "<font color='#C4A000'>$1$2 </font>", // Quit
        "/{$C}22(.[^ {$O}]*){$O}? {$C}26(.[^ {$O}]*){$O}? (.*){$C}18(.*){$O}?/"
            => "$1 <font color='#11A879'>$2</font> $3<font color='#000080'>$4</font> ", // Mode Changed
        "/(\d){$B}{$B}/"
            => "$1",
        "/{$C}18(.[^{$O}]*){$O}/"
            => "<font color='#000080'>$1</font>",
        "/{$C}19(.[^{$O}]*){$O}/"
            => "<font color='#11A879'>$1</font>",
        "/{$C}20(.[^{$O}]*){$O}/"
            => "<font color='#11A879'>$1</font>",
        "/{$C}22(.[^{$O}]*){$O}/"
            => "<font color='#5C3566'>$1</font>",
        "/{$C}26(.[^{$O}]*){$O}/"
            => "<font color='#11A879'>$1</font>",
        // mIRC
        "/<(@.[^>]*)>/"
            => "<<font color='#000080'>$1</font>>", // op
        "/<(\+.[^>]*)>/"
            => "<<font color='#800000'>$1</font>>", // voiced
        "/{$C}02(.[^{$O}]*){$O}/"
            => "<font color='#000080'>$1</font>",
        "/{$C}05(.[^{$O}]*){$O}/"
            => "<font color='#800000'>$1</font>",
        "/{$C}04(.[^{$O}]*){$O}/"
            => "<font color='red'>$1</font>",
        "/{$O}(.[^{$O}]*){$O}/"
            => "<b>$1</b>",
        "/{$B}(.[^{$B}]*){$B}/"
            => "$1",
        "/{$C}10(.*)/"
            => "<font color='#009490'>$1 </font>",
        "/{$C}11(.*)/"
            => "<font color='#00FFFF'>$1 </font>",
        "/{$C}12(.*)/"
            => "<font color='blue'>$1 </font>",
        "/{$C}13(.*)/"
            => "<font color='#FF00FF'>$1 </font>",
        "/{$C}14(.*)/"
            => "<font color='#808080'>$1 </font>",
        "/{$C}15(.*)/"
            => "<font color='#D0D4D0'>$1 </font>",
        "/{$C}0?1([^\d].*)/"
            => "<font color='black'>$1 </font>",
        "/{$C}0?2(.*)/"
            => "<font color='#000080'>$1 </font>",
        "/{$C}0?3(.*)/"
            => "<font color='#009400'>$1 </font>",
        "/{$C}0?4(.*)/"
            => "<font color='red'>$1 </font>",
        "/{$C}0?5(.*)/"
            => "<font color='#800000'>$1 </font>",
        "/{$C}0?6(.*)/"
            => "<font color='#A000A0'>$1 </font>",
        "/{$C}0?7(.*)/"
            => "<font color='#FF8000'>$1 </font>",
        "/{$C}0?8(.*)/"
            => "<font color='yellow'>$1 </font>",
        "/{$C}0?9(.*)/"
            => "<font color='#00FC00'>$1 </font>",
        // strip whatever control codes are left over
        "/{$C}\d?\d/"
            => "",
        "/{$C}{$B}|{$O}|{$C}/"
            => "",
    );
}

function processor_irccolor($formatter, $value = '', $options = array()) {
    static $patterns = null;
    static $replacements = null;

    if ($patterns === null) {
        $rules = _irccolor_rules();
        $patterns = array_keys($rules);
        $replacements = array_values($rules);
    }

    // drop the leading "#!irccolor ..." processor line
    if (substr($value, 0, 2) == '#!') {
        $tmp = explode("\n", $value, 2);
        $value = isset($tmp[1]) ? $tmp[1] : '';
    }

    $lines = explode("\n", $value);
    // drop the literal "0?" that follows a color code in some logs
    $lines = str_replace("\x03".'0?', "\x03", $lines);
    $lines = preg_replace($patterns, $replacements, $lines);

    return implode('<br />', $lines).'<br />';
}

// vim:et:sts=4:sw=4:
?>
