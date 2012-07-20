<?php
  date_default_timezone_set('UTC');

  header('Content-type: application/json');
  $bm = array();
  $xml = @simplexml_load_file(substr($_SERVER["REQUEST_URI"],strpos($_SERVER["REQUEST_URI"],'?')+1));
  // $xml = @simplexml_load_file('bm.xml');
  foreach ($xml->{'Bookmark'} as $b) {
    array_push($bm,array(
       'name'       => sprintf("%s",$b->{'bookmarkName'})
      ,'layers'     => sprintf("%s",$b->{'layers'})
      ,'basemap'    => sprintf("%s",$b->{'basemap'})
      ,'extent'     => sprintf("%s",$b->{'extent'})
      ,'addedDate'  => strtotime(sprintf("%s",$b->{'addedDate'}))
      ,'styles'     => sprintf("%s",$b->{'styles'})
      ,'elevations' => sprintf("%s",$b->{'elevations'})
    ));
  }

  $names = array();
  foreach ($bm as $k => $v) {
    $names[$k] = strtolower($v['name']);
  }
  array_multisort($names,SORT_ASC,$bm);

  $addedDate = array();
  foreach ($bm as $k => $v) {
    $addedDate[$k] = $v['addedDate'];
  }
  $bmByDate = $bm;
  array_multisort($addedDate,SORT_DESC,$bmByDate);

  echo json_encode(array(
     'all'  => $bm
    ,'top5' => array_slice($bmByDate,0,5)
  ));
?>
