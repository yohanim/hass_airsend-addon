<?php

/*
 * Home Assistant REST API helper used by the AirSend callback (hass_cb.php).
 *
 * The callback runs once per AirSend event. To stay cheap on large Home
 * Assistant installations it no longer decodes and hashes the whole state
 * machine on every event:
 *  - a compact index (states_index.json) is built from GET /api/states at
 *    most every INDEX_TTL seconds. It maps the 12 hex digits uid used by the
 *    integration (sha256 of the entity_id) to the entity_id, and keeps only
 *    the entities carrying an AirSend "channel" attribute for channel lookups;
 *  - the state of an entity about to be updated is read live with
 *    GET /api/states/<entity_id>, so it is always fresh and the whole state
 *    machine no longer has to be re-downloaded after toggle/data events.
 */
class HassAPI{
	const INDEX_TTL = 100;
	const STATES_FILE = 'states.json';
	const INDEX_FILE = 'states_index.json';
	const INDEX_VERSION = 1;

	var $index = null;

	function __construct($BASE_HASS_API, $HASS_API_TOKEN, $auto_include = false) {
		$this->BASE_HASS_API = $BASE_HASS_API;
		$this->HASS_API_TOKEN = $HASS_API_TOKEN;
		$this->auto_include = $auto_include;
	}

	public function isAuthorized() {
		if(!empty($this->BASE_HASS_API) && !empty($this->HASS_API_TOKEN)){
			return true;
		}
		return false;
	}
	public function isAutoInclude() {
		return $this->auto_include;
	}

	public static function uidOf($entity_id) {
		return intval(str_split(hash('sha256', $entity_id), 12)[0], 16);
	}

	function searchEntityId($uid) {
		if($this->loadIndex()){
			$key = (string)intval($uid, 0);
			if(array_key_exists($key, $this->index['uids'])){
				return $this->index['uids'][$key];
			}
		}
		return null;
	}

	function searchEntitiesFromChannelAndType($channel, $type) {
		$ret = array();
		if(isset($channel) && $this->loadIndex()){
			foreach ($this->index['channels'] as $value) {
				if(self::isChannelCompatible($channel, $value['channel'])){
					$foundid = false;
					$entity_id = $value['entity_id'];
					//Sensors
					$is_sensor = (stripos($entity_id, "sensor.") !== false) ? true : false;
					if($is_sensor && isset($value['device_class'])){
						$dclass = $value['device_class'];
						$dclass = str_replace('humidity', 'r_humidity', $dclass);
						if($dclass == $type){
							$foundid = true;
						}
					}
					//Others
					if($type == 'level' || $type == 'state' || $type == 'toggle' || $type == 'data'){
						$foundid = true;
					}
					if($foundid){
						$ret[] = $entity_id;
					}
				}
			}
		}
		return $ret;
	}

	function setState($entity_id, $type, $state, $timestamp_ms, $channel = null) {
		$new_entity = false;
		$attributes = null;
		if(isset($channel) && ((int)$channel["id"]) == 1){
			if($type === 'data'){
				return;	//accept AirSend state only
			}
		}
		if($entity_id == null && isset($channel)){
			$new_entity = true;
			//New entity
			$entity_id = "sensor.".self::toUniqueChannelName($channel);
			$attributes = array('channel' => $channel);
			if($type === 'temperature'){
				$entity_id .= '_temp';
				$attributes['device_class'] = 'temperature';
				$attributes['unit_of_measurement'] = '°C';
			}else if($type === 'illuminance'){
				$entity_id .= '_ill';
				$attributes['device_class'] = 'illuminance';
				$attributes['unit_of_measurement'] = 'lx';
			}else if($type === 'r_humidity'){
				$entity_id .= '_rh';
				$attributes['device_class'] = 'humidity';
				$attributes['unit_of_measurement'] = '%';
			}else if($type === 'toggle' || $type === 'data'){
				//auto_include disabled for toggle, it's too messy on dashboard
				return ;
			}
		}
		$content = $this->getState($entity_id);
		if($content == null && $attributes != null && $this->auto_include){
			$content = array('attributes' => $attributes);
		}
		if($content != null){
			if(!isset($content['attributes']) || !is_array($content['attributes'])){
				$content['attributes'] = array();
			}
			if($attributes != null){
				$content['attributes'] = array_merge($content['attributes'], $attributes);
			}
			$current_position = null;
			if($type === 'data'){
				if ($state && strlen($state) > 20){
				   $state = substr($state, 0, 17) . '...';
				}
				if(array_key_exists('state', $content) && $content['state'] == $state){
					$state .= '+';
				}
			}
			if($type === 'toggle'){
				if(array_key_exists('state', $content) && $content['state'] == 'pressed'){
					$state .= '+';
				}
			}
			if($type === 'level'){
				$is_cover = (stripos($entity_id, "cover.") !== false) ? true : false;
				$current_position = intval($state);
				$state = $current_position.'%';
				if($current_position >= 100){
					$state = $is_cover ? 'open': 'on';
				}else if($current_position <= 0){
					$state = $is_cover ? 'closed': 'off';
				}
			}
			$content['state'] = $state;
			if($current_position !== null){
				if(array_key_exists('supported_features', $content['attributes']) && ($content['attributes']['supported_features']&0x4) == 0x4){
					$content['attributes']['current_position'] = $current_position;
				}
			}
			$post = array('state' => $content['state'], 'attributes' => $content['attributes']);
			$this->state_post($entity_id, $post);
			if($new_entity){
				// A new sensor appeared: let the next event find it by channel
				$this->invalidateIndex();
			}
			return true;
		}
		return false;
	}

	/** Read one entity state live (always fresh). */
	function getState($entity_id){
		if(!$this->isAuthorized() || !isset($entity_id) || !self::isValidEntityId($entity_id)){
			return null;
		}
		if($this->isSupervisor()){
			//Bug in supervisor proxy => use bash
			$output = array();
			exec('./state_get.sh '.escapeshellarg($entity_id), $output, $retval);
			$data = implode("\n", $output);
		}else{
			$res = HassAPI::request($this->BASE_HASS_API."/states/".$entity_id, null, 'GET', $this->HASS_API_TOKEN);
			$data = ($res !== false && isset($res['info']['http_code']) && $res['info']['http_code'] == 200) ? $res['data'] : null;
		}
		if(empty($data)){
			return null;
		}
		$value = json_decode($data, true);
		if(!is_array($value) || !isset($value['entity_id'])){
			return null;
		}
		return $value;
	}

	public static function isValidEntityId($entity_id){
		return is_string($entity_id) && preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $entity_id) === 1;
	}

	public static function extractHostAndPort($input, $default_port = 8123){
		$hostWithPort = preg_replace('#^https?://#', '', $input);
		$hostWithPort = explode('/', $hostWithPort)[0];
		if (strpos($hostWithPort, ':') !== false) {
			list($hostname, $port) = explode(':', $hostWithPort, 2);
			$port = (int)$port;
		} else {
			$hostname = $hostWithPort;
		}
		if(empty($port) || ($port <= 0 && $port >= 0x1000)){
			$port = $default_port;
		}
		return [
			'hostname' => $hostname,
			'port'     => $port
		];
	}

	public static function convertNotesToStates($notes){
		$ret = array();
		if(is_array($notes) && count($notes) > 0){
			foreach ($notes as $i => $note) {
                $ovalue = $note['value'];
				$stype = null;
                $svalue = null;
				if($note['type'] == 0){			//STATE
                    if(!is_numeric($ovalue)){
                        $statemap = array("","PING","PROG","UNPROG","RESET",
                        "","","","","","","","","","","","","STOP","TOGGLE","OFF","ON","CLOSE","OPEN",
                        "","","","","","","","","","","MIDDLE","DOWN","UP","LEFT","RIGHT","USERPOSITION");
                        $ovalue = array_search($ovalue, $statemap);
                    }
					if(is_numeric($ovalue)){
						$stype = 'state';
						switch(intval($ovalue)){
							case 18:		//TOGGLE
								$stype = 'toggle';
								$svalue = 'pressed';
							break;
							case 19:		//OFF
								$stype = 'level';
								$svalue = 0;
							break;
							case 20:		//ON
								$stype = 'level';
								$svalue = 100;
							break;
							case 17:		//STOP
								$svalue = 'stop';
							break;
							case 33:		//MIDDLE
							case 38:		//USERPOS
								$svalue = 'user';
							break;
							case 34:		//DOWN
								$stype = 'level';
								$svalue = 0;
							break;
							case 35:		//UP
								$stype = 'level';
								$svalue = 100;
							break;
						}
					}
				}else if($note['type'] == 1){	//DATA
					$stype = 'data';
					$svalue = $ovalue;
				}else if($note['type'] == 2){	//TEMPERATURE
					$stype = 'temperature';
					$svalue = (floor((floatval($ovalue) - 273.15) * 10.0 + 0.5) / 10.0);	//Kelvins to celcius
				}else if($note['type'] == 3){	//ILLUMINANCE
					$stype = 'illuminance';
					$svalue = floatval($ovalue);
                }else if($note['type'] == 4){	//R_HUMIDITY
					$stype = 'r_humidity';
                    $svalue = intval($ovalue);
				}else if($note['type'] == 9){	//LEVEL
					$stype = 'level';
					$svalue = intval($ovalue);
				}
				if($stype != null){
					$ret[] = array($stype, $svalue);
				}
			}
		}
		return $ret;
	}

	public static function isChannelCompatible($obj1, $obj2){
        $result = false;
        if(isset($obj1) && isset($obj2) && is_array($obj1) && is_array($obj2)){
            $ch1 = self::toBasicChannel($obj1);
            $ch2 = self::toBasicChannel($obj2);
            $result = ($ch1 == $ch2);
        }
        return $result;
    }

	public static function toUniqueChannelName($channel){
        $result = $channel['id'];
        if($result){
            $uniquefield = array('source', 'mac', 'seed');
            foreach ($uniquefield as $field) {
                if(array_key_exists($field, $channel)){
                    $result .= "_";
                    $result .= $channel[$field];
                }
            }
        }
        return $result;
    }

	public static function toBasicChannel($channel){
        $result = array();
        $uniquefield = array('id', 'source');
        foreach ($channel as $key => $value) {
            if (in_array($key, $uniquefield)) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

	/** Build the compact index from a decoded GET /api/states response. */
	public static function buildIndex($states){
		$index = array('version' => self::INDEX_VERSION, 'uids' => array(), 'channels' => array());
		if(!is_array($states)){
			return $index;
		}
		foreach ($states as $value) {
			if(!isset($value['entity_id'])){
				continue;
			}
			$entity_id = $value['entity_id'];
			$index['uids'][(string)self::uidOf($entity_id)] = $entity_id;
			if(isset($value['attributes']['channel']) && is_array($value['attributes']['channel'])){
				$entry = array('entity_id' => $entity_id, 'channel' => $value['attributes']['channel']);
				if(isset($value['attributes']['device_class'])){
					$entry['device_class'] = $value['attributes']['device_class'];
				}
				$index['channels'][] = $entry;
			}
		}
		return $index;
	}

	private function isSupervisor(){
		return strpos($this->BASE_HASS_API, "supervisor") !== false;
	}

	private function state_post($entity_id, $content){
		if(!self::isValidEntityId($entity_id)){
			trigger_error("state_post: invalid entity_id ".$entity_id, E_USER_WARNING);
			return;
		}
		$json = json_encode($content, JSON_FORCE_OBJECT);
		if($this->isSupervisor()){
			//Bug in supervisor proxy => use bash
			exec('./state_post.sh '.escapeshellarg($entity_id).' '.escapeshellarg(base64_encode($json)), $output, $retval);
		}else{
			$res = HassAPI::request($this->BASE_HASS_API."/states/".$entity_id, $json, 'POST', $this->HASS_API_TOKEN);
		}
		trigger_error("state_post : ".$entity_id." ".$content['state'], E_USER_NOTICE);
	}

	/** Download all states (only to rebuild the index) into states.json. */
	private function states_get_cache(){
		if($this->isSupervisor()){
			//Bug in supervisor proxy => use bash
			exec('./states_get.sh', $output, $retval);
		}else{
			$res = HassAPI::request($this->BASE_HASS_API."/states", null, 'GET', $this->HASS_API_TOKEN);
			if($res !== false && isset($res) && is_array($res) && isset($res['data'])){
				file_put_contents(self::STATES_FILE, $res['data']);
			}
		}
	}

	private function invalidateIndex(){
		$this->index = null;
		@unlink(self::INDEX_FILE);
	}

	private function loadIndex() {
		if($this->index !== null){
			return true;
		}
		if(!$this->isAuthorized()){
			return false;
		}
		clearstatcache();
		$index_age = @(time() - filemtime(self::INDEX_FILE));
		if(file_exists(self::INDEX_FILE) && $index_age <= self::INDEX_TTL){
			$index = json_decode(@file_get_contents(self::INDEX_FILE), true);
			if(is_array($index) && isset($index['version']) && $index['version'] == self::INDEX_VERSION){
				$this->index = $index;
				return true;
			}
		}
		// Rebuild: one full download and decode, at most every INDEX_TTL seconds
		$this->states_get_cache();
		$states = json_decode(@file_get_contents(self::STATES_FILE), true);
		@unlink(self::STATES_FILE);
		if(!is_array($states)){
			trigger_error("API GET states wrong response", E_USER_WARNING);
			return false;
		}
		$this->index = self::buildIndex($states);
		file_put_contents(self::INDEX_FILE, json_encode($this->index));
		return true;
	}

    private static function request($url, $data = null, $method = 'GET', $token = null) {
		$result = false;
		if (extension_loaded('curl') === true){
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, $url);
			curl_setopt($curl, CURLOPT_AUTOREFERER, true);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
			curl_setopt($curl, CURLOPT_TIMEOUT, 15);
			if (preg_match('~^(?:DELETE|GET|POST|PUT)$~i', $method) > 0){
				if (preg_match('~^(?:POST|PUT)$~i', $method) > 0){
					if (is_array($data) === true){
						foreach (preg_grep('~^@~', $data) as $key => $value){
							$data[$key] = sprintf('@%s', rtrim(str_replace('\\', '/', realpath(ltrim($value, '@'))), '/') . (is_dir(ltrim($value, '@')) ? '/' : ''));
						}
						if (count($data) != count($data, COUNT_RECURSIVE)){
							$data = http_build_query($data, '', '&');
						}
					}
					curl_setopt($curl, CURLOPT_POST, true);
					curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
				}
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));
				if (isset($token)){
					$options = array('Content-Type: application/json', 'Authorization: Bearer ' . $token);
					curl_setopt($curl, CURLOPT_HTTPHEADER, $options);
				}
				$result = array();
				$result['data'] = curl_exec($curl);
				$result['info'] = curl_getinfo($curl);
				if (curl_errno($curl)) {
					$result['error'] = curl_error($curl);
				}
			}
			curl_close($curl);
		}
		return $result;
	}
}

?>
