<?php

function createsessions($username,$password)
{
    $_SESSION["gdusername"] = $username;
    $_SESSION["gdpassword"] = $password; // md5($password);
}

function clearsessionscookies()
{
    unset($_SESSION['gdusername']);
    unset($_SESSION['gdpassword']);
    
    session_unset();    
    session_destroy(); 

    setcookie ("gdusername", "",time()-60*60*24*100, "/");
    setcookie ("gdpassword", "",time()-60*60*24*100, "/");
}

function confirmUser($username,$password,$url)
{
    // $md5pass = md5($password); // Not needed any more as pointed by ted_chou12
    /* Validate from the database but as for now just demo username and password */

    $xml = simplexml_load_file("$url&username=$username&pw=$password");
    if (array_key_exists('Error',$xml)) {
      setcookie("failedLogin",'login');
      return false;
    }
    else {
      setcookie("failedLogin");
      setcookie("softwareKey"   ,$xml->{'softwareKey'});
      setcookie("bounds"        ,$xml->{'bounds'});
      setcookie("defaultLayers" ,$xml->{'defaultLayers'});
      setcookie("bannerImg"     ,$xml->{'bannerImg'});
      setcookie("bannerURL"     ,$xml->{'bannerURL'});
      setcookie("bannerTitle"   ,$xml->{'bannerTitle'});
      setcookie("userName"      ,$username);
      if (isset($xml->{'expirationDate'})) {
        $t = new DateTime(sprintf("%s",$xml->{'expirationDate'}));
        $expirationDate = $t->format('U');
      }
      if ($expirationDate < time()) {
        setcookie("failedLogin",'subscription');
        return false;
      }
      setcookie("expirationDate",$expirationDate);
      return true;
    }
}

function checkLoggedin($getUserInfo)
{
    if(isset($_SESSION['gdusername']) AND isset($_SESSION['gdpassword']))
        return true;
    elseif(isset($_COOKIE['gdusername']) && isset($_COOKIE['gdpassword']))
    {
        if(confirmUser($_COOKIE['gdusername'],$_COOKIE['gdpassword'],$getUserInfo))
        {
            createsessions($_COOKIE['gdusername'],$_COOKIE['gdpassword']);
            return true;
        }
        else
        {
            if ($_COOKIE['failedLogin']) {
              clearsessionscookies();
              setcookie("failedLogin",true);
            }
            else {
              clearsessionscookies();
            }
            return false;
        }
    }
    else
        return false;
}
?> 
