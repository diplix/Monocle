<?php
namespace request;

function get_url($url, $include_headers=false) {
  $ch = curl_init($url);
  set_user_agent($ch);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  if($include_headers) {
    curl_setopt($ch, CURLOPT_HEADER, true);
    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return [
      'headers' => trim(substr($response, 0, $header_size)),
      'body' => substr($response, $header_size)
    ];
  } else {
    return curl_exec($ch);
  }
}

function get_head($url) {
  $ch = curl_init($url);
  set_user_agent($ch);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HEADER, true);
  curl_setopt($ch, CURLOPT_NOBODY, true);
  return curl_exec($ch);
}

function post($url, $params, $format='form') {
  $ch = curl_init($url);
  set_user_agent($ch);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  if($format == 'json') {
    $body = json_encode($params);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
  } else {
    $body = http_build_query($params);
  }
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  $response = curl_exec($ch);
  return [
    'status' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
    'body' => $response
  ];
}

function set_user_agent(&$ch) {
  // Unfortunately I've seen a bunch of websites return different content when the user agent is set to something like curl or other server-side libraries, so we have to pretend to be a browser to successfully get the real HTML
  curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_8_2) p3k/Monocle/0.1.0 AppleWebKit/537.36 (KHTML, like Gecko) Chrome/29.0.1547.57 Safari/537.36');
}

