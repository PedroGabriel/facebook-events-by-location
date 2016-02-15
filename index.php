<?php

function _fetch($url){
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    $response = curl_exec($ch);
    return json_decode($response);
}

function calculateStarttimeDifference($currentTime,$dataString) {
  return strtotime($dataString)-$currentTime;
}

function compareVenue($a,$b) {
  if ($a['venueName'] < $b['venueName'])
    return -1;
  if ($a['venueName'] > $b['venueName'])
    return 1;
  return 0;
}

function compareTimeFromNow($a,$b) {
  if ($a['eventTimeFromNow'] < $b['eventTimeFromNow'])
    return -1;
  if ($a['eventTimeFromNow'] > $b['eventTimeFromNow'])
    return 1;
  return 0;
}

function compareDistance($a,$b) {
  $aEventDistInt = (int)($a['eventDistance']);
  $bEventDistInt = (int)($b['eventDistance']);
  if ($aEventDistInt < $bEventDistInt)
    return -1;
  if ($aEventDistInt > $bEventDistInt)
    return 1;
  return 0;
}

function comparePopularity($a,$b) {
  if (($a['eventStats']['attendingCount'] + ($a['eventStats']['maybeCount'] / 2)) < ($b['eventStats']['attendingCount'] + ($b['eventStats']['maybeCount'] / 2)))
    return 1;
  if (($a['eventStats']['attendingCount'] + ($a['eventStats']['maybeCount'] / 2)) > ($b['eventStats']['attendingCount'] + ($b['eventStats']['maybeCount'] / 2)))
    return -1;
  return 0;
}

function haversineDistance($coords1, $coords2, $isMiles) {

  $theta = $coords1[1] - $coords2[1];
  $dist = sin(deg2rad($coords1[0])) * sin(deg2rad($coords2[0])) +  cos(deg2rad($coords1[0])) * cos(deg2rad($coords2[0])) * cos(deg2rad($theta));
  $dist = acos($dist);
  $dist = rad2deg($dist);
  $miles = $dist * 60 * 1.1515;

  return ($miles * 1.609344);
  
}

if(!count(@$_GET)){
  echo json_encode(array("message"=>"Welcome to the Facebook Event Search service!"));
  exit;
}

if(isset($_GET) && count($_GET)){

  if (!isset($_GET['lat']) || !isset($_GET['lng']) || !isset($_GET['distance']) || !isset($_GET['access_token'])){
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(array("error"=>"Please specify the lat, lng, distance and access_token query parameters"));
    exit;
  } else {

    $idLimit = 50; //FB only allows 50 ids per /?ids= call
    $currentTimestamp = time();
    $venuesCount = 0;
    $venuesWithEvents = 0;
    $eventsCount = 0;

    $url = 'https://graph.facebook.com/v2.5/search?type=place&q=%2A&center='.$_GET['lat'].'%2C'.$_GET['lng'].'&distance='.($_GET['distance']*1000).'&limit=1000&fields=id&access_token='.$_GET['access_token'];

    echo "<pre>";
    $responseBody = _fetch($url);
    // print_r($responseBody);

    $ids = array();
    $tempArray = array();
    // print_r($responseBody);
    $data = $responseBody->data;

    //Set venueCount
    $venuesCount = count($data);
    //Create array of 50 places each
    foreach($data as $idObj){
      array_push($tempArray,$idObj->id);
      if(count($tempArray) >= $idLimit){
        array_push($ids,$tempArray);
        $tempArray = array();
      }
    }

    // Push the remaining places
    if (count($tempArray) > 0) {
      array_push($ids,$tempArray);
    }

    $results = array();

    //Create a Graph API request array
    foreach($ids as $idArray) {
      array_push($results,_fetch("https://graph.facebook.com/v2.5/?ids=" . implode(',',$idArray) . "&fields=id,name,cover.fields(id,source),picture.type(large),location,events.fields(id,name,cover.fields(id,source),picture.type(large),description,start_time,attending_count,declined_count,maybe_count,noreply_count).since(" . $currentTimestamp . ")&access_token=" . $_GET['access_token']));
    }
    $events = array();

    foreach($results as $resStr) {
      $resObj = $resStr;
      foreach ($resObj as $venue){

        if (isset($venue->events) && count($venue->events->data) > 0) {
          $venuesWithEvents++;
          foreach($venue->events->data as $index => $event) {
            $eventResultObj = array();
            $eventResultObj['venueId'] = $venue->id;
            $eventResultObj['venueName'] = $venue->name;
            $eventResultObj['venueCoverPicture'] = ($venue->cover ? $venue->cover->source : null);
            $eventResultObj['venueProfilePicture'] = ($venue->picture ? $venue->picture->data->url : null);
            $eventResultObj['venueLocation'] = ($venue->location ? $venue->location : null);
            $eventResultObj['eventId'] = $event->id;
            $eventResultObj['eventName'] = $event->name;
            $eventResultObj['eventCoverPicture'] = ($event->cover ? $event->cover->source : null);
            $eventResultObj['eventProfilePicture'] = ($event->picture ? $event->picture->data->url : null);
            $eventResultObj['eventDescription'] = ($event->description ? $event->description : null);
            $eventResultObj['eventStarttime'] = ($event->start_time ? $event->start_time : null);
            $eventResultObj['eventDistance'] = ($venue->location ? round((haversineDistance(array($venue->location->latitude, $venue->location->longitude), array($_GET['lat'], $_GET['lng']), false)*1000),2) : null);
            $eventResultObj['eventTimeFromNow'] = calculateStarttimeDifference($currentTimestamp, $event->start_time);
            $eventResultObj['eventStats'] = array(
              'attendingCount'=>$event->attending_count,
              'declinedCount'=>$event->declined_count,
              'maybeCount'=>$event->maybe_count,
              'noreplyCount'=>$event->noreply_count
            );
            array_push($events, $eventResultObj);
            $eventsCount++;
          }
        }
      }
    }

    //Sort if requested
    if (isset($_GET['sort']) && (strtolower($_GET['sort']) === "time" || strtolower($_GET['sort']) === "distance" || strtolower($_GET['sort']) === "venue" || strtolower($_GET['sort']) === "popularity")) {
      if (strtolower($_GET['sort']) === "time") {
        usort($events,'compareTimeFromNow');
      }
      if (strtolower($_GET['sort']) === "distance") {
        usort($events,'compareDistance');
      }
      if (strtolower($_GET['sort']) === "venue") {
        usort($events,'compareVenue');
      }
      if (strtolower($_GET['sort']) === "popularity") {
        usort($events,'comparePopularity');
      }
    }

    //Produce result object
    $send = array('events'=>$events,'metadata'=>array('venues'=>$venuesCount,'venuesWithEvents'=>$venuesWithEvents,'events'=>$eventsCount));

    echo json_encode($send);

  }

}

?>