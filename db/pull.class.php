<?php
/**
 * Copyright (c) 2014, Johannes Brunswicker
 * This file is licensed under the Affero General Public License version 3 or later.
 * See the COPYRIGHT file.
 */

/**
 * Class collecting functions for pulling data from Pods
 * @author J. Brunswicker
 * @version 1.0
 * @todo Evaluate if it is still required to pull the http header from the pod, as there is a statistics.json now
 */

require_once "Net/GeoIP.php";
require_once 'config.inc.php';

class Pull {

	/**
	 * issues a cUrl request and sends back the result AND the http_info
	 * @param string $url
	 * @param string $result
	 * @param array $info
	 */
	public static function getCurlResultAndInfo($url, &$result, &$info) {
		$curl = curl_init();

		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_POST, CURL_POST);
		curl_setopt($curl, CURLOPT_HEADER, 1);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, CURL_CONNECTTIMEOUT);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, CURL_RETURNTRANSFER);
		curl_setopt($curl, CURLOPT_NOBODY, CURL_NOBODY);

		$result = curl_exec($curl);
		$info = curl_getinfo($curl);
		curl_close($curl);
	}

	/**
	 * Issues a cUrl request to $url and returns the result
	 * @param string $url
	 * @return string
	 */
	public static function getCurlResult($url, $withoutHeader=false) {
		$curl = curl_init();

		if (DEBUG) {
			echo "Curl-Target: ".$url."<br />";
		}

		curl_setopt($curl, CURLOPT_URL, $url);
		if ($withoutHeader) {
			curl_setopt($curl, CURLOPT_HEADER, 0);
		} else {
			curl_setopt($curl, CURLOPT_HEADER, 1);
		}
		curl_setopt($curl, CURLOPT_POST, CURL_POST);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, CURL_CONNECTTIMEOUT);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, CURL_RETURNTRANSFER);
		curl_setopt($curl, CURLOPT_NOBODY, CURL_NOBODY);

		$result = curl_exec($curl);
		curl_close($curl);

		if (VERBOSE_CURL) {
			echo "Curl-Result: ".$result."<br />";
		}

		return $result;
	}

	/**
	 * returns a database Connection
	 * @return PDO
	 */
	public static function getDatabaseConnection() {
		$dsn = DB_DRIVER.":dbname=".DB_NAME.";host=".DB_HOST;

		if (DB_DRIVER == 'mysql') {
			$dsn .= ";charset=UTF8";
		}

		try {
			$connection = new PDO($dsn, DB_USER, DB_PASSWORD);
			return $connection;
		} catch (PDOException $e) {
			echo ("User: ".DB_USER."<br />");
			die('Connection to database with dsn '.$dsn.' failed: ' . $e->getMessage());
		}
	}

	/**
	 * Calculates the adminRating and userRating for the given Pod
	 * @param number $adminRating
	 * @param number $userRating
	 * @param string $podUrl
	 * @param PDO $db
	 */
	public static function getRatings(&$adminRating=0, &$userRating=0, $podUrl, PDO $db) {
		$adminRatingCounter = 0;
		$userRatingCounter = 0;
		$adminRatingTemp = 0;
		$userRatingTemp = 0;
		$sql = "SELECT * FROM rating_comments WHERE domain = ".$db->quote($podUrl);

		$result = $db->query($sql);

		if (!$result) {
			echo("Error fetching SQL Result for Ratings. Error: <pre>");
			print_r($db->errorInfo());
			die();
		}

		while ($row = $result->fetchAll()) {
			if ($row['admin'] == 1) {
				$adminRatingCounter++;
				$adminRatingTemp += $row['rating'];
			} elseif ($row['admin'] == 0) {
				$userRatingCounter++;
				$userRatingTemp += $row['rating'];
			}
		}

		// Set the Ratingvalues
		if ($adminRatingCounter > 0) {
			$adminRating = round($adminRatingTemp/$adminRatingCounter,22);
		} else {
			$adminRating = 0;
		}

		if ($userRatingCounter > 0) {
			$userRating = round($userRatingTemp/$userRatingCounter, 2);
		} else {
			$userRating = 0;
		}
	}

	/**
	 * Parses the header that is returned from the cUrl request
	 * @param string $header
	 * @param string $gitdate
	 * @param string $gitrev
	 * @param string $xdver
	 * @param string $diasporaVersion
	 * @param string $runtime
	 * @param string $server
	 * @param string $encoding
	 */
	public static function parseHeader($header, &$gitdate, &$gitrev, &$xdver, &$diasporaVersion, &$runtime, &$server, &$encoding) {

		preg_match('/X-Git-Update: (.*?)\n/', $header, $xgitdate);

		if (count($xgitdate) > 0) {
			$gitdate = trim($xgitdate[1]);
		}
			
		preg_match('/X-Git-Revision: (.*?)\n/',$header,$xgitrev);
		if (count($xgitrev) > 0) {
			$gitrev = trim($xgitrev[1]);
		}
			
		preg_match('/X-Diaspora-Version: (.*?)\n/',$header,$xdver);
		if (count($xdver) > 0) {
			$dverr = explode("-",trim($xdver[1]));
			$diasporaVersion = $dverr[0];
		}
			
		preg_match('/X-Runtime: (.*?)\n/',$header,$xruntime);
		$runtime = isset($xruntime[1]) ? trim($xruntime[1]) : null;
			
		preg_match('/Server: (.*?)\n/',$header,$xserver);
		$server = isset($xserver[1]) ? trim($xserver[1]) : null;
			
		preg_match('/Content-Encoding: (.*?)\n/',$header,$xencoding);
		if ($xencoding) {
			$encoding = trim($xencoding[1]);
		} else {
			$encoding = null;
		}

		if (DEBUG) {
			echo "GitUpdate: ".$gitdate."<br />";
			echo "GitRev: ".$gitrev."<br />";
			echo "Version code: ".$diasporaVersion."<br />";
			echo "Runtime: ".$runtime."<br />";
			echo "Server: ".$server."<br />";
			echo "Encoding: ".$encoding."<br />";
		}

	}

	/**
	 * parse the statistics.json
	 * @param string $header
	 * @param string $podName
	 * @param string $registrationsOpen
	 * @param string $totalUsers
	 * @param string $activeUsersHalfyear
	 * @param string $activeUsersMonthly
	 * @param string $localPosts
	 * @param string $diasporaVersion
	 * @param string $xdver
	 */
	public static function parseJSON($header, &$podName, &$registrationsOpen, &$totalUsers, &$activeUsersHalfyear, &$activeUsersMonthly, &$localPosts, &$diasporaVersion, &$xdver) {
		preg_match_all("/{(.*?)}/", $header, $JSONArray);
		$JSON = json_decode($JSONArray[0][0]);

		if ($JSON->registrations_open === true) {
			$registrationsOpen = 1;
		} else {
			$registrationsOpen = 0;
		}

		$podName = isset($JSON->name) ? $JSON->name : "null";
		$totalUsers = isset($JSON->total_users) ? $JSON->total_users : 0;
		$activeUsersHalfyear = isset($JSON->active_users_halfyear) ? $JSON->active_users_halfyear : 0;
		$activeUsersMonthly = isset($JSON->active_users_monthly) ? $JSON->active_users_monthly : 0;
		$localPosts = isset($JSON->local_posts) ? $JSON->local_posts : 0;
		if (isset($JSON->version)) {
			$version = explode("-", $JSON->version);
			$xdver = $JSON->version;
			$diasporaVersion = $version[0];
		}

		if (DEBUG) {
			echo "Registrations Open: ".$registrationsOpen."<br />";
			echo "PodName: ".$podName."<br />";
			echo "Active user over half year: ".$activeUsersHalfyear."<br />";
			echo "Active user monthly: ".$activeUsersMonthly."<br />";
			echo "Local Posts: ".$localPosts."<br />";
		}

	}

	public static function getMasterVersion() {
		//get master code version
		$masterVersionResult = Pull::getCurlResult("https://raw.github.com/diaspora/diaspora/master/config/defaults.yml");
		preg_match('/number: "(.*?)"/', $masterVersionResult, $masterVersion);
		$masterVersion = trim($masterVersion[1], '"');

		if (DEBUG) {
			echo "MasterVersion: ".$masterVersion."<br />";
		}

		return $masterVersion;
	}

	public static function getPodList($domain, PDO $dbConnection) {
		if (DEBUG) {
			echo "Getting List of Pods from Database<br /><br />";
		}

		if ($domain) {
			// Pull is requested for specific Domain
			$sql = "SELECT domain, pingdomurl, score, datecreated, adminrating FROM pods WHERE domain = ".$dbConnection->quote($domain);
		} else {
			// General pull. Get all pods from Database
			$sql = "SELECT domain, pingdomurl, score, datecreated, adminrating FROM pods";
		}

		$result = $dbConnection->query($sql);

		if (!$result) {
			if ($domain) {
				echo("Error fetching SQL Result for Pod: ".$domain.". Error: <pre>");
				print_r($dbConnection->errorInfo());
				die();
			} else {
				echo("Error fetching SQL Result. Error: <pre>");
				print_r($dbConnection->errorInfo());
				die();
			}
		} else {
			if ($result->rowCount() <= 0 && DEBUG) {
				echo "Podlist is empty";
			}
			return $result;
		}
	}

	/**
	 * Cap the score at 20 and -20
	 * @param integer $score
	 */
	public static function capScore(&$score) {
		if ($score > 20) {
			$score = 20;
		} elseif ($score < -20) {
			$score = -20;
		}
	}

	/**
	 * Returns the IPv6 Address of the pod if there is any
	 * @param string $podurl
	 * @return string
	 */
	public static function getIPv6($podurl) {
		$command = escapeshellcmd('dig +nocmd '.$podurl.' aaaa +noall +short');
		$result = exec($command);
		if (DEBUG) {
			echo "IPv6: ".$result."<br />";
		}
		return $result;
	}

	/**
	 * Returns the IPv4 Address of the pod, if there is any
	 * @param string $podurl
	 * @return string
	 */
	public static function getIPv4($podurl) {
		$command = escapeshellcmd('dig +nocmd '.$podurl.' a +noall +short');
		$result = exec($command);
		if (DEBUG) {
			echo "IPv4: ".$result."<br />";
		}
		return $result;
	}

	/**
	 * Tries ti get a GeoIP based Location
	 * @param string $ipnum
	 * @param string $whois
	 * @param string $country
	 * @param string $city
	 * @param string $lat
	 * @param string $long
	 */
	public static function getGeoIPData($ipnum, &$whois, &$country, &$city, &$lat, &$long) {
		$geoip = Net_GeoIP::getInstance("GeoLiteCity.dat");
		try {
			$location = $geoip->lookupLocation($ipnum);
			if (DEBUG) {
				echo "GEOIP: ".$location."<br />";
			}
		} catch (Exception $e) {
			// 	Handle exception
		}
		$whois = "Country: ".$location->countryName."\n Lat:".$location->latitude." Long:".$location->longitude;
		$country = $location->countryName;
		if (isset($location->city) && ($location->city != '')) {
			$city = utf8_encode($location->city);
		} else {
			$city = "null";
		}
		$lat = $location->latitude;
		$long = $location->longitude;

		if (DEBUG) {
			echo "Whois: ".$whois."<br />";
			echo "Country: ".$country."<br />";
			echo "City: ".$city."<br />";
			echo "Latitude: ".$lat."<br />";
			echo "Longitude: ".$long."<br />";
		}
	}

	/**
	 * Gets data from pingdom.com
	 * @param string $pingdomUrl
	 * @param string $responsetime
	 * @param string $months
	 * @param string $uptime
	 * @param string $live
	 * @param string $score
	 * @deprecated
	 * @todo Evaluate if this function is still needed
	 */
	private static function getPingdomData($pingdomUrl, &$responsetime, &$months, &$uptime, &$live, &$score) {
		// Pod is monitored via pingdom
		$thismonth = "/".date("Y")."/".date("m");
		Pull::getCurlResultAndInfo($pingdomUrl.$thismonth,$pingdom,$info);

		if ($pingdom) {
			if ($info['http_code'] == 200) {
					
				//response time
				preg_match_all('/<h3>Avg. resp. time this month<\/h3>
					        <p class="large">(.*?)</',$pingdom,$matcheach);
				$responsetime = $matcheach[1][0];
					
				//months monitored
				preg_match_all('/"historySelect">\s*(.*?)\s*<\/select/is',$pingdom,$matchhistory);
				$implodemonths = implode(" ", $matchhistory[1]);
					
				preg_match_all('/<option(.*?)/s',$implodemonths,$matchdates);
				$months = isset($matchdates[0])?count($matchdates[0]):0;
					
				preg_match_all('/<h3>Uptime this month<\/h3>\s*<p class="large">(.*?)%</',$pingdom,$matchper);
				$uptime = isset($matchper[1][0])?preg_replace("/,/", ".", $matchper[1][0]):0;
					
				if (strpos($pingdom,"class=\"up\"")) {
					$live="up";
				} elseif (strpos($pingdom,"class=\"down\"")) {
					$live="down";
				} elseif (strpos($pingdom,"class=\"paused\"")) {
					$live="paused";
				} else {
					$live="error";
					$score -= 2;
				}
			} else {
				//pingdom url is <> 200 so stats are gone, lower score
				$score -= 2;
			}
				
			if (DEBUG) {
				echo "Pingdom - Url: ".$pingdomUrl.$thismonth."<br />";
				echo "Pingdom code: ".$info['http_code']."<br />";
				echo "Responsetime: ".$responsetime."<br />";
				echo "Months: ".$months."<br />";
				echo "Live: ".$live."<br />";
				echo "Score: ".$score."<br />";
			}
				
			return true;
				
		} else {
			if (DEBUG) {
				echo "No connection to pingdomdata.com";
			}
			return false;
		}
	}

	/**
	 * Gets data from the uptimerobot
	 * @param string $pingdomUrl
	 * @param string $responsetime
	 * @param string $months
	 * @param string $uptime
	 * @param string $live
	 */
	private static function getUptimerobotData($pingdomUrl, $datecreated, &$responsetime, &$months, &$uptime, &$live) {
		//do uptimerobot API instead
		$uptimerobot = Pull::getCurlResult($pingdomUrl, true);
		if ($uptimerobot) {
			$json_encap = "jsonUptimeRobotApi()";
			$up2 = substr ($uptimerobot, strlen($json_encap) - 1, strlen ($uptimerobot) - strlen($json_encap));

			$JSON = json_decode($up2);
				
			$responsetime = 'n/a';
			$uptime = $JSON->monitors->monitor{'0'}->alltimeuptimeratio; // Uptimeratio
				
			$diff = abs(strtotime(date('Y-m-d H:i:s')) - strtotime($datecreated));
			$months = floor($diff / (30*24*60*60));
				
			switch ($JSON->monitors->monitor{'0'}->status) {
				case 1:
					$live = "Paused";
					break;
				case 2:
					$live = "Up";
					break;
				case 8:
					$live = "Seems Down";
					break;
				case 9:
					$live = "Down";
					break;
			}
				
			if (DEBUG) {
				echo "UptimeRobot - Url: ".$pingdomUrl."<br />";
				echo "Uptime: ".$uptime."<br />";
				echo "Responsetime: ".$responsetime."<br />";
				echo "Months: ".$months."<br />";
				echo "Live: ".$live."<br />";
			}
			return true;
				
		} else {
			if (DEBUG) {
				echo "No connection to uptimerobot. Will not update data<br />";
			}
				
			return false;
		}
	}

	/**
	 * gets the data from the robot
	 * @param string $pingdomUrl
	 * @param string $datecreated
	 * @param string $responsetime
	 * @param string $months
	 * @param string $uptime
	 * @param string $live
	 * @param string $score
	 * @return boolean
	 */
	public static function getRobotData($pingdomUrl, $datecreated, &$responsetime, &$months, &$uptime, &$live, &$score) {
		$month = 0;
		$uptime = 0;

		if ($pingdomUrl != '') {
			if (strpos($pingdomUrl, "pingdom.com")) {
				$result = Pull::getPingdomData($pingdomUrl, $responsetime, $months, $uptime, $live, $score);
			} else {
				$result = Pull::getUptimerobotData("http://api.uptimerobot.com/getMonitors?format=json&customUptimeRatio=7-30-60-90&apiKey=".$pingdomUrl, $datecreated, $responsetime, $months, $uptime, $live);
			}
				
			return $result;
		} else {
			echo "No PingdomURL provided<br />";
			return false;
		}
	}

	/**
	 * Writes the Data into the Database
	 * @param PDO $connection
	 * @param string $gitdate
	 * @param string $encoding
	 * @param string $secure
	 * @param string $hidden
	 * @param string $runtime
	 * @param string $gitrev
	 * @param string $ipnum
	 * @param string $ipv6
	 * @param string $months
	 * @param string $uptime
	 * @param string $live
	 * @param string $pingdomdate
	 * @param string $timenow
	 * @param string $responsetime
	 * @param string $score
	 * @param string $adminRating
	 * @param string $country
	 * @param string $city
	 * @param string $state
	 * @param string $lat
	 * @param string $long
	 * @param string $diasporaVersion
	 * @param string $whois
	 * @param string $userRating
	 * @param string $xdver
	 * @param string $masterVersion
	 * @param string $registrationsOpen
	 * @param string $totalUsers
	 * @param string $activeUsersHalfyear
	 * @param string $activeUsersMonthly
	 * @param string $localPosts
	 * @param string $podName
	 * @param string $domain
	 */
	public static function writeData(PDO $connection, $gitdate, $encoding, $secure, $hidden, $runtime, $gitrev, $ipnum, $ipv6, $months, $uptime, $live, $pingdomdate, $timenow, $responsetime, $score, $adminRating, $country, $city, $state, $lat, $long, $diasporaVersion, $whois, $userRating, $xdver, $masterVersion, $registrationsOpen, $totalUsers, $activeUsersHalfyear, $activeUsersMonthly, $localPosts, $podName, $domain) {

		$sql = "UPDATE pods SET Hgitdate=".$connection->quote($gitdate).", Hencoding=".$connection->quote($encoding).", secure=".$connection->quote($secure).", hidden=".$connection->quote($hidden).", Hruntime=".$connection->quote($runtime).", ";
		$sql .= "Hgitref=".$connection->quote($gitrev).", ip=".$connection->quote($ipnum).", ipv6=".$connection->quote($ipv6).", monthsmonitored=".$months.", uptimelast7=".$connection->quote($uptime).", status=".$connection->quote($live).", ";
		$sql .= "dateLaststats=".$connection->quote($pingdomdate).", dateUpdated=".$connection->quote($timenow).", responsetimelast7=".$connection->quote($responsetime).", score=".$connection->quote($score).", ";
		$sql .= "adminrating=".$connection->quote($adminRating).", country=".$connection->quote($country).", city=".$connection->quote($city).", state=".$connection->quote($state).", lat=".$connection->quote($lat).", ";
		$sql .= "connection=".$connection->quote($diasporaVersion).", whois=".$connection->quote($whois).", userrating=".$connection->quote($userRating).", longversion=".$connection->quote($xdver).", ";
		$sql .= "shortversion=".$connection->quote($diasporaVersion).", masterversion=".$connection->quote($masterVersion).", signup=".$connection->quote($registrationsOpen).", total_users=".$connection->quote($totalUsers).", ";
		$sql .= "active_users_halfyear=".$connection->quote($activeUsersHalfyear).", active_users_monthly=".$connection->quote($activeUsersMonthly).", local_posts=".$connection->quote($localPosts).", ";
		$sql .= "name=".$connection->quote($podName).", ";

		if (DB_NAME == 'mysql') {
			$sql .= "`long`=".$connection->quote($long)." ";
		} else {
			$sql .= "long=".$connection->quote($long)." ";
		}

		$sql .= "WHERE ";
		$sql .= "domain=".$connection->quote($domain);

		$result = $connection->query($sql);
		if (!$result) {
			echo("Error executing SQL query: <pre>");
			print_r($connection->errorInfo());
			die();
		}
	}
	
	/**
	 * tries to get the header from the Pod and returns the ssl status
	 * @param string $podUrl
	 * @param string $podSecure
	 * @return string|NULL
	 */
	public static function getHeaderFromPod($podUrl, &$podSecure) {
		// Get Header from Pod
		Pull::getCurlResultAndInfo("https://".$podUrl."/statistics.json", $header, $info);
		if ($info['http_code'] == 0 || $info['http_code'] == '404') {
			Pull::getCurlResultAndInfo("http://".$podUrl."/statistics.json", $header, $info);
			if ($info['http_code'] == 0 || $info['http_code'] == '404') {
				// No http connection either. Decreasing point
				$header = null;
				if (DEBUG) {
					echo "No connection to Pod possible. Deleting header<br />";
				}
			}
		} else {
			$podSecure = "true";
		}

		return $header;
	}
}
?>