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
  $buoys = array();
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
    if (preg_match('/^BUOY_/',$a['name'])) {
      // buoy names come in as BUOY_buoyName_sensorName
      $p = explode('_',$a['name']);
      $sensor = array_pop($p);
      $name   = implode('_',$p);
      if (!array_key_exists($name,$buoys)) {
        $buoys[$name] = array(
           'title'    => $a['abstract'].'||buoys'
          ,'name'     => $name
          ,'abstract' => 'No information currently available.' // $a['abstract']
          ,'bbox'     => $a['bbox']
          ,'status'   => in_array($name,$defaultLayers) || in_array($a['name'],$defaultLayers) ? 'on' : 'off'
          ,'type'     => 'buoys'
          ,'sensors'  => array()
        );
      }
      array_push($buoys[$name]['sensors'],array(
         'title' => $a['title']
        ,'name'  => $a['name']
      ));
    }
    else if (preg_match('/_CURRENTS$/',$a['name'])) {
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

  foreach (array_keys($buoys) as $b) {
    usort($buoys[$b]['sensors'],'customSort');
    array_unshift($layerStack,$buoys[$b]);
    array_push($layers['buoys'],$buoys[$b]);
  }

  foreach (array_keys($layers) as $l) {
    usort($layers[$l],'customSort');
  }

  function customSort($a,$b) {
    return $a['title'] > $b['title'];
  }
?>
