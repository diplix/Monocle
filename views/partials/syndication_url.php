<?php
if($this->syndication->syndication_url) {
  $domain = parse_url($this->syndication->syndication_url, PHP_URL_HOST);
  switch($domain) {
    case 'twitter.com':
      $icon = 'twitter';
      break;
    case 'facebook.com':
      $icon = 'facebook';
      break;
    case 'instagram.com':
      $icon = 'instagram';
      break;
    case 'medium.com':
      $icon = 'medium';
      break;
    case 'tumblr.com':
      $icon = 'tumblr';
      break;
    case 'youtube.com':
      $icon = 'youtube';
      break;
    default:
      $icon = 'globe';
  }
  echo '<a href="' . $this->syndication->syndication_url . '"><i class="fa fa-' . $icon . '"></i></a>';

}
