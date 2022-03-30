<?php

// ----------------------------------------------------------------
//
// modulators.php
//
// log viewer tool
//
// 18/05/2016 - LZ - First release
//
// ----------------------------------------------------------------

  // phpinfo();  exit();

  // in order to ease debugging comment or uncomment the following line: $debug = 1;
  // $debug = 1;
  if (isset($debug)) {echo "<pre>\nREQUEST: "; var_dump($_REQUEST); echo "</pre><p>\n";}

  // echo "<pre>\n_COOKIE: "; var_dump($_COOKIE); echo "</pre><p>\n";
  $script = $_SERVER["SCRIPT_NAME"];

  $filter_select = '';

  // ----------------------------------------------------------------
  // debug a variable
  function debug($var, $name='')
  {
    if ($name !== '') {
      echo "\$$name: ";
    }
    if (is_array($var)) {
      echo "<pre>"; print_r($var); echo "</pre><p>\n";
    }
    else {
      echo ($var===0? "0": $var)."<p>\n";
    }
  }

  // ----------------------------------------------------------------
  // Quote variable to make safe
  function quote_smart($value)
  {
     // Stripslashes
     if (get_magic_quotes_gpc()) {
         $value = stripslashes($value);
     }
     strtr($value, 'ï¿½ï¿½`', '""'."'");
     // Quote if not integer
     if (!is_numeric($value)) {
         $value = "'".mysql_real_escape_string($value)."'";
     }
     return $value;
  }

  // ----------------------------------------------------------------
  // check access as administrator
  function check_admin_access()
  {
    global $debug, $script;
    $admin_ip = array(
      "140.105.5.32" => array("140.105.5.32", "0.0.0.0"),
      "140.105.4.3" => array("140.105.5.32", "140.105.5.214", "192.168.205.55"),
      "140.105.2.5" => array("140.105.5.32", "140.105.5.214", "192.168.205.55"),
      "140.105.8.30" => array("140.105.5.32", "140.105.5.214", "192.168.205.55"),
      "140.105.8.31" => array("140.105.5.32", "140.105.4.214", "192.168.205.55")
    );
    $remote = $_SERVER['REMOTE_ADDR'];
    $forwarded = isset($_SERVER['HTTP_X_FORWARDED_FOR'])? $_SERVER['HTTP_X_FORWARDED_FOR']: 0;
    // echo "remote ip: $remote, forwarded for: $forwarded<br />\n";
    /*
    if ($remote == "127.0.0.1") {
      return;
    }
    */
    foreach ($admin_ip as $ip => $f) {
      if ($forwarded) {
        foreach ($f as $forw) {
          if (($forwarded == $forw) and ($remote == $ip)) {
            return true;
          }
        }
      }
      else if ($remote == $ip) {
        return true;
      }
    }
    return false;
  }

	// ----------------------------------------------------------------
	// parse and detect time periods
	function parse_time($time) {
		if (strpos($time, 'last ')!== false) {
			$last = explode(' ', $time);
			$i = $n = 1;
			if (count($last) == 3) {
				$i = 2;
				$n = $last[1];
			}
			if (strpos($last[$i], "second")!==false) {
				$time_factor = 1;
			}
			else if (strpos($last[$i], "minute")!==false) {
				$time_factor = 60;
			}
			else if (strpos($last[$i], "hour")!==false) {
				$time_factor = 3600;
			}
			else if (strpos($last[$i], "day")!==false) {
				$time_factor = 86400;
			}
			else if (strpos($last[$i], "week")!==false) {
				$time_factor = 604800;
			}
			else if (strpos($last[$i], "month")!==false) {
				$time_factor = 2592000; // 30days
			}
			else if (strpos($last[$i], "year")!==false) {
				$time_factor = 31536000; // 365days
			}
			$t = time();
			return date('Y-m-d H:i:s', $t - $n*$time_factor - ($t % $time_factor));
		}
		return $time;
	}

  // ----------------------------------------------------------------
  // check access as administrator
  function emit_stat()
  {
    global $debug, $script, $statquery, $admin, $filter_select;
    // debug($_REQUEST);
    $id = 0;
    $ids = array();
    $time = 'timestamp'; // 'db_time'; //
    $csv_separator = ",";
    if (isset($_REQUEST["startdate"]) and $_REQUEST["startdate"]=='lastday') {
      $_REQUEST["startdate"] = date("Y-m-d H:00:00", time()-86400);
    }
    $startdate = isset($_REQUEST["startdate"])? parse_time($_REQUEST["startdate"]): date("Y-m-d H:00:00", time()-3600);
    $stopdate = isset($_REQUEST["stopdate"])? parse_time($_REQUEST["stopdate"]): "";
    $signal = isset($_REQUEST["signal"])? $_REQUEST["signal"]: "";
    $offset = isset($_REQUEST["offset"])? $_REQUEST["offset"]: 0;
    $byte = (isset($_REQUEST["byte"])? $_REQUEST["byte"]: 1);
    $bit = (isset($_REQUEST["bit"])? $_REQUEST["bit"]: "");
    $year = substr($startdate, 0, 4);
    if (isset($_REQUEST["var"])) {
      $offset = 12;
      $byte = 312;
      $_REQUEST["oldval"] = true;
    }
    $leftbuffer = "<input name=\"search\" value=\"Search\" type=\"submit\" onClick=\"captureDialog()\"> &nbsp;<input value=\"Normal\" type=\"button\" onClick=\"implode()\"><br><br>Offset (first byte): <input name=\"offset\" type=\"text\" size=\"5\" value=\"$offset\"><br><br>Number of bytes: <input name=\"byte\" type=\"text\" size=\"5\" value=\"$byte\"><br><br>Bit: <input name=\"bit\" type=\"text\" size=\"5\" value=\"$bit\"><br><br>On variation: <input name=\"oldval\" type=\"checkbox\" ".(isset($_REQUEST["oldval"])? "CHECKED": "CHECKED")."> <br><br>";
    $leftshort = "<input name=\"var\" value=\"Search\" type=\"submit\" onClick=\"captureDialog()\"> &nbsp;
                  <input name=\"csv\" value=\"Export (csv)\" type=\"submit\" onClick=\"captureDialog()\"> &nbsp;
                  <!--input name=\"alarm_description\" value=\"Alarm Description\" type=\"submit\"> &nbsp;<br-->";
    $leftstart = $leftshort; // (isset($_REQUEST["var"]) or !isset($_REQUEST["startdate"]))? $leftshort: $leftbuffer;
    $rightstart = ""; // (isset($_REQUEST["var"]) or !isset($_REQUEST["startdate"]))? "": $names;
    // $stopdate = (isset($_REQUEST["stopdate"]) and strlen($_REQUEST["stopdate"]))? " AND plc_time<=UNIX_TIMESTAMP('{$_REQUEST["stopdate"]}')": "";
    $stopdate = (isset($_REQUEST["stopdate"]) and strlen($_REQUEST["stopdate"]))? " AND $time<=UNIX_TIMESTAMP('{$_REQUEST["stopdate"]}')": "";

    $mod_description = substr($startdate, 0, 4)<2016? 'mod_description_2015': 'mod_description';
    $filter_select = "<select name='filter_name'>\n<option value=''> </option>\n"; 
    $res = mysql_query("SELECT * FROM $mod_description WHERE NOT (name LIKE '--%' OR name LIKE '%spare%') ORDER BY name");
    // $res = mysql_query("SELECT byte_number, bit_number, name, comment FROM linac_db51_descr ORDER BY byte_number, bit_number");
    while ($r = mysql_fetch_array($res, MYSQL_ASSOC)) {
      // if (isset($_REQUEST['debug'])) debug($r);
      $linac_names[$r["byte_number"]][$r["bit_number"]] = $r["name"];
      $linac_comments[$r["byte_number"]][$r["bit_number"]] = $r["comment"];
      $filter_select .= "<option value='{$r["name"]}'>{$r["name"]} - {$r["byte"]}.{$r["bit"]}</option>\n";
    }
    // debug info                                                         <<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<================================================
    // $linac_names[308][3] = "debug bit";
    // $linac_names[309][3] = "debug bit";
    // $linac_names[323][0] = "BST_L1_OPENCMD (debug)";
    // $res = mysql_query("SELECT byte_number, bit_number, name, comment FROM undulator_db51_descr ORDER BY byte_number, bit_number");
    if (!isset($_REQUEST["startdate"])) return;
    $linac_db51_old = $undulator_db51_old = array();
    // $linac_query = "SELECT CONCAT(FROM_UNIXTIME(timestamp),SUBSTR(ROUND(MOD(timestamp,1)*1000)/1000,2,4)) AS plc_time, timestamp AS t, byte, bit, $mod_description.modulator, event,name, comment FROM real_time_$year,$mod_description WHERE $mod_description.id_mod_description=real_time_$year.id_mod_description AND timestamp>UNIX_TIMESTAMP('$startdate')$stopdate";
    $linac_query = "SELECT FROM_UNIXTIME(timestamp) AS plc_time, timestamp AS t, byte, bit, $mod_description.modulator, event,name, comment FROM real_time_$year,$mod_description WHERE $mod_description.id_mod_description=real_time_$year.id_mod_description AND timestamp>UNIX_TIMESTAMP('$startdate')$stopdate";
    $res = mysql_query("$linac_query ORDER BY t, byte");
    if (isset($_REQUEST['debug'])) echo "<br><br><br>$linac_query ORDER BY t, byte;<br>";
    
    $big_data = array();
    while ($r = mysql_fetch_array($res, MYSQL_ASSOC)) {
	  $big_data[] = array(
	        't'=>$r['t'],
		'plc_time'=>$r['plc_time'],
		'val'=>$r["event"],
		'address'=>strtr($r["byte"].'.'.$r["bit"], array('-1.-1'=>'')),
		'mod'=> strtr($r["modulator"], array('-1'=>'')),
		'name'=> $r["name"],
		'description'=> $r["comment"]
	  ); 
        $linac_db51_old[$r["byte_number"]] = $r["value"]; 
    }
    return $big_data;
  }

  // ----------------------------------------------------------------
  // MAIN
  // ----------------------------------------------------------------
  $db = mysql_connect(HOST, USERNAME, PASSWORD);
  mysql_select_db(DB, $db);

	$time_buffer = array('L'=>'','H'=>'');
	$line_style = 0;

	// ----------------------------------------------------------------
	// display line in table
	function emit_line($line) {
		global $debug, $script, $time_buffer, $line_style;
		$t = $line['plc_time'];
		$new_line = "<td>{$line['mod']}</td><td>{$line['address']}</td><td".($line['val']=="ALARM"? " style='color:red'": '').">{$line['val']}</td><td>{$line['name']}</td><td>{$line['description']}</td></tr>\n";
		if (!empty($_REQUEST['filter']) and (stripos($new_line, $_REQUEST['filter'])===false)) return;
		if ($time_buffer[$line['plc']]==$t) $t = '&nbsp;'; else {$time_buffer[$line['plc']]=$t;$line_style = 1 - $line_style;}
		echo "<tr class='".($line_style? 'info':'warning')."'><td>$t</td>$new_line";
	}


	// ----------------------------------------------------------------
	// export data in CSV
	function emit_csv($data1) {
		$csv = "PLC time,address,event,name,comment\n";
		foreach ($data1 as $data1_line) {
			$new_line = "{$data1_line['plc_time']},{$data1_line['mod']},{$data1_line['address']},{$data1_line['val']},{$data1_line['name']},{$data1_line['description']}\n";
			$csv .= (empty($_REQUEST['filter']) or (stripos($new_line, $_REQUEST['filter'])!==false))? $new_line: '';
		}
		header("Content-Disposition: attachment; filename=pss.csv");
		header("Content-Type: application/x-csv");
		header("Content-Length: ".strlen($csv));
		echo $csv;
		exit();
	}

	// ----------------------------------------------------------------
	// display data in HTML
	function emit_data($data1) {
		global $debug, $script, $filter_select;
		if (!empty($_REQUEST['filter_name'])) {$_REQUEST['filter'] = $_REQUEST['filter_name'];}
		if (isset($_REQUEST['export']) and ($_REQUEST['export']=='csv')) {emit_csv($data1);}
		$template = file_get_contents('./header_modulators.html');
		$replace = array("<!--startdate-->"=>$_REQUEST["startdate"],"<!--stopdate-->"=>$_REQUEST["stopdate"]);
		$replace['<!--L-->'] = $_REQUEST['plc']=='L'? ' checked': '';
		$replace['<!--H-->'] = $_REQUEST['plc']=='H'? ' checked': '';
		$replace['<!--all-->'] = $_REQUEST['plc']=='all'? ' checked': '';
		$replace['<!--filter-->'] = $_REQUEST['filter'];
		$replace['<!--filter_select-->'] = $filter_select;
		echo strtr($template, $replace);
		echo "<table class='table table-hover'>\n";
		echo "\n<tr><th>PLC time</th><th>mod</th><th>address</th><th>event</th><th>name</th><th>comment</th></tr>\n";
		// header("Content-Type: application/json");
		// echo json_encode(array_merge($data1,$data2));
		if (!empty($data1)) foreach ($data1 as $data1_line) {
			emit_line($data1_line);
		}
		echo "</table>\n";
		echo "</div>\n</div>\n";
		readfile('./footer.html');
	}
  
  

	// ----------------------------------------------------------------
	// MAIN
	// ----------------------------------------------------------------
	if (!isset($_REQUEST['plc'])) $_REQUEST['plc']='all';
	emit_data(emit_stat());

?>
