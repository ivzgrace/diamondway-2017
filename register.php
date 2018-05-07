<?php
require_once('config.php');

error_reporting(E_ALL);
ini_set('display_errors', 'On');

require_once __DIR__ . '/../sites/all/modules/webform_to_gdocs/google-api-php-client/src/Google/autoload.php';

define('APP_NAME', 'diamondway.org.au');
define('CLIENT_ID', '890604785975-sdp3q48km9va8ngnsp6bcqllfsng15c7.apps.googleusercontent.com');
define('CLIENT_SECRET', 'yo_PwTgkQO0ppCzBUaUX86et');
define('ACCESS_TOKEN', '{"access_token":"ya29.Ci-SA9EEhA1cBjxhiVQf2bTIbWjZ536zOO3e0r2fZReHYQTxRJanN6Qp1oafbIrijg","expires_in":3600,"id_token":"eyJhbGciOiJSUzI1NiIsImtpZCI6ImQ1MjA4ODBiNDYzNGE1YTNjNDFiNWNmNjU1M2U5ZWE0YTViNjA5ZjIifQ.eyJpc3MiOiJhY2NvdW50cy5nb29nbGUuY29tIiwiaWF0IjoxNDc4NzgxMjIxLCJleHAiOjE0Nzg3ODQ4MjEsImF0X2hhc2giOiJCX01UVU9PWHlhemJTTG9DV09wUUx3IiwiYXVkIjoiODkwNjA0Nzg1OTc1LXNkcDNxNDhrbTl2YThuZ25zcDZiY3FsbGZzbmcxNWM3LmFwcHMuZ29vZ2xldXNlcmNvbnRlbnQuY29tIiwic3ViIjoiMTE3MzExMDM1MzcwMTM0ODk4NjAzIiwiZW1haWxfdmVyaWZpZWQiOnRydWUsImF6cCI6Ijg5MDYwNDc4NTk3NS1zZHAzcTQ4a205dmE4bmduc3A2YmNxbGxmc25nMTVjNy5hcHBzLmdvb2dsZXVzZXJjb250ZW50LmNvbSIsImVtYWlsIjoiZHdic3lkQGdtYWlsLmNvbSJ9.YpoNGFtKeC3bXY3GhyschZ4IQLKPQD2BNOzEOboQLS0KRI3XJPZFifyR-IosQMAYN46bGXFWMZflBJYhj7ruuTXw6QHN_Gf9zN86f2PdDhgMIoVBF9XfzmcUwEomnEWUS8YoeGUnJB3zYjT8sTe4MA72gLsrdkDhFLOBfjP0OjqaPYmLxmJ8HripO_dYsly3OReRuFvK5B1vf-dBy6st8uvbIuTN7iiUkZGv5MP43O-9-kEH972fRnWvrcNuPOu_EThlsNhTKo5qr9uh8pmsIEAky3mMgBel8VZpsa4vPiMSzaTsyFdZol8C57g2us9_9E_12l_cJHohYdyjqby7Fw","refresh_token":"1\/_IiIHD_nx90djvGRVgRiJ7IvBbOY0sHr469YWkNb8PA","token_type":"Bearer","created":1478781221}');

$request = $_REQUEST;

$register = new Register( $request['stripeToken'] );
$register->ProcessRegister( $request );

class Register {

    private static $access_token_file = null;

    private static $tokenizer = null;

    function __construct( $token ) {
        self::$tokenizer = $token;
        self::$access_token_file = file_get_contents(__DIR__ . '/access.token');
    }

    public function ProcessRegister( $request ) {
        $response = array(
            'status'  => 0
        );

        $payment_response = false;
        $card_response = null;
        $bank_response = null;

        switch( strtolower( $request['payment_method'] ) ) {
            case 'bank' :
                $payment_response = true;
                break;
            case 'card' :
                $card_response = $this->_ProcessCardRegistration( $request );
                if( !empty($card_response) ) {
                    $payment_response = true;
                }
                break;
            default :
                break;
        }

        if ( $payment_response ) {
            $row = array();

            foreach ($request as $name => $value) {
                if ($name == 'children') {
                    $val = array();
                    if (!empty($value['names']) && !empty($value['ages'])) {
                        foreach ($value['names'] as $i => $child) {
                            if ($child && !empty($value['ages'][$i])) {
                                $val[] = $child . ' (' . $value['ages'][$i] . ')';
                            }
                        }
                    }
                    $value = implode('; ', $val);
                } else if ($name == 'dietary') {
                    foreach ($value as $type) {
                        switch ($type) {
                            case 'veg':
                            case 'dairy':
                            case 'gluten':
                                $row[$type] = 'yes';
                                break;
                            default:
                                $value = $type;
                        }
                    }
                } else if ($name == 'payment_reference') {
                    $value = ($card_response != null) ? $card_response : null; // if Card use Charge ID as a value, Otherwise use Bank Random Generate ID
                } else if (is_array($value)) {
                    $value = implode('; ', $value);
                }

                $row[$name] = $value;
            }

            ob_start();

            $insert_google_row = $this->_AddGoogleRow( $row );

            if( $insert_google_row ) {
                $send_email = $this->_SendMail( $row );

                if( $send_email ) {
                    $response['status'] = 1;
                }
            }

            ob_get_contents();
            ob_end_clean();
        }

        echo json_encode( $response );
        exit;
    }

    private function _ProcessCardRegistration( $params = array() ) {
        if( empty($params) ) {
            return '';
        }

        $cemail = $params['email'];
        $camount = $params['total'] * 100;
        $cname = $params['name'];

        $customer = \Stripe\Customer::create(array(
            'email' => $cemail,
            'source'  => self::$tokenizer,
            'description' => $cname
        ));

        $charge = \Stripe\Charge::create(array(
            'customer' => $customer->id,
            'amount'   => floatval($camount),
            'currency' => 'aud'
        ));

        if ( !empty($charge->id) ) {
            return $charge->id;
        }

        return '';
    }

    private function _WebFormToGDocsGoogleAPIScopes() {
        return array(
            'https://www.googleapis.com/auth/drive',
            'https://spreadsheets.google.com/feeds',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/plus.me',
        );
    }

    private function _WebFormToGDocsGoogleDriveGetService() {
        $client = new Google_Client();

        $client->setApplicationName(APP_NAME);
        $client->setClientId(CLIENT_ID);
        $client->setClientSecret(CLIENT_SECRET);
        $client->setScopes( $this->_WebFormToGDocsGoogleAPIScopes() );
        $client->setAccessToken(ACCESS_TOKEN);

        if ($client->getAuth()->isAccessTokenExpired()) {
            $access_token_decoded = json_decode(ACCESS_TOKEN);
            $client->getAuth()->refreshToken($access_token_decoded->refresh_token);

            $new_access_token = $client->getAccessToken();
            file_put_contents(__DIR__ . '/access.token', $new_access_token);
            self::$access_token_file = $new_access_token;
        }

        return new Google_Service_Drive($client);
    }

    private function _AddGoogleRow( $google_row = '') {
        if( empty($google_row) ) {
            return false;
        }

        $this->_WebFormToGDocsGoogleDriveGetService();
        $access_token = @json_decode(self::$access_token_file);

        // Insert it via Google Spreadsheets API.
        $ch = curl_init();
        $url = 'https://spreadsheets.google.com/feeds/list/1j0lSJxsAl8zhUO4jl02XVas4UmuBCfgTCAaJOZAKCOw/oxfg85s/private/full';

        $post_body = '<entry xmlns="http://www.w3.org/2005/Atom" xmlns:gsx="http://schemas.google.com/spreadsheets/2006/extended">';

        foreach ($google_row as $col => $val) {
          // Only allow alphanumeric + underscores in column name.
          $col = preg_replace("/[^\da-z]/i", '', $col);
          $post_body .= '<gsx:' . $col . '>' . $val . '</gsx:' . $col . '>';
        }
        $post_body .= '</entry>';

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $access_token->access_token,
            'Content-type: application/atom+xml'
        ));

        $result = curl_exec($ch);

        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (!in_array(intval($httpcode), array(200, 201))) {
            throw new Exception("Invalid response code ({$httpcode}) from Google Docs API.");
        }

        curl_close($ch);

        return true;
    }

    private function _SendMail($row = array()) {
        // Zend library include path
        set_include_path(get_include_path() . PATH_SEPARATOR . $_SERVER['DOCUMENT_ROOT']);
        //require_once 'Google_Spreadsheet.php';
        require_once 'Zend/Mail.php';

        if( empty($row) ) {
            return false;
        }

        $table = array();

        $table['Registrees'] = $row['name'] . (!empty($row['partner']) ? '; ' . $row['partner'] : '') . (!empty($row['children']) ? '; ' . $row['children'] : '');
        $table['Where are you based'] = $row['where'] == 'au' ? 'Australia/New Zealand' : 'International traveller';
        $table['Email'] = $row['email'];
        $table['Phone'] = $row['phone'];
        $table['DWB centre'] = !empty($row['centre']) ? $row['centre'] : 'not specified';
        $nights = 0;
        if ($row['course'] == 'full') {
          $table['Course options'] = 'Full course (7 nights - including dinner Friday 10 February to breakfast Friday 17 February)';
        }
        elseif (!empty($row['course_night'])) {
          $nights = 1 + substr_count($row['course_night'], ';');
          $table['Course options'] = 'Dates: ' . $row['course_night'];
        }
        else {
          $table['Course options'] = '-';
        }

        $table['Discount'] = 'none';
        if (!empty($row['discounts'])) {
            if ($row['discounts'] == 'concession') {
                $table['Discount'] = $nights ? '$' . (10 * $nights) : 'yes';//($row['course'] == 'full' ? '$80' : '$40') . ' - student or pensioner concession';
            }
        }

        switch ($row['accommodation']) {
            case 'ensuite':
                $table['Accommodation'] = 'Cabin with bathroom';
                if ($table['Discount'] === 'yes' && $row['course'] == 'full') {
                  $table['Discount'] = '$100';
                }
                break;
            case 'cabin':
                $table['Accommodation'] = 'Cabin without bathroom';
                if ($table['Discount'] === 'yes' && $row['course'] == 'full') {
                  $table['Discount'] = '$100';
                }
                break;
            case 'dorm':
                $table['Accommodation'] = 'Supplied tent with bed';
                if ($table['Discount'] === 'yes' && $row['course'] == 'full') {
                  $table['Discount'] = '$70';
                }
                break;
            case 'tent':
                $table['Accommodation'] = 'BYO tent';
                if ($table['Discount'] === 'yes' && $row['course'] == 'full') {
                  $table['Discount'] = '$70';
                }
                break;
        }

        switch ($row['in']) {
            case 'goldcoast-2ave':
                $table['Getting in'] = 'Bus from the 2nd Ave Apartments on Friday 10 February at midday';
                break;
            case 'goldcoast':
                $table['Getting in'] = 'Bus from the Gold Coast airport on Friday 10 February at 1:00pm';
                break;
            case 'ballina':
                $table['Getting in'] = 'Bus from Ballina airport on Friday 10 February at 4:45pm';
                break;
            case 'no':
                $table['Getting in'] = 'Own arrangements';
                break;
        }
        switch ($row['out']) {
            case 'goldcoast':
                $table['Getting out'] = 'Bus to the Gold Coast Airport, departing BBV on Friday 17 February at midday (arrive GC Airport approximately 1pm)';
                break;
            case 'ballina':
                $table['Getting out'] = 'Bus to Ballina Airport, departing BBV on Friday 17 February at 10:30am (arrive Ballina Airport approximately 11:00am)';
                break;
            case 'no':
                $table['Getting out'] = 'Own arrangements';
                break;
        }

        $dietary = array();
        if (!empty($row['veg'])) {
            $dietary[] = 'Vegetarian';
        }
        if (!empty($row['dairy'])) {
            $dietary[] = 'Dairy free';
        }
        if (!empty($row['gluten'])) {
            $dietary[] = 'Gluten free';
        }
        if (!empty($row['dietary'])) {
            $dietary[] = $row['dietary'];
        }
        $table['Dietary requirements'] = !empty($dietary) ? implode('; ', $dietary) : 'none';
        $table['Special requests'] = !empty($row['requests']) ? $row['requests'] : 'none';
        $table['Child care'] = !empty($row['childcare']) ? 'I\'m interested in shared childcare arrangements' : 'no';

        $table['Melbourne'] = !empty($row['melbourne']) ? $row['melbourne'] : '';

        if ($row['payment_reference']) {
          $table['Payment reference number'] = $row['payment_reference'];
        }

        $regData = "<table><tbody>";



        foreach ($table as $heading => $value) {
            $regData .= "<tr><td>{$heading}:</td> <td>{$value}</td></tr>\n";
        }

    $regData .= "</tbody></table>";

    $cc_payemnt_total = intval(intval($row['total']) / 0.981);

    $payment_details = '';
    if (empty($row['payment_reference'])) {
        $payment_details = <<<EOT
<p>Don't forget, your accommodation will only be allocated after you make your payment of \${$row['total']}:</p>

<table>
    <tbody>
        <tr>
            <td>Account name:</td>
            <td>Buddhism Diamond Way Northern Rivers</td>
        </tr>
        <tr>
            <td>BSB:</td>
            <td>062 657</td>
        </tr>
        <tr>
            <td>Account number:&nbsp;&nbsp;</td>
            <td>1008 7570</td>
        </tr>
    </tbody>
</table>

<p>Important: Please enter <b>your NAME ONLY in the direct deposit description field</b> so that we can match your payment to your registration.</p>

<p>If you prefer to pay by credit card, email <a href="mailto:northern-rivers-centre@diamondway.org.au">northern-rivers-centre@diamondway.org.au</a> or phone 0406 104 693 quoting the total amount of \${$cc_payemnt_total} to be paid. Note, this amount already includes 1.9% surcharge.
EOT;
    }

    $text = <<<EOT
<p>Dear {$row['name']}</p>

<p>Thank you for registering for the Mahamudra: 7 Nights with the Lama course. We look forward to seeing you and with your help, making it a memorable and joyful experience.</p>

{$payment_details}

<p>International travellers, for any questions regarding the tour, please contact Kat Kahler (<a href="mailto:travelcoordinator.dwb.perth@gmail.com">travelcoordinator.dwb.perth@gmail.com</a>)</p>

<p>Australians and New Zealanders, please contact <a href="mailto:mahamudra2017@diamondway.org.au">mahamudra2017@diamondway.org.au</a></p>

{$regData}

<p>Following is some information that will make your travels easier and your stay at the Ballina Beach Village (BBV) more comfortable.</p>

<p><b>Supplies</b></p>

<p>BBV is a long way from any shops and there is no public transport into town. If you want to get back to civilisation, you will need to get friendly with a local. There is a shop on-site with snacks, drinks and limited personal supplies. They accept credit card for purchases at the shop, but you will need to bring cash with you as there are no EFTPOS facilities on-site and course registration and all Diamond Way purchases (eg alcohol, dharma shop etc) are cash only.</p>

<p>The buses doing airport transfers will NOT be stopping at banks/teller machines.</p>

<p><b>What to bring</b></p>

<p>All the regular toiletries and personal items of course, but here are some specific things to consider...</p>

<p><i>Swimming costume, beach towel and sunscreen</i> are a must and a hat is advisable. Ballina in February will be hot and we are highly likely to have rain as well. Think tropical!</p>

<p>Please note that the towels available to hire at the venue are NOT under any circumstances to be used at the beach, river or pools, so either bring your own beach towel or be prepared to drip dry.</p>

<p><i>Insect repellent</i></p>

<p>You are unlikely to be bitten by anything lethal at BBV but there are local insects who may have a chew on you and can leave you very itchy.</p>

<p><i>Sun care</i></p>

<p>If you don't adequately protect yourself from the sun, you will need an aloe-vera or similar treatment. The Australian sun is vicious and it is not recommended that you have extended exposure between the hours of 10am and 3pm. And make sure you drink plenty of water - it is easy to quickly dehydrate.</p>

<p><i>Meditation cushion</i></p>

<p>Our gompa for the 7 days is a marquee and while we will do all that we can, if you can bring a meditation cushion, you will be more comfortable.</p>

<p><b>Facilities on site</b></p>

<ul>
    <li>Cafe with snacks, drinks, basic toiletries and supplies</li>
    <li>Laundry (coin operated)</li>
    <li>Safe available for valuables</li>
    <li>Lovely beach and bush walks</li>
    <li>Meditation garden and forest</li>
    <li>Swimming in the pools and river at all times and organised times for surf patrols on the beach</li>
    <li>Sandpit and playground - great scooter and bike areas for kids</li>
    <li>Bikes available</li>
    <li>And most importantly, friends and our Lama!</li>
</ul>

<p>Check out the <a href="http://www.ballinabeachvillage.com.au/" target="_blank">venue</a> online.</p>

<p>We are looking forward to seeing you for what is sure to be a wonderful experience.</p>

<p><i>The 2017 Oz East Coast Organisers</i></p>

EOT;

        $mail = new Zend_Mail();
        $mail->setBodyText(strip_tags($text));
        $mail->setBodyHtml($text);
        $mail->setFrom('mahamudra2017@diamondway.org.au', 'Mahamudra Course Team');
        $mail->addTo($row['email'], $row['name']);
        $mail->setSubject('2017 Lama Ole Mahamudra Course Registration Confirmation');
        $mail->send();
        return true;
    }
}