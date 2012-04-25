<?php
  session_start();

  $config = getenv('config');
  require_once("config/$config/conf.php");
  require_once("config/$config/getCaps.php");

  echo 'var layerConfig = '.json_encode(array(
     'availableLayers' => $layers
    ,'layerStack'      => $layerStack
  )).";\n";
?>

var defaultBasemap = 'ESRI Ocean';

var mainStore = new Ext.data.ArrayStore({
  fields : [
     'type'
    ,'name'
    ,'displayName'
    ,'info'
    ,'status'
    ,'settings'
    ,'infoBlurb'
    ,'settingsParam'
    ,'settingsOpacity'
    ,'settingsImageQuality'
    ,'settingsImageType'
    ,'settingsPalette'
    ,'settingsBaseStyle'
    ,'settingsColorMap'
    ,'settingsStriding'
    ,'settingsBarbLabel'
    ,'settingsTailMag'
    ,'settingsMin'
    ,'settingsMax'
    ,'settingsMinMaxBounds'
    ,'rank'
    ,'legend'
    ,'timestamp'
    ,'bbox'
    ,'queryable'
    ,'settingsLayers'
    ,'category'
  ]
});

var defaultLayers = {};
var defaultStyles = {};
var guaranteeDefaultStyles = {};
var legendsStore = new Ext.data.ArrayStore({
  fields : [
     'name'
    ,'displayName'
    ,'status'
    ,'rank'
    ,'fetchTime'
    ,'type'
  ]
  ,listeners : {update : function() {
    this.sort('rank','ASC');
  }}
});

var currentsStore     = new Ext.data.ArrayStore({fields : []});
var windsStore        = new Ext.data.ArrayStore({fields : []});
var wavesStore        = new Ext.data.ArrayStore({fields : []});
var temperaturesStore = new Ext.data.ArrayStore({fields : []});
var otherStore        = new Ext.data.ArrayStore({fields : []});

var baseStylesStore = new Ext.data.ArrayStore({
  fields : [
    'name'
   ,'value'
   ,'type'
  ]
  ,data : [
     ['Ramp','CURRENTS_RAMP','CURRENTS']
    ,['Black','CURRENTS_STATIC_BLACK','CURRENTS']
    ,['Green','WINDS_VERY_SPARSE_GREEN','WINDS']
    ,['Purple','WINDS_VERY_SPARSE_PURPLE','WINDS']
    ,['Yellow','WINDS_VERY_SPARSE_YELLOW','WINDS']
    ,['Orange','WINDS_VERY_SPARSE_ORANGE','WINDS']
    ,['Gradient','WINDS_VERY_SPARSE_GRADIENT','WINDS']
  ]
});

var colorMapStore = new Ext.data.ArrayStore({
  fields : [
    'name'
  ]
  ,data : [
     ['Jet']
    ,['NoGradient']
    ,['Gray']
    ,['Blue']
    ,['Cool']
    ,['Hot']
    ,['Summer']
    ,['Winter']
    ,['Spring']
    ,['Autumn']
  ]
});

var stridingStore = new Ext.data.ArrayStore({
  fields : [
    'index','param'
  ]
  ,data : [
     [0,0.25]
    ,[1,0.33]
    ,[2,0.50]
    ,[3,1.00]
    ,[4,2.00]
    ,[5,3.00]
    ,[6,4.00]
  ]
});

var barbLabelStore = new Ext.data.ArrayStore({
  fields : [
    'name'
  ]
  ,data : [
     ['True']
    ,['False']
  ]
});

var tailMagStore = new Ext.data.ArrayStore({
  fields : [
    'name'
  ]
  ,data : [
     ['True']
    ,['False']
  ]
});

var imageQualityStore = new Ext.data.ArrayStore({
  fields : [
    'name'
   ,'value'
  ]
  ,data : [
     ['low','Low']
    ,['high','High']
  ]
});

var dNow = new Date();
dNow.setUTCMinutes(0);
dNow.setUTCSeconds(0);
dNow.setUTCMilliseconds(0);
var dNow12Hours = new Date(dNow.getTime());
dNow12Hours.setUTCHours(12);
if (dNow.getHours() >= 12) {
  dNow.setUTCHours(12);
}
else {
  dNow.setUTCHours(0);
}

var lastMapClick = {
   layer : ''
  ,xy    : ''
};

var chartData;
var chartUrls = {};
var chartLayerStore =  new Ext.data.ArrayStore({
   id        : 0
  ,fields    : ['rank','name','displayName']
  ,listeners : {
    add     : function(store,recs,idx) {
      Ext.getCmp('chartLayerCombo').setValue(recs[0].get('name'));
    }
    ,remove : function(store) {
      if (store.getCount() > 0) {
        Ext.getCmp('chartLayerCombo').setValue(store.getAt(0).get('name'));
      }
    }
  }
});

var layersToSyncBbox    = {};
var needToInitGridPanel = {};

var cp;
var map;

var legendImages = {};

var proj3857   = new OpenLayers.Projection("EPSG:3857");
var proj900913 = new OpenLayers.Projection("EPSG:900913");
var proj4326   = new OpenLayers.Projection("EPSG:4326");

var lineColors = [
   ['#99BBE8','#1558BB']
  ,['#e8bb99','#b56529']
  ,['#99e9ae','#1d8538']
];

var activeSettingsWindows = {};
var activeInfoWindows     = {};

function init() {
  var loadingMask = Ext.get('loading-mask');
  var loading = Ext.get('loading');

  //Hide loading message
  loading.fadeOut({duration : 0.2,remove : true});

  //Hide loading mask
  loadingMask.setOpacity(0.9);
  loadingMask.shift({
     xy       : loading.getXY()
    ,width    : loading.getWidth()
    ,height   : loading.getHeight()
    ,remove   : true
    ,duration : 1
    ,opacity  : 0.1
    ,easing   : 'bounceOut'
  });

  cp = new Ext.state.CookieProvider({
    expires : new Date(new Date().getTime()+(1000*60*60*24*30)) //30 days
  });
  Ext.state.Manager.setProvider(cp);

  Ext.QuickTips.init();

  // don't remember window settings
  Ext.override(Ext.Component,{
    stateful : false
  });

  initMainStore();
  initComponents();
}

function initMainStore() {
  mainStore.removeAll();
  for (var layerType in layerConfig.availableLayers) {
    for (var i = 0; i < layerConfig.availableLayers[layerType].length; i++) {
      if (layerType == 'currents') {
        if (typeof defaultStyles[layerConfig.availableLayers[layerType][i].title] != 'string') {
          defaultStyles[layerConfig.availableLayers[layerType][i].title]          = 'CURRENTS_RAMP-Jet-False-1-True-0-2-Low';
          guaranteeDefaultStyles[layerConfig.availableLayers[layerType][i].title] = 'CURRENTS_RAMP-Jet-False-1-True-0-2-Low';
        }
        mainStore.add(new mainStore.recordType({
           'type'                 : 'currents'
          ,'name'                 : layerConfig.availableLayers[layerType][i].title
          ,'displayName'          : layerConfig.availableLayers[layerType][i].title
          ,'info'                 : 'off'
          ,'status'               : layerConfig.availableLayers[layerType][i].status
          ,'settings'             : 'off'
          ,'infoBlurb'            : layerConfig.availableLayers[layerType][i].abstract
          ,'settingsParam'        : 'baseStyle,colorMap,barbLabel,striding,tailMag,min,max'
          ,'settingsOpacity'      : 100
          ,'settingsImageQuality' : defaultStyles[layerConfig.availableLayers[layerType][i].title].split('-')[7]
          ,'settingsImageType'    : 'png'
          ,'settingsPalette'      : ''
          ,'settingsBaseStyle'    : defaultStyles[layerConfig.availableLayers[layerType][i].title].split('-')[0]
          ,'settingsColorMap'     : defaultStyles[layerConfig.availableLayers[layerType][i].title].split('-')[1]
          ,'settingsStriding'     : defaultStyles[layerConfig.availableLayers[layerType][i].title].split('-')[2]
          ,'settingsBarbLabel'    : defaultStyles[layerConfig.availableLayers[layerType][i].title].split('-')[3]
          ,'settingsTailMag'      : defaultStyles[layerConfig.availableLayers[layerType][i].title].split('-')[4]
          ,'settingsMin'          : defaultStyles[layerConfig.availableLayers[layerType][i].title].split('-')[5]
          ,'settingsMax'          : defaultStyles[layerConfig.availableLayers[layerType][i].title].split('-')[6]
          ,'settingsMinMaxBounds' : '0-6'
          ,'rank'                 : ''
          ,'legend'               : wms + 'LAYER=' + layerConfig.availableLayers[layerType][i].name + '&FORMAT=image/png&TRANSPARENT=TRUE&STYLES=' + defaultStyles[layerConfig.availableLayers[layerType][i].title] + '&SERVICE=WMS&VERSION=1.1.1&REQUEST=GetLegendGraphic&TIME=&SRS=EPSG:3857&LAYERS=' + layerConfig.availableLayers[layerType][i].name
          ,'timestamp'            : ''
          ,'bbox'                 : layerConfig.availableLayers[layerType][i].bbox
          ,'queryable'            : 'true'
          ,'settingsLayers'       : ''
          ,'category'             : 'currentsVelocity'
        }));
      }
      else if (layerType == 'winds') {
        if (typeof defaultStyles[layerConfig.availableLayers[layerType][i].title] != 'string') {
          defaultStyles[layerConfig.availableLayers[layerType][i].title]          = 'WINDS_VERY_SPARSE_GRADIENT-False-1-0-45-Low';
          guaranteeDefaultStyles[layerConfig.availableLayers[layerType][i].title] = 'WINDS_VERY_SPARSE_GRADIENT-False-1-0-45-Low';
        }
        mainStore.add(new mainStore.recordType({
           'type'                 : 'winds'
          ,'name'                 : layerConfig.availableLayers[layerType][i].title
          ,'displayName'          : layerConfig.availableLayers[layerType][i].title
          ,'info'                 : 'off'
          ,'status'               : layerConfig.availableLayers[layerType][i].status
          ,'settings'             : 'off'
          ,'infoBlurb'            : layerConfig.availableLayers[layerType][i].abstract
          ,'settingsParam'        : 'baseStyle,barbLabel,striding,min,max'
          ,'settingsOpacity'      : 100
          ,'settingsImageQuality' : defaultStyles[layerConfig.availableLayers[layerType][i].title].split('-')[5]
          ,'settingsImageType'    : 'png'
          ,'settingsPalette'      : ''
          ,'settingsBaseStyle'    : defaultStyles[layerConfig.availableLayers[layerType][i].title].split('-')[0]
          ,'settingsColorMap'     : ''
          ,'settingsStriding'     : defaultStyles[layerConfig.availableLayers[layerType][i].title].split('-')[2]
          ,'settingsBarbLabel'    : defaultStyles[layerConfig.availableLayers[layerType][i].title].split('-')[1]
          ,'settingsTailMag'      : ''
          ,'settingsMin'          : defaultStyles[layerConfig.availableLayers[layerType][i].title].split('-')[3]
          ,'settingsMax'          : defaultStyles[layerConfig.availableLayers[layerType][i].title].split('-')[4]
          ,'settingsMinMaxBounds' : '0-70'
          ,'rank'                 : ''
          ,'legend'               : wms + 'LAYER=' + layerConfig.availableLayers[layerType][i].name + '&FORMAT=image/png&TRANSPARENT=TRUE&STYLES=' + defaultStyles[layerConfig.availableLayers[layerType][i].title] + '&SERVICE=WMS&VERSION=1.1.1&REQUEST=GetLegendGraphic&TIME=&SRS=EPSG:3857&LAYERS=' + layerConfig.availableLayers[layerType][i].name
          ,'timestamp'            : ''
          ,'bbox'                 : layerConfig.availableLayers[layerType][i].bbox
          ,'queryable'            : 'true'
          ,'settingsLayers'       : ''
          ,'category'             : 'windsVelocity'
        }));
      }
      else if (layerType == 'waves') {
        if (typeof defaultStyles[layerConfig.availableLayers[layerType][i].title] != 'string') {
          defaultStyles[layerConfig.availableLayers[layerType][i].title]          = '';
          guaranteeDefaultStyles[layerConfig.availableLayers[layerType][i].title] = '';
        }
        mainStore.add(new mainStore.recordType({
           'type'                 : 'waves'
          ,'name'                 : layerConfig.availableLayers[layerType][i].title
          ,'displayName'          : layerConfig.availableLayers[layerType][i].title
          ,'info'                 : 'off'
          ,'status'               : layerConfig.availableLayers[layerType][i].status
          ,'settings'             : 'off'
          ,'infoBlurb'            : layerConfig.availableLayers[layerType][i].abstract
          ,'settingsParam'        : ''
          ,'settingsOpacity'      : 100
          ,'settingsImageQuality' : ''
          ,'settingsImageType'    : 'png'
          ,'settingsPalette'      : ''
          ,'settingsBaseStyle'    : ''
          ,'settingsColorMap'     : ''
          ,'settingsStriding'     : ''
          ,'settingsBarbLabel'    : ''
          ,'settingsTailMag'      : ''
          ,'settingsMin'          : ''
          ,'settingsMax'          : ''
          ,'settingsMinMaxBounds' : ''
          ,'rank'                 : ''
          ,'legend'               : wms + 'LAYER=' + layerConfig.availableLayers[layerType][i].name + '&FORMAT=image/png&TRANSPARENT=TRUE&STYLES=' + defaultStyles[layerConfig.availableLayers[layerType][i].title] + '&SERVICE=WMS&VERSION=1.1.1&REQUEST=GetLegendGraphic&TIME=&SRS=EPSG:3857&LAYERS=' + layerConfig.availableLayers[layerType][i].name
          ,'timestamp'            : ''
          ,'bbox'                 : layerConfig.availableLayers[layerType][i].bbox
          ,'queryable'            : 'true'
          ,'settingsLayers'       : ''
          ,'category'             : 'wavesElevation'
        }));
      }
      else if (layerType == 'temperature') {
        if (typeof defaultStyles[layerConfig.availableLayers[layerType][i].title] != 'string') {
          defaultStyles[layerConfig.availableLayers[layerType][i].title]          = '';
          guaranteeDefaultStyles[layerConfig.availableLayers[layerType][i].title] = '';
        }
        mainStore.add(new mainStore.recordType({
           'type'                 : 'temperatures'
          ,'name'                 : layerConfig.availableLayers[layerType][i].title
          ,'displayName'          : layerConfig.availableLayers[layerType][i].title
          ,'info'                 : 'off'
          ,'status'               : layerConfig.availableLayers[layerType][i].status
          ,'settings'             : 'off'
          ,'infoBlurb'            : layerConfig.availableLayers[layerType][i].abstract
          ,'settingsParam'        : ''
          ,'settingsOpacity'      : 100
          ,'settingsImageQuality' : ''
          ,'settingsImageType'    : 'png'
          ,'settingsPalette'      : ''
          ,'settingsBaseStyle'    : ''
          ,'settingsColorMap'     : ''
          ,'settingsStriding'     : ''
          ,'settingsBarbLabel'    : ''
          ,'settingsTailMag'      : ''
          ,'settingsMin'          : ''
          ,'settingsMax'          : ''
          ,'settingsMinMaxBounds' : ''
          ,'rank'                 : ''
          ,'legend'               : wms + 'LAYER=' + layerConfig.availableLayers[layerType][i].name + '&FORMAT=image/png&TRANSPARENT=TRUE&STYLES=' + defaultStyles[layerConfig.availableLayers[layerType][i].title] + '&SERVICE=WMS&VERSION=1.1.1&REQUEST=GetLegendGraphic&TIME=&SRS=EPSG:3857&LAYERS=' + layerConfig.availableLayers[layerType][i].name
          ,'timestamp'            : ''
          ,'bbox'                 : layerConfig.availableLayers[layerType][i].bbox
          ,'queryable'            : 'true'
          ,'settingsLayers'       : ''
          ,'category'             : 'temperature'
        }));
      }
      else {
        if (typeof defaultStyles[layerConfig.availableLayers[layerType][i].title] != 'string') {
          defaultStyles[layerConfig.availableLayers[layerType][i].title]          = '';
          guaranteeDefaultStyles[layerConfig.availableLayers[layerType][i].title] = '';
        }
        mainStore.add(new mainStore.recordType({
           'type'                 : 'other'
          ,'name'                 : layerConfig.availableLayers[layerType][i].title
          ,'displayName'          : layerConfig.availableLayers[layerType][i].title
          ,'info'                 : 'off'
          ,'status'               : 'off'
          ,'settings'             : 'off'
          ,'infoBlurb'            : layerConfig.availableLayers[layerType][i].abstract
          ,'settingsParam'        : ''
          ,'settingsOpacity'      : 100
          ,'settingsImageType'    : 'png'
          ,'settingsPalette'      : ''
          ,'settingsBaseStyle'    : ''
          ,'settingsColorMap'     : ''
          ,'settingsStriding'     : ''
          ,'settingsBarbLabel'    : ''
          ,'settingsTailMag'      : ''
          ,'settingsMin'          : ''
          ,'settingsMax'          : ''
          ,'settingsMinMaxBounds' : ''
          ,'rank'                 : ''
          ,'legend'               : wms + 'LAYER=' + layerConfig.availableLayers[layerType][i].name + '&FORMAT=image/png&TRANSPARENT=TRUE&STYLES=' + defaultStyles[layerConfig.availableLayers[layerType][i].title] + '&SERVICE=WMS&VERSION=1.1.1&REQUEST=GetLegendGraphic&TIME=&SRS=EPSG:3857&LAYERS=' + layerConfig.availableLayers[layerType][i].name
          ,'timestamp'            : ''
          ,'bbox'                 : layerConfig.availableLayers[layerType][i].bbox
          ,'queryable'            : 'false'
          ,'settingsLayers'       : ''
          ,'category'             : ''
        }));
      }
    }
  }

  var i = 0;
  mainStore.each(function(rec) {
    rec.set('rank',i++);
    rec.commit();
  });

  mainStore.each(function(rec) {
    if (rec.get('type') == 'currents') {
      currentsStore.add(rec);
    }
  });

  mainStore.each(function(rec) {
    if (rec.get('type') == 'winds') {
      windsStore.add(rec);
    }
  });

  mainStore.each(function(rec) {
    if (rec.get('type') == 'waves') {
      wavesStore.add(rec);
    }
  });

  mainStore.each(function(rec) {
    if (rec.get('type') == 'temperatures') {
      temperaturesStore.add(rec);
    }
  });

  mainStore.each(function(rec) {
    if (rec.get('type') == 'other') {
      otherStore.add(rec);
    }
  });
}

function initComponents() {
  var introPanel = new Ext.Panel({
     height : 52
    ,border : false
    ,html   : '<table class="smallFont" width="100%"><tr><td><a target=_blank href="' + bannerURL + '"><img title="' + bannerTitle + '" src="' + bannerImg + '"></a></td></tr></table>'
  });

  var currentsSelModel = new Ext.grid.CheckboxSelectionModel({
     header    : ''
    ,checkOnly : true
    ,listeners : {
      rowdeselect : function(sm,idx,rec) {
        map.getLayersByName(rec.get('name'))[0].setVisibility(false);
      }
      ,rowselect : function(sm,idx,rec) {
        map.getLayersByName(rec.get('name'))[0].setVisibility(true);
      }
    }
  });
  var currentsGridPanel = new Ext.grid.GridPanel({
     id               : 'currentsGridPanel'
    ,height           : Math.min(currentsStore.getCount(),5) * 21.1 + 26 + 11 + 25
    ,title            : 'Currents'
    ,collapsible      : true
    ,store            : currentsStore
    ,border           : false
    ,selModel         : currentsSelModel
    ,columns          : [
       currentsSelModel
      ,{id : 'status'     ,dataIndex : 'status'     ,renderer : renderLayerButton   ,width : 25}
      ,{id : 'displayName',dataIndex : 'displayName',renderer : renderLayerInfoLink ,width : 167}
      ,{id : 'bbox'       ,dataIndex : 'bbox'       ,renderer : renderLayerCalloutButton    ,width : 20}
    ]
    ,hideHeaders      : true
    ,disableSelection : true
    ,listeners        : {viewready : function(grid) {
      layersToSyncBbox['currents'] = true;
      needToInitGridPanel['currents'] = true;
      syncLayersToBbox('currents');
      var sm = grid.getSelectionModel();
      currentsStore.each(function(rec) {
        if (rec.get('status') == 'on') {
          sm.selectRecords([rec],true);
        }
      });
    }}
    ,tbar             : [
      {
         text    : 'Turn all currents off'
        ,icon    : 'img/delete.png'
        ,handler : function() {
          currentsSelModel.clearSelections();
        }
      }
    ]
  });

  var windsSelModel = new Ext.grid.CheckboxSelectionModel({
     header    : ''
    ,checkOnly : true
    ,listeners : {
      rowdeselect : function(sm,idx,rec) {
        map.getLayersByName(rec.get('name'))[0].setVisibility(false);
      }
      ,rowselect : function(sm,idx,rec) {
        map.getLayersByName(rec.get('name'))[0].setVisibility(true);
      }
    }
  });
  var windsGridPanel = new Ext.grid.GridPanel({
     id               : 'windsGridPanel'
    ,height           : Math.min(windsStore.getCount(),5) * 21.1 + 26 + 11 + 25
    ,title            : 'Winds'
    ,collapsible      : true
    ,store            : windsStore
    ,border           : false
    ,selModel         : windsSelModel
    ,columns          : [
       windsSelModel
      ,{id : 'status'     ,dataIndex : 'status'     ,renderer : renderLayerButton   ,width : 25}
      ,{id : 'displayName',dataIndex : 'displayName',renderer : renderLayerInfoLink ,width : 167}
      ,{id : 'bbox'       ,dataIndex : 'bbox'       ,renderer : renderLayerCalloutButton    ,width : 20}
    ]
    ,hideHeaders      : true
    ,disableSelection : true
    ,listeners        : {viewready : function(grid) {
      layersToSyncBbox['winds'] = true;
      needToInitGridPanel['winds'] = true;
      syncLayersToBbox('winds');
      var sm = grid.getSelectionModel();
      windsStore.each(function(rec) {
        if (rec.get('status') == 'on') {
          sm.selectRecords([rec],true);
        }
      });
    }}
    ,tbar             : [
      {
         text    : 'Turn all winds off'
        ,icon    : 'img/delete.png'
        ,handler : function() {
          windsSelModel.clearSelections();
        }
      }
    ]
  });

  var wavesSelModel = new Ext.grid.CheckboxSelectionModel({
     header    : ''
    ,checkOnly : true
    ,listeners : {
      rowdeselect : function(sm,idx,rec) {
        map.getLayersByName(rec.get('name'))[0].setVisibility(false);
      }
      ,rowselect : function(sm,idx,rec) {
        map.getLayersByName(rec.get('name'))[0].setVisibility(true);
      }
    }
  });
  var wavesGridPanel = new Ext.grid.GridPanel({
     id               : 'wavesGridPanel'
    ,height           : Math.min(wavesStore.getCount(),5) * 21.1 + 26 + 11 + 25
    ,title            : 'Waves'
    ,collapsible      : true
    ,store            : wavesStore
    ,border           : false
    ,selModel         : wavesSelModel
    ,columns          : [
       wavesSelModel
      ,{id : 'status'     ,dataIndex : 'status'     ,renderer : renderLayerButton   ,width : 25}
      ,{id : 'displayName',dataIndex : 'displayName',renderer : renderLayerInfoLink ,width : 167}
      ,{id : 'bbox'       ,dataIndex : 'bbox'       ,renderer : renderLayerCalloutButton    ,width : 20}
    ]
    ,hideHeaders      : true
    ,disableSelection : true
    ,listeners        : {viewready : function(grid) {
      layersToSyncBbox['waves'] = true;
      needToInitGridPanel['waves'] = true;
      syncLayersToBbox('waves');
      var sm = grid.getSelectionModel();
      wavesStore.each(function(rec) {
        if (rec.get('status') == 'on') {
          sm.selectRecords([rec],true);
        }
      });
    }}
    ,tbar             : [
      {
         text    : 'Turn all waves off'
        ,icon    : 'img/delete.png'
        ,handler : function() {
          wavesSelModel.clearSelections();
        }
      }
    ]
  });

  var temperaturesSelModel = new Ext.grid.CheckboxSelectionModel({
     header    : ''
    ,checkOnly : true
    ,listeners : {
      rowdeselect : function(sm,idx,rec) {
        map.getLayersByName(rec.get('name'))[0].setVisibility(false);
      }
      ,rowselect : function(sm,idx,rec) {
        map.getLayersByName(rec.get('name'))[0].setVisibility(true);
      }
    }
  });
  var temperaturesGridPanel = new Ext.grid.GridPanel({
     id               : 'temperaturesGridPanel'
    ,height           : Math.min(temperaturesStore.getCount(),5) * 21.1 + 26 + 11 + 25
    ,title            : 'Water temperatures'
    ,collapsible      : true
    ,store            : temperaturesStore
    ,border           : false
    ,selModel         : temperaturesSelModel
    ,columns          : [
       temperaturesSelModel
      ,{id : 'status'     ,dataIndex : 'status'     ,renderer : renderLayerButton   ,width : 25}
      ,{id : 'displayName',dataIndex : 'displayName',renderer : renderLayerInfoLink ,width : 167}
      ,{id : 'bbox'       ,dataIndex : 'bbox'       ,renderer : renderLayerCalloutButton    ,width : 20}
    ]
    ,hideHeaders      : true
    ,disableSelection : true
    ,listeners        : {viewready : function(grid) {
      layersToSyncBbox['temperatures'] = true;
      needToInitGridPanel['temperatures'] = true;
      syncLayersToBbox('temperatures');
      var sm = grid.getSelectionModel();
      temperaturesStore.each(function(rec) {
        if (rec.get('status') == 'on') {
          sm.selectRecords([rec],true);
        }
      });
    }}
    ,tbar             : [
      {
         text    : 'Turn all temperatures off'
        ,icon    : 'img/delete.png'
        ,handler : function() {
          temperaturesSelModel.clearSelections();
        }
      }
    ]
  });

  var otherSelModel = new Ext.grid.CheckboxSelectionModel({
     header    : ''
    ,checkOnly : true
    ,listeners : {
      rowdeselect : function(sm,idx,rec) {
        map.getLayersByName(rec.get('name'))[0].setVisibility(false);
      }
      ,rowselect : function(sm,idx,rec) {
        map.getLayersByName(rec.get('name'))[0].setVisibility(true);
      }
    }
  });
  var otherGridPanel = new Ext.grid.GridPanel({
     id               : 'otherGridPanel'
    ,height           : Math.min(otherStore.getCount(),5) * 21.1 + 26 + 11 + 25
    ,title            : 'Other'
    ,collapsible      : true
    ,store            : otherStore
    ,border           : false
    ,selModel         : otherSelModel
    ,columns          : [
       otherSelModel
      ,{id : 'status'     ,dataIndex : 'status'     ,renderer : renderLayerButton   ,width : 25}
      ,{id : 'displayName',dataIndex : 'displayName',renderer : renderLayerInfoLink ,width : 167}
      ,{id : 'bbox'       ,dataIndex : 'bbox'       ,renderer : renderLayerCalloutButton    ,width : 20}
    ]
    ,hideHeaders      : true
    ,disableSelection : true
    ,listeners        : {viewready : function(grid) {
      layersToSyncBbox['other'] = true;
      needToInitGridPanel['other'] = true;
      syncLayersToBbox('other');
      var sm = grid.getSelectionModel();
      otherStore.each(function(rec) {
        if (rec.get('status') == 'on') {
          sm.selectRecords([rec],true);
        }
      });
    }}
    ,tbar             : [
      {
         text    : 'Turn all other off'
        ,icon    : 'img/delete.png'
        ,handler : function() {
          otherSelModel.clearSelections();
        }
      }
    ]
  });

  var legendsGridPanel = new Ext.grid.GridPanel({
     id               : 'legendsGridPanel'
    ,region           : 'east'
    ,width            : 180
    ,title            : 'Legends'
    ,collapsible      : true
    ,store            : legendsStore
    ,split            : true
    ,columns          : [
       {id : 'status',dataIndex : 'status',renderer : renderLayerStatus}
      ,{id : 'legend',dataIndex : 'name'  ,renderer : renderLegend}
    ]
    ,hideHeaders      : true
    ,disableSelection : true
    ,listeners        : {afterrender : function() {
      this.addListener('bodyresize',function(p,w,h) {
        this.getColumnModel().setConfig([
           {id : 'status',dataIndex : 'status',renderer : renderLayerStatus,width : (config == 'gliders' ? 42 : 30)}
          ,{id : 'legend',dataIndex : 'name'  ,renderer : renderLegend     ,width : w - 4 - 42}
        ]);
      });
    }}
    ,tbar : {items : [
       '->'
      ,{
         icon    : 'img/door_out.png'
        ,text    : 'Logout'
        ,tooltip : 'Logout of this map session'
        ,handler : function() {
          document.location = 'logout.php';
        }
      }
    ]}
  });

  var managerItems = [
     introPanel
    ,currentsGridPanel
    ,windsGridPanel
    ,wavesGridPanel
    ,temperaturesGridPanel
    ,otherGridPanel
  ];

  new Ext.Viewport({
     layout : 'border'
    ,id     : 'viewport'
    ,items  : [
      new Ext.Panel({
         region      : 'west'
        ,id          : 'managerPanel'
        ,width       : 255
        ,title       : globalTitle + ' Manager'
        ,collapsible : true
        ,items       : managerItems
        ,listeners        : {afterrender : function() {
          this.addListener('bodyresize',function(p,w,h) {
            if (currentsGridPanel.getStore().getCount() > 10) {
              currentsGridPanel.setHeight(h - introPanel.getHeight() - windsGridPanel.getHeight() - temperaturesGridPanel.getHeight() - wavesGridPanel.getHeight() - otherGridPanel.getHeight());
            }
            else {
              otherGridPanel.setHeight(h - introPanel.getHeight() - currentsGridPanel.getHeight() - windsGridPanel.getHeight() - temperaturesGridPanel.getHeight() - wavesGridPanel.getHeight());
            }
          });
        }}
        ,tbar      : [
           {
             icon : 'img/blank.png'
           }
          ,'->'
          ,'Only list & map layers in current extents?'
          ,' '
          ,new Ext.form.Checkbox({
             checked   : false
            ,id        : 'restrictLayersToBbox'
            ,listeners : {check : function() {
              syncLayersToBbox();
            }}
          })
        ]
      })
      ,new Ext.Panel({
         region    : 'center'
        ,title     : globalTitle + ' Explorer'
        ,layout    : 'border'
        ,items     : [
          {
             html      : '<div id="map"></div>'
            ,region    : 'center'
            ,border    : false
            ,bbar      : {items : [
              {
                 xtype     : 'buttongroup'
                ,autoWidth : true
                ,columns   : 1
                ,title     : 'Map date & time'
                ,items     : [{
                   id    : 'mapTime'
                  ,text  : dNow.getUTCFullYear() + '-' + String.leftPad(dNow.getUTCMonth() + 1,2,'0') + '-' + String.leftPad(dNow.getUTCDate(),2,'0') + ' ' + String.leftPad(dNow.getUTCHours(),2,'0') + ':00 UTC'
                  ,width : 135
                }]
              }
              ,{
                 xtype     : 'buttongroup'
                ,autoWidth : true
                ,columns   : 5
                ,title     : 'Change map date & time'
                ,items     : [
                   {
                     text    : 'Date'
                    ,tooltip : 'Change the map\'s date and time'
                    ,icon    : 'img/calendar_view_day16.png'
                    ,menu    : new Ext.menu.Menu({showSeparator : false,items : [
                      new Ext.DatePicker({
                         value     : new Date(dNow.getTime() + dNow.getTimezoneOffset() * 60000)
                        ,id        : 'datePicker'
                        ,listeners : {
                          select : function(picker,d) {
                            d.setUTCHours(0);
                            d.setUTCMinutes(0);
                            d.setUTCSeconds(0);
                            d.setUTCMilliseconds(0);
                            dNow = d;
                            setMapTime();
                          }
                        }
                      })
                    ]})
                  }
                  ,{
                     text    : '-6h'
                    ,icon    : 'img/ButtonRewind.png'
                    ,handler : function() {dNow = new Date(dNow.getTime() - 6 * 3600000);setMapTime();}
                  }
                  ,{
                     text    : '-1h'
                    ,icon    : 'img/ButtonPlayBack.png'
                    ,handler : function() {dNow = new Date(dNow.getTime() - 1 * 3600000);setMapTime();}
                  }
                  ,{
                     text    : '+1h'
                    ,icon    : 'img/ButtonPlay.png'
                    ,handler : function() {dNow = new Date(dNow.getTime() + 1 * 3600000);setMapTime();}
                  }
                  ,{
                     text    : '+6h'
                    ,icon    : 'img/ButtonForward.png'
                    ,handler : function() {dNow = new Date(dNow.getTime() + 6 * 3600000);setMapTime();}
                  }
                ]
              }
              ,'->'
              ,{
                 xtype     : 'buttongroup'
                ,autoWidth : true
                ,columns   : 2
                ,title     : 'Map options'
                ,items     : [
                  {text : 'Bathymetry',icon : 'img/map16.png',menu : {items : [
                    {
                       text         : 'Hide bathymetry contours'
                      ,checked      : typeof defaultLayers['Bathymetry contours'] == 'undefined'
                      ,group        : 'bathy'
                      ,handler      : function() {
                        var lyr = map.getLayersByName('Bathymetry contours')[0];
                        if (!lyr) {
                          Ext.Msg.alert('Bathymetry contours',"We're sorry, but this layer is not available.");
                        }
                        else {
                          lyr.setVisibility(false);
                        }
                      }
                    }
                    ,{
                       text         : 'Show bathymetry contours'
                      ,checked      : typeof defaultLayers['Bathymetry contours'] != 'undefined'
                      ,group        : 'bathy'
                      ,handler      : function() {
                        var lyr = map.getLayersByName('Bathymetry contours')[0];
                        if (!lyr) {
                          Ext.Msg.alert('Bathymetry contours',"We're sorry, but this layer is not available.");
                        }
                        else {
                          lyr.setVisibility(true);
                        }
                      }
                    }
                  ]}}
                  ,{text : 'Basemap',icon : 'img/world16.png',menu : {items : [
                    {
                       text         : 'Show ESRI Ocean basemap'
                      ,checked      : defaultBasemap == 'ESRI Ocean'
                      ,group        : 'basemap'
                      ,handler      : function() {
                        var lyr = map.getLayersByName('ESRI Ocean')[0];
                        if (lyr.isBaseLayer) {
                          lyr.setOpacity(1);
                          map.setBaseLayer(lyr);
                          lyr.redraw();
                        }
                      }
                    }
                    ,{
                       text         : 'Show Google Hybrid basemap'
                      ,checked      : defaultBasemap == 'Google Hybrid'
                      ,group        : 'basemap'
                      ,handler      : function() {
                        var lyr = map.getLayersByName('Google Hybrid')[0];
                        if (lyr.isBaseLayer) {
                          lyr.setOpacity(1);
                          map.setBaseLayer(lyr);
                          lyr.redraw();
                        }
                      }
                    }
                    ,{
                       text         : 'Show Google Satellite basemap'
                      ,checked      : defaultBasemap == 'Google Satellite'
                      ,group        : 'basemap'
                      ,handler      : function() {
                        var lyr = map.getLayersByName('Google Satellite')[0];
                        if (lyr.isBaseLayer) {
                          lyr.setOpacity(1);
                          map.setBaseLayer(lyr);
                          lyr.redraw();
                        }
                      }
                    }
                    ,{
                       text         : 'Show Google Terrain basemap'
                      ,checked      : defaultBasemap == 'Google Terrain'
                      ,group        : 'basemap'
                      ,handler      : function() {
                        var lyr = map.getLayersByName('Google Terrain')[0];
                        if (lyr.isBaseLayer) {
                          lyr.setOpacity(1);
                          map.setBaseLayer(lyr);
                          lyr.redraw();
                        }
                      }
                    }
                  ]}}
                ]
              }
            ]}
            ,listeners : {
              afterrender : function(panel) {
                initMap();
              }
              ,bodyresize : function(p,w,h) {
                var el = document.getElementById('map');
                if (el) {
                  el.style.width = w;
                  el.style.height = h;
                  map.updateSize();
                }
              }
            }
          }
          ,new Ext.Panel({
             region      : 'south'
            ,id          : 'timeseriesPanel'
            ,title       : 'Time-Series Query Results'
            ,tbar        : [
              {
                 text : 'Active model query layer: '
                ,id   : 'activeLabel'
              }
              ,' '
              ,new Ext.form.ComboBox({
                 mode           : 'local'
                ,id             : 'chartLayerCombo'
                ,width          : 300
                ,store          : chartLayerStore
                ,displayField   : 'displayName'
                ,valueField     : 'name'
                ,forceSelection : true
                ,triggerAction  : 'all'
                ,editable       : false
              })
              ,'->'
              ,{
                 text    : 'Requery'
                ,icon    : 'img/arrow_refresh.png'
                ,id      : 'requery'
                ,hidden  : true
                ,handler : function() {
                  if (lyrQueryPts.features.length > 0) {
                    mapClick(lastMapClick['xy'],true,true);
                  }
                }
              }
              ,{
                 text    : 'Clear query'
                ,icon    : 'img/trash-icon.png'
                ,id      : 'graphAction'
                ,width   : 90
                ,handler : function() {
                  if (this.icon == 'img/blueSpinner.gif') {
                    return;
                  }
                  lyrQueryPts.removeFeatures(lyrQueryPts.features);
                  Ext.getCmp('requery').hide();
                  document.getElementById('tsResults').innerHTML = '<table class="obsPopup timeSeries"><tr><td><img width=3 height=3 src="img/blank.png"><br/><img width=8 height=1 src="img/blank.png">Click anywhere on the map or on a dot to view a time-series graph of model or observation output.<br/><img width=8 height=1 src="img/blank.png"><img src="img/graph_primer.png"></td></tr></table>';
                  chartData = [];
                  $('#tooltip').remove();
                  Ext.getCmp('chartLayerCombo').show();
                  Ext.getCmp('activeLabel').setText('Active model query layer: ');
                }
              }
            ]
            ,border      : false
            ,height      : 220
            ,collapsible : true
            ,split       : true
            ,items       : {border : false,html : '<div style="width:10;height:10" id="tsResults"/>'}
            ,listeners   : {
              afterrender : function(win) {
                var prevPt;
                $('#tsResults').bind('plothover',function(event,pos,item) {
                  if (item) {
                    var x = new Date(item.datapoint[0] + new Date().getTimezoneOffset() * 60 * 1000);
                    var y = item.datapoint[1];
                    var label = item.series.label ? item.series.label + ' : ' : 'Map Time : ';
                    if (prevPoint != item.dataIndex) {
                      $('#tooltip').remove();
                      showToolTip(item.pageX,item.pageY,x + '<br/>' + label + y);
                    }
                    prevPoint = item.dataIndex;
                  }
                  else {
                    $('#tooltip').remove();
                    prevPoint = null;
                  }
                });
                win.addListener('resize',function(win) {
                  var ts = document.getElementById('tsResults');
                  ts.style.width  = win.getWidth() - 15;
                  ts.style.height = win.getHeight() - 55;
                  var spd = [];
                  var dir = []; 
                  if (!chartData || chartData.length <= 0) {
                    ts.innerHTML = '<table class="obsPopup timeSeries"><tr><td><img width=3 height=3 src="img/blank.png"><br/><img width=8 height=1 src="img/blank.png">Click anywhere on the map or on a dot to view a time-series graph of model or observation output.<br/><img width=8 height=1 src="img/blank.png"><img src="img/graph_primer.png"></td></tr></table>';
                  }
                  else if (chartData && chartData.length > 0 && typeof chartData[0] == 'string' && chartData[0].indexOf('QUERY ERROR') == 0) {
                    ts.innerHTML = '<table class="obsPopup timeSeries"><tr><td><img width=3 height=3 src="img/blank.png"><br/><font color="red">' + chartData[0] + '</font><br/>' + '<img width=8 height=1 src="img/blank.png">Click anywhere on the map or on a dot to view a time-series graph of model or observation output.<br/><img width=8 height=1 src="img/blank.png"><img src="img/graph_primer.png"></td></tr></table>'; 
                  }
                  else {
                    for (var i = 0; i < chartData.length; i++) {
                      if (new RegExp(/Velocity|Speed/).test(chartData[i].label)) {
                        spd.push(chartData[i]);
                      }
                      else if (chartData[i].label.indexOf('Direction') >= 0) {
                        dir.push(chartData[i]);
                      }
                    }
                    ts.innerHTML    = '';
                    var p = $.plot(
                       $('#tsResults')
                      ,spd.length > 0 && dir.length > 0 ? spd : chartData
                      ,{
                         xaxis     : {mode  : "time"}
                        ,crosshair : {mode  : 'x'   }
                        ,grid      : {backgroundColor : {colors : ['#fff','#eee']},borderWidth : 1,borderColor : '#99BBE8',hoverable : true}
                        ,zoom      : {interactive : false}
                        ,pan       : {interactive : false}
                        ,legend    : {backgroundOpacity : 0.3}
                      }
                    );
                    if (spd.length > 0 && dir.length > 0 && spd.length == dir.length) {
                      // assume that #spd == #dir
                      for (var j = 0; j < spd.length; j++) {
                        var imageSize = 80;
                        for (var i = spd[j].data.length - 1; i >= 0; i--) {
                          var type = 'arrow';
                          if (spd[j].label.indexOf('Wind') >= 0) {
                            type = 'barb';
                          }
                          var o = p.pointOffset({x : spd[j].data[i][0],y : spd[j].data[i][1]});
                          if (type == 'barb') {
                            var val = Math.round(dir[j].data[i][1]);
                            if (spd[j].type == 'obs') {
                              val = (val + 180) % 360;
                            }
                            $('#tsResults').prepend('<div class="dir" style="position:absolute;left:' + (o.left-imageSize/2) + 'px;top:' + (o.top-(imageSize/2)) + 'px;background-image:url(\'vector.php?w=' + imageSize + '&h=' + imageSize + '&dir=' + val + '&spd=' + Math.round(spd[j].data[i][1]) + '&type=' + type + '&color=' + lineColor2VectorColor(dir[j].color).replace('#','') + '\');width:' + imageSize + 'px;height:' + imageSize + 'px;"></div>');
                          }
                          else {
                            // pull arrows from cache
                            $('#tsResults').prepend('<div class="dir" style="position:absolute;left:' + (o.left-imageSize/2) + 'px;top:' + (o.top-(imageSize/2)) + 'px;background-image:url(\'img/vectors/' + type + '/' + imageSize + 'x' + imageSize + '.dir' + Math.round(dir[j].data[i][1]) + '.' + lineColor2VectorColor(dir[j].color).replace('#','') + '.png\');width:' + imageSize + 'px;height:' + imageSize + 'px;"></div>');
                          }
                        }
                      }
                    }
                    if (chartData[0].nowIdx != '' && chartData[0].data[chartData[0].nowIdx]) {
                      var imageSize = 16;
                      var o = p.pointOffset({x : chartData[0].data[chartData[0].nowIdx][0],y : chartData[0].data[chartData[0].nowIdx][1]});
                      $('#tsResults').prepend('<div class="dir" style="position:absolute;left:' + (o.left-imageSize/2) + 'px;top:' + (o.top-(imageSize/2)) + 'px;background-image:url(\'img/asterisk_orange.png\');width:' + imageSize + 'px;height:' + imageSize + 'px;"></div>');
                    }
                  }
                  lyrQueryPts.features.length > 0 ? Ext.getCmp('requery').show() : Ext.getCmp('requery').hide();
                });
              }
            }
          })
        ]
      })
      ,legendsGridPanel
    ]
  });
}

function renderLayerButton(val,metadata,rec) {
  return '<img  width=20 height=20 src="img/DEFAULT.drawn.png">';
}

function renderLayerInfoLink(val,metadata,rec) {
  return '<span class="name">' + val.split('||')[0] + '</span>';
}

function renderLayerCalloutButton(val,metadata,rec) {
  return '<a id="info.' + rec.get('name') + '" href="javascript:setLayerInfo(\'' + rec.get('name')  + '\',\'' + rec.get('info') + '\' != \'on\')"><img title="Customize layer appearance" style="margin-top:2px" src="img/page_go.png"></a>';
}

function renderLayerStatus(val,metadata,rec) {
  if (val == 'loading') {
    return '<img src="img/loading.gif">';
  }
  else {
    return '<img class="layerIcon" src="img/DEFAULT.drawn.png">';
  }
}

function renderLegend(val,metadata,rec) {
  var idx = mainStore.find('name',rec.get('name'));
  var a = [rec.get('displayName').split('||')[0]];
  if (rec.get('timestamp') && rec.get('timestamp') != '') {
    a.push(rec.get('timestamp'));
  }
  if (mainStore.getAt(idx).get('legend') != '') {
    if (!legendImages[rec.get('name')]) {
      var img = new Image();
      img.src = 'getLegend.php?' + mainStore.getAt(idx).get('legend');
      legendImages[rec.get('name')] = img;
    }

    var customize = '<table><tr><td width=20><a id="settings.' + rec.get('name') + '" title="Customize this layer\'s appearance" href="javascript:setLayerSettings(\'' + rec.get('name') + '\',true)"><img width=16 height=16 src="img/setting_tools.png"></a></td><td><a title="Customize this layer\'s appearance" href="javascript:setLayerSettings(\'' + rec.get('name') + '\',true)">Customize&nbsp;this&nbsp;layer</a></td></tr></table>';
    if (map.getLayersByName(rec.get('name'))[0].featureFactor) {
      customize = '';
    }

    a.push('<img src="getLegend.php?' + mainStore.getAt(idx).get('legend') + '">');
  }
  return a.join('<br/>');
}

function syncLayersToBbox(l) {
  if (!Ext.getCmp('restrictLayersToBbox').checked) {
    return;
  }
  for (var type in layersToSyncBbox) {
    if ((typeof l == 'string' && l == type) || (typeof l != 'string')) {
      var gp  = Ext.getCmp(type + 'GridPanel');
      var sto = gp.getStore();
      var sm  = gp.getSelectionModel();
      if (needToInitGridPanel[type]) {
        if (gp.isVisible() && sto.getCount() == 0) {
          gp.hide();
        }
        needToInitGridPanel[type] = false;
      }
      sm.suspendEvents();
      sto.removeAll();
      mainStore.each(function(rec) {
        if (rec.get('type') == type) {
          var bbox = String(rec.get('bbox')).split(',');
          if (
            map.getExtent().transform(map.getProjectionObject(),proj4326).intersectsBounds(new OpenLayers.Bounds(bbox[0],bbox[1],bbox[2],bbox[3]))
            || new OpenLayers.Bounds(bbox[0],bbox[1],bbox[2],bbox[3]).containsBounds(map.getExtent().transform(map.getProjectionObject(),proj4326))
            || !Ext.getCmp('restrictLayersToBbox').checked
          ) {
            sto.add(rec);
          }
          else if (map.getLayersByName(rec.get('name'))[0].visibility) {
            map.getLayersByName(rec.get('name'))[0].setVisibility(false);
          }
        }
      });
      var j = 0;
      sto.each(function(rec) {
        if (map.getLayersByName(rec.get('name'))[0].visibility) {
          sm.selectRow(j,true);
        }
        j++;
      });
      sm.resumeEvents();
    }
  }
}

function initMap() {
  // set transformation functions from/to alias projection
  OpenLayers.Projection.addTransform("EPSG:4326","EPSG:3857",OpenLayers.Layer.SphericalMercator.projectForward);
  OpenLayers.Projection.addTransform("EPSG:3857","EPSG:4326",OpenLayers.Layer.SphericalMercator.projectInverse);

  OpenLayers.Util.onImageLoadError = function() {this.src = 'img/blank.png';}

  // patch openlayers 2.11RC to fix problem when switching to a google layer
  // from a non google layer after resizing the map
  // http://osgeo-org.1803224.n2.nabble.com/trunk-google-v3-problem-resizing-and-switching-layers-amp-fix-td6578816.html
  OpenLayers.Layer.Google.v3.onMapResize = function() {
    var cache = OpenLayers.Layer.Google.cache[this.map.id];
    cache.resized = true;
  };
  OpenLayers.Layer.Google.v3.setGMapVisibility_old =
  OpenLayers.Layer.Google.v3.setGMapVisibility;
  OpenLayers.Layer.Google.v3.setGMapVisibility = function(visible) {
    var cache = OpenLayers.Layer.Google.cache[this.map.id];
    if (visible && cache && cache.resized) {
      google.maps.event.trigger(this.mapObject, "resize");
      delete cache.resized;
    }
    OpenLayers.Layer.Google.v3.setGMapVisibility_old.apply(this,arguments);
  };

  lyrQueryPts = new OpenLayers.Layer.Vector(
     'Query points'
    ,{styleMap : new OpenLayers.StyleMap({
      'default' : new OpenLayers.Style(OpenLayers.Util.applyDefaults({
         externalGraphic : 'img/${img}'
        ,pointRadius     : 10
        ,graphicOpacity  : 1
        ,graphicWidth    : 16
        ,graphicHeight   : 16
      }))
    })}
  );

  esriOcean = new OpenLayers.Layer.XYZ(
     'ESRI Ocean'
    ,'http://services.arcgisonline.com/ArcGIS/rest/services/Ocean_Basemap/MapServer/tile/${z}/${y}/${x}.jpg'
    ,{sphericalMercator: true,visibility : defaultBasemap == 'ESRI Ocean',isBaseLayer : true,opacity : 1,wrapDateLine : true,attribution : "GEBCO, NOAA, National Geographic, AND data by <a href='http://www.arcgis.com/home/item.html?id=6348e67824504fc9a62976434bf0d8d5'>ESRI</a>"} // ,serverResolutions : basemapResolutions,resolutions : basemapResolutions.slice(1)}
  );

  map = new OpenLayers.Map('map',{
    layers            : [
       esriOcean
      ,new OpenLayers.Layer.Google('Google Hybrid',{
         type          : google.maps.MapTypeId.HYBRID
        ,projection    : proj900913
        ,opacity       : 1
        ,visibility    : defaultBasemap == 'Google Hybrid'
        ,minZoomLevel  : 2
      })
      ,new OpenLayers.Layer.Google('Google Satellite',{
         type          : google.maps.MapTypeId.SATELLITE
        ,projection    : proj900913
        ,opacity       : 1
        ,visibility    : defaultBasemap == 'Google Satellite'
        ,minZoomLevel  : 2
      })
      ,new OpenLayers.Layer.Google('Google Terrain',{
         type          : google.maps.MapTypeId.TERRAIN
        ,projection    : proj900913
        ,opacity       : 1
        ,visibility    : defaultBasemap == 'Google Terrain'
        ,minZoomLevel  : 2
      })
      ,lyrQueryPts
    ]
    ,projection        : proj900913
    ,displayProjection : proj4326
    ,units             : "m"
    ,maxExtent         : new OpenLayers.Bounds(-20037508,-20037508,20037508,20037508.34)
  });

  for (var i = 0; i < map.layers.length; i++) {
    var lyr = map.getLayersByName(defaultBasemap)[0];
    if (!lyr.visibility) {
      map.setBaseLayer(lyr);
    }
  }

  map.events.register('click',this,function(e) {
    mapClick(e.xy,true,true);
  });

  map.events.register('addlayer',this,function() {
    map.setLayerIndex(lyrQueryPts,map.layers.length - 1);
  });

  map.zoomToExtent(new OpenLayers.Bounds(<?php echo $_COOKIE['bounds']?>).transform(proj4326,proj900913));

  var navControl = new OpenLayers.Control.NavToolbar();
  map.addControl(navControl);
  // only need 1 zoom wheel responder!
  navControl.controls[0].disableZoomWheel();

  var mouseControl = new OpenLayers.Control.MousePosition({
    formatOutput: function(lonLat) {
      return convertDMS(lonLat.lat.toFixed(5), "LAT") + ' ' + convertDMS(lonLat.lon.toFixed(5), "LON");
    }
  });
  mouseControl.displayProjection = new OpenLayers.Projection('EPSG:4326');
  map.addControl(mouseControl);

  map.events.register('moveend',this,function() {
    syncLayersToBbox();
    if (navControl.controls[1].active) {
      navControl.controls[1].deactivate();
      navControl.draw();
    }
  });

  map.events.register('changelayer',this,function(e) {
    if (e.property == 'params') {
      // keep legend in sync if a GetLegendGraphic legend
      var idx = mainStore.find('name',e.layer.name);
      if (idx >= 0 && mainStore.getAt(idx).get('legend').indexOf('GetLegendGraphic') >= 0) {
        var params = {
           REQUEST : 'GetLegendGraphic'
          ,LAYER   : OpenLayers.Util.getParameters(e.layer.getFullRequestString({}))['LAYERS']
        };
        if (mainStore.getAt(idx).get('legend').indexOf('GetMetadata') >= 0) {
          params.GetMetadata     = '';
          params.COLORSCALERANGE = getColorScaleRange();
        }
        mainStore.getAt(idx).set('legend',e.layer.getFullRequestString(params));
        mainStore.getAt(idx).commit();
      }
    }
  });

  for (var i = 0; i < layerConfig.layerStack.length; i++) {
    addWMS({
       name       : layerConfig.layerStack[i].title
      ,url        : 'http://coastmap.com/ecop/wms.aspx?'
      ,layers     : layerConfig.layerStack[i].name
      ,format     : 'image/png'
      ,styles     : defaultStyles[layerConfig.layerStack[i].title]
      ,singleTile : true
      ,projection : proj3857
    });
  }
}

function addWMS(l) {
  var lyr = new OpenLayers.Layer.WMS(
     l.name
    ,l.url
    ,{
       layers      : l.layers
      ,format      : l.format
      ,transparent : true
      ,styles      : l.styles
    }
    ,{
       isBaseLayer : false
      ,projection  : l.projection
      ,singleTile  : l.singleTile
      ,visibility  : mainStore.getAt(mainStore.find('name',l.name)).get('status') == 'on'
      ,opacity     : mainStore.getAt(mainStore.find('name',l.name)).get('settingsOpacity') / 100
      ,wrapDateLine : true
    }
  );
  addLayer(lyr,true);
}

function addLayer(lyr,timeSensitive) {
  lyr.events.register('visibilitychanged',this,function(e) {
    if (!lyr.visibility) {
      var idx = legendsStore.find('name',lyr.name);
      if (idx >= 0) {
        legendsStore.removeAt(idx);
      }
      idx = chartLayerStore.find('name',lyr.name);
      if (idx >= 0) {
        chartLayerStore.removeAt(idx);
        if (Ext.getCmp('chartLayerCombo').getValue() == lyr.name) {
          Ext.getCmp('chartLayerCombo').clearValue();
        }
      }
      layerLoadendUnmask();
    }
  });
  lyr.events.register('loadstart',this,function(e) {
    layerLoadstartMask();
    var idx = legendsStore.find('name',lyr.name);
    if (idx >= 0) {
      var rec = legendsStore.getAt(idx);
      rec.set('status','loading');
      rec.commit();
    }
    else {
      var rec = mainStore.getAt(mainStore.find('name',lyr.name));
      legendsStore.add(new legendsStore.recordType({
         name        : lyr.name
        ,displayName : rec.get('displayName')
        ,status      : 'loading'
        ,rank        : rec.get('rank')
        ,fetchTime   : rec.get('timestamp') != 'false'
        ,type        : rec.get('type')
      }));
    }
    idx = chartLayerStore.find('name',lyr.name);
    if (idx < 0) {
      var mainIdx = mainStore.find('name',lyr.name);
      if (mainStore.getAt(mainIdx).get('queryable') == 'true') {
        chartLayerStore.add(new chartLayerStore.recordType({
           rank        : mainStore.getAt(mainIdx).get('rank')
          ,name        : lyr.name
          ,displayName : mainStore.getAt(mainIdx).get('displayName').split('||')[0]
          ,category    : mainStore.getAt(mainIdx).get('category')
        }));
      }
    }
    // record the action on google analytics
    pageTracker._trackEvent('layerView','loadStart',mainStore.getAt(mainStore.find('name',lyr.name)).get('displayName'));
  });
  lyr.events.register('loadend',this,function(e) {
    var idx = legendsStore.find('name',lyr.name);
    if (idx >= 0) {
      var rec = legendsStore.getAt(idx);
      rec.set('status','drawn');
      rec.commit();
      if (rec.get('fetchTime')) {
        OpenLayers.Request.GET({
           url      : 'getTimestamp.php?'
            + lyr.getFullRequestString({})
            + '&WIDTH='  + map.getSize().w
            + '&HEIGHT=' + map.getSize().h
            + '&BBOX=' +  map.getExtent().toArray().join(',')
            + '&' + new Date().getTime()
            + '&drawImg=false'
          ,callback : function(r) {
            if (r.responseText == '') {
              rec.set('timestamp','<span class="alert">There was a problem<br/>drawing this layer.<span>');
            }
            else if (r.responseText == 'invalidBbox') {
              rec.set('timestamp','<span class="alert">This layer\'s domain<br/>is out of bounds.<span>');
            }
            else if (r.responseText == 'dateNotAvailable') {
              rec.set('timestamp','');
            }
            else {
              var prevTs = rec.get('timestamp');
              var newTs  = shortDateString(new Date(r.responseText * 1000));
              rec.set('timestamp',newTs);
            }
          }
        });
      }
    }
    layerLoadendUnmask();
    // record the action on google analytics
    pageTracker._trackEvent('layerView','loadEnd',mainStore.getAt(mainStore.find('name',lyr.name)).get('displayName'));
  });
  if (timeSensitive) {
    lyr.mergeNewParams({TIME : dNow.getUTCFullYear() + '-' + String.leftPad(dNow.getUTCMonth() + 1,2,'0') + '-' + String.leftPad(dNow.getUTCDate(),2,'0') + 'T' + String.leftPad(dNow.getUTCHours(),2,'0') + ':00'});
    // the sat SST tds layer can be ID'ed by GFI_TIME -- this layer also needs COLORSCALERANGE
    // I didn't want to make this part of the URL
    if (lyr.url.indexOf('GFI_TIME') >= 0) {
      lyr.mergeNewParams({COLORSCALERANGE : getColorScaleRange()}); 
    }
  }
  map.addLayer(lyr);
}

function layerLoadstartMask() {
  Ext.getCmp('legendsGridPanel').getEl().mask('<table><tr><td>Updating map...&nbsp;</td><td><img src="js/ext-3.3.0/resources/images/default/grid/loading.gif"></td></tr></table>','mask');
}

function layerLoadendUnmask() {
  var stillLoading = 0;
  legendsStore.each(function(rec) {
    stillLoading += (rec.get('status') != 'drawn' ? 1 : 0);
  });
  if (stillLoading == 0) {
    Ext.getCmp('legendsGridPanel').getEl().unmask();
  }
}

function graphLoadstartMask() {
  Ext.getCmp('timeseriesPanel').getEl().mask('<table><tr><td>Updating graph...&nbsp;</td><td><img src="js/ext-3.3.0/resources/images/default/grid/loading.gif"></td></tr></table>','mask');
}

function graphLoadendUnmask() {
  Ext.getCmp('timeseriesPanel').getEl().unmask();
}

function mapClick(xy,doWMS,chartIt) {
  lastMapClick['xy'] = xy;
  lyrQueryPts.removeFeatures(lyrQueryPts.features);

  var modelQueryLyr = map.getLayersByName(Ext.getCmp('chartLayerCombo').getValue())[0];
  var modelQueryRec = mainStore.getAt(mainStore.find('name',modelQueryLyr.name));
  if ((modelQueryLyr && modelQueryLyr.visibility && modelQueryLyr.DEFAULT_PARAMS)) {
    var lonLat = map.getLonLatFromPixel(xy);
    var f = new OpenLayers.Feature.Vector(new OpenLayers.Geometry.Point(lonLat.lon,lonLat.lat));
    f.attributes.img = 'Delete-icon.png';
    lyrQueryPts.addFeatures(f);
  }

  var queryLyrs = [modelQueryLyr];
  if (doWMS && modelQueryLyr && modelQueryLyr.visibility && modelQueryLyr.DEFAULT_PARAMS) {
    // now that we've established our pivot point, see if there are any other active layers to drill into based on the category
    var displayName = mainStore.getAt(mainStore.find('name',modelQueryLyr.name)).get('displayName');
    var lyrType = displayName.substr(displayName.lastIndexOf(' ') + 1);
    Ext.getCmp('chartLayerCombo').getStore().each(function(rec) {
      if (rec.get('name') != modelQueryLyr.name && rec.get('category') == modelQueryRec.get('category')) {
        var lyr = map.getLayersByName(rec.get('name'))[0];
        if (lyr && lyr.visibility && lyr.DEFAULT_PARAMS) {
          queryLyrs.push(lyr);
        }
      }
    });
    return queryWMS(xy,queryLyrs,chartIt);
  }
}

function queryWMS(xy,a,chartIt) {
  lastMapClick['layer'] = a[0].name;
  if (chartIt) {
    graphLoadstartMask();
  }
  var targets = [];
  for (var i = 0; i < a.length; i++) {
    var mapTime;
    var legIdx = legendsStore.find('name',a[i].name);
    if (legIdx >= 0 && legendsStore.getAt(legIdx).get('timestamp') && String(legendsStore.getAt(legIdx).get('timestamp')).indexOf('alert') < 0) {
      mapTime = '&mapTime=' + (new Date(shortDateToDate(legendsStore.getAt(legIdx).get('timestamp')).getTime() - new Date().getTimezoneOffset() * 60000) / 1000);
    }
    var paramOrig = OpenLayers.Util.getParameters(a[i].getFullRequestString({}));
    var paramNew = {
       REQUEST       : 'GetFeatureInfo'
      ,EXCEPTIONS    : 'application/vnd.ogc.se_xml'
      ,BBOX          : map.getExtent().toBBOX()
      ,X             : xy.x
      ,Y             : xy.y
      ,INFO_FORMAT   : 'text/xml'
      ,FEATURE_COUNT : 1
      ,WIDTH         : map.size.w
      ,HEIGHT        : map.size.h
    };
    targets.push({url : a[i].getFullRequestString(paramNew,'getFeatureInfo.php?' + a[i].url + '&tz=' + new Date().getTimezoneOffset() + mapTime),title : mainStore.getAt(mainStore.find('name',a[i].name)).get('displayName'),type : 'model'});
  }
  if (chartIt) {
    makeChart('model',targets);
  }
  return targets;
}

function makeChart(type,a) {
  Ext.getCmp('chartLayerCombo').show();
  Ext.getCmp('activeLabel').setText('Active model query layer: ');
  for (var j = 0; j < a.length; j++) {
    chartUrls[a[j].url] = true;
  }
  chartData = [];
  var color;
  for (var j = 0; j < a.length; j++) {
    OpenLayers.Request.GET({
       url      : a[j].url
      ,callback : OpenLayers.Function.bind(makeChartCallback,null,a[j].title,lineColors[(j + (a[j].dontAdvanceColor ? -1 : 0)) % lineColors.length][0],a[j].type,a[j].url)
    });
  }
  function makeChartCallback(title,lineColor,type,url,r) {
    var obs = new OpenLayers.Format.JSON().read(r.responseText);
    var yaxis = 1;
    if (obs && obs.error) {
      chartData.push({
         data   : []
        ,label  : title.split('||')[0] + ': QUERY ERROR ' + obs.error
        ,nowIdx : ''
      });
      // record the action on google analytics
      pageTracker._trackEvent('chartView',title,'error');
    }
    else if (!obs || obs.d == '' || obs.d.length == 0) {
      chartData.push({
         data   : []
        ,label  : title.split('||')[0] + ': QUERY ERROR'
        ,nowIdx : ''
      });
      // record the action on google analytics
      pageTracker._trackEvent('chartView',title,'error');
    }
    else {
      // get rid of any errors if good, new data has arrived
      if (chartData.length == 1 && String(chartData[0]).indexOf('QUERY ERROR') == 0) {
        chartData.pop();
      }
      for (var v in obs.d) {
        // get the data
        chartData.push({
           data   : []
          ,label  : title.split('||')[0] + ' : ' + v + ' (' + toEnglish({typ : 'title',src : obs.u[v],val : obs.u[v]}) + ')'
          ,yaxis  : yaxis
          ,lines  : {show : true}
          ,nowIdx : obs.d[v].length > 1 ? obs.nowIdx : ''
          ,color  : lineColor
          ,type   : type
        });
        for (var i = 0; i < obs.d[v].length; i++) {
          chartData[chartData.length-1].data.push([obs.t[i],toEnglish({typ : 'obs',src : obs.u[v],val : obs.d[v][i]})]);
        }
        if (obs.d[v].length == 1) {
          chartData[chartData.length - 1].points = {show : true};
        }
        yaxis++;
      }
      // record the action on google analytics
      pageTracker._trackEvent('chartView',title,'ok');
    }
    delete chartUrls[url];
    var hits = 0;
    for (var i in chartUrls) {
      hits++;
    }
    if (hits == 0) {
      graphLoadendUnmask();
    }
    Ext.getCmp('timeseriesPanel').fireEvent('resize',Ext.getCmp('timeseriesPanel'));
  }
}

function toEnglish(v) {
  if (String(v.src).indexOf('Celcius') >= 0) {
    if (v.typ == 'title') {
      return v.val.replace('Celcius','Fahrenheit');
    }
    else {
      return v.val * 9/5 + 32;
    }
  }
  else if (String(v.src).indexOf('Meters') >= 0) {
    if (v.typ == 'title') {
      return v.val.replace('Meters','Feet');
    }
    else {
      return v.val * 3.281;
    }
  }
  return v.val;
}

function showToolTip(x,y,contents) {
  $('<div id="tooltip">' + contents + '</div>').css({
     position           : 'absolute'
    ,display            : 'none'
    ,top                : y + 10
    ,left               : x + 10
    ,border             : '1px solid #99BBE8'
    ,padding            : '2px'
    ,'background-color' : '#fff'
    ,opacity            : 0.80
  }).appendTo("body").fadeIn(200);
}

function setLayerInfo(layerName,on) {
  var mainRec = mainStore.getAt(mainStore.find('name',layerName));
  mainRec.set('info',on ? 'on' : 'off');
  mainRec.commit();

  // only one popup can be displayed at a time
  mainStore.each(function(rec) {
    if (layerName != rec.get('name') && rec.get('info') == 'on') {
      rec.set('info','off');
      rec.commit();
      if (Ext.getCmp('info.popup.' + rec.get('name'))) {
        Ext.getCmp('info.popup.' + rec.get('name')).destroy();
      }
    }
    else if (layerName == rec.get('name') && rec.get('info') == 'off') {
      rec.set('info','off');
      rec.commit();
      var el = Ext.getCmp('info.popup.' + layerName);
      if (el) {
        el.hide();
        return;
      }
    }
  });

  if (on && (!Ext.getCmp('info.popup.' + layerName) || !Ext.getCmp('info.popup.' + layerName).isVisible())) {
    var customize = '<a class="blue-href-only" href="javascript:setLayerSettings(\'' + mainRec.get('name') + '\');setLayerInfo(\'' + layerName + '\',false)"><img width=32 height=32 src="img/settings_tools_big.png"><br>Customize<br>appearance</a>';
    new Ext.ToolTip({
       id        : 'info.popup.' + layerName
      ,title     : mainRec.get('displayName').split('||')[0]
      ,anchor    : 'right'
      ,target    : 'info.' + layerName 
      ,autoHide  : false
      ,closable  : true
      ,width     : 250
      ,items     : {
         layout   : 'column'
        ,defaults : {border : false}
        ,height   : 75
        ,bodyStyle : 'padding:6'
        ,items    :  [
           {columnWidth : 0.33,items : {xtype : 'container',autoEl : {tag : 'center'},items : {border : false,html : '<a class="blue-href-only" href="javascript:zoomToBbox(\'' + mainRec.get('bbox') + '\');setLayerInfo(\'' + layerName + '\',false)"><img width=32 height=32 src="img/find_globe_big.png"><br>Zoom<br>to layer</a>'}}}
          ,{columnWidth : 0.33,items : {xtype : 'container',autoEl : {tag : 'center'},items : {border : false,html : customize}}}
          ,{columnWidth : 0.33,items : {xtype : 'container',autoEl : {tag : 'center'},items : {border : false,html : '<a class="blue-href-only" href="javascript:showLayerInfo(\'' + mainRec.get('name') + '\');setLayerInfo(\'' + layerName + '\',false)"><img width=32 height=32 src="img/document_image.png"><br>Layer<br>information</a>'}}}
        ]
      }
      ,listeners : {
        hide : function() {
          this.destroy();
          mainRec.set('info','off');
          mainRec.commit();
        }
      }
    }).show();
  }
}

function zoomToBbox(bbox) {
  var p = bbox.split(',');
  map.zoomToExtent(new OpenLayers.Bounds(p[0],p[1],p[2],p[3]).transform(proj4326,map.getProjectionObject()));
}

function showLayerInfo(layerName) {
  if (!activeInfoWindows[layerName]) {
    var idx = mainStore.find('name',layerName);
    var pos = getOffset(document.getElementById('info.' + layerName));
    activeInfoWindows[layerName] = new Ext.Window({
       width      : 400
      ,x          : pos.left
      ,y          : pos.top
      ,autoScroll : true
      ,constrainHeader : true
      ,title      : mainStore.getAt(idx).get('displayName').split('||')[0] + ' :: info'
      ,items      : {border : false,bodyCssClass : 'popup',html : mainStore.getAt(idx).get('infoBlurb')}
      ,listeners  : {hide : function() {
        activeInfoWindows[layerName] = null;
      }}
    }).show();
  }
}

function setLayerSettings(layerName) {
  if (!activeSettingsWindows[layerName]) {
    var pos = getOffset(document.getElementById('info.' + layerName));
    var idx = mainStore.find('name',layerName);
    var height = 26;
    var id = Ext.id();
    var items = [
      new Ext.Slider({
         fieldLabel : 'Opacity<a href="javascript:Ext.getCmp(\'tooltip.' + id + '.opacity' + '\').show()"><img style="margin-left:2px;margin-bottom:2px" id="' + id + '.opacity' + '" src="img/info.png"></a>'
        ,id       : 'opacity.' + id
        ,width    : 130
        ,minValue : 0
        ,maxValue : 100
        ,value    : mainStore.getAt(idx).get('settingsOpacity')
        ,plugins  : new Ext.slider.Tip({
          getText : function(thumb) {
            return String.format('<b>{0}%</b>', thumb.value);
          }
        })
        ,listeners : {
          afterrender : function() {
            new Ext.ToolTip({
               id     : 'tooltip.' + id + '.opacity'
              ,target : id + '.opacity'
              ,html   : "Use the slider to adjust the layer's opacity.  The lower the opacity, the greater the transparency."
            });
          }
          ,change : function(slider,val) {
            mainStore.getAt(idx).set('settingsOpacity',val);
            mainStore.getAt(idx).commit();
            map.getLayersByName(mainStore.getAt(idx).get('name'))[0].setOpacity(val / 100);
          }
        }
      })
    ];
    if (mainStore.getAt(idx).get('settingsImageQuality') != '') {
      height += 27;
      items.push(
        new Ext.form.ComboBox({
           fieldLabel     : 'Image quality<a href="javascript:Ext.getCmp(\'tooltip.' + id + '.imageQuality' + '\').show()"><img style="margin-left:2px;margin-bottom:2px" id="' + id + '.imageQuality' + '" src="img/info.png"></a>'
          ,id             : 'imageType.' + id
          ,store          : imageQualityStore
          ,displayField   : 'name'
          ,valueField     : 'value'
          ,value          : mainStore.getAt(idx).get('settingsImageQuality')
          ,editable       : false
          ,triggerAction  : 'all'
          ,mode           : 'local'
          ,width          : 130
          ,forceSelection : true
          ,listeners      : {
            afterrender : function() {
              new Ext.ToolTip({
                 id     : 'tooltip.' + id + '.imageQuality'
                ,target : id + '.imageQuality'
                ,html   : "Selecting high quality may result in longer download times."
              });
            }
            ,select : function(comboBox,rec) {
              mainStore.getAt(idx).set('settingsImageQuality',rec.get('value'));
              mainStore.getAt(idx).commit();
              setCustomStyles(mainStore.getAt(idx));
            }
          }
        })
      )
    }
    if (mainStore.getAt(idx).get('settingsBaseStyle') != '') {
      height += 27;
      items.push(
        new Ext.form.ComboBox({
           fieldLabel     : 'Base style<a href="javascript:Ext.getCmp(\'tooltip.' + id + '.baseStyle' + '\').show()"><img style="margin-left:2px;margin-bottom:2px" id="' + id + '.baseStyle' + '" src="img/info.png"></a>'
          ,id             : 'baseStyle.' + id
          ,store          : baseStylesStore
          ,displayField   : 'name'
          ,valueField     : 'value'
          ,value          : mainStore.getAt(idx).get('settingsBaseStyle')
          ,editable       : false
          ,triggerAction  : 'all'
          ,mode           : 'local'
          ,width          : 130
          ,forceSelection : true
          ,lastQuery      : ''
          ,listeners      : {
            afterrender : function() {
              new Ext.ToolTip({
                 id     : 'tooltip.' + id + '.baseStyle'
                ,target : id + '.baseStyle'
                ,html   : "In general, the Black base style has a better appearance if high resolution is also selected."
              });
            }
            ,select : function(comboBox,rec) {
              if (rec.get('value') == 'CURRENTS_STATIC_BLACK' && Ext.getCmp('colorMap')) {
                Ext.getCmp('colorMap').disable();
              }
              else if (Ext.getCmp('colorMap')) {
                Ext.getCmp('colorMap').enable();
              }
              mainStore.getAt(idx).set('settingsBaseStyle',rec.get('value'));
              mainStore.getAt(idx).commit();
              setCustomStyles(mainStore.getAt(idx));
            }
            ,beforerender : function() {
              baseStylesStore.filter('type',mainStore.getAt(idx).get('settingsBaseStyle').split('_')[0]);
            }
          }
        })
      )
    }
    if (mainStore.getAt(idx).get('settingsColorMap') != '') {
      height += 27;
      items.push(
        new Ext.form.ComboBox({
           fieldLabel     : 'Colormap<a href="javascript:Ext.getCmp(\'tooltip.' + id + '.colormap' + '\').show()"><img style="margin-left:2px;margin-bottom:2px" id="' + id + '.colormap' + '" src="img/info.png"></a>'
          ,id             : 'colorMap.' + id
          ,disabled       : mainStore.getAt(idx).get('settingsBaseStyle') == 'CURRENTS_STATIC_BLACK'
          ,store          : colorMapStore
          ,displayField   : 'name'
          ,valueField     : 'name'
          ,value          : mainStore.getAt(idx).get('settingsColorMap')
          ,editable       : false
          ,triggerAction  : 'all'
          ,mode           : 'local'
          ,width          : 130
          ,forceSelection : true
          ,listeners      : {
            afterrender : function() {
              new Ext.ToolTip({
                 id     : 'tooltip.' + id + '.colormap'
                ,target : id + '.colormap'
                ,html   : "Feature contrasts may become more obvious based on the selected colormap."
              });
            }
            ,select : function(comboBox,rec) {
              mainStore.getAt(idx).set('settingsColorMap',rec.get('name'));
              mainStore.getAt(idx).commit();
              setCustomStyles(mainStore.getAt(idx));
            }
          }
        })
      )
    }
    if (mainStore.getAt(idx).get('settingsMinMaxBounds') != '') {
      height += 27;
      var settingsParam = mainStore.getAt(idx).get('settingsParam').split(',');
      var settings = {};
      for (var i = 0; i < settingsParam.length; i++) {
        if (settingsParam[i] != '') {
          settings[settingsParam[i]] = guaranteeDefaultStyles[mainStore.getAt(idx).get('name')].split('-')[i];
        }
      }
      items.push(
        new Ext.slider.MultiSlider({
           fieldLabel : 'Min/max<a href="javascript:Ext.getCmp(\'tooltip.' + id + '.minMax' + '\').show()"><img style="margin-left:2px;margin-bottom:2px" id="' + id + '.minMax' + '" src="img/info.png"></a>'
          ,id       : 'minMax.' + id
          ,width    : 130
          ,minValue : mainStore.getAt(idx).get('settingsMinMaxBounds').split('-')[0]
          ,maxValue : mainStore.getAt(idx).get('settingsMinMaxBounds').split('-')[1]
          ,decimalPrecision : 1
          ,values   : [mainStore.getAt(idx).get('settingsMin'),mainStore.getAt(idx).get('settingsMax')]
          ,plugins  : new Ext.slider.Tip({
            getText : function(thumb) {
              return String.format('<b>{0}</b>', thumb.value);
            }
          })
          ,listeners : {
            afterrender : function() {
              new Ext.ToolTip({
                 id     : 'tooltip.' + id + '.minMax'
                ,target : id + '.minMax'
                ,html   : "Use the slider to adjust the layer's minimum and maximum values."
              });
            }
            ,change : function(slider) {
              mainStore.getAt(idx).set('settingsMin',slider.getValues()[0]);
              mainStore.getAt(idx).set('settingsMax',slider.getValues()[1]);
              mainStore.getAt(idx).commit();
              setCustomStyles(mainStore.getAt(idx));
            }
          }
        })
      )
    }
    if (mainStore.getAt(idx).get('settingsStriding') != '') {
      height += 27;
      items.push(
        new Ext.Slider({
           fieldLabel : 'Data density<a href="javascript:Ext.getCmp(\'tooltip.' + id + '.striding' + '\').show()"><img style="margin-left:2px;margin-bottom:2px" id="' + id + '.striding' + '" src="img/info.png"></a>'
          ,id       : 'striding.' + id
          ,width    : 130
          ,minValue : 0
          ,maxValue : stridingStore.getCount() - 1
          ,value    : stridingStore.find('param',mainStore.getAt(idx).get('settingsStriding'))
          ,plugins  : new Ext.slider.Tip({
            getText : function(thumb) {
              var pct = stridingStore.getAt(thumb.value).get('param');
              var s;
              if (thumb.value == 0) {
                s = 'sparsest';
              }
              else if (pct < 1) {
                s = 'sparser';
              }
              else if (pct == 1) {
                s = 'normal';
              }
              else if (thumb.value < stridingStore.getCount() - 1) {
                s = 'denser';
              }
              else if (thumb.value == stridingStore.getCount() - 1) {
                s = 'densest';
              }
              return String.format('<b>{0}</b>',s);
            }
          })
          ,listeners : {
            afterrender : function() {
              new Ext.ToolTip({
                 id     : 'tooltip.' + id + '.striding'
                ,target : id + '.striding'
                ,html   : "Adjust the space between vectors with the data density factor.  The impact of this value varies based on the zoom level."
              });
            }
            ,change : function(slider,val) {
              mainStore.getAt(idx).set('settingsStriding',stridingStore.getAt(val).get('param'));
              mainStore.getAt(idx).commit();
              setCustomStyles(mainStore.getAt(idx));
            }
          }
        })
      )
    }
    if (mainStore.getAt(idx).get('settingsTailMag') != '') {
      height += 27;
      items.push(
        new Ext.form.ComboBox({
           fieldLabel     : 'Tail magnitude<a href="javascript:Ext.getCmp(\'tooltip.' + id + '.tailMagnitude' + '\').show()"><img style="margin-left:2px;margin-bottom:2px" id="' + id + '.tailMagnitude' + '" src="img/info.png"></a>'
          ,id             : 'tailMag.' + id
          ,store          : tailMagStore
          ,displayField   : 'name'
          ,valueField     : 'name'
          ,value          : mainStore.getAt(idx).get('settingsTailMag')
          ,editable       : false
          ,triggerAction  : 'all'
          ,mode           : 'local'
          ,width          : 130
          ,forceSelection : true
          ,listeners      : {
            afterrender : function() {
              new Ext.ToolTip({
                 id     : 'tooltip.' + id + '.tailMagnitude'
                ,target : id + '.tailMagnitude'
                ,html   : "Choose whether or not the vector tail length will vary based on its magnitude.  The difference may be subtle in layers with small magnitude variability." 
              });
            }
            ,select : function(comboBox,rec) {
              mainStore.getAt(idx).set('settingsTailMag',rec.get('name'));
              mainStore.getAt(idx).commit();
              setCustomStyles(mainStore.getAt(idx));
            }
          }
        })
      )
    }
    if (mainStore.getAt(idx).get('settingsBarbLabel') != '') {
      height += 27;
      items.push(
        new Ext.form.ComboBox({
           fieldLabel     : 'Magnitude label<a href="javascript:Ext.getCmp(\'tooltip.' + id + '.magnitudeLabel' + '\').show()"><img style="margin-left:2px;margin-bottom:2px" id="' + id + '.magnitudeLabel' + '" src="img/info.png"></a>'
          ,id             : 'barbLabel.' + id
          ,store          : barbLabelStore
          ,displayField   : 'name'
          ,valueField     : 'name'
          ,value          : mainStore.getAt(idx).get('settingsBarbLabel')
          ,editable       : false
          ,triggerAction  : 'all'
          ,mode           : 'local'
          ,width          : 130
          ,forceSelection : true
          ,listeners      : {
            afterrender : function() {
              new Ext.ToolTip({
                 id     : 'tooltip.' + id + '.magnitudeLabel'
                ,target : id + '.magnitudeLabel'
                ,html   : "Choose whether or not a text label should be drawn by each vector to identify its magnitude."
              });
            }
            ,select : function(comboBox,rec) {
              mainStore.getAt(idx).set('settingsBarbLabel',rec.get('name'));
              mainStore.getAt(idx).commit();
              setCustomStyles(mainStore.getAt(idx));
            }
          }
        })
      )
    }

    activeSettingsWindows[layerName] = new Ext.Window({
       bodyStyle : 'background:white;padding:5'
      ,x         : pos.left
      ,y         : pos.top
      ,resizable : false
      ,width     : 270
      ,constrainHeader : true
      ,title     : mainStore.getAt(idx).get('displayName').split('||')[0] + ' :: settings'
      ,items     : [
         new Ext.FormPanel({buttonAlign : 'center',border : false,bodyStyle : 'background:transparent',width : 240,height : height + 35,labelWidth : 100,labelSeparator : '',items : items,buttons : [{text : 'Restore default settings',width : 150,handler : function() {restoreDefaultStyles(layerName,items,id)}}]})
      ]
      ,listeners : {hide : function() {
        activeSettingsWindows[layerName] = null;
      }}
    }).show();
  }
}

function setCustomStyles(rec) {
  var styles = [rec.get('settingsBaseStyle')];
  if (rec.get('settingsColorMap') != '') {
    styles.push(rec.get('settingsColorMap'));
    // record the action on google analytics
    pageTracker._trackEvent('setStyle','colorMap',rec.get('name'));
  }
  if (rec.get('settingsBarbLabel') != '') {
    styles.push(rec.get('settingsBarbLabel'));
    // record the action on google analytics
    pageTracker._trackEvent('setStyle','barbLabel',rec.get('name'));
  }
  if (rec.get('settingsStriding') != '') {
    styles.push(rec.get('settingsStriding'));
    // record the action on google analytics
    pageTracker._trackEvent('setStyle','striding',rec.get('name'));
  }
  if (rec.get('settingsTailMag') != '') {
    styles.push(rec.get('settingsTailMag'));
    // record the action on google analytics
    pageTracker._trackEvent('setStyle','tailMag',rec.get('name'));
  }
  if (rec.get('settingsMin') != '') {
    styles.push(rec.get('settingsMin'));
    // record the action on google analytics
    pageTracker._trackEvent('setStyle','minVal',rec.get('name'));
  }
  if (rec.get('settingsMax') != '') {
    styles.push(rec.get('settingsMax'));
    // record the action on google analytics
    pageTracker._trackEvent('setStyle','maxVal',rec.get('name'));
  }
  if (rec.get('settingsImageQuality') != '') {
    styles.push(rec.get('settingsImageQuality'));
    // record the action on google analytics
    pageTracker._trackEvent('setStyle','imageQuality',rec.get('name'));
  }
  map.getLayersByName(rec.get('name'))[0].mergeNewParams({STYLES : styles.join('-')});
}

function restoreDefaultStyles(l,items,id) {
  var rec = mainStore.getAt(mainStore.find('name',l));
  var settingsParam = rec.get('settingsParam').split(',');
  var settings = {};
  for (var i = 0; i < settingsParam.length; i++) {
    if (settingsParam[i] != '') {
      settings[settingsParam[i]] = guaranteeDefaultStyles[l].split('-')[i];
    }
  }
  for (var i = 0; i < items.length; i++) {
    var cmp = Ext.getCmp(items[i].id);
    if (items[i].id == 'opacity.' + id) {
      cmp.setValue(100);
    }
    else if (items[i].id == 'imageType.' + id) {
      cmp.setValue('png');
      cmp.fireEvent('select',cmp,new imageQualityStore.recordType({value : 'png'}));
    }
    else if (items[i].id == 'baseStyle.' + id) {
      cmp.setValue(settings['baseStyle']);
      cmp.fireEvent('select',cmp,new baseStylesStore.recordType({value : settings['baseStyle']}));
    }
    else if (items[i].id == 'colorMap.' + id) {
      cmp.setValue(settings['colorMap']);
      cmp.fireEvent('select',cmp,new colorMapStore.recordType({name : settings['colorMap']}));
    }
    else if (items[i].id == 'striding.' + id) {
      cmp.setValue(stridingStore.find('param',settings['striding']));
      cmp.fireEvent('change',cmp,stridingStore.find('param',settings['striding']));
    }
    else if (items[i].id == 'tailMag.' + id) {
      cmp.setValue(settings['tailMag']);
      cmp.fireEvent('select',cmp,new tailMagStore.recordType({name : settings['tailMag']}));
    }
    else if (items[i].id == 'barbLabel.' + id) {
      cmp.setValue(settings['barbLabel']);
      cmp.fireEvent('select',cmp,new barbLabelStore.recordType({name : settings['barbLabel']}));
    }
    else if (items[i].id == 'minMax.' + id) {
      cmp.setValue(0,settings['min']);
      cmp.setValue(1,settings['max']);
      cmp.fireEvent('change',cmp);
    }
  }
}

function setMapTime() {
  Ext.getCmp('mapTime').setText(dNow.getUTCFullYear() + '-' + String.leftPad(dNow.getUTCMonth() + 1,2,'0') + '-' + String.leftPad(dNow.getUTCDate(),2,'0') + ' ' + String.leftPad(dNow.getUTCHours(),2,'0') + ':00 UTC');
  var dStr = dNow.getUTCFullYear() + '-' + String.leftPad(dNow.getUTCMonth() + 1,2,'0') + '-' + String.leftPad(dNow.getUTCDate(),2,'0') + 'T' + String.leftPad(dNow.getUTCHours(),2,'0') + ':00';
  for (var i = 0; i < map.layers.length; i++) {
    // WMS layers only
    if (map.layers[i].DEFAULT_PARAMS) {
      map.layers[i].mergeNewParams({TIME : dStr});
      if (OpenLayers.Util.getParameters(map.layers[i].getFullRequestString({}))['COLORSCALERANGE']) {
        map.layers[i].mergeNewParams({COLORSCALERANGE : getColorScaleRange()});
      }
      // record the action on google analytics
      if (mainStore.find('name',map.layers[i].name) >= 0) {
        pageTracker._trackEvent('timeSlider',mainStore.getAt(mainStore.find('name',map.layers[i].name)).get('displayName'));
      }
    }
  }

  if (Ext.getCmp('datePicker')) {
    var dp = Ext.getCmp('datePicker');
    dp.suspendEvents();
    dp.setValue(new Date(dNow.getTime() + dNow.getTimezoneOffset() * 60000));
    dp.resumeEvents();
  }
}

function lineColor2VectorColor(l) {
  for (var i = 0; i < lineColors.length; i++) {
    if (lineColors[i][0] == l) {
      return lineColors[i][1];
    }
  }
  return lineColors[0][1];
}
