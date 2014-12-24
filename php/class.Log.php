<?php

class Log{
	$LOG_DIR='/logs/';
	public function __construct(){//Automatically does a pageview log.
		$IP=???;//--todo-- uh
		$PG=???;
		file_put_contents($LOG_DIR.'pageview.log',"Request from $IP at ".time()." for page $PG\n",FILE_APPEND);
	}
	public function __destruct(){
	}
	public function error($source,$text){
		//--todo-- how to format time?
		file_put_contents($LOG_DIR.'error.log',"Error in [[$source]] at ".time().":\n$text\n\n\n",FILE_APPEND);
		//--todo-- is this right
	}
};
$Log=new Log;
?>
