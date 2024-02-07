<?php
// if (!function_exists('mysqli_init') && !extension_loaded('mysqli')) {
//     echo 'We don\'t have mysqli!!!';
// } else {
//     echo 'Phew we have it!';
// };

// Testing CURL functionality ----------------------------
echo "Testing CURL functionality!" . "<br>";

$ch = curl_init();
$curlurl = "https://reqres.in/api/users?page=2";
$curlcertpath = "C:\Program Files\php-8.3.2\certificates\cacert.pem";

curl_setopt($ch, CURLOPT_URL, $curlurl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

// Disable SSL verification
//curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 

// Provide the path to CA bundle
curl_setopt($ch, CURLOPT_CAINFO, $curlcertpath); 

$server_response = curl_exec($ch);

curl_close($ch);

if ($e = curl_error($ch)) {
    echo $e;
} else {
    echo "<prev>";
    print_r($server_response);
    echo "</prev>";
    // echo '<textarea cols="40" rows="40">'.$server_response.'</textarea>';
};
// -------------------------------------------------------
