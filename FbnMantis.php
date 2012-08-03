<?php

/**
 * FbnMantis - this extension shows bugs from bug tracker MantisBT
 *
 * To activate this extension, add the following into your LocalSettings.php file:
 * $wgMantisIntegration["host"]   = "server";
 * $wgMantisIntegration["user"]   = "username";
 * $wgMantisIntegration["pw"]     = "password";
 * $wgMantisIntegration["db"]     = "database";
 * $wgMantisIntegration["prefix"] = "mantis_";
 * $wgMantisIntegration["url"]    = "http://example.org/mantis/view.php?id=";
 * include_once("$IP/extensions/FbnMantis/FbnMantis.php");
 *
 * Usage:
 * <bug>ID</bug>        - for a single bug item
 * <bug>p:project</bug> - for all bugs in a project
 * <bug>u:user</bug>    - for all bugs assigned to the user
 *
 * Documentation: http://www.mediawiki.org/wiki/Manual:Tag_extensions
 *
 * @ingroup Extensions
 * @author Martin Muskulus <martin@martin-muskulus.de>
 * @version 1.1.0
 * @link http://www.fbn-dd.de/wiki/FbnMantis
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 */
/**
 * Protect against register_globals vulnerabilities.
 * This line must be present before any global variable is referenced.
 */
if (!defined('MEDIAWIKI')) {
    echo( "This is an extension to the MediaWiki package and cannot be run standalone.\n" );
    die(-1);
}

// Extension credits that will show up on Special:Version 
$wgExtensionCredits['parserhook']['FbnMantis'] = array(
    'name' => 'FbnMantis',
    'version' => '1.1.0',
    'author' => 'Martin Muskulus',
    'description' => 'provides <tt>&lt;bug&gt;ID&lt;/bug&gt;</tt> to view bugs from bug tracker MantisBT',
    'url' => 'https://www.fbn-dd.de/wiki/FbnMantis'
);

// Extension translation files to load
$wgExtensionMessagesFiles['FbnMantis'] = dirname(__FILE__) . '/FbnMantis.i18n.php';

// register our extension to load when parser loads
if (defined('MW_SUPPORTS_PARSERFIRSTCALLINIT')) {
    $wgHooks['ParserFirstCallInit'][] = 'FbnMantisParserInit';
} else {
    // Otherwise do things the old fashioned way
    $wgExtensionFunctions[] = 'FbnMantisParserInit';
}

// register function for tag
function FbnMantisParserInit() {
    global $wgParser;
    wfLoadExtensionMessages('FbnMantis');
    // tag, callback function
    $wgParser->setHook('bug', 'mantisRender');
    return true;
}

// query mantis and return information
// $input    Input between the <sample> and </sample> tags, or null if the tag is "closed", i.e. <sample />
// $args     Tag arguments, which are entered like HTML tag attributes; this is an associative array indexed by attribute name.
// $parser   The parent parser (a Parser object); more advanced extensions use this to obtain the contextual Title, parse wiki text, expand braces, register link relationships and dependencies, etc.
function mantisRender($input, $args, $parser) {
    global $wgMantisIntegration;
    $out = '';

    // database connect here, mysql_real_escape_string() needs it
    $link = @mysql_connect($wgMantisIntegration["host"], $wgMantisIntegration["user"], $wgMantisIntegration["pw"]);
    if (!$link) {
        return "can't connect to bug tracker database server";
    }

    $db = @mysql_select_db($wgMantisIntegration["db"], $link);
    if (!$db) {
        return "can't select bug tracker database";
    }

    if (preg_match('/[0-9]+/', $input)) {
        // single bug
        $bugId = intval($input);
        $sql = sprintf("
            SELECT bt.id, convert(convert(bt.summary using utf8), binary) as summary, bt.status 
            FROM " . $wgMantisIntegration["prefix"] . "bug_table AS bt
            WHERE bt.id=%d;", @mysql_real_escape_string($bugId));
    }

    if (preg_match('/p:[a-zA-Z0-9]+/', $input)) {
        // all bugs in project
        $project = substr(strval($input), 2);
        $sql = sprintf("
            SELECT bt.id, convert(convert(bt.summary using utf8), binary) as summary, bt.status 
            FROM " . $wgMantisIntegration["prefix"] . "bug_table AS bt
            JOIN " . $wgMantisIntegration["prefix"] . "project_table AS pt ON pt.id = bt.project_id
            WHERE pt.name LIKE '%s' AND bt.status < 90;", @mysql_real_escape_string($project));
    }

    if (preg_match('/u:[a-zA-Z0-9]+/', $input)) {
        // all bugs assigned to user
        $user = substr(strval($input), 2);
        $sql = sprintf("
            SELECT bt.id, convert(convert(bt.summary using utf8), binary) as summary, bt.status 
            FROM " . $wgMantisIntegration["prefix"] . "bug_table AS bt
            JOIN " . $wgMantisIntegration["prefix"] . "user_table AS ut ON ut.id = bt.handler_id
            WHERE ut.username LIKE '%s' AND bt.status < 90;", @mysql_real_escape_string($user));
    }

    if (empty($sql)) {
        return wfMsg('mantis-error-input');
    }

    $res = @mysql_query($sql);
    if (!$res) {
        return wfMsg('mantis-error-query');
    }

    // status id => name
    $status = array(
        0 => array('mantis-status-unknown', 'white'),
        10 => array('mantis-status-new', '#fcbdbd'),
        20 => array('mantis-status-reply', '#e3b7eb'),
        30 => array('mantis-status-acknowledged', '#ffcd85'),
        40 => array('mantis-status-confirmed', '#fff494'),
        50 => array('mantis-status-assigned', '#c2dfff'),
        80 => array('mantis-status-resolved', '#d2f5b0'),
        90 => array('mantis-status-closed', '#c9ccc4'),
    );

    $numresult = @mysql_num_rows($res);
    if ($numresult > 1) {
        $out .= '<ul>';
    }

    $i = 0;
    while ($row = @mysql_fetch_array($res)) {
        // unknown status?
        if (!array_key_exists($row['status'], $status)) {
            $row['status'] = 0;
        }

        if ($numresult > 1) {
            $out .= '<li>';
        }

        // bug resolved or closed
        $row['summary'] = ($row['status'] > 80 ? '<s>' : '') . $row['summary'] . ($row['status'] > 80 ? '</s>' : '');

        $msgStatus = $status[$row['status']][0];
        $msgColor = $status[$row['status']][1];
        $out .= '<span style="background-color:' . $msgColor . ';"><a href="' . $wgMantisIntegration['url'] . $row['id'] . '">#' .
                str_pad($row['id'], 7, '0', STR_PAD_LEFT) . '</a> ' . $row['summary'] .
                ' <em>(' . wfMsg('mantis-status') . ': ' . wfMsg($msgStatus) . ')</em></span>';

        if ($numresult > 1) {
            $out .= '</li>';
        }

        $i++;
    }

    if ($numresult > 1) {
        $out .= '</ul>';
    }

    if ($i == 0) {
        $out = wfMsg('mantis-error-notfound');
    }

    @mysql_free_result($res);
    @mysql_close($link);

    return $out;
}
