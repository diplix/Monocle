<?php
// Default timezone
date_default_timezone_set('UTC');

ORM::configure('mysql:host=' . Config::$dbHost . ';dbname=' . Config::$dbName);
ORM::configure('username', Config::$dbUsername);
ORM::configure('password', Config::$dbPassword);

function friendly_url($url) {
  return preg_replace(['/https?:\/\//','/\/$/'],'',$url);
}

function friendly_date($date_string, $tz_offset) {
  $date = new DateTime($date_string);
  if($tz_offset > 0)
    $date->add(new DateInterval('PT'.$tz_offset.'S'));
  elseif($tz_offset < 0)
    $date->sub(new DateInterval('PT'.abs($tz_offset).'S'));
  $tz = ($tz_offset < 0 ? '-' : '+') . sprintf('%02d:%02d', abs($tz_offset/60/60), ($tz_offset/60)%60);
  return $date->format('F j, Y g:ia') . ' ' . $tz;
}

// Input: Any URL or string like "aaronparecki.com"
// Output: Normlized URL (default to http if no scheme, force "/" path)
//         or return false if not a valid URL
function normalize_url($url) {
  $me = parse_url($url);

  if(array_key_exists('path', $me) && $me['path'] == '')
    return false;

  // parse_url returns just "path" for naked domains
  if(count($me) == 1 && array_key_exists('path', $me)) {
    $me['host'] = $me['path'];
    unset($me['path']);
  }

  if(!array_key_exists('scheme', $me))
    $me['scheme'] = 'http';

  if(!array_key_exists('path', $me))
    $me['path'] = '/';

  // Invalid scheme
  if(!in_array($me['scheme'], array('http','https')))
    return false;

  return build_url($me);
}

function render($page, $data) {
  global $app;
  ob_start();
  $app->render('layout.php', array_merge($data, array('page' => $page)));
  return ob_get_clean();
};

function partial($template, $data=array(), $debug=false) {
  global $app;

  if($debug) {
    $tpl = new Savant3(\Slim\Extras\Views\Savant::$savantOptions);
    echo '<pre>' . $tpl->fetch($template . '.php') . '</pre>';
    return '';
  }

  ob_start();
  $tpl = new Savant3(\Slim\Extras\Views\Savant::$savantOptions);
  foreach($data as $k=>$v) {
    $tpl->{$k} = $v;
  }
  $tpl->display($template . '.php');
  return ob_get_clean();
}

function json_response(&$app, $response) {
  $app->response()['Content-Type'] = 'application/json';
  $app->response()->body(json_encode($response));
}

function session($key) {
  if(array_key_exists($key, $_SESSION))
    return $_SESSION[$key];
  else
    return null;
}

function k($a, $k, $default=null) {
  if(is_array($k)) {
    $result = true;
    foreach($k as $key) {
      $result = $result && array_key_exists($key, $a);
    }
    return $result;
  } else {
    if(is_array($a) && array_key_exists($k, $a) && $a[$k])
      return $a[$k];
    elseif(is_object($a) && property_exists($a, $k) && $a->$k)
      return $a->$k;
    else
      return $default;
  }
}

function get_timezone($lat, $lng) {
  try {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://atlas.p3k.io/api/timezone?latitude='.$lat.'&longitude='.$lng);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $tz = @json_decode($response);
    if($tz)
      return new DateTimeZone($tz->timezone);
  } catch(Exception $e) {
    return null;
  }
  return null;
}

function micropub_post($endpoint, $params, $access_token, $h='entry') {
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $endpoint);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Authorization: Bearer ' . $access_token
  ));
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge(array(
    'h' => $h
  ), $params)));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HEADER, true);
  $response = curl_exec($ch);
  $error = curl_error($ch);
  return array(
    'response' => $response,
    'error' => $error,
    'curlinfo' => curl_getinfo($ch),
    'status' => curl_getinfo($ch, CURLINFO_HTTP_CODE)
  );
}

function relative_time($date) {
  static $rel;
  if(!isset($rel)) {
    $config = array(
      'language' => '\RelativeTime\Languages\English',
      'separator' => ', ',
      'suffix' => true,
      'truncate' => 1,
    );
    $rel = new \RelativeTime\RelativeTime($config);
  }
  return $rel->timeAgo($date);
}

function bs()
{
  static $pheanstalk;
  if(!isset($pheanstalk)) {
    $pheanstalk = new Pheanstalk\Pheanstalk(Config::$beanstalkServer, Config::$beanstalkPort);
  }
  return $pheanstalk;
}

function pluralize($word, $num) {
  if($num == 1) {
    return $word;
  } else { 
    return $word . 's';
  }
}

function response_icon($type) {
  switch($type) {
    case 'like':
      return '<i class="fa fa-star-o"></i>';
    case 'comment':
      return '<i class="fa fa-comment-o"></i>';
    case 'repost':
      return '<i class="fa fa-retweet"></i>';
    case 'rsvp':
      return '<i class="fa fa-calendar"></i>';
  }
}
