<?php
  // don't change the next line
  $imgSrc = "config/$config/img";

  // customize any values below
  $title       = 'CoastMap';

  //Production
  $getUserInfo = 'http://coastmap.com/ecop/wms.aspx?request=GetUserInfo&version=1.1.1&';  // including trailing ? or &
  $wms         = 'http://coastmap.com/ecop/wms.aspx?';                                    // including trailing ? or &

  //Staging
  //$getUserInfo = 'h1ttp://192.168.150.5/ecop/wms.aspx?request=GetUserInfo&version=1.1.1&';  // including trailing ? or &
  //$wms         = 'h1ttp://192.168.150.5/ecop/wms.aspx?';                                    // including trailing ? or &

  //Localhost
  //$getUserInfo = 'h1ttp://localhost:50217/wms.aspx?request=GetUserInfo&version=1.1.1&';  // including trailing ? or &
  //$wms         = 'h1ttp://localhost:50217/wms.aspx?';                                    // including trailing ? or &
?>
