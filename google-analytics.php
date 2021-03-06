<?php

uEvents::AddCallback('ProcessDomDocument','GoogleAnalytics::ProcessDomDocument','',99999);
utopia::AddTemplateParser('google_analytics_custom','GoogleAnalytics::IncludeCustom','');
class GoogleAnalytics extends uBasicModule {
	static function ProcessDomDocument($obj,$event,$doc) {
		$account = modOpts::GetOption('google_analytics_account');
		if (!$account) return;
		
		$body = $doc->getElementsByTagName('body')->item(0);
		$node = $doc->createElement('script');
		$node->appendChild($doc->createCDATASection("var _gaq = _gaq || [];
_gaq.push(['_setAccount', '$account']);
_gaq.push(['_trackPageview']);
{google_analytics_custom}
(function() {
var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
})();"));
		$body->appendChild($node);
	}
	public static function TrackEvent($category,$action,$opt_label=null,$opt_value=null,$opt_noninteraction=null) {
		if ($opt_label) $opt_label = ", '$opt_label'";
		if ($opt_value) $opt_value = ", $opt_value";
		if ($opt_noninteraction) $opt_noninteraction = ", ".($opt_noninteraction ? 'true' : 'false');
		$_SESSION['google_analytics_custom'][] = '_gaq.push(["_trackEvent", "'.$category.'", "'.$action.'"'.$opt_label.$opt_value.$opt_noninteraction.']);';
	}
	public static function PageView($url) {
		$_SESSION['google_analytics_custom'][] = '_gaq.push(["_trackPageview", "'.$url.'"]);';
	}
	public static function IncludeCustom() {
		if (!isset($_SESSION['google_analytics_custom']) || !$_SESSION['google_analytics_custom']) return '';
		
		// is redirect issued?  If so, don't draw now.
		foreach (headers_list() as $h) {
			if (preg_match('/^location:/i',$h)) return '';
		}
		
		$t = "\n" . implode("\n\t",$_SESSION['google_analytics_custom']) . "\n";
		unset($_SESSION['google_analytics_custom']);
		return $t;
	}
	public static function Initialise() {
		uEvents::AddCallback('ShowDashboard','GoogleAnalytics::showWidget');
		modOpts::AddOption('google_analytics_token',null);
		modOpts::AddOption('google_analytics_client_id','Client ID', 'Google Analytics');
		modOpts::AddOption('google_analytics_client_secret','Client Secret', 'Google Analytics');
		modOpts::AddOption('google_analytics_account','Tracking Account (UA-XXXXX-X)', 'Google Analytics');
	}
	public function SetupParents() {}
	public function RunModule() {
		$account_id = modOpts::GetOption('google_analytics_account');

		$client = self::GetClient();
		$client->addService('analytics');
		$client->setRedirectUri('http://'.utopia::GetDomainName().$this->GetURL());

		$token = '';
		if (isset($_GET['code'])) {
			$client->authenticate();
			$token = $client->getAccessToken();
			modOpts::SetOption('google_analytics_token',$token);
			$redirect = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
			header('Location: ' . filter_var($redirect, FILTER_SANITIZE_URL));
		}

		$token = modOpts::GetOption('google_analytics_token');
		if ($token) {
			$client->setAccessToken($token);
		}

		if (!$client->getAccessToken()) {
			$authUrl = $client->createAuthUrl();
			echo "<a class='btn' href='$authUrl'>Connect Widget!</a>";
			return;
		}

		$service= self::GetService($client);
		$account = self::GetAccount($service,$account_id);
		if (!$account) {
			echo 'account doesnt exist in this session';
			return;
		}

		$profile = self::GetProfile($service);
return;
		$data = self::GetAnalyticsData($service,
			date('Y-m-d',time()-(60*60*24*14)),
			date('Y-m-d',time()));

//                array('dimensions' => 'ga:date',
//                      'sort' => '-ga:visits,ga:keyword',
//                      'filters' => 'ga:medium==organic',
//                      'max-results' => '25');

		var_dump($data);
	}
	private static $apiClient = null;
	public static function &GetClient() {
		if (self::$apiClient) return self::$apiClient;
		require_once('lib/Google_Client.php');
		$client = new Google_Client();
		$client->setApplicationName("Google Analytics for uCore");

		// Visit https://code.google.com/apis/console?api=analytics to generate your
		// client id, client secret, and to register your redirect uri.
		$client->setClientId(modOpts::GetOption('google_analytics_client_id'));
		$client->setClientSecret(modOpts::GetOption('google_analytics_client_secret'));

		self::$apiClient = &$client;
		return $client;
	}
	private static $apiService = null;
	public static function &GetService($client) {
		if (self::$apiService) return self::$apiService;
		require_once('lib/contrib/Google_AnalyticsService.php');
		$service = new Google_AnalyticsService($client);
		self::$apiService = &$service;
		return $service;
	}
	public static function GetAccount($service) {
		$account = modOpts::GetOption('google_analytics_account');
		$props = $service->management_webproperties->listManagementWebproperties('~all');
		foreach ($props['items'] as $acc) {
			if ($acc['id'] == $account) {
				$account = $acc;
				break;
			}
		}
		return $account;
	}
	public static function GetProfile($service,$fullid = null) {
		$account = self::GetAccount($service);
		if (!$fullid) $fullid = $account['id'];
		$profiles = $service->management_profiles->listManagementProfiles($account['accountId'],$fullid);
		if ($profiles['items']) $profile = reset($profiles['items']);
		return $profile;
	}
	public static function GetAnalyticsData($service,$start_date,$end_date,$metrics='ga:visits',$options=array('dimensions'=>'ga:date')) {
		$profile = self::GetProfile($service);

		$data = $service->data_ga->get(
			'ga:' . $profile['id'],
			$start_date,
			$end_date,
			$metrics,
			$options
		);
		return $data;
	}
	public static function showWidget() {
		$o = utopia::GetInstance(__CLASS__);
		$token = modOpts::GetOption('google_analytics_token');
		if (!$token) {
			if (modOpts::GetOption('google_analytics_client_id') && modOpts::GetOption('google_analytics_client_secret')) {
				$link = $o->GetURL();
				echo '<a href="'.$link.'">Enable Analytics Widget</a>';
			}
			return;
		}
		
		echo '<div><h1>Google Analytics</h1>';
		$client = self::GetClient();
		$client->setAccessToken($token);
		$service = self::GetService($client);
		if (!$service) return;
		
		utopia::AddJSFile(dirname(__FILE__).'/visualize/visualize.jQuery.js');
		utopia::AddCSSFile(dirname(__FILE__).'/visualize/visualize.css');
		
		$data = self::GetAnalyticsData($service,
			date('Y-m-d',time()-(60*60*24*7)),
			date('Y-m-d',time()));

		$row1 = array();
		$row2 = array();
		foreach ($data['rows'] as $r) {
			$dateTime = strptime($r[0],'%Y%m%d');
			$d = mktime(0,0,0, $dateTime['tm_mon']+1, $dateTime['tm_mday']+1, $dateTime['tm_year']+1900);

			$row1[] = '<th>'.date('d M',$d).'</th>';
			$row2[] = '<td>'.$r[1].'</td>';
		}

		echo '<table id="chart1">';
		echo '<thead><td></td>'.implode('',$row1).'</thead>';
		echo '<tbody><th>Visitors</th>'.implode('',$row2).'</tbody>';
		echo '</table>';
		echo <<<FIN
<script type="text/javascript">
$(function(){
	$('#chart1').hide().visualize({title:'Visitors for last 7 days',type:'line',width:'400px',height:'200px',parseDirection:'x'});
});
</script>
FIN;
		$o->RunModule();
		echo '</div>';
	}
}
