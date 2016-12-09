<?php
ini_set("display_errors", "yes");
error_reporting(E_ALL ^ E_NOTICE);
$time_start = microtime(true); 
$max_exec_time = ini_get('max_execution_time'); 

abstract class DeviceType
{
    const Desktop = 0;
    const Mobile  = 1;
}

abstract class MessateType
{
    const PairingRequest   = "preq";
    const PairingResponse  = "pres";
    const Data             = "data";
}

abstract class MessateState
{
    const Undelivered = 0;
    const Delivered  = 1;
}

class MyDB extends SQLite3
{
  private $msglifespan = 10; /* 10 sec max lifespan */
  function __construct()
  {
     $this->open('db/db.sqlite');
     $this->busyTimeout(1000); /* 1000ms busy timeout to avoid locking issues */
  }
  
  function addPairingRequest($uuid, $payload)
  {
      if ($this->addProxyData($uuid, DeviceType::Mobile, DeviceType::Desktop, MessateType::PairingRequest, $payload, 30))
          return true;
      return false;
  }
  
  function addPairingResponse($uuid, $payload)
  {
      if ($this->addProxyData($uuid, DeviceType::Desktop, DeviceType::Mobile, MessateType::PairingResponse, $payload, 30))
          return true;
      return false;
  }
  
  function addData($uuid, $from, $to, $payload)
  {
      if ($this->addProxyData($uuid, $from, $to, MessateType::Data, $payload, 30))
          return true;
      return false;
  }
 
  function addProxyData($uuid, $from, $to, $cmd, $payload, $addtime = 0)
  {
      $sql = "INSERT INTO proxydata (channelid, created, fromtype, totype, state, maxlifespanto, cmd, payload) VALUES ('".$this->escapeString($uuid)."', ".time().", ".intval($from).", ".intval($to).", ".MessateState::Undelivered.", ".(time()+$this->msglifespan+$addtime).",'".$this->escapeString($cmd)."', '".$this->escapeString($payload)."')";
      $ret = $this->exec($sql);
      
      addLogEntry("added to db: cid: ".$uuid.", maxlifespanto: ".($this->msglifespan+$addtime).", payload: ".$payload." , payload base64 decoded: ".base64_decode($payload));
      if ($ret)
          return true;
      return false;
  }
  function haveData($uuid, $deviceType)
  {
      $sql = "SELECT * FROM proxydata WHERE totype=".intval($deviceType)." AND state=".MessateState::Undelivered." AND maxlifespanto > ".time()." AND channelid = '".$this->escapeString($uuid)."' ORDER BY created ASC LIMIT 1";
      $ret = $this->query($sql);
      $data = array();
      while($row = $ret->fetchArray(SQLITE3_ASSOC) ){
          array_push($data, array("id" => $row['id'], "age" => (time() - $row['created']), "payload" => base64_decode($row['payload'])));
      }
      return $data;
  }
  function flagOffData($uuid, $deviceType, $dataArray)
  {
      foreach($dataArray as $element)
      {
          $sql = "UPDATE proxydata SET state=".MessateState::Delivered." WHERE id=".intval($element['id'])." AND channelid = '".$this->escapeString($uuid)."'";
          $ret = $this->exec($sql);          
      }
      return true;
  }
  function deleteData()
  {
      $sql = "DELETE FROM proxydata WHERE maxlifespanto <= ".time();
      $ret = $this->exec($sql);
      return true;
  }
}

function responseJSON($data = null)
{
    $status = "ok";
    $json = array("status" => $status, "data" => $data);
    echo json_encode($json);
}

function responseError($error)
{
    $status = "nok";
    $json = array("status" => $status, "error" => $error);
    echo json_encode($json);
}

function addLogEntry($text)
{
    $entry = date("Y-m-d | h:i:s")." ".$text.PHP_EOL;
    $myfile = file_put_contents('db/debug.log', $entry , FILE_APPEND);
}

$db = new MyDB();
if(!$db){
  echo responseError("database error (".$db->lastErrorMsg().")");
  exit(1);
}

$postdata = file_get_contents("php://input");
$INPUTDATA = array();
if (strlen($postdata) > 0)
{
    $tokens = explode("&", $postdata);
    $urlVars = array();
    foreach ($tokens as $token) {
        $pos = strpos($token, "=");
        $key = substr($token, 0, $pos);
        $value = substr($token,$pos+1);
        $INPUTDATA[$key] = $value;
    }
}
else
    $INPUTDATA = $_REQUEST;

if ($INPUTDATA['c'] == "data")
{
    /* post pairing response */
    $uuid = htmlentities(strip_tags($INPUTDATA['uuid']));
    $dtype = htmlentities(strip_tags($INPUTDATA['dt'])); /* device type*/
    $pl = base64_encode($INPUTDATA['pl']);
    
    $toType = DeviceType::Mobile;
    if ($dtype == DeviceType::Mobile)
        $toType = DeviceType::Desktop;

    if ($db->addData($uuid, $dtype, $toType, $pl))
    {
        responseJSON();
        if (rand(0, 10) == 0)
            $db->deleteData();
    }
    else
    {
        responseError();
    }
}
else if ($INPUTDATA['c'] == "gd")
{
    /* get data */
    $uuid  = htmlentities(strip_tags($INPUTDATA['uuid'])); /* channel UUID */
    $dtype = htmlentities(strip_tags($INPUTDATA['dt'])); /* device type*/
    $datafound = false;
    while(microtime(true) - $time_start < 10.0)
    {
        $dataArray = $db->haveData($uuid, $dtype);
        if (count($dataArray) > 0)
        {
            $array = $db->flagOffData($uuid, $dtype, $dataArray);
            $datafound = true;
            responseJSON($dataArray);
            break;
        }
        else
            sleep(1);
    }
    if (!$datafound)
        responseJSON();
}
else if ($INPUTDATA['c'] == "dd")
{
    $db->deleteData();
    responseJSON();
}
else 
{
    responseError("command not found");
}

$db->close();
?>
