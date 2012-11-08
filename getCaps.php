<?php
  $layers = array(
     'currents'    => array()
    ,'winds'       => array()
    ,'waves'       => array()
    ,'temperature' => array()
    ,'other'       => array()
    ,'buoys'       => array()
  );
  $layerStack = array();
  $xml = @simplexml_load_file(
     $wms
    .'service=WMS&key='.$_COOKIE['softwareKey']
    .'&version=1.1.1&request=getcapabilities'
    .'&time='.time()
  );
  $defaultLayers = explode(',',$_COOKIE['defaultLayers']);
  foreach ($xml->{'Capability'}[0]->{'Layer'}[0]->{'Layer'} as $l) {
    $a = array(
       'title'    => sprintf("%s",$l->{'Title'})
      ,'name'     => sprintf("%s",$l->{'Name'})
      ,'abstract' => sprintf("%s",$l->{'Abstract'})
      ,'bbox'     => array(
         sprintf("%f",$l->{'LatLonBoundingBox'}->attributes()->{'minx'})
        ,sprintf("%f",$l->{'LatLonBoundingBox'}->attributes()->{'miny'})
        ,sprintf("%f",$l->{'LatLonBoundingBox'}->attributes()->{'maxx'})
        ,sprintf("%f",$l->{'LatLonBoundingBox'}->attributes()->{'maxy'})
      )
      ,'maxDepth' => sprintf("%f",$l->{'DepthLayers'})
      ,'status'   => in_array(sprintf("%s",$l->{'Name'}),$defaultLayers) ? 'on' : 'off'
    );
    if (preg_match('/_CURRENTS$/',$a['name'])) {
      $a['type']  = 'currents';
      $a['title'] .= '||'.$a['type'];
      array_push($layerStack,$a);
      array_push($layers['currents'],$a);
    }
    else if (preg_match('/_WINDS$/',$a['name'])) {
      $a['type']  = 'winds';
      $a['title'] .= '||'.$a['type'];
      array_push($layerStack,$a);
      array_push($layers['winds'],$a);
    }
    else if (preg_match('/_WAVE_/',$a['name'])) {
      $a['type']  = 'waves';
      $a['title'] .= '||'.$a['type'];
      if (preg_match('/DIRECTION$/',$a['name'])) {
        array_push($layerStack,$a);
      }
      else {
        array_unshift($layerStack,$a);
      }
      array_push($layers['waves'],$a);
    }
    else {
      $a['type']  = 'other';
      $a['title'] .= '||'.$a['type'];
      array_unshift($layerStack,$a);
      array_push($layers['other'],$a);
    }
  }

  if ($_COOKIE['softwareKey'] == 999) {
    $buoys = array();
    $xml = @simplexml_load_file('http://coastmap.com/ecop/wms.aspx?Request=GetCapabilities&SERVICE=WMS&key=apasametocean');
    $defaultLayers = explode(',',$_COOKIE['defaultLayers']);
    foreach ($xml->{'Capability'}[0]->{'Layer'}[0]->{'Layer'} as $l) {
      $a = array(
         'title'    => sprintf("%s",$l->{'Title'})
        ,'name'     => sprintf("%s",$l->{'Name'})
        ,'abstract' => sprintf("%s",$l->{'Abstract'})
        ,'bbox'     => array(
           sprintf("%f",$l->{'LatLonBoundingBox'}->attributes()->{'minx'})
          ,sprintf("%f",$l->{'LatLonBoundingBox'}->attributes()->{'miny'})
          ,sprintf("%f",$l->{'LatLonBoundingBox'}->attributes()->{'maxx'})
          ,sprintf("%f",$l->{'LatLonBoundingBox'}->attributes()->{'maxy'})
        )
        ,'maxDepth' => sprintf("%f",$l->{'DepthLayers'})
        ,'status'   => in_array(sprintf("%s",$l->{'Name'}),$defaultLayers) ? 'on' : 'off'
      );
  
      // buoy names come in as buoyName_sensorName
      $p = explode('_',$a['name']);
      if (!array_key_exists($p[0],$buoys)) {
        $buoys[$p[0]] = array(
           'title'    => $p[0].'||buoys' // $a['abstract'].'||buoys'
          ,'name'     => $p[0]
          ,'abstract' => 'No information currently available.' // $a['abstract']
          ,'bbox'     => $a['bbox']
          ,'status'   => in_array($p[0],$defaultLayers) || in_array($a['name'],$defaultLayers) ? 'on' : 'off'
          ,'type'     => 'buoys'
          ,'sensors'  => array()
        );
      }
      array_push($buoys[$p[0]]['sensors'],$p[1]);
    }

    foreach (array_keys($buoys) as $b) {
      sort($buoys[$b]['sensors']);
      array_unshift($layerStack,$buoys[$b]);
      array_push($layers['buoys'],$buoys[$b]);
    }
  }

  foreach (array_keys($layers) as $l) {
    usort($layers[$l],'customSort');
  }

  function customSort($a,$b) {
    return $a['title'] > $b['title'];
  }
?>
