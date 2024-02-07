<?php
if(!isset($_GET['lock']) || $_GET['lock']!='eCZNZTlId2dRcWpWJDln'){exit();}

//echo phpinfo();

//$dbBP = connectCMDB("127.0.0.1","dbarney_webtools","dbarney_webtools","PASSWORD","MYSQL");

$apiurl = "https://api.carat-platforms.fiserv.com/";
$apiusername = 'USER_NAME';
$apipassword = 'PASSWORD';

if (isset($_REQUEST['testingenv'])){
  
    $apiurl = "https://uat-fd-pfac-c-api.technologi.co.uk/";
$apiusername = 'USER_NAME';
$apipassword = 'PASSWORD';
    
    
}
$dbhost = 'localhost';
$dbuser = 'USER_NAME';
$dbpass = 'PASSWORD';
$dbname = 'dbarney_webtools';

$db = new db($dbhost, $dbuser, $dbpass, $dbname);


$access_token = gettoken($db);

//echo get_hold_balance($db,'########');
echo run_merchants($db);

function run_merchants($db)
{

    $starttime = date('Y-m-d H:i:s');
    if (isset($_REQUEST['testingenv'])){
        $runtype = "testenv";
        if (isset($_REQUEST['fund']) && $_REQUEST['fund']=='########'){$runtype = "testenvfund";}
        $extramerchsql=" and merchant_name like 'Test%' ";
    
    }else{$extramerchsql=" and merchant_name not like 'Test%' ";
          $runtype = "prodcheck";
          if (isset($_REQUEST['fund']) && $_REQUEST['fund']=='########'){$runtype = "prodfund";}
          
 
         }
    
    $inssql= "INSERT INTO run_log (`runtype`, `starttime`) VALUES ('{$runtype}', '{$starttime}')";  
    $db->query($inssql);  
    $runlogid = $db->lastInsertID();
    
    //get res of all active merchants
    $merchants = $db->query("SELECT * from merchants where active = 1 $extramerchsql order by added asc")->fetchAll();
    echo "<pre>";echo var_dump($merchants);echo "</pre>";
   
    $runcount=0;
    $merchcount = count($merchants);
    $fundcount = 0;
    $zerofundcount = 0;
    
    foreach ($merchants as $merchant){
        
        $ballogid=0;
        $fundlogid=0;
        $balafterlogid=0;
            
        list($merchant_acct_balance,$ballogid) = get_hold_balance($db,$merchant['mid'],$runlogid);
       
        if ($merchant_acct_balance=='error'){$merchant_acct_balance='0';}
        
        if ($merchant_acct_balance!='error'){
        if ($merchant_acct_balance>0){
            $runcount++;
            
            
            if (isset($_REQUEST['testingenv'])){
                
                $merchant_acct_balance=round($merchant_acct_balance/600,2);
              //  $merchant_acct_balance=10.40;
            
            }
            $merchant_dollars = round(($merchant_acct_balance  / (1 + $merchant['disc_rate'])),2);
            
            /// divide by 1.395
            
            $disc_rate_dollars = round($merchant_acct_balance - ($merchant_acct_balance  / (1 + $merchant['disc_rate'])),2);
            
            echo "Merchant - {$merchant['mid']} {$merchant['merchant_name']} {$merchant['location_name']}<br>";
            echo "Acct Bal - $merchant_acct_balance <br>";
            echo "Merchant Bal - $merchant_dollars <br>";
            echo "Disc Rate Bal - $disc_rate_dollars <br>";
            echo $ballogid;
            
            if (isset($_REQUEST['fund']) && $_REQUEST['fund']=='RsS328K5bvFWeHLpc6fj4gPdAMQNwyqJ'){
                
                if ($merchant['mid']=='140920210021'){$merchant['mid']='1234';}
                list($fundingres,$fundlogid) = funding($db,$merchant_acct_balance,$merchant_dollars,$disc_rate_dollars,$merchant['mid'],$runlogid);$fundcount++;
            
            }
            
            
            
            list($merchant_acct_balance_after,$balafterlogid) = get_hold_balance($db,$merchant['mid'],$runlogid);
            
            if ($merchant_acct_balance_after=='error'){$merchant_acct_balance_after=$merchant_acct_balance;}
            
            
            $errorres = $db->query("SELECT count(*) as errorcount from api_log where id in ($ballogid,$fundlogid,$balafterlogid) and haserror = 1")->fetchAll();
            
            echo "<pre>";echo var_dump($errorres);echo "</pre>";
            
            $errorcount = $errorres[0]['errorcount'];
            
            $logsql = "INSERT INTO fund_log (mid, fund_date, receipts, deposits, fees,startbal_logid,fund_logid,endbal_logid,errorcount,endbal,runid) VALUES ({$merchant['mid']}, '". date('Y-m-d H:i:s') . "', $merchant_acct_balance, $merchant_dollars, $disc_rate_dollars,$ballogid,$fundlogid,$balafterlogid,$errorcount,$merchant_acct_balance_after,$runlogid)";
            
            echo $logsql;
            
            $insert = $db->query($logsql); 
            $finallogid = $db->lastInsertID();
            
            if ($fundlogid>0){
            $procsql = "UPDATE merchants set processed = '". date('Y-m-d H:i:s') . "' where mid = {$merchant['mid']}";
            $db->query($procsql);
            }
            //echo $logsql;
            //$runcount++;
            
        }
        }
        else{echo "no money :(";$zerofundcount++;}
        
         //run through res and get balance
          //then figure out both buckets of money
    //log info to funding table
    //run funding api to put money in accounts
    //get balance again and write to fundinglog
    //update merchant table with last sucesful run date if no errors and acctbalance is now zero but not if account bal was zero in first check  
    
    
    }

    $inssql= "update run_log set endtime = '" . date('Y-m-d H:i:s') . "',activecount=$merchcount,runcount=$runcount,fundcount=$fundcount,zerofundcount=$zerofundcount WHERE id = $runlogid ";  
    $db->query($inssql);  
    //$runlogid = $db->lastInsertID();
    
    
    
    
}


function funding($db,$merchant_acct_balance,$merchant_dollars,$disc_rate_dollars,$merchant_id,$runlogid)
{
    
    global $access_token;
    list($apires,$logid) = api_call($db,'funding/instruction',array('merchant_id'=>$merchant_id,'currency'=>'USD','total_amount'=>$merchant_acct_balance,"accounts"=>array(array('account_type'=>'REVENUE_ACCOUNT','amount'=>$merchant_dollars),array('account_type'=>'FEE_ACCOUNT','amount'=>$disc_rate_dollars))),$access_token,$runlogid);
    
    
     $data = json_decode($apires,TRUE);
    
    if (isset($data['error'])){return array('error',$logid);}else{return array($data['account']['balance'],$logid);}
    
    
    
    
}

function get_hold_balance($db,$merchant_id,$runlogid)
{
    global $access_token;
    list($apires,$logid) = api_call($db,'account/balance',array('merchant_id'=>$merchant_id,'currency'=>'USD',"account_type"=>"INSTRUCTIONAL_HOLD_ACCOUNT"),$access_token,$runlogid);
    
    
     $data = json_decode($apires,TRUE);
    
    if (isset($data['error'])){return array('error',$logid);}else{return array($data['account']['balance'],$logid);}
    
    
}
    
    
   // api_call($db,'account/balance',array('merchant_id'=>'526269716886','currency'=>'USD',"account_type"=>"INSTRUCTIONAL_HOLD_ACCOUNT"),$access_token);

function api_call($db,$method,$postarr,$token,$runlogid){
    global $apiurl;
   // echo "https://fd-pfac-api.technologi.co.uk/". $method . "'";
  //  echo "<pre>";echo var_dump($postarr);echo "</pre>";
    
    
    $curl = curl_init();
foreach($postarr as $postkey=>$postval){
    $curlpostarr[]='"' . $postkey . '": "' . $postval . '"';}
    
    $poststring = '{' . implode(',',$curlpostarr) . '}';
    
    $poststring2 = json_encode($postarr);
   // echo $poststring2."<br>";
 //   echo $poststring;
    
    $poststring = $poststring2;
curl_setopt_array($curl, array(
     CURLOPT_VERBOSE=> true,
  CURLOPT_URL => $apiurl . $method ,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'POST',
  CURLOPT_POSTFIELDS => $poststring,
  CURLOPT_HTTPHEADER => array(
    'Content-Type: application/json',
    'Accept-Language: en;q=0.8,es-cl;q=0.5,zh-cn;q=0.3',
    'Authorization: Bearer ' . $token
  ),
));

$response = curl_exec($curl);

curl_close($curl);
    
    
    $curlurl = $apiurl . $method;
    $curlrequeststr = var_export($postarr, true);
    
    $data = json_decode($response,TRUE);
    
    $curlresponsestr = var_export($data,TRUE);
    //echo "X".$curlresponsestr;
    if (isset($data['error'])){$haserror='1';}else{$haserror='0';}
    
   $insert = $db->query("INSERT INTO api_log (url,request,response,haserror,runid) VALUES ('{$curlurl}','" . addslashes( $curlrequeststr) ."','" . addslashes( $curlresponsestr) ."',{$haserror},$runlogid)"); 
    
    //echo "INSERT INTO api_log (url,request,response,haserror,runid) VALUES ('{$curlurl}','" . addslashes( $curlrequeststr) ."','" . addslashes( $curlresponsestr) ."',{$haserror},$runlogid)";
    $logid = $db->lastInsertID();
    
    
return array($response,$logid);
    
    
    
}


function gettoken($db){
$newtokenflag = 1;
if(check_token_ok($db)){if(test_api_call(get_active_token($db))){$newtokenflag = 0;}}
    
    
    //echo $newtokenflag;exit();
if($newtokenflag == 1){
    
    global $apiusername;
    global $apipassword;
    global $apiurl;
    
$username = $apiusername;
    //Your password.
$password = $apipassword;

//Initiate cURL.
$ch = curl_init($url);
 
//Specify the username and password using the CURLOPT_USERPWD option.

$curl = curl_init();
    
  //  curl_setopt($ch, CURLOPT_USERPWD=> $username . ":" . $password);


curl_setopt_array($curl, array(
  CURLOPT_URL => $apiurl . 'oauth/token',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    //CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
   // CURLOPT_USERPWD=> $username . ":" . $password,
  CURLOPT_CUSTOMREQUEST => 'POST',
  CURLOPT_POSTFIELDS =>'{
	"grant_type":"client_credentials"
}',
  CURLOPT_HTTPHEADER => array(
    'Content-Type: application/json',
    'Accept-Language: en;q=0.8,es-cl;q=0.5,zh-cn;q=0.3',
    'Authorization: Basic ' . base64_encode($username . ':' . $password) 
  ),
));
$curlurl = $apiurl.'/oauth/token';
    $curlrequeststr = var_export(array(
    'Content-Type: application/json',
    'Accept-Language: en;q=0.8,es-cl;q=0.5,zh-cn;q=0.3',
    'Authorization: Basic ' . base64_encode($username . ':' . $password) 
  ), true);
    
$response = curl_exec($curl);

curl_close($curl);
//e//cho $response;
  
$data = json_decode($response,TRUE);
    
    $curlresponsestr = var_export($data,TRUE);
    //echo "X".$curlresponsestr;
    if (isset($data['error'])){$haserror='1';}else{$haserror='0';}
    
   $insert = $db->query("INSERT INTO api_log (url,request,response,haserror) VALUES ('{$curlurl}','" . addslashes(str_replace('QVBJLUJDN0Q1NkM1OjNjYWQwNDU0NDkxNzkwMjY2ZWQ2MmJkMDk0MzQxM2NjYmJiODc0OTRhZTEyYmQzNjE1ZjZlMWY4N2Q1NWFmMGU=','APIPASSWORDHIDDEN',$curlrequeststr)) ."','" . addslashes($curlresponsestr) ."',{$haserror})"); 

   // echo "<pre>";echo var_dump($data);echo "</pre>";
  if (!isset($data['error'])){  
$insert = $db->query("INSERT INTO tokens (token) VALUES ('{$data['access_token']}')");
  }
  

return $data['access_token'];
}
    
return get_active_token($db);
//echo $insert->affectedRows();
    
    //echo $username . ":" . $password;
}


function get_active_token($db)
{
  
    $tokens = $db->query('SELECT * from tokens where tokenactive = 1 order by tokenstartdate desc ')->fetchAll();
    
    return $tokens[0]['token'];
    
    
}

function check_token_ok($db)
{
  //check if active token, if not return false
    $tokens = $db->query('SELECT * from tokens where tokenactive = 1 order by tokenstartdate desc')->fetchAll();
    if (count($tokens)==0){return false;}
    return true;
    //if active token, use token to call GET ACCOUNT BALANCE, if not $array['result'] = "SUCCESS" THEN return false
    
    //if $array['result'] = "SUCCESS" then return true
    
}

function test_api_call($token)
{
  global $apiurl;
    $curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => $apiurl.'/account/balance',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'POST',
  CURLOPT_POSTFIELDS =>'{
    "merchant_id": "526269716886",
    "currency": "USD",
    "account_type": "INSTRUCTIONAL_HOLD_ACCOUNT"

}
',
  CURLOPT_HTTPHEADER => array(
    'Content-Type: application/json',
    'Accept-Language: en;q=0.8,es-cl;q=0.5,zh-cn;q=0.3',
    'Authorization: Bearer ' . $token
  ),
));

$response = curl_exec($curl);
    
    $data = json_decode($response,TRUE);
    
 

curl_close($curl);

       if($data['result']=='SUCCESS'){return true;}else{return false;}
    
    
}



class db {

    protected $connection;
	protected $query;
    protected $show_errors = TRUE;
    protected $query_closed = TRUE;
	public $query_count = 0;

	public function __construct($dbhost = 'localhost', $dbuser = 'root', $dbpass = '', $dbname = '', $charset = 'utf8') {
		$this->connection = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
		if ($this->connection->connect_error) {
			$this->error('Failed to connect to MySQL - ' . $this->connection->connect_error);
		}
		$this->connection->set_charset($charset);
	}

    public function query($query) {
        if (!$this->query_closed) {
            $this->query->close();
        }
		if ($this->query = $this->connection->prepare($query)) {
            if (func_num_args() > 1) {
                $x = func_get_args();
                $args = array_slice($x, 1);
				$types = '';
                $args_ref = array();
                foreach ($args as $k => &$arg) {
					if (is_array($args[$k])) {
						foreach ($args[$k] as $j => &$a) {
							$types .= $this->_gettype($args[$k][$j]);
							$args_ref[] = &$a;
						}
					} else {
	                	$types .= $this->_gettype($args[$k]);
	                    $args_ref[] = &$arg;
					}
                }
				array_unshift($args_ref, $types);
                call_user_func_array(array($this->query, 'bind_param'), $args_ref);
            }
            $this->query->execute();
           	if ($this->query->errno) {
				$this->error('Unable to process MySQL query (check your params) - ' . $this->query->error);
           	}
            $this->query_closed = FALSE;
			$this->query_count++;
        } else {
            $this->error('Unable to prepare MySQL statement (check your syntax) - ' . $this->connection->error);
        }
		return $this;
    }


	public function fetchAll($callback = null) {
	    $params = array();
        $row = array();
	    $meta = $this->query->result_metadata();
	    while ($field = $meta->fetch_field()) {
	        $params[] = &$row[$field->name];
	    }
	    call_user_func_array(array($this->query, 'bind_result'), $params);
        $result = array();
        while ($this->query->fetch()) {
            $r = array();
            foreach ($row as $key => $val) {
                $r[$key] = $val;
            }
            if ($callback != null && is_callable($callback)) {
                $value = call_user_func($callback, $r);
                if ($value == 'break') break;
            } else {
                $result[] = $r;
            }
        }
        $this->query->close();
        $this->query_closed = TRUE;
		return $result;
	}

	public function fetchArray() {
	    $params = array();
        $row = array();
	    $meta = $this->query->result_metadata();
	    while ($field = $meta->fetch_field()) {
	        $params[] = &$row[$field->name];
	    }
	    call_user_func_array(array($this->query, 'bind_result'), $params);
        $result = array();
		while ($this->query->fetch()) {
			foreach ($row as $key => $val) {
				$result[$key] = $val;
			}
		}
        $this->query->close();
        $this->query_closed = TRUE;
		return $result;
	}

	public function close() {
		return $this->connection->close();
	}

    public function numRows() {
		$this->query->store_result();
		return $this->query->num_rows;
	}

	public function affectedRows() {
		return $this->query->affected_rows;
	}

    public function lastInsertID() {
    	return $this->connection->insert_id;
    }

    public function error($error) {
        if ($this->show_errors) {
            exit($error);
        }
    }

	private function _gettype($var) {
	    if (is_string($var)) return 's';
	    if (is_float($var)) return 'd';
	    if (is_int($var)) return 'i';
	    return 'b';
	}

}

?>