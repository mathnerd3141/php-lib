<?php
if(!defined('ROOT_PATH')){header('HTTP/1.0 404 Not Found');die();}

/*class.DB.php

Written by Clive Chan in PHP, 2013-2014
Licensed under a Creative Commons Attribution-ShareAlike 4.0 International License
http://creativecommons.org/licenses/by-sa/4.0/

Version: 2.0

Defines the DB class, through which consistently secure, short database queries can be made.
No more 
	$con=new MySQLi($DB_SERVER,$DB_USERNAME,$DB_PASSWORD,$DB_DATABASE);
	if(!$con||$con->connect_error)error('Failed connection');
	$a=$con->query("SELECT id FROM users WHERE name=='".
		$con->real_escape_string(str_replace("'","",htmlentities(stripslashes($name)))).
		"' LIMIT 1")
	$id_array=$a->fetch_assoc();
	$id=$id_array['id'];
	echo $id;
Now just use
	$db=new DB($DB_SERVER,$DB_USERNAME,$DB_PASSWORD,$DB_DATABASE);
	if(!$db->error)echo $id = $db->query_assoc('SELECT id FROM users WHERE name=={0} LIMIT 1',[$name],'id');

Potential improvements:
	Can interpret these:
		DELETE... (LIMIT 1)-> UPDATE... SET Deleted=1 (LIMIT 1)
		UNDELETE... (LIMIT 1)-> UPDATE... SET Deleted=0 (LIMIT 1)
	For each pageview a PageView-ID?
	Encryption key built in so it's secure before even sending to (POSSIBLY SEPARATE, thru NON-encrypted path) SQL server.
	PDO thing??
	HTMLENTITIES stupidly encodes quotes, which therefore pass and cancel out somehow.
	htmlentities($processed,ENT_QUOTES|ENT_HTML401,'ISO-8859-1')//Trying to make HTMLENTITIES work.
*/

/*
Dependencies:
	PHP MySQLi (introduced in PHP 5)
	class.Log.php (my own logging class)
*/
require_once('class.Log.php');

/*class DB

USAGE:
Initialization
	$db=new DB("myServer","MooCow123","passw0rd","myDatabase");
		//Constructs database object connecting to those parameters.
Queries
	$db->query('SELECT col1, col2 FROM table1 WHERE id={0} AND name={1}',[$_POST['id'],$name]);
		//Constructs the query from the first template string, and substituting each {n}
			//with the corresponding (zero-indexed) index from the second parameter (an array).
		//Everything is escaped carefully, so feel free to pass in $_POST variables directly.
		//Returns the mysqli_result object from the query.
	$db->query_assoc(...);
		//Same as $db->query, but gives back the row found as associative array.
Insert-ID
	$db->insert_id
		//Gives you the ID (from the PRIMARY KEY column) of the INSERTed row from mysqli->insert_id
		//Note: gives the ID of the LAST inserted row when inserting multiple rows
Number of selected rows
	$db->num_rows
		//Gives the number of rows SELECTed.
Unescaping
	$db->unescape($str)
		//Since we escape all of your parameters as they go into SQL, you can unescape it here if necessary for postprocessing,
		//but if you're outputting it, it's all escaped as HTML entities anyway so it'll display fine.
Closing
	unset($db);
Debugging
	$db->error
		//Contains the description of the error, if any, during the last-executed command.
*/

class DB{
	/*mysqli $con
		Connection to database made upon DB() construction.*/
	private $con = NULL;
	
	/*int $insert_id
		The (integer) inserted id for INSERT statements in AUTO_INCREMENT tables. Don't use for non-INSERT.*/
	public $insert_id = NULL;
	
	/*int $num_rows
		The (integer) number of rows found in a SELECT operation. Don't use for non-SELECT.*/
	public $num_rows = NULL;
	
	/*string $error
		Description of error.*/
	public $error = NULL;
	
	/*void __construct(string $DB_SERVER, string $DB_USERNAME, string $DB_PASSWORD, string $DB_DATABASE)
		Constructs an instance of DB using the credentials provided, which are saved until destruction.
	*/
	public function __construct($DB_SERVER=NULL, $DB_USERNAME=NULL, $DB_PASSWORD=NULL, $DB_DATABASE=NULL){
		$this->error=NULL;
		
		if(!is_string($DB_SERVER)||!is_string($DB_USERNAME)||!is_string($DB_PASSWORD)||!is_string($DB_DATABASE)){
			$this->err('Bad connection parameters');
			return;
		}
		$this->con=new MySQLi($DB_SERVER,$DB_USERNAME,$DB_PASSWORD,$DB_DATABASE);
		if(!$this->con||$this->con->connect_error){
			$this->err('Failed connection');
			unset($this->con);
			return;
		}
		
		//SHOW GRANTS to check that it's only SELECT, INSERT, UPDATE.
		//view COLUMNS to check that it's using the Deleted flag
		//Check that wildcards are disabled?
	}
	
	/*void __destruct()
		Run-of-the-mill class destructor. Call directly, use unset(), or just let PHP garbage-collect upon program end.
	*/
	public function __destruct(){
		if(isSet($this->con)){
			$this->con->kill($this->con->thread_id);
			$this->con->close();
		}
	}
	
	/*mysqli_result query(string $template, array(string) $replaceArr)
		Queries the database, sanitizing the items in $replaceArr[i] and substituting with $template's {i}
	*/
	public function query($template, $replaceArr=array()){
		$this->error=NULL;
		
		//Param-checking, to make sure people aren't doing things in the v1.0 style.
		if(!is_string($template)){$this->err('Template not a string');return false;}
		if(!is_array($replaceArr)){$this->err('Replacements not an array');return false;}
		if(func_num_args()>2){$this->err('Too many parameters!');return false;}
		
		foreach($replaceArr as $ind=>$replace)//Go replace stuff
			if(!is_int($ind)){$this->err('Replacement arr index not int.');return false;}
			else{
				$escaped=$this->escape($replace);
				if(is_null($escaped)){
					$this->err('Unsanitary replacement.');
					return false;
				}
				$template=str_replace('{'.intval($ind).'}',$escaped,$template);
			}
		
		//Since { } don't mean anything in SQL, checks that none of them are left outside of quotes.
			//if there are there was a replacement error.
		$quotesremoved=preg_replace('/[\"][^\"]*[\"]/','',$template);
		//(preg_match returns 1 or 0)
		if(preg_match('/[\"\'\`]/',$quotesremoved)){$this->err('wtf quotes??');return false;}//--todo-- what if quotes in original template?
		if(preg_match('/\{\}/',$quotesremoved)){$this->err('Missing replace parameters: '.$template);return false;}
		
		//Check that its main command is indeed SELECT, INSERT, or UPDATE.
		$q=trim($template);
		if(strpos($q,' ')===false || !in_array(strtolower(substr($q,0,strpos($q,' '))),array('select','insert','update'),true)){
			$this->err('Select, insert, and update only.');
			return false;
		}
		
		//Resets public vars to default
		$this->insert_id=$this->num_rows=NULL;
		
		//Send it to SQL.
		if(!$this->con)return false;
		if(($qresult=$this->con->query($template))===false){
			$this->err('Query failed: '.$this->con->error);
			return false;
		}
		else{
			$this->insert_id=intval($this->con->insert_id);
			if($qresult!==true)$this->num_rows=intval($qresult->num_rows);
		}
		
		return $qresult;
	}
	
	/*array($colname1=>$val1,...) query_assoc(string $template,array(string) $replaceArr)
		Same as query(), but returns the associative array representing the first row instead. Returns TRUE if not data-query.
	*/
	public function query_assoc($template,$replaceArr=array(),$col_to_fetch=NULL){
		$this->error=NULL;
		
		if(func_num_args()>3||!(is_string($col_to_fetch)||is_null($col_to_fetch))){$this->err('Too many parameters!');return false;}
		$qresult=$this->query($template,$replaceArr);
		
		if($this->num_rows != 1){
			$this->err('Query_assoc on more than one row, or no rows found');return false;//You're doing something wrong, perhaps missing a LIMIT 1
		}
		
		if($qresult==true){//Non-data queries return TRUE.
			if(!is_null($col_to_fetch))$this->err('There are no columns to fetch; not a data query.');
			return true;
		}
		else{
			if(!is_null($col_to_fetch)){
				$a=$qresult->fetch_assoc();
				if(!array_key_exists($col_to_fetch,$a)){$this->err("Column to fetch does not exist.");return $a;}
				else return $a[$col_to_fetch];
			}
			else return $qresult->fetch_assoc();
		}
	}
	
	/*string escape(mixed $in)
		Escapes a replacement-variable of a query.
	*/
	private function escape($in){
		//NULL: Nothing to sanitize, but needs to be explicitly 'NULL' so no empty spot.
		if($in===NULL)return 'NULL';
		
		//Empty string.
		if($in==='')return '""';
		
		//BOOL: Explicit casting to string.
		if($in===true)return 'TRUE';
		if($in===false)return 'FALSE';
		
		//INT/FLOAT: More explicit typecasting
		if(is_int($in))return strval($in);
		if(is_float($in))return strval($in);
		
		//STRING: Below means it's a string of some sort, so it'll be thoroughly escaped:
			//str_replace'd, HTMLENTITIES'd, mysqli_real_escape_string'd, and put in double quotes.
		if(!is_string($in)){
			$this->err('Invalid replacement-param type');
			return NULL;
		}
		
		$this->encode_unicode_chars($in);
		$this->encode_ascii_stuff($in);
		
		//And then real_escape_string it, which properly done should not change it.
		$escaped=$this->con->real_escape_string($in);
			
		return '"'.$escaped.'"';//--todo-- what if I INSERT INTO tbl ("INT_COL") VALUES ("1234") ?
	}
	
	/*mixed unescape(string $str)
		Unescapes stuff for you. We can't auto-unescape SELECT results, but you can use this yourself.
	*/
	public function unescape($str){
		$this->error=NULL;
		
		//NULL: Nothing to sanitize, but needs to be explicitly 'NULL' so no empty spot.
		if($str===NULL)return NULL;
		
		//Empty string.
		if($str==='')return '';
		
		//BOOL: Explicit casting to string.
		if($str===true)return true;
		elseif($str===false)return false;
		
		//FLOAT/INT: More explicit typecasting
		if(ctype_digit($in))return intval($in);
		if(is_numeric($in))return floatval($in);
		
		//STRING: Below means it's a string of some sort, so it'll be thoroughly escaped:
			//str_replace'd, HTMLENTITIES'd, mysqli_real_escape_string'd, and put in double quotes.
		
		$newstr=$this->decode_html_entities($str);
		
		//And then real_escape_string it, which properly done should not change it. (--todo-- check this)
		$unescaped=$this->con->real_unescape_string($newstr);
	}
	
	//......-_- is there a better encoding system than html entities?
	private function encode_unicode_chars($string){
		return preg_replace_callback('/[\x{80}-\x{10FFFF}]/u', function ($m) {
			$char = current($m);
			$utf = iconv('UTF-8', 'UCS-4', $char);
			return sprintf("&#x%s;", ltrim(strtoupper(bin2hex($utf)), "0"));
		}, $string);
	}
	private function encode_ascii_stuff($string){//nonprintables and quotes "'` and ampersands
		
	}
	private function decode_html_entities($string){
		
	}
	
	
	/*void err(string $str)
		Triggers a custom error specifically for class DB.
	*/
	private function err($str){
		$errtext='DB error: '.htmlentities($str);
		
		//$this->error=$errtext;//Helpful insecure.
		$this->error='Error. Check logs.';//Unhelpful secure.
		
		global $Log;
		$Log->error('Database',$errtext);
	}
};
?>
