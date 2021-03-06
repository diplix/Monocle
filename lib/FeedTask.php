<?php
use BarnabyWalters\Mf2;

class FeedTask {

  public static function refresh_feed($feed_id) {
    $feed = db\get_feed($feed_id);

    echo "Refreshing feed ".$feed->feed_url." ($feed_id)\n\n";

    // check if this feed is already being refreshed, and re-queue the job for 30 seconds from now to give the first job a chance to finish
    if($feed->refresh_in_progress) {
      echo "This feed is already being processed, re-queuing for later\n";
      DeferredTask::queue('FeedTask', 'refresh_feed', $feed_id, 5);
      return; // return here which will cause the job runner to re-queue the job
    }

    // mark that this feed is currently being refreshed
    $feed->refresh_started = date('Y-m-d H:i:s');
    $feed->refresh_in_progress = 1;
    $feed->save();

    // only deal with mf2 feeds for now

    try {

      $response = request\get_url($feed->feed_url, true);
      $header_rels = IndieWeb\http_rels($response['headers']);
      $html = $response['body'];
      $mf2 = feeds\parse_mf2($html, $feed->feed_url);
      $hub_url = false;

      if(k($header_rels, 'hub')) {
        $hub_url = $header_rels['hub'][0];
        $hub_url_source = 'http';
      } elseif(k($mf2, 'rels') && k($mf2['rels'], 'hub')) {
        $hub_url = $mf2['rels']['hub'][0];
        $hub_url_source = 'html';
      }

      // check for PuSH info and subscribe to the hub if found
      if($hub_url) {

        if(k($header_rels, 'self')) {
          $self_url = $header_rels['self'][0];
          $self_url_source = 'http';
        } elseif(k($mf2, 'rels') && k($mf2['rels'], 'self')) {
          $self_url = $mf2['rels']['self'][0];
          $self_url_source = 'html';
        } else {
          $self_url = $feed->feed_url;
          $self_url_source = 'default';
        }

        // Keep track of what the hub URL was last time we saw it
        $last_hub_url = $feed->push_hub_url;

        // Store the new hub and topic
        $feed->push_hub_url = $hub_url;
        $feed->push_topic_url = $self_url;

        // re-subscribe if the expiration date is coming up soon
        // or if the hub has changed
        if($feed->push_subscribed == 0
           || ($hub_url != $last_hub_url)
           || ($feed->push_expiration && strtotime($feed->push_expiration) - 300 < time())) {

          echo "Attempting to subscribe to the hub!\n";
          echo "Hub: " . $feed->push_hub_url . " (found in $hub_url_source)\n";
          echo "Topic: " . $feed->push_topic_url . " (found in $self_url_source)\n";

          // This will cause the hub to make a GET request to the callback URL which we will to verify
          $response = request\post($feed->push_hub_url, [
            'hub.mode' => 'subscribe',
            'hub.topic' => $feed->push_topic_url,
            // http for now
            'hub.callback' => 'http://' . Config::$hostname . '/push/feed/' . $feed->hash
          ]);
          echo "Hub responded:\n";
          echo $response['status']."\n";
          echo $response['body']."\n";
        }

        $feed->save();
      }

      // check if there are any h-entry posts
      $info = feeds\find_feed_info($mf2);
      if($info) {
        #print_r($info);
        foreach($info['entries'] as $i=>$e) {
          echo "\nProcessing entry $i\n";

          // Find the canonical URL for the entry and fetch the page
          $entry_url = Mf2\getPlaintext($e, 'url');
          if($entry_url) {

            echo $entry_url . "\n";

            // Parse the entry for all required info and store in the "entries" table
            $entry_html = request\get_url($entry_url);
            if($entry_html) {

              $entry_mf2 = feeds\parse_mf2($entry_html, $entry_url);
              $entries = Mf2\findMicroformatsByType($entry_mf2['items'], 'h-entry');
              $entry_mf2 = $entries[0];

              if(!Mf2\isMicroformat($entry_mf2)) {
                echo "Does not appear to be a microformat\n";
                continue;
              }
              
              if(!in_array('h-entry', $entry_mf2['type'])) {
                print_r($entry_mf2);
                continue;
              }

              if(!($entry = ORM::for_table('entries')->where('feed_id',$feed->id)->where('url',$entry_url)->find_one())) {
                $entry = ORM::for_table('entries')->create();
                $entry->feed_id = $feed->id;
                $entry->url = $entry_url;
              }

              // Decide whether to store the name, summary and content depending on whether they are unique
              $name = Mf2\getPlaintext($entry_mf2, 'name');
              $summary = Mf2\getPlaintext($entry_mf2, 'summary');
              $content = Mf2\getHtml($entry_mf2, 'content');
              $content_text = Mf2\getPlaintext($entry_mf2, 'content');

              // Store the name if it's different from the summary and the content
              if((!feeds\content_is_equal($name, $summary)) && (!feeds\content_is_equal($name, $content_text))) {
                $entry->name = $name;
                echo "Entry has a name: $name\n";
              } else {
                $entry->name = '';
              }

              // Store the summary if it's different from the content
              if($summary && !feeds\content_is_equal($summary, $content_text)) {
                $entry->summary = $summary;
                echo "Entry has a summary\n";
              } else {
                $entry->summary = '';
              }

              $entry->content = $content;


              $date_string = Mf2\getPlaintext($entry_mf2, 'published');
              if($date_string) {
                try {
                  $date = new DateTime($date_string);
                  if($date) {
                    $entry->timezone_offset = $date->format('Z');
                    $date->setTimeZone(new DateTimeZone('UTC'));
                    $entry->date_published = $date->format('Y-m-d H:i:s');
                    echo "Published: $entry->date_published\n";
                  }
                } catch(Exception $e) {
                  echo "Error parsing date: $date_string\n";
                }
              }

              // Set the date published to now if none was found in the entry
              if(!$entry->date_published) {
                $entry->date_published = date('Y-m-d H:i:s');
              }

              if(Mf2\getPlaintext($entry_mf2, 'like-of'))
                $entry->like_of_url = Mf2\getPlaintext($entry_mf2, 'like-of');
              if(Mf2\getPlaintext($entry_mf2, 'repost-of'))
                $entry->repost_of_url = Mf2\getPlaintext($entry_mf2, 'repost-of');

              // TODO: move this to a helper
              // finds the URL for a property if the property is a plain string or a nested h-cite
              if(Mf2\getPlaintext($entry_mf2, 'in-reply-to')) {
                if(Mf2\isMicroformat($entry_mf2['properties']['in-reply-to'][0]))
                  $entry->in_reply_to_url = $entry_mf2['properties']['in-reply-to'][0]['properties']['url'][0];
                else
                $entry->in_reply_to_url = Mf2\getPlaintext($entry_mf2, 'in-reply-to');
              }

              if(Mf2\getPlaintext($entry_mf2, 'photo')) {
                $entry->photo_url = Mf2\getPlaintext($entry_mf2, 'photo');
              }

              if(Mf2\getPlaintext($entry_mf2, 'video')) {
                $entry->video_url = Mf2\getPlaintext($entry_mf2, 'video');
              }

              if(Mf2\getPlaintext($entry_mf2, 'audio')) {
                $entry->audio_url = Mf2\getPlaintext($entry_mf2, 'audio');
              }


              $author_mf2 = false;
              if(Mf2\hasProp($entry_mf2, 'author')) {
                $author_mf2 = $entry_mf2['properties']['author'][0];
              } elseif(Mf2\hasProp($info, 'author')) {
                $author_mf2 = $info['properties']['author'][0];
              }
              if($author_mf2) {
                $entry->author_name = Mf2\getPlaintext($author_mf2, 'name');
                $entry->author_url = Mf2\getPlaintext($author_mf2, 'url');
                $entry->author_photo = Mf2\getPlaintext($author_mf2, 'photo');
              } else {
                echo "NO AUTHOR WAS FOUND!!\n";
              }

              if(Mf2\hasProp($entry_mf2, 'like'))
                $entry->num_likes = count($entry_mf2['properties']['like']);
              if(Mf2\hasProp($entry_mf2, 'repost'))
                $entry->num_reposts = count($entry_mf2['properties']['repost']);
              if(Mf2\hasProp($entry_mf2, 'comment'))
                $entry->num_comments = count($entry_mf2['properties']['comment']);
              if(Mf2\hasProp($entry_mf2, 'rsvp'))
                $entry->num_rsvps = count($entry_mf2['properties']['rsvp']);
              
              $entry->date_retrieved = date('Y-m-d H:i:s');
              $entry->date_updated = date('Y-m-d H:i:s');
              $entry->save();

              // Add or update all tags for this entry
              if(Mf2\hasProp($entry_mf2, 'category')) {
                $entry_tags = array_unique(array_map(function($c){
                  return strtolower(trim($c, '#'));
                }, $entry_mf2['properties']['category']));
                foreach($entry_tags as $tag) {
                  if(!ORM::for_table('entry_tags')->where('entry_id', $entry->id)->where('tag', $tag)->find_one()) {
                    $et = ORM::for_table('entry_tags')->create();
                    $et->entry_id = $entry->id;
                    $et->tag = $tag;
                    $et->save();
                  }
                }
              } else {
                $entry_tags = array();
              }

              // TODO: Remove tags that are no longer found in the entry


              // Add syndication URLs
              if(Mf2\hasProp($entry_mf2, 'syndication')) {
                $syndications = array_unique($entry_mf2['properties']['syndication']);
                foreach($syndications as $syn) {
                  if(!ORM::for_table('entry_syndications')->where('entry_id', $entry->id)->where('syndication_url', $syn)->find_one()) {
                    $es = ORM::for_table('entry_syndications')->create();
                    $es->entry_id = $entry->id;
                    $es->syndication_url = $syn;
                    $es->save();
                  }
                }
              }

              // TODO: Remove urls that are no longer found in the entry



              // Run through all the channels that have this feed and add the entry to each channel
              $sources = ORM::for_table('channel_sources')->where('feed_id', $feed_id)->find_many();
              foreach($sources as $source) {
                #$channel = ORM::for_table('channel')->where('id',$source->channel_id)->find_one();
                $add = false;
                if($source->filter) {
                  $tags = explode(',', $source->filter);
                  foreach($tags as $tag) {
                    if(preg_match('/\b'.$tag.'\b/', $entry->content."\n".$entry->name."\n".$entry->summary))
                      $add = true;
                    if(in_array(strtolower($tag), $entry_tags))
                      $add = true;
                  }
                } else {
                  $add = true;
                }
                if($add) {
                  $ce = ORM::for_table('channel_entries')->where('channel_id', $source->channel_id)->where('entry_id', $entry->id)->find_one();
                  if(!$ce) {
                    $ce = ORM::for_table('channel_entries')->create();
                    $ce->channel_id = $source->channel_id;
                    $ce->entry_id = $entry->id;
                  }
                  $ce->entry_published = $entry->date_published;
                  $ce->date_created = date('Y-m-d H:i:s');
                  $ce->save();
                  echo "Adding to channel\n";
                }
              }


            } else {
              // Bad response returned, might be 410 deleted
              // TODO: Figure out if it's a deleted post or just temporary error

            }

          } else {
            echo "No URL was found for this entry\n";
          }

        }
      }

      $feed->last_retrieved = date('Y-m-d H:i:s');

    } catch(Exception $e) {
      echo "Error processing feed!\n";
      echo $e->getMessage() . "\n";
      echo $e->getTraceAsString() . "\n";
    }

    // mark complete
    // TODO: add some exception handling that will set this to 0 on errors?
    $feed->refresh_in_progress = 0;
    $feed->save();
  }

}
