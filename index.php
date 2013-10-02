<?php
  ob_start();
  session_start();

  $version = 0.11;

  date_default_timezone_set('UTC');

  $config = getenv('config') ? getenv('config') : 'DEFAULT';
  require_once("config/$config/conf.php");
  require_once('auth.php');

  if (!checkLoggedin($getUserInfo)) {
    header('Location: login.php');
    return;
  }
?>

<html>
  <head>
    <title><?php echo $title?> Explorer</title>
    <link rel="stylesheet" type="text/css" href="./js/ext-3.3.0/resources/css/ext-all.css"/>
    <link rel="stylesheet" type="text/css" href="style.css?<?php echo $version?>"/>
    <!--[if IE]>
      <link rel="stylesheet" type="text/css" href="style.ie.css?<?php echo $version?>" />
    <![endif]-->

    <script>
      var config         = '<?php echo $config?>';
      var globalTitle    = '<?php echo $title?>';
      var wms            = '<?php echo $wms?>';
      var bannerImg      = '<?php echo $_COOKIE['bannerImg']?>';
      var bannerURL      = '<?php echo $_COOKIE['bannerURL']?>';
      var bannerTitle    = '<?php echo str_replace("\n",'',str_replace("'","\\'",$_COOKIE['bannerTitle']))?>';
      var expirationDate = new Date(<?php echo $_COOKIE['expirationDate']?> * 1000);
      var userName       = '<?php echo $_COOKIE['userName']?>';
      var utcOffset      = <?php echo json_encode((int)$_COOKIE['utcOffset'] * 1000)?>;
      var timezone       = <?php echo json_encode($_COOKIE['timezone'])?>;
    </script>

    <script type="text/javascript">
      var gaJsHost = (("https:" == document.location.protocol) ? "https://ssl." : "http://www.");
      document.write(unescape("%3Cscript src='" + gaJsHost + "google-analytics.com/ga.js' type='text/javascript'%3E%3C/script%3E"));
    </script>
    <script type="text/javascript">
      try{
        var pageTracker = _gat._getTracker("UA-25332621-1");
        pageTracker._trackPageview();
      } catch(err) {}
    </script>

<script type="text/javascript">

  var _gaq = _gaq || [];
  _gaq.push(['_setAccount', 'UA-31568449-1']);
  _gaq.push(['_trackPageview']);

  (function() {
    var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
    ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
    var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
  })();

</script>
  </head>
  <body onload="Ext.onReady(function(){init()})">
    <div id="loading-mask"></div>
    <div id="loading">
      <span id="loading-message">Loading core API. Please wait...</span>
    </div>
    <script type="text/javascript" src="http://maps.google.com/maps/api/js?sensor=false"></script>
    <script type="text/javascript" src="./js/ext-3.3.0/adapter/ext/ext-base.js"></script>
    <script type="text/javascript" src="./js/ext-3.3.0/ext-all.js"></script>
    <script type="text/javascript" src="./js/ext-3.3.0/Spotlight.js"></script>
    <script type="text/javascript" src="./js/OpenLayers-2.12/OpenLayers-closure.js"></script>
    <script type="text/javascript" src="./js/jquery/jquery.js"></script>
    <script type="text/javascript" src="./js/jquery/jquery.flot.js"></script>
    <script type="text/javascript" src="./js/jquery/jquery.flot.crosshair.js"></script>
    <script type="text/javascript" src="./js/jquery/jquery.flot.navigate.js"></script>
    <script type="text/javascript" src="./js/jquery/excanvas.js"></script>
    <script type="text/javascript" src="./js/overlib.js"></script>
    <script type="text/javascript" src="./js/dateFormat.js"></script>
    <script type="text/javascript" src="misc.js?<?php echo $version?>"></script>
    <script type="text/javascript" src="map.js.php?<?php echo $version?>"></script>
    <div id="overDiv" class="overStyle" style="position:absolute;visibility:hidden;z-index:1000000;"></div>
  </body>
</html>
