<?php
// BEGIN MAIN PHP script ---------------------------------------------
echo "Running kevinTest... <br>";

// GLOBAl variable declarations
$apiurl = "https://api.carat-platforms.fiserv.com/";
$apiusername = 'USER_NAME';
$apipassword = 'PASSWORD';

$next_day_funding = (isset($_REQUEST['NDF']) && $_REQUEST['NDF'] == 1);
$runfunding = false;                // set this to TRUE when ready to actually FUND !!

$dbhost = 'localhost';
$dbuser = 'USER_NAME';
$dbpass = 'PASSWORD';
$dbname = 'dbarney_webtools';

$db = new db($dbhost, $dbuser, $dbpass, $dbname);
$access_token = gettoken($db);

$starttime = date('Y-m-d H:i:s');
$runtype = "kevinTest";

$inssql = "INSERT INTO run_log (`runtype`, `starttime`) VALUES ('{$runtype}', '{$starttime}')";
$db->query($inssql);
$runlogid = $db->lastInsertID();

if ($next_day_funding) {
    $inssql = "update run_log set endtime = '" . date('Y-m-d H:i:s') . "',activecount=1,runcount=0,fundcount=0,zerofundcount=0 WHERE id = $runlogid ";
} else {
    $inssql = "update run_log set endtime = '" . date('Y-m-d H:i:s') . "',activecount=0,runcount=0,fundcount=0,zerofundcount=0 WHERE id = $runlogid ";
};
$db->query($inssql);

// END MAIN PHP script -----------------------------------------------
// *******************************************************************


// BEGIN function and class declarations -----------------------------
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
        CURLOPT_CAINFO => $curlcertpath,
        CURLOPT_CUSTOMREQUEST => $request,
        CURLOPT_POSTFIELDS => $postfields,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Accept-Language: en;q=0.8,es-cl;q=0.5,zh-cn;q=0.3',
            $authorization,
        ),
    ));

    $server_response = curl_exec($ch);
    curl_close($ch);

    if ($e = curl_error($ch)) {
        echo $e;
        return null;
    } else {
        // echo "<prev>";
        // print_r($server_response);
        // echo "</prev>";
        return $server_response;
    };
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
    // echo "<pre>";
    // echo var_dump($insert);
    // echo "</pre>";

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
        // echo "<pre>";
        // echo var_dump($insert);
        // echo "</pre>";

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
