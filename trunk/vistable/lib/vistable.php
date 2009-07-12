<?php
/***********************************************************************
    Copyright 2008-2009 Mark Williams

    Licensed under the Apache License, Version 2.0 (the "License");
    you may not use this file except in compliance with the License.
    You may obtain a copy of the License at

        http://www.apache.org/licenses/LICENSE-2.0

    Unless required by applicable law or agreed to in writing, software
    distributed under the License is distributed on an "AS IS" BASIS,
    WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
    See the License for the specific language governing permissions and
    limitations under the License. 

    File: vistable.php
***********************************************************************/

require "visparser.php";
require "visformat.php";

global $vistable_ordering;
function vistable_order_function($a, $b)
{
    global $vistable_ordering;

    foreach ($vistable_ordering as $id => $dir) {
        $aval = $a[$id];
        $bval = $b[$id];
        if ($aval < $bval) return -$dir;
        if ($bval < $aval) return $dir;
    }
    return 0;
}

abstract class vistable {
    protected $query;
    protected $params;

    private $response;
    private $tqrt;
    private $tq;
    private $tz;
    private $locale;
    protected $fields;
    protected $debug;
    private $aggregates;
    private $visited = 0;
    private $agr_reset = 0;
    
    public function __construct($tqx,$tq,$tqrt,$tz,$locale,$extra=NULL) {
        $this->response = array('status' => 'ok');

        $this->params = array(
            'version' => '0.6',
            'sig' => '',
            'responseHandler' => 'google.visualization.Query.setResponse'
            );

        if ($extra) {
            foreach ($extra as $key => $value) {
                $this->params[$key] = $value;
            }
        }

        if ($tqx) {
            foreach (explode(';', $tqx) as $kvpair) {
                $kva = explode(':', $kvpair, 2);
                if (count($kva) == 2) {
                    $this->params[$kva[0]] = $kva[1];
                }
            }
        }

        if (get_magic_quotes_gpc()) {
            $tq = stripslashes($tq);
        }
        
        $this->debug = $extra && $extra['debug'];

        $this->tq = $tq;
        $this->tqrt = $tqrt;
        $this->tz = $tz;
        $this->locale = $locale;

        $timezone = new DateTimeZone($tz);
        $date = new DateTime("", $timezone);
        $this->gmt_offset = $timezone->getOffset($date);
    }

    public function get_param($param,$default = NULL)
    {
        return isset($this->params[$param]) ? $this->params[$param] : $default;
    }

    private function diagnostic($kind, $reason, $message, $detailed_message)
    {
        if ($this->response['status'] == 'ok' || $kind == 'error') {
            $this->response['status'] = $kind;
        }   
        $kind .= "s";

        if (!isset($this->response[$kind])) {
            $this->response[$kind] = array();
        }

        array_push($this->response[$kind], array(
                       'reason' => $reason,
                       'message' => $message,
                       'detailed_message' => $detailed_message));
    }

    public function error($reason,$message,$detailed_message)
    {
        $this->diagnostic('error',$reason,$message,$detailed_message);
    }

    public function warning($reason,$message,$detailed_message)
    {
        $this->diagnostic('warning',$reason,$message,$detailed_message);
    }

    abstract protected function fetch_table($query);
    protected function pre_write($q) { return NULL; }

    protected function write_func($v,$q,$rev = FALSE)
    {
        $args = array();
        for ($i=0;isset($q[$i]);$i++) {
            $args[] = $this->write_expr($q[$i]);
        }
        if ($rev) $args = array_reverse($args);
        return "$v(".implode(",",$args).")";
    }

    protected function write_expr($q)
    {
        $r = $this->pre_write($q);
        if (is_string($r)) {
            return $r;
        } else if ($r !== NULL) {
            $q = $r;
        }
        $v = $q[VALUE];
        switch ($q[TYPE]) {
        case LITERAL:
            switch ($q['type']) {
            case 'string':
            case 'date':
            case 'datetime':
            case 'timeofday':
                return "'".mysql_real_escape_string($v)."'";
            case 'number':
                return $v;
            }
            break;
        case OPERATOR:
            $e0 = $this->write_expr($q[0]);
            if (isset($q[1])) {
                $e1 = $this->write_expr($q[1]);
                return "$e0 $v $e1";
            } else {
                return "$v $e0";
            }
        case FUNCT:
            return $this->write_func($v,$q);
        case SIMPLE:
            return $v;
        }
    }
    private function datepart($part,$date)
    {
        $dateParts = array('dayofweek' => array('w',1),
                           'day'       => 'j',
                           'month'     => 'n',
                           'year'      => 'Y',
                           'hour'      => 'G',
                           'minute'    => 'i',
                           'second'    => 's',
                           );

        $part = $dateParts[$part];
        $offset = 0;
        if (is_array($part)) {
            $offset = $part[1];
            $part = $part[0];
        }

        $date += $this->gmt_offset;
        return intval(gmdate($part, $date)) + $offset;
    }

    public function evaluate($row, &$q)
    {
        $v = $q[VALUE];
        switch ($q[TYPE]) {
        case STRING:
            break;
        case OPERATOR:
        case FUNCT:
            $args = array();
            for ($i=0;isset($q[$i]);$i++) {
                $args[] = $this->evaluate($row,$q[$i]);
            }
            switch ($v) {
                case '*': $v = $args[0] * $args[1]; break;
                case '/': $v = $args[0] / $args[1]; break;
                case '+': $v = $args[0] + $args[1]; break;
                case '-': $v = $args[0] - $args[1]; break;
                case '<': $v = $args[0] < $args[1]; break;
                case '>': $v = $args[0] > $args[1]; break;
                case '<=': $v = $args[0] <= $args[1]; break;
                case '>=': $v = $args[0] >= $args[1]; break;
                case 'starts_with': $v = !strncmp($args[0], $args[1], strlen($args[1])); break;
                case 'ends_with': $v = substr($args[0],-strlen($args[1])) == $args[1]; break;
                case 'contains': $v = strpos($args[0], $args[1]) !== FALSE; break;
                case 'matches': $v = preg_match("/^{$args[1]}$/", $args[0]); break;
                case '=': $v = $args[0] == $args[1]; break;
                case '!=':
                case '<>': $v = $args[0] != $args[1]; break;
                case 'and': $v = $args[0] && $args[1]; break;
                case 'or': $v = $args[0] || $args[1]; break;
                case 'not': $v = !$args[0]; break;
                case 'millisecond':$v = 0; break;
                case 'year':
                case 'month':
                case 'day':
                case 'hour':
                case 'minute':
                case 'second':
                case 'dayofweek':
                    $v = $this->datepart($v,$args[0]);
                    break;
                case 'quarter':$a=getdate($args[0]);$v = (int)(($a['mon']-1)/3)+1; break;
                case 'datediff':$v = (int)($args[0] / (3600 * 24)) - (int)($args[1] / (3600 * 24)); break;
                case 'now': $v = time(); break;
                case 'todate':
                    $v = 0;
                    switch ($q[0]["type"]) {
                        case 'date':
                        case 'datetime':
                            $v = (int)($args[0] / (3600 * 24)) * (3600 * 24);
                            break;
                        case 'number':
                            $v = (int)($args[0] / 1000);
                            break;
                    }
                    break;
                case 'upper': $v = strtoupper($args[0]); break;
                case 'lower': $v = strtolower($args[0]); break;

                case 'count':
                case 'max':
                case 'min':
                case 'sum':
                case 'avg':
                    if ($q[0]['visited'] != $this->visited) {
                        $q[0]['visited'] = $this->visited;
                        if ($this->agr_reset || !isset($q[0]['agr-count'])) {
                            $q[0]['agr-count'] = 1;
                            $q[0]['agr-max'] = $args[0];
                            $q[0]['agr-min'] = $args[0];
                            $q[0]['agr-sum'] = $args[0];
                            $q[0]['agr-avg'] = $args[0];
                        } else {
                            $q[0]['agr-count'] += 1;
                            if ($args[0] > $q[0]['agr-max']) {
                                $q[0]['agr-max'] = $args[0];
                            }
                            if ($args[0] < $q[0]['agr-max']) {
                                $q[0]['agr-min'] = $args[0];
                            }
                            $q[0]['agr-sum'] += $args[0];
                            $q[0]['agr-avg'] = $q[0]['agr-sum'] / $q[0]['agr-count'];
                        }
                    }
                    $this->aggregates = 1;
                    $v = $q[0]['agr-'.$v];
                    break;
            }
            break;
            case SIMPLE:
                $v = $row[$v];
            case LITERAL:
                $v = $this->convert_literal($q['type'], $v);
        }
        if ($this->debug) {
            echo "{$q[TYPE]}:{$q[VALUE]}:{$q['type']} = $v\n";
        }
        return $v;
    }

    private function mktime($year,$month,$day,$hour,$minute,$second,$ms = 0)
    {
        $year = intval($year,10);
        $month = intval($month,10);
        $day = intval($day,10);
        $hour = intval($hour,10);
        $minute = intval($minute,10);
        $second = intval($second,10);
        return gmmktime($hour,$minute,$second,$month,$day,$year);
    }

    private function convert_literal($type, $v)
    {
        if ($v !== NULL) {
            switch ($type) {
                case 'date':
                    if (is_string($v) && preg_match('/^(....)-(..)-(..)$/', $v, $matches)) {
                        $v = $this->mktime($matches[1],$matches[2],$matches[3], 0, 0, 0);
                    }
                    break;
                case 'timeofday':
                    if (is_string($v) && preg_match('/^(..):(..):(..)$/', $v, $matches)) {
                        $v = $this->mktime(0,0,0,$matches[1],$matches[2],$matches[3]);
                    }
                    break;
                case 'datetime':
                    if (is_string($v) && preg_match('/^(....)-(..)-(..) (..):(..):(..)$/', $v, $matches)) {
                        $v = $this->mktime($matches[1],$matches[2],$matches[3],
                                           $matches[4],$matches[5],$matches[6]);
                    }
                    break;
                case 'number':
                    $v = (double)$v;
                    break;
                case 'boolean':
                    if (is_string($v) && !strcasecmp($v, "false")) {
                        $v = FALSE;
                    } else {
                        $v = (bool)$v;
                    }
                    break;
            }
        }
        return $v;
    }

    private function value_convert($type, $v)
    {
        if ($v !== NULL) {
            switch ($type) {
                case 'date':
                    $a = getdate($v);
                    $m = $a['mon']-1;
                    return "new Date({$a['year']},$m,{$a['mday']})";
                case 'timeofday':
                    $a = getdate($v);
                    return "[{$a['hours']},{$a['minutes']},{$a['seconds']}]";
                case 'datetime':
                    $a = getdate($v);
                    $m = $a['mon']-1;
                    return "new Date({$a['year']},$m,{$a['mday']},{$a['hours']},{$a['minutes']},{$a['seconds']})";
            }
        }
        return $v;
    }

    protected function make_order($elist, &$cols, &$exprs)
    {
        $order = array();
        foreach ($elist as &$col) {
            $dir = isset($col['dir']) && $col['dir'] == 'desc' ? -1 : 1;
            $s = $this->write_expr($col);
            if (!isset($exprs[$s])) {
                $cols[] = array('id' => $s, 'label' => $s, 'type' => $col['type']);
                $exprs[$s] = $col;
            }
            $order[$s] = $dir;
        }
        return $order;
    }

    public function query_filter(&$rows, $query)
    {
        $pivot_key = "pivot|key";
        $group_key = "group|key";

        $cols = array();
        $order = array();
        $exprs = array();
        foreach ($query['select'] as &$col) {
            $s = $this->write_expr($col);
            $c = array('id' => $s, 'label' => $s, 'type' => $col['type']);
            if ($col['type'] != 'string' && $col['format']) {
                $c['pattern'] = $col['format'];
            }
            if ($col['label']) {
                $c['label'] = $col['label'];
            }
            if ($col['is_pivot'] || $col['is_group']) {
                $c['group'] = 1;
            }
            $cols[] = $c;
            $exprs[$s] =& $col;
        }

        unset($col);

        $ncol = count($cols);
        if (isset($query['order'])) {
            $order = $this->make_order($query['order'], $cols, $exprs);
        } else if (isset($query['group'])) {
            $order = $this->make_order($query['group'], $cols, $exprs);
        }

        /* If grouping/pivoting is required, match the rows to their groups */
        $groups = array();
        $ga = isset($query['group']) ? $query['group'] : array();
        $pa = isset($query['pivot']) ? $query['pivot'] : array();
        $porder = NULL;
        if ($pa || $ga) {
            if ($pa) {
                $porder = $this->make_order($query['pivot'], $cols, $exprs);
            }
            foreach ($rows as $row) {
                if (!$query['where'] ||
                    $this->evaluate($row, $query['where'])) {
                    $gkey = "";
                    foreach ($ga as &$value) {
                        $k = $this->evaluate($row, $value);
                        $k = str_replace('|', '||', $k);
                        $gkey .= "$k|";
                    }
                    $pkey = "";
                    foreach ($pa as &$value) {
                        $k = $this->evaluate($row, $value);
                        $k = str_replace('|', '||', $k);
                        $pkey .= "$k|";
                    }
                    if ($pa) {
                        $row[$pivot_key] = $pkey;
                        $row[$group_key] = $gkey;
                    }
                    $groups[$pkey.$gkey][] = $row;
                }
            }
            $this->aggregates = 1;
        } else {
            if ($query['where']) {
                foreach ($rows as $row) {
                    if ($this->evaluate($row, $query['where'])) {
                        $groups[""][] = $row;
                    }
                }
            } else {
                $groups[""] = $rows;
            }
            $this->aggregates = 0;
        }

        /*
          Evaluate the rows by groups. Note that $this->aggregates may become
          true even if there are no groups, if any of the aggregation functions
          is used.
        */
        $rout = array();
        foreach ($groups as $pgkey => $grows) {
            $this->agr_reset = 1;
            foreach ($grows as $row) {
                $this->visited++;
                $r = array();
                $i = 0;
                foreach ($exprs as &$col) {
                    $v = $this->evaluate($row, $col);
                    $r[$cols[$i++]['id']] = $v;
                }
                if ($pa) {
                    $r[$pivot_key] = $row[$pivot_key];
                    $r[$group_key] = $row[$group_key];
                }
                if (!$this->aggregates) {
                    $rout[] = $r;
                }
                $this->agr_reset = 0;
            }
            if (!$this->agr_reset &&
                $this->aggregates &&
                (!$query['having'] ||
                 $this->evaluate($row, $query['having'])))
            {
                $rout[] = $r;
            }
        }

        unset($col);

        global $vistable_ordering;
        if ($pa) {
            $vistable_ordering = $porder;
            usort($rout, "vistable_order_function");

            $pivots = array();
            foreach ($rout as $row) {
                if (!isset($pivots[$row[$pivot_key]])) {
                    $pivots[$row[$pivot_key]] = 1;
                }
            }
        }

        if ($order) {
            $vistable_ordering = $order;
            usort($rout, "vistable_order_function");
        }

        if (count($cols) > $ncol) {
            $cols = array_slice($cols, 0, $ncol);
            foreach ($rout as &$row) {
                $pk = $row[$pivot_key];
                $gk = $row[$group_key];
                $row = array_slice($row, 0, $ncol, TRUE);
                if ($pa) {
                    $row[$pivot_key] = $pk;
                    $row[$group_key] = $gk;
                }
            }
            unset($row);
        }

        if ($pa) {
            $groups = array();

            foreach ($rout as $row) {
                $groups[$row[$group_key]][$row[$pivot_key]] = $row;
            }

            $nrows = array();
            $ncols = array();

            // Copy "grouped" columns to new col array
            foreach ($cols as $col) {
                if ($col['group']) {
                    $ncols[] = $col;
                }
            }

            // Create pivoted columns for non-"grouped" columns
            foreach ($pivots as $pivot => $value) {
                foreach ($cols as $col) {
                    if (!$col['group']) {
                        $col['id'] = $pivot.$col["id"];
                        $col['label'] = $pivot.$col["label"];
                        $ncols[] = $col;
                    }
                }
            }

            foreach ($groups as $gkey => $rs) {
                $nrow = array();
                // get the "grouped" elements of the row
                foreach ($cols as $col) {
                    if ($col['group']) {
                        foreach ($rs as $row) {
                            $nrow[] = $row[$col['id']];
                            break;
                        }
                    }
                }
                // and now the "pivoted" elements
                foreach ($pivots as $pivot => $value) {
                    $row = isset($rs[$pivot]) ? $rs[$pivot] : NULL;
                    foreach ($cols as $col) {
                        if (!$col['group']) {
                            $nrow[] = $row !== NULL ? $row[$col['id']] : NULL;
                        }
                    }
                }

                $nrows[] = $nrow;
            }

            $cols = $ncols;
            $rout = $nrows;
        }

        $offset = 0;
        $limit = count($rout);

        if ($query['offset']) {
            $offset = $this->evaluate(NULL, $query['offset']);
        }
        if ($query['limit']) {
            $limit = $this->evaluate(NULL, $query['limit']);
        }

        $rows = array_slice($rout, $offset, $limit);
        return $cols;
    }

    public function execute()
    {
        $table = NULL;

        if ($this->response['status'] != 'error') {

            $parser = new visparser($this->fields);
            $this->query = $parser->parse($this->tq);

            if ($this->debug) {
                print_r($parser);
            }

            if (!$this->query) {
                $this->error('invalid_query', "", $parser->error_message);
            } else {
                $table = $this->fetch_table($this->query);
            }
        }

        $outfmt = "json";
        if (isset($this->params["out"])) {
            $outfmt = $this->params["out"];
        }

        if ($table) {
            $no_values = isset($this->query["options"]["no_values"]);
            $no_format = isset($this->query["options"]["no_format"]);
            if ($outfmt != "json") {
                $no_values = true;
                $no_format = false;
            }
            $rows = array();
            $cols = $table['cols'];
            $formatters = array('date' => new DateFormatter($this->locale, $this->tz,
                                                            'yyyy-MM-dd'),
                                'timeofday' => new DateFormatter($this->locale, $this->tz,
                                                                 'HH:mm:ss'),
                                'datetime' => new DateFormatter($this->locale, $this->tz,
                                                                'yyyy-MM-dd HH:mm:ss'));

            foreach ($cols as &$colref) {
                if (isset($colref['pattern'])) {
                    if ($colref['type'] == 'number') {
                        $colref['fmt'] = new NumberFormatter($this->locale,
                                                             NumberFormatter::PATTERN_DECIMAL, 
                                                             $colref['pattern']);
                        if ($this->debug) {
                            print_r($colref['fmt']);
                        }
                    } else if ($colref['type'] == 'date' || $colref['type'] == 'datetime') {
                        $colref['fmt'] = new DateFormatter($this->locale, $this->tz,
                                                           $colref['pattern']);
                        if ($this->debug) {
                            print_r($colref['fmt']);
                        }
                    } else if ($colref['type'] == 'boolean') {
                        $colref['fmt'] = new BoolFormatter($colref['pattern']);
                        if ($this->debug) {
                            print_r($colref['fmt']);
                        }
                    }
                } else if (isset($formatters[$colref['type']])) {
                    $colref['fmt'] = $formatters[$colref['type']];
                }
            }

            foreach ($table['rows'] as $row) {
                $r = array();
                foreach ($row as $key => $value) {
                    $val = $v = $f = $value;
                    $c = count($r);
                    $col = $cols[$c];
                    $type = $col['type'];
                    $v = $this->convert_literal($type, $v);
                    if ($v === NULL) {
                        $a = NULL;
                    } else {
                        $a = array();
                        if (!$no_values) {
                            $a['v'] = $this->value_convert($type,$v);
                        }
                        if (!$no_format) {
                            if ($col['fmt']) {
                                $f = $col['fmt']->format($v);
                            }
                            $a['f'] = $f;
                        }
                    }
                    $r[] = $a;
                }
                $rows[] = array('c'=>$r);
            }
            $table['rows'] = $rows;
            $this->response['table'] = $table;
        }

        $sig = md5(json_encode($this->response));
        if ($sig == $this->params['sig']) {
            $this->error('not_modified', '', '');
        }

        unset($this->response['table']);
        $this->response['version'] = $this->params['version'];
        if (isset($this->params['reqId'])) {
            $this->response['reqId'] = $this->params['reqId'];
        }

        $this->response['sig'] = $sig;

        if ($this->response['status'] == 'error') {
            $table = NULL;
            unset($this->response['warnings']);
        }
        if ($table) {
            $this->response['table'] = $table;
        }

        if ($this->debug) {
            $outfmt = "debug";
        }

        $out = "";
        switch ($outfmt) {
        case 'json':
            header('Content-type: text/plain; charset="UTF-8"');

            $out = json_encode($this->response);
            $out = preg_replace('/"(new Date\(.*?\))"/', "$1", $out);
            $out = preg_replace('/([\{,])"([A-Za-z_][A-Za-z0-9_]*)"/', "$1$2", $out);
            $out = $this->params['responseHandler']."($out);\n";
            break;
        case 'csv':
            header('Content-type: text/plain; charset="UTF-8"');

            if ($table) {
                $out = self::csv_row($table['cols'], "label");
                foreach ($table['rows'] as $row) {
                    $out .= self::csv_row($row['c'], 'f');
                }
            }
            break;
        case 'html':
            header('Content-type: text/html; charset="UTF-8"');

            $out = "<html><body><table border='1' cellpadding='2' cellspacing='0'>";
            if ($this->response['status'] != 'ok') {
                if (isset($this->response['errors'])) {
                    $out .= self::html_diagnostic($this->response['errors'],"#f00");
                }
                if (isset($this->response['warnings'])) {
                    $out .= self::html_diagnostic($this->response['warnings'],"#ff0");
                }
                $out .= "</table><table border='1' cellpadding='2' cellspacing='0'>";
            }
            if ($table) {
                $out .= self::html_row($table['cols'], 'label', 'font-weight: bold; background-color: #aaa;');
                $colors = array('#f0f0f0','#ffffff');
                $cix = 0;
                foreach ($table['rows'] as $row) {
                    $out .= self::html_row($row['c'], 'f', 'background-color: '.$colors[$cix]);
                    $cix ^= 1;
                }
            }
            $out .= "</table></body></html>";
            break;

        case 'debug':
            header('Content-type: text/plain; charset="UTF-8"');
            ob_start();
            var_dump($this->response);
            $out=ob_get_contents();
            ob_end_clean();
            break;
        }

        return $out;
    }

    private static function csv_row($r, $id)
    {
        $out = array();
        foreach ($r as $v) {
            if (!$v) {
                $x = "";
            } else {                
                $x = $v[$id];
            }
            if (strpbrk($x, '",')) {
                $x = str_replace('"','""',$x);
                $x = '"'.$x.'"';
            }
            $out[] = $x;
        }
        return implode(",", $out)."\n";
    }

    private static function html_diagnostic($diagnostics, $color)
    {
        $out = "";
        foreach ($diagnostics as $diag) {
            $out .= "<tr style='background-color: $color'>";
            $out .= "<td>{$diag['reason']}</td>";
            $msg = isset($diag['message']) ? $diag['message'] : "&nbsp;";
            $out .= "<td>$msg</td>";
            $msg = isset($diag['detailed_message']) ? $diag['detailed_message'] : "&nbsp;";
            $out .= "<td>$msg</td>";
            $out .= "</tr>";
        }
        return $out;
    }

    private static function html_row($r, $id, $style)
    {
        $out = "<tr style='$style'>";
        foreach ($r as $v) {
            if (!$v) {
                $x = "";
            } else {                
                $x = $v[$id];
                if ($x == "") {
                    $x = "";
                }
            }
            if ($x == "") {
                $x = "&nbsp;";
            } else {
                $x = htmlspecialchars($x, ENT_COMPAT, "UTF-8");
            }
            $out .= "<td>$x</td>";
        }
        return $out."\n";
    }
};

class mysql_vistable extends vistable {
    private $tables;
    private $mode = 0;

    public function __construct($tqx,$tq,$tqrt,$tz,$locale,$extra=NULL) {
        parent::__construct($tqx,$tq,$tqrt,$tz,$locale,$extra);
    }
        
    public function setup_database($tables, $fields)
    {
        $this->tables = $tables;
        $this->fields = $fields;

        $q = "SELECT";
        $fields = array();
        foreach ($this->fields as $key => $value) {
            $f = "";
            $sql_field = NULL;
            if (is_string($value)) {
                $sql_field = $value;
            }

            if (is_array($value)) {
                if (isset($value["sql_field"])) {
                    $sql_field = $value["sql_field"];
                }
            } else {
                $this->fields[$key] = array(TYPE=>SIMPLE, VALUE=>$key);
            }
            if ($sql_field) {
                $f .= " $sql_field AS";
                $this->fields[$key]["sql_field"] = $sql_field;
            }
            $f .= " $key";
            $fields[] = $f;
        }
        $q .= implode(",",$fields)." FROM ".$this->tables;
        $q .= " LIMIT 0";
        $result = mysql_query($q);
        if ($result) {
            $sql_types = array(
                               "number" => "INT|FLOAT|DOUBLE|REAL|DECIMAL|NUMERIC|BIT",
                               "boolean" => "BOOL",
                               "date" => '^DATE$',
                               "timeofday" => '^TIME$',
                               "datetime" => '^DATETIME|TIMESTAMP$',
                               );
            $ncol = mysql_num_fields($result);
            for ($i=0; $i < $ncol; $i++) {
                $fname = mysql_field_name($result, $i);
                if (!isset($this->fields[$fname]['type'])) {
                    $ftype = mysql_field_type($result, $i);
                    $type = 'string';
                    foreach ($sql_types as $t => $pat) {
                        if (preg_match("/$pat/i", $ftype)) {
                            $type = $t;
                            break;
                        }
                    }
                    $this->fields[$fname]['type'] = $type;
                }
                if (!isset($this->fields[$fname]['label'])) {
                    $this->fields[$fname]['label'] = $fname;
                }
            }
        }
    }

    protected function pre_write($q)
    {
        $v = $q[VALUE];
        if ($this->mode != 0) {
            switch ($q[TYPE]) {
                case OPERATOR:
                    switch ($v) {
                        case "matches":
                            $e0 = $this->write_expr($q[0]);
                            $e1 = $this->write_expr($q[1]);
                            return "$e0 REGEXP $e1";
                        case "contains":
                            $r = $this->write_func("LOCATE", $q, TRUE);
                            $r .= "!=0";
                            return $r;
                        case "starts_with":
                            $r = $this->write_func("LOCATE", $q, TRUE);
                            $r .= "=1";
                            return $r;
                        case "ends_with":
                            $e0 = $this->write_expr($q[0]);
                            $e1 = $this->write_expr($q[1]);
                            return "RIGHT($e0,LENGTH($e1))=$e1";
                    }
                case FUNCT:
                    switch ($v) {
                        case 'now':
                            return "CONCAT(CURRENT_DATE,' ',CURRENT_TIME)";
                        case 'todate':
                            return $this->write_func("date", $q);
                    }
                    break;
                case SIMPLE:
                    if (isset($q["sql_field"])) {
                        return $q["sql_field"];
                    } else {
                        return $v;
                    }
            }
        }
        return NULL;
    }

    private function sql_expr($q, $mode)
    {
        $this->mode = $mode;
        $ret = $this->write_expr($q);
        $this->mode = 0;
        return $ret;
    }

    private function vis_query2sql_query($query, &$cols)
    {
        $q = "SELECT ";
        $fields = array();
        $cols = array();
        $order = array();
        foreach ($query['select'] as $value) {
            $f = $this->sql_expr($value, 1);
            $as = $this->sql_expr($value, 0);
            $type = isset($value['type']) ? $value['type'] : 'string';
            if ($f != $as) {
                $f .= " AS `$as`";
            }
            $label = isset($value['label']) ? $value['label'] : $as;
            $fields[$as] = $f;
            $col = array('id' => $as, 'label' => $label, 'type' => $type);
            if ($type != 'string' && isset($value['format'])) {
                $col['pattern'] = $value['format'];
            }
            $cols[] = $col;
        }

        if (isset($query['order'])) {
            foreach ($query['order'] as $value) {
                $dir = isset($value['dir']) && $value['dir'] == 'desc' ? 'desc' : 'asc';
                $as = $this->sql_expr($value, 0);
                if (!isset($fields[$as])) {
                    $o = $this->sql_expr($value,1);
                } else {
                    $o = "`$as`";
                }
                $order[] = "$o $dir";
            }
        }

        $q .= implode(",",$fields)." FROM ".$this->tables;
        if ($query['where']) {
            $q .= " WHERE ".$this->sql_expr($query['where'], 1);
        }
        if ($query['group']) {
            $q .= " GROUP BY ";
            $fields = array();
            foreach ($query['group'] as $value) {
                $fields[] = $this->sql_expr($value, 1);
            }
            $q .= implode(",", $fields);
        }
        if ($query['having']) {
            $q .= " HAVING ".$this->sql_expr($query['where'], 1);
        }
        if (count($order)) {
            $q .= " ORDER BY ".implode(",",$order);
        }
        if ($query['limit'] || $query['offset']) {
            $o = $query['offset'] ? $this->sql_expr($query['offset'], 0) : "0";
            $l = $query['limit'] ? $this->sql_expr($query['limit'], 0) : "1000000000";
            $q .= " LIMIT $o,$l";
        }

        if ($this->debug) {
            echo "\n\nQ: $q\n\n\n";
        }
        return $q;
    }

    protected function fetch_table($query)
    {
        $use_query = isset($query['pivot']) || $this->params['nosql'];
        if ($use_query) {
            $q = "SELECT";
            $fields = array();
            foreach ($this->fields as $key => $value) {
                $f = "";
                if (isset($value["sql_field"])) {
                    $f .= " {$value["sql_field"]} AS";
                    # $this->fields[$key]["sql_field"] = $sql_field;
                }
                $f .= " $key";
                $fields[] = $f;
            }
            $q .= implode(",",$fields)." FROM ".$this->tables;
            if ($query['where']) {
                $q .= " WHERE ".$this->sql_expr($query['where'], 1);
            }
        } else {
            $q = $this->vis_query2sql_query($query, $cols);
            if ($q === FALSE) return FALSE;
        }

        $data = mysql_query($q);

        if (!$data) {
            $this->error("internal_error", "query failed", "query `$q' failed:".mysql_error());
            return FALSE;
        }

        $rows = array();
        while ($row = mysql_fetch_assoc($data)) {
            $rows[] = $row;
        }

        if ($use_query) {
            $cols = $this->query_filter($rows, $query);
        }

        return array('cols' => $cols, 'rows' => $rows);
    }
};

class csv_vistable extends vistable {
    private $table;

    public function __construct($tqx,$tq,$tqrt,$tz,$locale,$extra=NULL) {
        parent::__construct($tqx,$tq,$tqrt,$tz,$locale,$extra);
    }
        
    public function setup_table($data)
    {
        $this->fields = array();
        $row = $this->next_row($data);
        if ($row === NULL) return;
        foreach ($row as &$id) {
            $type = 'string';
            if (preg_match('/^(.*) as (date|datetime|boolean|timeofday|number)$/',$id,$matches)) {
                $id = $matches[1];
                $type = $matches[2];
            }
            $this->fields[$id] = array(TYPE=>SIMPLE, VALUE=>$id, 'type' => $type);
        }
        $cols = $row;
        $this->table = array();
        while (($row = $this->next_row($data)) !== FALSE) {
            if (count($row) > count($cols)) continue;
            $this->table[] = array_combine(array_slice($cols,0,count($row)), $row);
        }
    }

    protected function fetch_table($query)
    {
        $rows = $this->table;

        if (!$rows) {
            $this->error("internal_error", "no data", "");
            return FALSE;
        }

        $cols = $this->query_filter($rows, $query);

        return array('cols' => $cols, 'rows' => $rows);
    }

    private function next_row(&$data)
    {
        if (preg_match('/^(([^\"\n]*(\"[^\"]*\"))*[^\"\n]*)\n(.*)$/s',$data,$matches)) {
            $data=$matches[4];
            $ret = $matches[1];
        } else if ($data !== "") {
            $ret = $data;
            $data = "";
        } else {
            return FALSE;
        }

        $ret .= ",";
        $row = array();
        while ($ret !== "") {
            if (preg_match('/^\s*\"(([^\"]*\"\")*[^\"]*)\"\s*,(.*)$/', $ret, $matches)) {
                $ret = $matches[3];
                $row[] = $matches[1];
            } else if (preg_match('/^([^,]*),(.*)$/', $ret, $matches)) {
                $ret = $matches[2];
                $row[] = $matches[1];
            } else {
                return FALSE;
            }
        }

        return $row;
    }
};
