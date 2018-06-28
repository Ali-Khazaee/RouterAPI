# RouterAPI
PHP Client API for RouterOS ( Mikrotik )

## Sample

```php
require('RouterAPI.php');

$API = new RouterAPI();

// Set true if you want doing debugging ( default is false )
$API->IsDebug = false;

// Set true if your using SSL-API ( default is false )
$API->IsSSL = false;

// Attempts to retry if fails ( default is 3 )
$API->Attempt = 2;

// Socket timeout ( default is 5 )
$API->Timeout = 3;

// Delay between fails ( default is 3 )
$API->Delay = 3;

// Username - Password - Host - Port ( Can be omitted default is 8728)
if ($API->Connect('USERNAME', 'PASSWORD', '100.100.100.100', 8728))
{
    /* Example for adding a VPN user */
    $API->Command("/ppp/secret/add", array("name" => "ali", "password" => "123456", "service" => "pptp"));

    /* Example of finding registration-table ID for specified MAC */
    $MacList = $API->Command("/interface/wireless/registration-table/print", array(".proplist"=> ".id", "?mac-address" => "00:0E:BB:DD:FF:FF"));

    print_r($MacList);

    # Get all current hosts
    $API->Write('/ip/dns/static/print');
    $IPs = $API->Read();

    # Delete them all !
    foreach($IPs as $Key => $Value)
    {
        $API->Write('/ip/dns/static/remove', false);
        $API->Write("=.id=" . $Value[".id"], true);
    }

    # Add some new
    $API->Command("/ip/dns/static/add", array("name" => "jefkeklak", "address" => "1.2.3.4", "ttl" => "1m"));

    # Show me what you got
    $API->Write('/ip/dns/static/print');
    $IPs = $API->Read();
    var_dump($IPs);

    // Disconnect Connection
    $API->Disconnect();
}
```
