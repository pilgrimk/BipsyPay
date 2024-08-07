<?php
//if(!isset($_GET['lock']) || $_GET['lock']!='eCZNZTlId2dRcWpWJDln'){exit();}

//working_directory/emailBuilder.php
require_once('db.php');
require_once(__DIR__ . '/vendor/autoload.php');

// Check if the script is being run from the command line (CLI)
$my_arguments = '';
if (php_sapi_name() === 'cli') {
    // Check if command-line arguments are provided
    if (isset($argv[1])) {
        // $argv contains the parsed arguments
        // var_dump($argv);
        $my_arguments = $argv;
    } else {
        "No command line parameters found." . "\n";
    }
} else {
    // If not running from the command line, assume it's a web request
    // Parse query string parameters
    $query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
    if ($query) {
        // $query contains the parsed query string parameters
        // var_dump($query);
        $my_arguments = $query;
    } else {
        echo "No query string parameters found." . "\n";
    }
}

// set these variables based upon passed arguments
$testingenv = (my_arg_parser($my_arguments, 'TEST') == '1');  

$dbhost = 'localhost';
$dbuser = 'dbarney_webtools';
$dbpass = 'Ka5Wvw-8FeY5';
$dbname = 'dbarney_webtools';

$db = new db($dbhost, $dbuser, $dbpass, $dbname);

if ($testingenv) {

  $runtype = "testenvfund";
  $merchantmask = "merchant_name like 'Test%'";
  $urlmask = "url like 'https://uat-fd-pfac%'";
} else {

  $runtype = "prodfund";
  $merchantmask = "merchant_name not like 'Test%'";
  $urlmask = "url like 'https://fd-pfac%'";
}

// echo "runtype: {$runtype}" . "\n";

$datetodo = date("Y-m-d");

if (isset($_REQUEST['datetodo'])) {
  $datetodo = date("Y-m-d", strtotime($_REQUEST['datetodo']));
}

#DAILY FUNDING LOG
$dailyfundingdetailsql = "SELECT
	merchants.mid as \"Merchant ID\",
	merchants.merchant_name as \"Merchant\", 
	merchants.location_name as \"Location\", 
	fund_log.fund_date \"Run Time\", 
	merchants.next_day_funding \"Next Day Funding\", 
	CASE 
		WHEN merchants.next_day_funding = 1 THEN \"X\"
		ELSE null
	END \"Next Day Funding\",  
	fund_log.receipts as \"Starting Balance\", 
	fund_log.deposits as \"Deposit\", 
	fund_log.fees as \"Fee\", 
	fund_log.chargebacks as \"Chargebacks\", 
	CASE 
		WHEN fund_log.chargebacks IS NULL THEN \"0.00\"
		ELSE fund_log.chargebacks
	END \"Chargebacks\", 	
	fund_log.endbal as \"Ending Balance\"
FROM
	fund_log
	INNER JOIN
	merchants
ON 
	fund_log.mid = merchants.mid
INNER JOIN
  run_log
ON 
	fund_log.runid = run_log.id
WHERE 
  run_log.runtype like '{$runtype}%' and fund_type = 0 and fund_date >= '{$datetodo} 00:00:00' and fund_date <= '{$datetodo} 23:59:59'
ORDER BY 
  merchants.next_day_funding DESC, fund_log.fund_date DESC;";
  
#MONTHLY FUNDING LOG
$monthlyfundingdetailsql = "SELECT
	merchants.mid as \"Merchant ID\",
	merchants.merchant_name as \"Merchant\", 
	merchants.location_name as \"Location\", 
	fund_log.fund_date \"Run Time\", 
	fund_log.receipts as \"Starting Balance\", 
	fund_log.deposits as \"Deposit\", 
	fund_log.fees as \"Fee\"
FROM
	fund_log
	INNER JOIN
	merchants
ON 
	fund_log.mid = merchants.mid
INNER JOIN
  run_log
ON 
	fund_log.runid = run_log.id
WHERE 
  run_log.runtype like '{$runtype}%' and fund_type = 1 and fund_date >= '{$datetodo} 00:00:00' and fund_date <= '{$datetodo} 23:59:59'
ORDER BY 
  merchants.next_day_funding DESC, fund_log.fund_date DESC;";  

#Active Merchants No Balance
$activezerobalsql = "select merchants.mid as \"Merchant ID\",
	merchants.merchant_name as \"Merchant\", 
	merchants.location_name as \"Location\",processed as \"Last Funding\" from merchants where mid not in (select mid from fund_log where receipts>0 and fund_date >= '{$datetodo} 00:00:00' and fund_date <= '{$datetodo} 23:59:59' and fund_logid != 0) and active = 1 and {$merchantmask};";

#Error Detail
$errordetsql = "select datelogged as \"When\",replace(url,'https://fd-pfac-api.technologi.co.uk/','') as \"Api Method\",request as \"Request\",response as \"Response\" from api_log where datelogged >= '{$datetodo} 00:00:00' and datelogged <= '{$datetodo} 23:59:59' and haserror = 1 and {$urlmask};";

//echo $errordetsql;

#Summary	Funding
$summaryfundsql = "select count(DISTINCT fund_log.mid) as \"MIDS\",sum(fund_log.receipts) as \"Gross\",sum(fund_log.deposits) as \"Deposit\", sum(fund_log.fees) as \"Fees\", sum(fund_log.chargebacks) as \"Chargebacks\" FROM
	fund_log
	INNER JOIN
	merchants
	ON 
		fund_log.mid = merchants.mid
	INNER JOIN
	run_log
	ON 
		fund_log.runid = run_log.id
		
		where run_log.runtype like '{$runtype}%' and fund_date >= '{$datetodo} 00:00:00' and fund_date <= '{$datetodo} 23:59:59' ";

if ((isset($_REQUEST['testenv']))) {
  $summaryfundsql .= "";
} else {
  $summaryfundsql .= "and endbal = 0 and errorcount = 0; ";
}

//echo $summaryfundsql;

#summary error
$summaryerrsql = "select count(DISTINCT fund_log.mid) as \"MIDS\",sum(fund_log.receipts) as \"Gross\",sum(errorcount) as \"Errors\" FROM
	fund_log
	INNER JOIN
	merchants
	ON 
		fund_log.mid = merchants.mid
	INNER JOIN
	run_log
	ON 
		fund_log.runid = run_log.id
		
		where run_log.runtype like '{$runtype}%' and fund_date >= '{$datetodo} 00:00:00' and fund_date <= '{$datetodo} 23:59:59' and errorcount > 0;";

// echo $summaryerrsql;

$emailcss = "<head> <style type = 'text/css'>
table.paleBlueRows {
  font-family: \"Times New Roman\", Times, serif;
  border: 1px solid #FFFFFF;
  text-align: center;
  border-collapse: collapse;
}
table.paleBlueRows td, table.paleBlueRows th {
  border: 1px solid #000000;
  padding: 3px 2px;
}
table.paleBlueRows tbody td {
  font-size: 13px;
}
table.paleBlueRows tr:nth-child(even) {
  background: #D0E4F5;
}
table.paleBlueRows thead {
  background: #0B6FA4;
  border-bottom: 5px solid #FFFFFF;
}
table.paleBlueRows thead th {
  font-size: 17px;
  font-weight: bold;
  color: #FFFFFF;
  text-align: center;
  border-left: 2px solid #FFFFFF;
}
table.paleBlueRows thead th:first-child {
  border-left: none;
}

table.paleBlueRows tfoot {
  font-size: 14px;
  font-weight: bold;
  color: #333333;
  background: #D0E4F5;
  border-top: 3px solid #444444;
}
table.paleBlueRows tfoot td {
  font-size: 14px;
}</style></head>";

// echo "<pre>"; echo var_dump($dailyfundingdetailsql); echo "</pre>";

$dailyfundingdetailsqlres = $db->query($dailyfundingdetailsql)->fetchAll();
$monthlyfundingdetailsqlres = $db->query($monthlyfundingdetailsql)->fetchAll();
$activezerobalsqlres = $db->query($activezerobalsql)->fetchAll();
$errordetsqlres = $db->query($errordetsql)->fetchAll();
$summaryfundsqlres = $db->query($summaryfundsql)->fetchAll();
$summaryerrsqlres = $db->query($summaryerrsql)->fetchAll();

//echo "<pre>";echo var_dump($dailyfundingdetailsqlres);echo "</pre>";

$emailbody = $emailcss;
$emailbody .= count($summaryfundsqlres) > 0 ? gettableforemail($summaryfundsqlres, "Funding Summary") : '';
$emailbody .= count($summaryerrsqlres) > 0 ? gettableforemail($summaryerrsqlres, "") : '';
$emailbody .= count($activezerobalsqlres) > 0 ? gettableforemail($activezerobalsqlres, "Active Merchant Zero Starting Balance") : '';
$emailbody .= count($dailyfundingdetailsqlres) > 0 ? gettableforemail($dailyfundingdetailsqlres, "Daily Funding Detail") : '';
$emailbody .= count($monthlyfundingdetailsqlres) > 0 ? gettableforemail($monthlyfundingdetailsqlres, "Monthly Funding Detail") : '';
$emailbody .= count($errordetsqlres) > 0 ? gettableforemail($errordetsqlres, "Error Detail") : '';

// echo $emailbody;
// echo $emailcss . gettableforemail($dailyfundingdetailsqlres,"Daily Funding Detail");
// echo gettableforemail($activezerobalsqlres,"Active Merchants with zero starting balance");

if (isset($_REQUEST['skipemail'])) {
  echo "NO EMAIL";
} else {
  sendFundingLogEmail($emailbody);
}

function gettableforemail($res, $resheader, $cssclass = 'paleBlueRows')
{
  $dollarfieldsarr = array('Gross', 'Starting Balance', 'Deposit', 'Fee', 'Fees', 'Ending Balance', 'Chargebacks');

  if (count($res) == 0 || $res[0]['MIDS'] == '0') {
    return "";
  }

  $body = "<h3>$resheader</h3>";
  //$body.="<hr>";

  $headerarr = array_keys($res[0]);
  $headerrow = "<tr><th>" . implode('</th><th>', $headerarr) . "</th></tr>";

  foreach ($res as $resrow) {
    if (isset($thisrowarr)) {
      unset($thisrowarr);
    }
    foreach ($headerarr as $thisheader) {
      if (in_array($thisheader, $dollarfieldsarr)) {
        if (trim($resrow[$thisheader]) == '') {
          $resrow[$thisheader] = '0';
        }
        $resrow[$thisheader] = '$' . $resrow[$thisheader];
      }
      $thisrowarr[] = $resrow[$thisheader];
    }

    $tablebodyarr[] = '<tr><td>' . implode('</td><td>', $thisrowarr) . "</td></tr>";
  }

  $tablebody = "<table class='$cssclass'>" . $headerrow . implode(' ', $tablebodyarr) . "</table><br>";

  $body .= $tablebody;

  return $body;
}

function sendFundingLogEmail($emailHTML)
{
  $credentials = SendinBlue\Client\Configuration::getDefaultConfiguration()->setApiKey('api-key', 'XXXXXX');
  $apiInstance = new SendinBlue\Client\Api\TransactionalEmailsApi(new GuzzleHttp\Client(), $credentials);

  /* to:	Mark Smith <marksmith@bipsypay.com>
  cc:	"msamaniego cash-llc.com" <msamaniego@cash-llc.com>,
  "jforsyth cash-llc.com" <Jforsyth@cash-llc.com>*/

  $sendSmtpEmail = new \SendinBlue\Client\Model\SendSmtpEmail([
    'subject' => 'Bipsypay API Funding Report ' . date('Y-m-d'),
    'sender' => ['name' => 'BipsyReporting', 'email' => 'bipsyreporting@bipsypay.com'],
    'replyTo' => ['name' => 'BipsyReporting', 'email' => 'bipsyreporting@bipsypay.com'],
    'to' => [ //[ 'name' => 'Donald Kirchner', 'email' => 'donald.kirchner@gmail.com']
      ['name' => 'Mark Smith', 'email' => 'marksmith@bipsypay.com'],
      ['name' => 'Mark W Smith', 'email' => 'letrfly@msn.com'],
      ['name' => 'msamaniego cash-llc.com', 'email' => 'msamaniego@cash-llc.com'],
      ['name' => 'msamaniego bipsy', 'email' => 'mikesam@bipsypay.com'],
      ['name' => 'Jforsyth@cash-llc.com', 'email' => 'Jforsyth@cash-llc.com'],
      ['name' => 'Kevin Pilgrim', 'email' => 'pilgrimka1@yahoo.com']
    ],
    // 'cc' => [[ 'name' => 'Donald Kirchner', 'email' => 'donald.kirchner@gmail.com']],    
    //  'bcc' => [[ 'name' => 'Donald Kirchner', 'email' => 'donald.kirchner@gmail.com']],
    'htmlContent' => $emailHTML,
    'params' => ['bodyMessage' => 'made just for you!']
  ]);

  try {
    $result = $apiInstance->sendTransacEmail($sendSmtpEmail);
    print_r($result);
  } catch (Exception $e) {
    echo $e->getMessage(), PHP_EOL;
  }
}

function my_arg_parser($arguments, $target)
{
    $result = '0';

    if (!is_null($arguments) && ($arguments != ''))
    {
        // check each ARG for our TARGET 
        foreach($arguments as $arg)
        {
            if(strpos($arg, $target) !== false){
                $arr = explode("=", $arg);
                $result = $arr[1];
            }
        }
    }

    // echo "{$target} return value: {$result}" . "\n";
    return $result;
}

