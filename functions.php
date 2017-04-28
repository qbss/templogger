<?php

define("SYS_BUS_LOCATION","/sys/bus/w1/devices/"); // location of sensors data bus
define("DATA_DIR","data/"); // where we save rrd files, and where sensor config lives


function pushbullet_notify($title = "test", $body = "test", $target = PUSHBULLET_TARGETS) {
	// mostly borrowed from "pushnotify" bash script (of which the most important bit is here:
	// echo $DATA | curl -u tXsHRjiFERdKbpvjBb1BCoLOTaG4J7lX: -X POST https://api.pushbullet.com/v2/pushes --header 'Content-Type: application/json' --data-binary @-

	$type = "note";
	$source_iden = PUSHBULLET_SOURCE;
	$url = "https://api.pushbullet.com/v2/pushes";

	$json_data = json_encode(array("type" => $type, "title" => $title, "body" => "$body", "device_iden" => $target, "source_device_iden" => $source_iden ));
	
	$username = PUSHBULLET_USER_SECRET;
	
	$process = curl_init($url);
	
	curl_setopt($process, CURLOPT_POST, 1);
	curl_setopt($process, CURLOPT_POSTFIELDS, $json_data);
	curl_setopt($process, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($process, CURLOPT_USERPWD, $username);
	curl_setopt($process, CURLOPT_TIMEOUT, 30);
	curl_setopt($process, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
	$data = curl_exec($process);
	
	echo "RETURNED: ".$data."\n";
	curl_close($process);	
}

function create_rrd_database($sensor) {
	$options = array(
		"--step", "300",            // Use a step-size of 5 minutes
		"DS:temp:GAUGE:600:U:U", // raw sensor temperature
		"DS:ctemp:GAUGE:600:U:U", // with offset applied (if there is one)
		"RRA:AVERAGE:0.5:1:288",
		"RRA:AVERAGE:0.5:12:2016", // one hour for 12 weeks
		);
	$filename = DATA_DIR.$sensor.".rrd";
	$ok = rrd_create ( $filename, $options );
	
	if (!$ok) {
		 echo "<b>Creation error: </b>".rrd_error()."\n";
		 return false;
	}
	
	return true;
}

function update_rrd_database($sensor, $temp, $offset = 0) {
	$filename = DATA_DIR.$sensor.".rrd";
	$now = round(gettimeofday(true));
	
	if (!file_exists($filename)) {
		$ok = create_rrd_database($sensor);
		if (!$ok) {
			return false;
		}
	}
	
	$ctemp = $temp + $offset;
	$update = array("$now:$temp:$ctemp");
	
	$ok = rrd_update($filename, $update);
	
	return $ok;
}

function update_all_sensors ( ) {
	$dir = SYS_BUS_LOCATION;
	if (is_dir($dir)) {
		if ($dh = opendir($dir)) {
			while (($file = readdir($dh)) !== false) {
				if ( substr ( $file , 0, 2) == "28" ) { // 28_* is a temp sensor
					$temp = read_sensor($dir.$file);
					if ($temp !== false ) {
						update_rrd_database($file, $temp);
					}
				}
			}
			closedir($dh);
		}
	}
}

function read_sensor($sensor) {
	echo "read_sensor($sensor)\n";
	
	$filename = $sensor."/w1_slave";
		
	$data = file_get_contents($filename);
	
	if ($data === false )
		return false;
	
	$lines = explode("\n", $data);
	//print_r($lines);
	
	if (substr($lines[0],-3) == "YES") { // CRC is ok
		$fields = explode(" ", $lines[1]);
		//print_r($fields);
		$temp = substr($fields[9],2,5)/1000;
		echo "temperature = $temp \n";
		return $temp;
	}
	else {
		echo "Bad CRC data\n";
		return false;
	}
}

function create_graph($file, $title) {
  $options = array(
    "--title=$title",
    "--vertical-label=Temp",
    "DEF:temp=$file:temp:AVERAGE",
    "CDEF:ttemp=temp,1,*",
    "AREA:ttemp#00FF00:Temperature",
  );

  $ret = rrd_graph("temp.png", $options);
  if (! $ret) {
    echo "<b>Graph error: </b>".rrd_error()."\n";
  }
}




?>
