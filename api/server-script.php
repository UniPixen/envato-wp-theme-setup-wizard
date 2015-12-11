<?php

/**
 * Server Side oAuth Script to get WordPress Token
 * Author: dtbaker
 *
 * Instructions: https://github.com/dtbaker/envato-wp-theme-setup-wizard
 */


define('_ENVATO_APP_ID','put-your-envato-app-id-here');
define('_ENVATO_APP_SECRET','put-your-envato-app-secret-here');
define('_ENVATO_APP_URL','http://yoursite.com/envato/api/server-script.php');

// we need long lived sessions for refresh token to work, so we'll store them separately in our own folder.
// todo - move this session data into a database
ini_set('session.gc_probability',0);
ini_set('session.save_path',__DIR__ . '/sessions/');

if(!empty($_POST['oauth_session']) && preg_match('/^[-,a-zA-Z0-9]{1,128}$/', $_POST['oauth_session'])){
	session_id($_POST['oauth_session']);
}

session_start();

if(isset($_REQUEST['wp_return'])){
	$_SESSION['wp_return'] = urldecode($_REQUEST['wp_return']);
}
if(isset($_REQUEST['oauth_nonce'])){
	$_SESSION['oauth_nonce'] = $_REQUEST['oauth_nonce'];
}
if(empty($_SESSION['wp_return']) || !filter_var($_SESSION['wp_return'], FILTER_VALIDATE_URL) || empty($_SESSION['oauth_nonce'])){
	die('Failed to find WordPress return URL. Please report this error to the item author.');
}
if(isset($_REQUEST['get_token'])){
	header("Content-type: text/javascript");
	if(!empty($_SESSION['oauth_token'])){
		$token_to_send = $_SESSION['oauth_token'];
		unset($token_to_send['refresh_token']);
		unset($token_to_send['token_type']);
		echo json_encode($token_to_send);
	}else{
		echo '-1';
	}
	exit;
}

$envato = new envato_api_basic();
$envato->set_client_id(_ENVATO_APP_ID);
$envato->set_client_secret(_ENVATO_APP_SECRET);
$envato->set_redirect_url(_ENVATO_APP_URL);

if(isset($_REQUEST['refresh_token'])){
	header("Content-type: text/javascript");
	if(!empty($_SESSION['oauth_token']['refresh_token'])){
		$envato->set_manual_token($_SESSION['oauth_token']);
		$new_access = $envato->refresh_token();
		if($new_access){
			$_SESSION['oauth_token']['access_token'] = $new_access;
		}
		echo json_encode(array('new_token'=>$new_access));
	}else{
		echo '-1';
	}
	exit;
}


if(!empty($_REQUEST['code'])){
	// we have a login callback.
	$token = false;
	try{
		$token = $envato->get_authentication($_REQUEST['code']);
	}catch(Exception $e){

	}
	if($token && !empty($token['access_token']) && !empty($token['expires_in'])){
		$token['expires'] = time() + $token['expires_in'];
		$_SESSION['oauth_token'] = $token;
		$_SESSION['theme'] = isset($_REQUEST['theme']) ? $_REQUEST['theme'] : false;
		$_SESSION['version'] = isset($_REQUEST['version']) ? $_REQUEST['version'] : false;
		$_SESSION['url'] = isset($_REQUEST['url']) ? $_REQUEST['url'] : false;
	}
	?>

<!doctype html>
<html>
<head>
    <meta charset="utf-8">
	<title>Loading...</title>
	<script src="//code.jquery.com/jquery-1.11.3.min.js"></script>
    <link href="//fonts.googleapis.com/css?family=Roboto:400,100,300,700" rel="stylesheet" type="text/css">
</head>
<style type="text/css">
    body{
        background: #1E201F;
        -webkit-font-smoothing: antialiased;
        font-family: "Roboto", "Helvetica Neue", Helvetica, sans-serif;
        font-size: 14px;
        font-weight: 400;
        line-height: 1.6;
    }
    #shub_page{
        max-width: 1200px;
        margin:40px auto;
    }
    #shub_wrapper h1{
        text-align: center;
        color: #FFF;
        margin: 0;
        padding: 0 0 27px;;
    }
    #shub_content{
        border-radius: 5px;
        padding: 20px 40px 30px;
        background: #fff;
        position: relative;
    }
    #shub_content:before{
        background: #FFFFFF;
        border-radius: 2px 0 0 0;
        content: "";
        display: block;
        height: 20px;
        left: 50%;
        margin-left: -10px;
        position: absolute;
        transform: rotate(45deg);
        top: -10px;
        width: 20px;
    }
    @media (min-width: 640px) {
        #shub_wrapper {
            padding-left: 10.0%;
            padding-right: 10.0%;
        }
    }
    @media (min-width: 1024px){
        #shub_wrapper {
            padding-left: 20%;
            padding-right: 20%;
        }
    }
    .permissions__logo {
	    display: block;
	    margin: 0 auto 40px;
	}
</style>
<body>

<div id="shub_page">
    <div id="shub_wrapper">
        <img src="https://api.envato.com/images/logo.svg" alt="Envato API" class="permissions__logo">
        <div id="shub_content">
            <p>Loading...</p>
	        <form action="<?php echo htmlspecialchars( $_SESSION['wp_return'] ); ?>" method="POST" id="oauth_submit">
				<input type="hidden" name="oauth_nonce" value="<?php echo htmlspecialchars( $_SESSION['oauth_nonce'] ); ?>">
				<input type="hidden" name="oauth_session" value="<?php echo htmlspecialchars( session_id() ); ?>">
				<input type="submit" name="go" value="Click here to continue" id="manual-button">
			</form>
		</div>
		<script type="text/javascript">
			document.getElementById('manual-button').style.display = 'none';
			document.getElementById('oauth_submit').submit();
		</script>
	</div>
</div>

</body>
</html>

	<?php
}else if(!empty($_REQUEST['error'])){
	header("Location: ".$_SESSION['wp_return']);
}else{
	$url = $envato->get_authorization_url();
	header("Location: ".$url);
	//echo '<a href="'.$url.'">'.$url.'</a>';
}


/**
 * Exception handling class.
 */
class EnvatoException extends Exception {
}


class envato_api_basic {

	private static $instance = null;

	public static function getInstance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private $_api_url = 'https://api.envato.com/';

	private $_client_id = false;
	private $_client_secret = false;
	private $_personal_token = false;
	private $_redirect_url = false;
	private $_cookie = false;
	private $token = false; // token returned from oauth
	private $ch = false; // curl

	public function set_client_id( $token ) {
		$this->_client_id = $token;
	}

	public function set_client_secret( $token ) {
		$this->_client_secret = $token;
	}

	public function set_personal_token( $token ) {
		$this->_personal_token = $token;
	}

	public function set_redirect_url( $token ) {
		$this->_redirect_url = $token;
	}

	public function set_cookie( $cookie ) {
		$this->_cookie = $cookie;
	}

	public function api( $endpoint, $params = array(), $personal = true ) {
		$headers = array();
		if ( $personal && ! empty( $this->_personal_token ) ) {
			$headers[] = 'Authorization: Bearer ' . $this->_personal_token;
		} else if ( ! empty( $this->token['access_token'] ) ) {
			$headers[] = 'Authorization: Bearer ' . $this->token['access_token'];
		}
		$response = $this->get_url($this->_api_url . $endpoint,false,$headers);
		if ( is_array( $response ) && isset( $response['body'] ) && isset( $response['response']['code'] ) && $response['response']['code'] == 200 ) {
			$body   = @json_decode( $response['body'], true );
			if ( ! $body ) {
				echo 'Error';
			}
			return $body;
		} else{
			print_r($response);
			echo 'API Error';
		}

		return false;
	}


	public function curl_init() {
		if ( ! function_exists( 'curl_init' ) ) {
			echo 'Please contact hosting provider and enable CURL for PHP';

			return false;
		}
		$this->ch = curl_init();
		curl_setopt( $this->ch, CURLOPT_RETURNTRANSFER, true );
		@curl_setopt( $this->ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $this->ch, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $this->ch, CURLOPT_TIMEOUT, 20 );
		curl_setopt( $this->ch, CURLOPT_HEADER, false );
		curl_setopt( $this->ch, CURLOPT_USERAGENT, "Envato Simple PHP Class dtbaker" );
	}

	public function get_url( $url, $post = false, $extra_headers = array() ) {

		if ( $this->ch ) {
			curl_close( $this->ch );
		}
		$this->curl_init();
		curl_setopt( $this->ch, CURLOPT_URL, $url );
		if ( $extra_headers ) {
			curl_setopt( $this->ch, CURLOPT_HTTPHEADER, $extra_headers );
		}
		if ( is_string( $post ) && strlen( $post ) ) {
			curl_setopt( $this->ch, CURLOPT_POST, true );
			curl_setopt( $this->ch, CURLOPT_POSTFIELDS, $post );
		} else if ( is_array( $post ) && count( $post ) ) {
			curl_setopt( $this->ch, CURLOPT_POST, true );
			curl_setopt( $this->ch, CURLOPT_POSTFIELDS, $post );
		} else {
			curl_setopt( $this->ch, CURLOPT_POST, 0 );
		}

		return curl_exec( $this->ch );
	}

	/**
	 * OAUTH STUFF
	 */

	public function get_authorization_url() {
		return 'https://api.envato.com/authorization?response_type=code&client_id=' . $this->_client_id . "&redirect_uri=" . urlencode( $this->_redirect_url );
	}

	public function get_token_url() {
		return 'https://api.envato.com/token';
	}

	public function get_authentication( $code ) {
		$url                         = $this->get_token_url();
		$parameters                  = array();
		$parameters['grant_type']    = "authorization_code";
		$parameters['code']          = $code;
		$parameters['redirect_uri']  = $this->_redirect_url;
		$parameters['client_id']     = $this->_client_id;
		$parameters['client_secret'] = $this->_client_secret;
		$fields_string               = '';
		foreach ( $parameters as $key => $value ) {
			$fields_string .= $key . '=' . urlencode( $value ) . '&';
		}
		try {
			$response = $this->get_url( $url, $fields_string, false, false );
		} catch ( EnvatoException $e ) {
			echo 'OAuth API Fail: ' . $e->__toString();

			return false;
		}
		$this->token = json_decode( $response, true );

		return $this->token;
	}

	public function set_manual_token( $token ) {
		$this->token = $token;
	}

	public function refresh_token() {
		$url = $this->get_token_url();

		$parameters               = array();
		$parameters['grant_type'] = "refresh_token";

		$parameters['refresh_token'] = $this->token['refresh_token'];
		$parameters['redirect_uri']  = $this->_redirect_url;
		$parameters['client_id']     = $this->_client_id;
		$parameters['client_secret'] = $this->_client_secret;

		$fields_string = '';
		foreach ( $parameters as $key => $value ) {
			$fields_string .= $key . '=' . urlencode( $value ) . '&';
		}
		try {
			$response = $this->get_url( $url, $fields_string, false, false );
		} catch ( EnvatoException $e ) {
			echo 'OAuth API Fail: ' . $e->__toString();

			return false;
		}
		$new_token                   = json_decode( $response, true );
		$this->token['access_token'] = $new_token['access_token'];

		return $this->token['access_token'];
	}


}