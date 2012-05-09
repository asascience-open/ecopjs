<?php
  date_default_timezone_set('UTC');

  $xml = @simplexml_load_file(substr($_SERVER["REQUEST_URI"],strpos($_SERVER["REQUEST_URI"],'?')+1));

  echo json_encode(array(
     'startTime' => strtotime(sprintf("%s",$xml->{'StartTime'}))
    ,'endTime'   => strtotime(sprintf("%s",$xml->{'EndTime'}))
  ));
?>
