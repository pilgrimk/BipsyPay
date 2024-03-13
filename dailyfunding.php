<?php
// BEGIN MAIN PHP script ---------------------------------------------
echo "Running dailyfunding... <br>";

// Check if the script is being run from the command line (CLI)
if (php_sapi_name() === 'cli') {
    // Check if command-line arguments are provided
    if (isset($argv[1])) {
        parse_str($argv[1], $arg);
        // Now $arg contains the parsed arguments
        // var_dump($arg);
    } else {
        "No command line parameters found. <br>";
    }
} else {
    // If not running from the command line, assume it's a web request
    // Parse query string parameters
    $query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
    if ($query) {
        parse_str($query, $arg);
        // Now $arg contains the parsed query string parameters
        // var_dump($arg);
    } else {
        echo "No query string parameters found. <br>";
    }
}

// GLOBAL variable declarations
$apiurl = "https://api.carat-platforms.fiserv.com/";
$apiusername = 'USER_NAME';
$apipassword = 'PASSWORD';

$dbhost = 'localhost';
$dbuser = 'USER_NAME';
$dbpass = 'PASSWORD';
$dbname = 'dbarney_webtools';

// set these variables based upon passed arguments
$next_day_funding = (isset($arg['NDF']) && $arg['NDF'] == '1');
$runfunding = (isset($arg['FUND']) && $arg['FUND'] == '1');

// Testing ******************************
// $next_day_funding = false; 
// $runfunding = false;            // set this to TRUE only when ready to actually FUND !!
// **************************************

// account types
$REVENUE_ACCOUNT = 'REVENUE_ACCOUNT';
$FEE_ACCOUNT = 'FEE_ACCOUNT';
$HOLD_ACCOUNT = 'HOLD_ACCOUNT';
$CHARGEBACK_ACCOUNT = 'CHARGEBACK_ACCOUNT';
$CHARGEBACK_FEE_AMOUNT = 25.00;

$db = new db($dbhost, $dbuser, $dbpass, $dbname);
$access_token = gettoken($db);
// echo "main, access_token: $access_token" . "<br>";

echo run_merchants($db);

// END MAIN PHP script -----------------------------------------------
// *******************************************************************


// BEGIN function and class declarations -----------------------------
function run_merchants($db)
{
    global $next_day_funding;
    global $runfunding;

    $starttime = date('Y-m-d H:i:s');
    if (isset($_REQUEST['testingenv'])) {
        $runtype = "testenv";
        if ($runfunding) {
            $runtype = "testenvfund";
        }
        $extramerchsql = " and merchant_name like 'Test%' ";
    } else {
        $extramerchsql = " and merchant_name not like 'Test%' ";
        $runtype = "prodcheck";
        if ($runfunding) {
            $runtype = "prodfund";
        }
    }

    $inssql = "INSERT INTO run_log (`runtype`, `starttime`) VALUES ('{$runtype}', '{$starttime}')";
    $db->query($inssql);
    $runlogid = $db->lastInsertID();

    // check NEXT_DAY_FUNDING flag
    if ($next_day_funding) {
        // get res of all active, next_day_funding merchants
        $merchants = $db->query("SELECT * from merchants where active = 1 and next_day_funding = 1 $extramerchsql order by added asc")->fetchAll();
    } else {
        // get res of all active merchants
        $merchants = $db->query("SELECT * from merchants where active = 1 $extramerchsql order by added asc")->fetchAll();
    };

    echo "<pre>";echo var_dump($merchants);echo "</pre>";

    $runcount = 0;
    $merchcount = count($merchants);
    $fundcount = 0;
    $zerofundcount = 0;

    foreach ($merchants as $merchant) {

        //run through res and get balance
        //then figure out both buckets of money
        //log info to funding table
        //run funding api to put money in accounts
        //get balance again and write to fundinglog
        //update merchant table with last sucesful run date 
        //if no errors and acctbalance is now zero but not 
        //if account bal was zero in first check  

        $merchant_mid = $merchant['mid'];
        $ballogid = 0;
        $fundlogid = 0;
        $balafterlogid = 0;
        $reserve_balance = 0;
        $reserve_rate_dollars = 0;
        $chargeback_amount = 0;

        list($merchant_acct_balance, $ballogid) = get_account_balance($db, $merchant_mid, $runlogid);

        if ($merchant_acct_balance == 'error') {
            $merchant_acct_balance = '0';
        }

        $chargeback_amount = get_chargeback_balance($db, $merchant_mid, $runlogid);

        // Testing ******************************
        // if ($merchant_mid == '526269716886') {
        //     $merchant_acct_balance = 1000;
        //     $chargeback_amount = 400;
        // }

        if ($merchant_acct_balance != 'error') {
            if ($merchant['reserve_rate'] > 0) {
                $reserve_balance = get_reserve_balance($db, $merchant_mid, $runlogid);

                // Testing ******************************
                // if ($merchant_mid == '526269716886') {
                //     $reserve_balance = 700;
                // }
            }

            echo "Merchant: {$merchant_mid} - {$merchant['merchant_name']}, acct balance: {$merchant_acct_balance}, resv balance: {$reserve_balance}, resv cap: {$merchant['reserve_cap']}, chargeback amount: {$chargeback_amount} <br>";

            if ($merchant_acct_balance > 0) {
                $runcount++;

                list($merchant_dollars, $disc_rate_dollars, $reserve_rate_dollars, $chargeback_amount) = calculate_funding_amounts(
                    $merchant_acct_balance,
                    $merchant['disc_rate'],
                    $merchant['surcharge'],
                    $merchant['recon_type'],
                    $merchant['reserve_rate'],
                    $merchant['reserve_cap'],
                    $reserve_balance,
                    $chargeback_amount
                );

                $fund_amounts = build_fund_amounts(
                    $merchant_dollars,
                    $disc_rate_dollars,
                    $reserve_rate_dollars,
                    $chargeback_amount,
                    $merchant['recon_type']
                );

                // echo "Merchant - {$merchant_mid} {$merchant['merchant_name']} {$merchant['location_name']}<br>";
                // echo "Reconcile Type - {$merchant['recon_type']}, Surcharge - {$merchant['surcharge']}, Next Day Funding - {$merchant['next_day_funding']}<br>";
                // echo "Acct Bal - {$merchant_acct_balance} <br>";
                // echo "Dics Rate - {$merchant['disc_rate']} <br>";
                // echo "Merchant Bal - {$merchant_dollars} <br>";
                // echo "Disc Rate Bal - {$disc_rate_dollars} <br>";
                // echo "Reserve Rate - {$merchant['reserve_rate']}, Reserve Cap - {$merchant['reserve_cap']}, Reserve Balance - {$reserve_balance} <br>";
                // echo "Log ID - {$ballogid} <br>";

                if ($runfunding && (!is_null($fund_amounts))) {
                    list($fundingres, $fundlogid) = do_funding($db, $merchant_acct_balance, $fund_amounts, $merchant_mid, $runlogid);
                    $fundcount++;
                }

                list($merchant_acct_balance_after, $balafterlogid) = get_account_balance($db, $merchant_mid, $runlogid);

                if ($merchant_acct_balance_after == 'error') {
                    $merchant_acct_balance_after = $merchant_acct_balance;
                }

                $errorres = $db->query("SELECT count(*) as errorcount from api_log where id in ($ballogid,$fundlogid,$balafterlogid) and haserror = 1")->fetchAll();
                $errorcount = $errorres[0]['errorcount'];
                if ($errorcount > 0) {
                    echo "Count API_LOG errors logged: {$errorcount} for ballogid: {$ballogid}, fundlogid: {$fundlogid}, balafterlogid: {$balafterlogid} <br>";
                }

                $logsql = "INSERT INTO fund_log (mid,fund_date,receipts,deposits,fees,reserves,chargebacks,startbal_logid,fund_logid,endbal_logid,errorcount,endbal,runid) VALUES ({$merchant_mid}, '" . date('Y-m-d H:i:s') . "', $merchant_acct_balance, $merchant_dollars, $disc_rate_dollars,$reserve_rate_dollars,$chargeback_amount,$ballogid,$fundlogid,$balafterlogid,$errorcount,$merchant_acct_balance_after,$runlogid)";
                // echo $logsql . "<br>";

                $insert = $db->query($logsql);
                // echo "<pre>";echo var_dump($insert);echo "</pre>";

                $finallogid = $db->lastInsertID();

                if ($fundlogid > 0) {
                    $procsql = "UPDATE merchants set processed = '" . date('Y-m-d H:i:s') . "' where mid = {$merchant_mid}";
                    $db->query($procsql);
                }
            }
        } else {
            echo "no money :(";
            $zerofundcount++;
        }
    }

    $inssql = "update run_log set endtime = '" . date('Y-m-d H:i:s') . "',activecount=$merchcount,runcount=$runcount,fundcount=$fundcount,zerofundcount=$zerofundcount WHERE id = $runlogid ";
    $db->query($inssql);
}

function do_funding(
    $db,
    $merchant_acct_balance,
    $funding_amounts,
    $merchant_id,
    $runlogid
) {
    global $access_token;
    $accounts_array = null;
    $apires = null;
    $logid = 0;

    $accounts_array = build_funding_accounts($funding_amounts);
    if (!is_null($accounts_array)) {
        $postarr = array('merchant_id' => $merchant_id, 'currency' => 'USD', 'total_amount' => $merchant_acct_balance, "accounts" => $accounts_array);
        list($apires, $logid) = api_call($db, 'funding/instruction', $postarr, $access_token, $runlogid);
    }

    if (is_null($apires)) {
        $data = null;
    } else {
        $data = json_decode($apires, TRUE);
    }

    if (is_null($data)) {
        return array('error', $logid);
    } elseif (isset($data['error'])) {
        return array('error', $logid);
    } else {
        return array($data['account']['balance'], $logid);
    }
}

function get_account_balance($db, $merchant_id, $runlogid)
{
    global $access_token;
    $postarr = array('merchant_id' => $merchant_id, 'currency' => 'USD', "account_type" => "INSTRUCTIONAL_HOLD_ACCOUNT");
    list($apires, $logid) = api_call($db, 'account/balance', $postarr, $access_token, $runlogid);

    if (is_null($apires)) {
        $data = null;
    } else {
        $data = json_decode($apires, TRUE);
    }

    if (is_null($data) || isset($data['error'])) {
        return array('error', $logid);
    } else {
        return array($data['account']['balance'], $logid);
    }
}

function get_chargeback_balance($db, $merchant_id, $runlogid)
{
    global $access_token;
    $postarr = array('merchant_id' => $merchant_id, 'currency' => 'USD', "account_type" => "CHARGEBACK_ACCOUNT");
    list($apires, $logid) = api_call($db, 'account/balance', $postarr, $access_token, $runlogid);

    if (is_null($apires)) {
        $data = null;
    } else {
        $data = json_decode($apires, TRUE);
        // echo "<pre>";echo var_dump($data);echo "</pre>";
    }

    if (is_null($data) || isset($data['error'])) {
        return 0;
    } else {
        return $data['account']['balance'];
    }
}

function get_reserve_balance($db, $merchant_id, $runlogid)
{
    global $access_token;
    $postarr = array('merchant_id' => $merchant_id, 'currency' => 'USD', "account_type" => "HOLD_ACCOUNT");
    list($apires, $logid) = api_call($db, 'account/balance', $postarr, $access_token, $runlogid);

    if (is_null($apires)) {
        $data = null;
    } else {
        $data = json_decode($apires, TRUE);
        // echo "<pre>";echo var_dump($data);echo "</pre>";
    }

    if (is_null($data) || isset($data['error'])) {
        return 0;
    } else {
        return $data['account']['balance'];
    }
}

function calculate_funding_amounts(
    $merchant_acct_balance,
    $disc_rate,
    $surcharge,
    $recon_type,
    $reserve_rate,
    $reserve_cap,
    $reserve_balance,
    $chargeback_amount
) {
    global $CHARGEBACK_FEE_AMOUNT; 
    $merchant_dollars = $merchant_acct_balance;
    $disc_rate_dollars = 0;
    $reserve_rate_dollars = 0;

    // calculate surcharge amount
    if ($recon_type == 1) {
        // do NOT apply surcharge, 
        $disc_rate_dollars = 0;
    } elseif ($surcharge == 0) {
        // do NOT apply surcharge, apply flat rate
        $merchant_dollars = round(($merchant_acct_balance  -  ($merchant_acct_balance  * $disc_rate)), 2);
        // calculate the flat rate
        $disc_rate_dollars = round(($merchant_acct_balance * $disc_rate), 2);
    } else {
        // apply surcharge; this follows original functionality
        $merchant_dollars = round(($merchant_acct_balance  / (1 + $disc_rate)), 2);
        // divide by DISCOUNT_RATE
        $disc_rate_dollars = round($merchant_acct_balance - ($merchant_acct_balance  / (1 + $disc_rate)), 2);
        // echo "acct bal: {$merchant_acct_balance}, calc: ({$merchant_acct_balance}  / (1 + {$disc_rate})) <br>";
    }

    // calculate reserve amount
    if ($reserve_rate > 0) {
        // echo "Reserve Rate: {$reserve_rate}, Reserve Cap: {$reserve_cap}, Reserve Balance: {$reserve_balance} <br>";

        // divide by RESERVE_RATE
        $reserve_rate_dollars = round(($merchant_acct_balance * $reserve_rate), 2);

        // if there's a current RESERVE_BALANCE make sure we account for the amount
        if ($reserve_balance > 0) {
            if ($reserve_balance >= $reserve_cap) {
                // we've met the reserve cap
                $reserve_rate_dollars = 0;
            } elseif ($reserve_rate_dollars > ($reserve_cap - $reserve_balance)) {
                $reserve_rate_dollars = $reserve_cap - $reserve_balance;
            }
        }

        // apply the reserve rate to the running balance
        $merchant_dollars = round(($merchant_dollars - $reserve_rate_dollars), 2);
    }

    // CHARGEBACK is a big hammer, applied even if the dollar amount goes negative !!
    if ($chargeback_amount > 0) {
        // first apply CHARGEBACK fees
        $disc_rate_dollars = round(($disc_rate_dollars + $CHARGEBACK_FEE_AMOUNT), 2);
        $merchant_dollars = round(($merchant_dollars - $CHARGEBACK_FEE_AMOUNT), 2);

        $merchant_dollars = round(($merchant_dollars - $chargeback_amount), 2);
    }

    $result = array($merchant_dollars, $disc_rate_dollars, $reserve_rate_dollars, $chargeback_amount);
    // echo "<pre>";echo var_dump($result);echo "</pre>";

    return $result;
}

function build_fund_amounts($merchant_dollars, $disc_rate_dollars, $reserve_rate_dollars, $chargeback_amount, $recon_type)
{
    global $REVENUE_ACCOUNT;
    global $FEE_ACCOUNT;
    global $HOLD_ACCOUNT;
    global $CHARGEBACK_ACCOUNT;
    $amounts = [];

    if ($merchant_dollars != 0) {
        $amounts[$REVENUE_ACCOUNT] = $merchant_dollars;
    }
    if (($recon_type == 0) && ($disc_rate_dollars > 0)) {
        $amounts[$FEE_ACCOUNT] = $disc_rate_dollars;
    }
    if ($reserve_rate_dollars != 0) {
        $amounts[$HOLD_ACCOUNT] = $reserve_rate_dollars;
    }
    if ($chargeback_amount != 0) {
        $amounts[$CHARGEBACK_ACCOUNT] = $chargeback_amount;
    }

    // echo "<pre>";echo var_dump($amounts);echo "</pre>";
    return $amounts;
}

function build_funding_accounts($fund_amounts)
{
    global $REVENUE_ACCOUNT;
    global $FEE_ACCOUNT;
    global $HOLD_ACCOUNT;
    global $CHARGEBACK_ACCOUNT;
    $accounts = [];

    if (isset($fund_amounts[$REVENUE_ACCOUNT])) {
        $accounts[] = array('account_type' => $REVENUE_ACCOUNT, 'amount' => $fund_amounts[$REVENUE_ACCOUNT]);
    }
    if (isset($fund_amounts[$FEE_ACCOUNT])) {
        $accounts[] = array('account_type' => $FEE_ACCOUNT, 'amount' => $fund_amounts[$FEE_ACCOUNT]);
    }
    if (isset($fund_amounts[$HOLD_ACCOUNT])) {
        $accounts[] = array('account_type' => $HOLD_ACCOUNT, 'amount' => $fund_amounts[$HOLD_ACCOUNT]);
    }
    if (isset($fund_amounts[$CHARGEBACK_ACCOUNT])) {
        $accounts[] = array('account_type' => $CHARGEBACK_ACCOUNT, 'amount' => $fund_amounts[$CHARGEBACK_ACCOUNT]);
    }

    // echo "<pre>";echo var_dump($accounts);echo "</pre>";
    return $accounts;
}

function api_call($db, $method, $postarr, $token, $runlogid)
{
    global $apiurl;

    // echo "-- api_call, print_r(method): " . print_r($method) . "<br>";
    // echo "-- api_call, print_r(postarr): " . print_r($postarr) . "<br>";
    // echo "api_call, runlogid: $runlogid" . "<br>";

    $poststring2 = json_encode($postarr);
    // echo "api_call, poststring: $poststring2 <br>";

    // make the CURL call
    $verbose = false;
    $url = $apiurl . $method;
    $request = 'POST';
    $postfields = $poststring2;
    $authorization = 'Authorization: Bearer ' . $token;
    $response = make_curl_call($verbose, $url, $request, $postfields, $authorization);

    $curlurl = $apiurl . $method;
    $curlrequeststr = var_export($postarr, true);

    if (is_null($response)) {
        $data = "";
    } else {
        $data = json_decode($response, TRUE);
    }

    $curlresponsestr = var_export($data, TRUE);
    if (isset($data['error'])) {
        $haserror = '1';
    } else {
        $haserror = '0';
    }

    $insert = $db->query("INSERT INTO api_log (url,request,response,haserror,runid) VALUES ('{$curlurl}','" . addslashes($curlrequeststr) . "','" . addslashes($curlresponsestr) . "',{$haserror},$runlogid)");
    // echo "<pre>";echo var_dump($insert);echo "</pre>";

    $logid = $db->lastInsertID();

    return array($response, $logid);
}

function gettoken($db)
{
    $newtokenflag = 1;
    if (check_token_ok($db)) {
        if (test_api_call(get_active_token($db))) {
            $newtokenflag = 0;
        }
    }
    // echo "gettoken, newtokenflag: $newtokenflag"."<br>"; exit();

    if ($newtokenflag == 1) {
        global $apiusername;
        global $apipassword;
        global $apiurl;

        //credentials.
        $username = $apiusername;
        $password = $apipassword;

        // make the CURL call
        $verbose = false;
        $url = $apiurl . 'oauth/token';
        $request = 'POST';
        $postfields = '{ "grant_type":"client_credentials" }';
        $authorization = 'Authorization: Basic ' . base64_encode($username . ':' . $password);
        $response = make_curl_call($verbose, $url, $request, $postfields, $authorization);

        $curlrequeststr = var_export(array(
            'Content-Type: application/json',
            'Accept-Language: en;q=0.8,es-cl;q=0.5,zh-cn;q=0.3',
            'Authorization: Basic ' . base64_encode($username . ':' . $password)
        ), true);
        //echo "gettoken, curlrequeststr: $curlrequeststr"."<br>";

        if (is_null($response)) {
            $data = "";
        } else {
            $data = json_decode($response, TRUE);
        }

        $curlresponsestr = var_export($data, TRUE);
        // echo "gettoken, curlresponsestr: $curlresponsestr" . "<br>";

        if ($data == "") {
            $haserror = '1';
        } elseif (isset($data['error'])) {
            $haserror = '1';
        } else {
            $haserror = '0';
        }

        $insert = $db->query("INSERT INTO api_log (url,request,response,haserror) VALUES ('{$url}','" . addslashes(str_replace('QVBJLUJDN0Q1NkM1OjNjYWQwNDU0NDkxNzkwMjY2ZWQ2MmJkMDk0MzQxM2NjYmJiODc0OTRhZTEyYmQzNjE1ZjZlMWY4N2Q1NWFmMGU=', 'APIPASSWORDHIDDEN', $curlrequeststr)) . "','" . addslashes($curlresponsestr) . "',{$haserror})");
        // echo "<pre>";echo var_dump($insert);echo "</pre>";

        if (!is_null($data) && !isset($data['error'])) {
            $insert = $db->query("INSERT INTO tokens (token) VALUES ('{$data['access_token']}')");
        }

        if (!is_null($data) && isset($data['access_token'])) {
            return $data['access_token'];
        } else {
            return NULL;
        };
    }

    return get_active_token($db);
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
    if (count($tokens) == 0) {
        return false;
    } else {
        return true;
    }
}

function test_api_call($token)
{
    global $apiurl;

    // make the CURL call
    $verbose = false;
    $url = $apiurl . 'account/balance';
    $request = 'POST';
    $postfields = '{
        "merchant_id": "526269716886",
        "currency": "USD",
        "account_type": "INSTRUCTIONAL_HOLD_ACCOUNT"
    }';
    $authorization = 'Authorization: Bearer ' . $token;
    $response = make_curl_call($verbose, $url, $request, $postfields, $authorization);
    // echo "<pre>";echo var_dump($data);echo "</pre>";

    if (is_null($response)) {
        return false;
    } else {
        $data = json_decode($response, TRUE);
        return ($data['result'] == 'SUCCESS');
    }
}

function make_curl_call($verbose, $url, $request, $postfields, $authorization)
{
    $curlcertpath = "C:\Program Files\php-8.3.2\certificates\cacert.pem";

    // initialize and set up the CURL API call
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_VERBOSE => $verbose,
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        // CURLOPT_CAINFO => $curlcertpath,
        CURLOPT_CUSTOMREQUEST => $request,
        CURLOPT_POSTFIELDS => $postfields,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Accept-Language: en;q=0.8,es-cl;q=0.5,zh-cn;q=0.3',
            'X-API-VERSION: 3',
            $authorization,
        ),
    ));

    $server_response = curl_exec($ch);
    curl_close($ch);

    if ($e = curl_error($ch)) {
        echo $e;
        return null;
    } else {
        // echo "<pre>";echo var_dump($server_response);echo "</pre>";
        return $server_response;
    };
}

class db
{
    protected $connection;
    protected $query;
    protected $show_errors = TRUE;
    protected $query_closed = TRUE;
    public $query_count = 0;

    public function __construct($dbhost = 'localhost', $dbuser = 'root', $dbpass = '', $dbname = '', $charset = 'utf8')
    {
        $this->connection = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
        if ($this->connection->connect_error) {
            $this->error('Failed to connect to MySQL - ' . $this->connection->connect_error);
        }
        $this->connection->set_charset($charset);
    }

    public function query($query)
    {
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

    public function fetchAll($callback = null)
    {
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

    public function fetchArray()
    {
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

    public function close()
    {
        return $this->connection->close();
    }

    public function numRows()
    {
        $this->query->store_result();
        return $this->query->num_rows;
    }

    public function affectedRows()
    {
        return $this->query->affected_rows;
    }

    public function lastInsertID()
    {
        return $this->connection->insert_id;
    }

    public function error($error)
    {
        if ($this->show_errors) {
            exit($error);
        }
    }

    private function _gettype($var)
    {
        if (is_string($var)) return 's';
        if (is_float($var)) return 'd';
        if (is_int($var)) return 'i';
        return 'b';
    }
}
// END function and class declarations -------------------------------
// *******************************************************************
