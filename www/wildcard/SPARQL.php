<?php
/*
 * SPARQL support
 */
 
function queryError($msg) {
    header('HTTP/1.1 400 Bad Request');
    echo "$msg\n";
    exit;
}

function querySplit($txt) {
    $chr = array('<','>','{','}','"',';');
    $pos = array();
    foreach ($chr as $c) {
        $x = 0;
        while (FALSE != ($x = strpos($txt, $c, $x+1))) {
            if (!isset($pos[$c]))
                $pos[$c] = array();
            $pos[$c][] = $x;
        }
    }
    $cidx = array();
    foreach ($pos as $c=>$d) {
        foreach ($d as $i) {
            $cidx[$i] = $c;
        }
    }
    ksort($cidx, SORT_NUMERIC);
    $braces = 0;
    $brackd = false;
    $quoted = false;
    $r = array();
    $i = 0;
    foreach ($cidx as $j=>$c) {
        switch ($c) {
            case ';':
                if (!$brackd && !$quoted && $braces < 1) {
                    $r[] = substr($txt, $i, $j-$i);
                    $i = $j+1;
                }
                break;
            case '<':
                if (!$quoted)
                    $brackd = true;
                break;
            case '>':
                if ($brackd)
                    $brackd = false;
                break;
            case '"':
                if (!$brackd)
                    $quoted = !$quoted;
                break;
            case '{':
                if (!$brackd && !$quoted)
                    $braces += 1;
                break;
            case '}':
                if (!$brackd && !$quoted)
                    $braces -= 1;
                break;
        }
    }
    $r[] = substr($txt, $i);
    return $r;
}

require_once('arc2/ARC2.php');

function queryExecute($query, $g) {
    $parser = ARC2::getMITSPARQLParser();
    $parser->parse($query);
    if (isset($parser->errors) && count($parser->errors))
        queryError(implode("\n",$parser->errors));

    $info = $parser->getQueryInfos();
    $query = $info['query'];

    $assure_strings = array('type', 'target_graph');
    $assure_arrays = array('dataset', 'target_graphs', 'construct_triples');

    foreach ($assure_strings as $k=>$v)
        if (!isset($query[$v]))
            $query[$v] = '';
    foreach ($assure_arrays as $k=>$v)
        if (!isset($query[$v]))
            $query[$v] = array();
    foreach ($query as $k=>$v)
        if (!in_array($k, $assure_arrays) && !in_array($k, $assure_strings))
            queryError('unsupported query feature: '.$k);

    if (!in_array($query['type'], array('insert', 'delete')))
        queryError('valid query types: insert');

    if (strlen($_base) && strlen($query['target_graph'])) {
        if ($query['target_graph'] != $_base)
            queryError('query must target request URI graph (only)');
        if (count($query['target_graphs']) && $query['target_graphs'][0] != $_base)
            queryError('query must target request URI graph (only)');
    }

    foreach ($query['construct_triples'] as $elt)
        foreach (array('s', 'p', 'o') as $k)
            if (!in_array($elt["{$k}_type"], array('uri', 'literal')))
                queryError('unsupported node type: '.$elt[$k].' ('.$elt["{$k}_type"].')');

    $n = 0;
    switch ($query['type']) {
        case 'insert':
            foreach ($query['construct_triples'] as $elt) {
                $g->append_objects($elt['s'], $elt['p'], array(array('type'=>$elt['o_type'], 'value'=>$elt['o'])));
                $n += 1;
            }
            break;
        case 'delete':
            foreach ($query['construct_triples'] as $elt) {
                $g->remove_triple($elt);
                $n += 1;
            }
            break;
    }

    return $n;
}

$n = 0;
foreach (querySplit($_data) as $i=>$query) {
    $n += queryExecute($query, $g);
}

if ($n)
    $g->save($_data);
