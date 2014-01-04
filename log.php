<?php
file_put_contents('/log.txt',
microtime().' '.$_SERVER['REMOTE_ADDR'].' '.$_SERVER['REQUEST_URI']."\r\n",
FILE_APPEND|LOCK_EX);
?>