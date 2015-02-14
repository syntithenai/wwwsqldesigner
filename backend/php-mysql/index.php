<?php
/*
$mysqli = new mysqli("example.com", "user", "password", "database");
$result = $mysqli->query("SELECT 'Hello, dear MySQL user!' AS _message FROM DUAL");
$row = $result->fetch_assoc();
echo htmlentities($row['_message']);
*/

$connection=null;
set_time_limit(0);
	function setup_saveloadlist() {
		define("SERVER","localhost");
		define("USER","root");
		define("PASSWORD","");
		define("DB","wwwsqldesigner");
		define("TABLE","wwwsqldesigner");
	}
	function setup_import() {
		define("SERVER","localhost");
		define("USER","root");
		define("PASSWORD","");
		define("DB","information_schema");
	}
	function connect() {
		global $connection;
		//echo "CONNECT:";
		//$conn = mysql_connect(SERVER,USER,PASSWORD);
		//if (!$conn) return false;
		//$res = mysql_select_db(DB);
		$connection=new mysqli(SERVER,USER,PASSWORD,DB);
		//print_r($connection);
		if (!$connection) {
			echo "ERR:".$connection->error();
			return false;
		}
		return true;
	}

	function import() {
		global $connection;
		$db = (isset($_GET["database"]) ? $_GET["database"] : "information_schema");
		//$db = mysql_real_escape_string($db);
		$xml = "";

		$arr = array();
		@ $datatypes = file("../../db/mysql/datatypes.xml");
		$arr[] = $datatypes[0];
		$arr[] = '<sql db="mysql">';
		for ($i=1;$i<count($datatypes);$i++) {
			$arr[] = $datatypes[$i];
		}
		$result = $connection->query("SELECT * FROM TABLES WHERE TABLE_SCHEMA = '".$db."'");
//echo "got file ".$db;
//flush();
//die();
		while ($row = $result->fetch_assoc()) {
			$table = $row["TABLE_NAME"];
	//		echo "got table".$table;
//flush();
			$xml .= '<table name="'.$table.'">';
			$comment = (isset($row["TABLE_COMMENT"]) ? $row["TABLE_COMMENT"] : "");
			if ($comment) { $xml .= '<comment>'.htmlspecialchars($comment).'</comment>'; }

			$q = "SELECT * FROM COLUMNS WHERE TABLE_NAME = '".$table."' AND TABLE_SCHEMA = '".$db."'";
			$result2 = $connection->query($q);
			while ($row = $result2->fetch_assoc()) {
				$name  = $row["COLUMN_NAME"];
				$type  = $row["COLUMN_TYPE"];
				$comment = (isset($row["COLUMN_COMMENT"]) ? $row["COLUMN_COMMENT"] : "");
				$null = ($row["IS_NULLABLE"] == "YES" ? "1" : "0");

				if (preg_match("/binary/i",$row["COLUMN_TYPE"])) {
					$def = bin2hex($row["COLUMN_DEFAULT"]);
				} else {
					$def = $row["COLUMN_DEFAULT"];
				}

				$ai = (preg_match("/auto_increment/i",$row["EXTRA"]) ? "1" : "0");
				if ($def == "NULL") { $def = ""; }
				$xml .= '<row name="'.$name.'" null="'.$null.'" autoincrement="'.$ai.'">';
				$xml .= '<datatype>'.strtoupper($type).'</datatype>';
				$xml .= '<default>'.$def.'</default>';
				if ($comment) { $xml .= '<comment>'.htmlspecialchars($comment).'</comment>'; }

				// fk constraints 
				$q = "SELECT
					REFERENCED_TABLE_NAME AS 'table', REFERENCED_COLUMN_NAME AS 'column'
					FROM KEY_COLUMN_USAGE k
					LEFT JOIN TABLE_CONSTRAINTS c
					ON k.CONSTRAINT_NAME = c.CONSTRAINT_NAME
					WHERE CONSTRAINT_TYPE = 'FOREIGN KEY'
					AND c.TABLE_SCHEMA = '".$db."' AND c.TABLE_NAME = '".$table."'
					AND k.COLUMN_NAME = '".$name."'";
				$result3 = $connection->query($q);

				while ($row =$result3->fetch_assoc()) {
					$xml .= '<relation table="'.$row["table"].'" row="'.$row["column"].'" />';
				}

				$xml .= '</row>';
			}

			// keys 
			$q = "SELECT * FROM STATISTICS WHERE TABLE_NAME = '".$table."' AND TABLE_SCHEMA = '".$db."' ORDER BY SEQ_IN_INDEX ASC";
			$result2 = $connection->query($q);
			$idx = array();

			while ($row = $result2->fetch_assoc()) {
				$name = $row["INDEX_NAME"];
				if (array_key_exists($name, $idx)) {
					$obj = $idx[$name];
				} else {
					$type = $row["INDEX_TYPE"];
					$t = "INDEX";
					if ($type == "FULLTEXT") { $t = $type; }
					if ($row["NON_UNIQUE"] == "0") { $t = "UNIQUE"; }
					if ($name == "PRIMARY") { $t = "PRIMARY"; }

					$obj = array(
						"columns" => array(),
						"type" => $t
					);
				}

				$obj["columns"][] = $row["COLUMN_NAME"];
				$idx[$name] = $obj;
			}

			foreach ($idx as $name=>$obj) {
				$xml .= '<key name="'.$name.'" type="'.$obj["type"].'">';
				for ($i=0;$i<count($obj["columns"]);$i++) {
					$col = $obj["columns"][$i];
					$xml .= '<part>'.$col.'</part>';
				}
				$xml .= '</key>';
			}
			$xml .= "</table>";
			
		}
		$arr[] = $xml;
		$arr[] = '</sql>';
		return implode("\n",$arr);
	}

	$a = (isset($_GET["action"]) ? $_GET["action"] : false);
	switch ($a) {
		case "list":
			setup_saveloadlist();
			if (!connect()) {
				header("HTTP/1.0 503 Service Unavailable");
				break;
			}
			//var_dump($connection);
			$result = $connection->query("SELECT keyword FROM ".TABLE." ORDER BY dt DESC");
			while ($row = $result->fetch_assoc()) {
				echo $row["keyword"]."\n";
			}
		break;
		case "save":
			setup_saveloadlist();
			if (!connect()) {
				header("HTTP/1.0 503 Service Unavailable");
				break;
			}
			$keyword = (isset($_GET["keyword"]) ? $_GET["keyword"] : "");
			//$keyword = mysql_real_escape_string($keyword);
			$data = file_get_contents("php://input");
			if (get_magic_quotes_gpc() || get_magic_quotes_runtime()) {
			   $data = stripslashes($data);
			}
			$data = $connection->real_escape_string($data);
			//var_dump($data);
			//die();
			$r = $connection->query("SELECT * FROM ".TABLE." WHERE keyword = '".$keyword."'");
			if (count($r->fetch_assoc()) > 0) {
				$res = $connection->query("UPDATE ".TABLE." SET data = '".$data."' WHERE keyword = '".$keyword."'");
			} else {
				$res = $connection->query("INSERT INTO ".TABLE." (keyword, data) VALUES ('".$keyword."', '".$data."')");
			}
			if (!$res) {
				header("HTTP/1.0 500 Internal Server Error");
			} else {
				header("HTTP/1.0 201 Created");
			}
		break;
		case "load":
			setup_saveloadlist();
			if (!connect()) {
				header("HTTP/1.0 503 Service Unavailable");
				break;
			}
			$keyword = (isset($_GET["keyword"]) ? $_GET["keyword"] : "");
			//$keyword = mysql_real_escape_string($keyword);
			$result = $connection->query("SELECT `data` FROM ".TABLE." WHERE keyword = '".$keyword."'");
			$row = $result->fetch_assoc();
			if (!(count($row)>0)) {
				header("HTTP/1.0 404 Not Found");
			} else {
				header("Content-type: text/xml");
				echo $row["data"];
			}
		break;
		case "import":
			setup_import();
			if (!connect()) {
				header("HTTP/1.0 503 Service Unavailable");
				break;
			}

			header("Content-type: text/xml");
			echo import();
		break;
		default: header("HTTP/1.0 501 Not Implemented");
	}


	/*
		list: 501/200
		load: 501/200/404
		save: 501/201
		import: 501/200
	*/
?>
