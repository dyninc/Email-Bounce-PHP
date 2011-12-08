<?php
//Turn php error reporting on. Turn this off in production
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error_log.txt');
error_reporting(E_ERROR);

// pull out the email address that bounced
$email = $_GET["email"];

if(VerifyBounce($email) == true)
{
	// call the function to update your CRM, in this case salesforce
	UpdateInfoInSalesForce($email);
}

function UpdateInfoInSalesForce($email)
{
        // You would probably want to move this to a config file in production, it is the credentials salesforce will give you 
	// for API access
        define("USERNAME", "my_user_name");
        define("PASSWORD", "my_password");
        define("SECURITY_TOKEN", "my_security_token");

	// include the salesforce php library
        require_once ('soapclient/SforceEnterpriseClient.php');

	// use the library to create a connection, point to your wsdl (which you get through the salesforce administration page) and login
        $mySforceConnection = new SforceEnterpriseClient();
        $mySforceConnection->createConnection("sfdyn.wsdl.xml"); // the wsdl will need to be updated if any of the chema in use changes within salesforce
        $mySforceConnection->login(USERNAME, PASSWORD.SECURITY_TOKEN);

	// setup a query that will get the email and id for a contact then return back the results
        $query = "SELECT Email, id from Contact where Email='" . $email . "'";
        $response = $mySforceConnection->query($query);

	// if we in fact got a result.... this assumes there is only one contact per email, you may want to loop all the results for the same
	// email being associated with multiple contacts
        if(count($response->records) > 0)
        {
		// update the contact with our "bounced" email address
                $updated_records = array();
                $updated_records[0] = new stdclass();
                $updated_records[0]->Id = $response->records[0]->Id;
                $updated_records[0]->Email = "bounced@bounced.com";

                $response_out = $mySforceConnection->update($updated_records, 'Contact');
                echo(print_r($response_out));
        }
        else
        {
		// no salesforce contact
                echo "Could not find SF Contact to update from email: " . $email;
        }
}

function VerifyBounce($email)
{
	// Just like in with the SF credentials you probably want to read these out of a config file
	$url = 'http://emailapi.dynect.net/rest/';
	$apikey = 'your_dynect_email_api_key';
	
	// We are using the GET verb  
	$method = 'GET';
	
	// build up the parameters, in this case we are selecteing bounces from today
	$data = Array();
	$data['apikey'] = $apikey;
	$data['startdate'] = date('Y-m-d');
	$data['enddate'] = date('Y-m-d');
	$requestBody = http_build_query($data, '', '&');

	// initialize the curl handle
	$ch = curl_init();

	try
	{
		// build the request
		$loc = 'reports/bounces?' . $requestBody;
		
		// set our curl options, we want json back in this case, xml and html could also be returned
		curl_setopt($ch, CURLOPT_TIMEOUT, 20);
		curl_setopt($ch, CURLOPT_URL, $url . 'json/' . $loc);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array ('Accept: application/json'));
		
		// make the request and get back the results....
		$responseBody = curl_exec($ch);
		$responseInfo = curl_getinfo($ch);

		// decode the response into a nice assoc. array
		$result =  json_decode($responseBody, true);

		// if a success
		if($result['response']['status'] == 200)
		{
			// loop through today's bounces
			$bounces = $result['response']['data']['bounces'];
			foreach ($bounces as $bounce)
			{
				// if the email we got the update on is in this list return true, it definately bounced and we want to update the info in SF
				if(strcasecmp($bounce['bounceemail'], $email) == 0)
				{
					curl_close($ch);
					return true;
				}
			}
		}
		
		curl_close($ch);
	}
	catch(Exception $e)
	{
		// if we error out, print it and cleanup
		curl_close($ch);
		echo "Error: " . $e;
	}
	
	// default to returning false...
	return false;
}

?>