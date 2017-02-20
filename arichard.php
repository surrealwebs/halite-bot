<?php

// For debuggin'
 error_reporting(E_ALL);
 ini_set('log_errors', 1);
 ini_set('error_log', '/tmp/halite_error_log');

require_once 'hlt.php';
require_once 'networking.php';
require_once 'helper.php';

list($myID, $gameMap) = getInit();
sendInit('arichard');

$helper = new Helper($myID);
$helper->setWidth($_width)   // board size
       ->setHeight($_height) // board size
       ->setEstimatedTurns(floor(10*sqrt($_width*$_height))); // game rule

while (true) {
    $gameMap = getFrame();
    $helper->heartbeat($gameMap);
    for ($y = 0; $y < $gameMap->height; ++$y) {
        for ($x = 0; $x < $gameMap->width; ++$x) {
            $currentLoc = new Location($x, $y);
            $adjacentLocations = $helper->getAdjacents($currentLoc);

            if ($gameMap->getSite($currentLoc)->owner === $myID) {
                // Check to see if we've already moved this cell because we may
                // move cells out of order if they can help a friend.
                if ($helper->hasMoved($currentLoc)) {
                    continue;
                }
                $helper->makeNextMove($currentLoc);
            }
        }
    }

    sendFrame($helper->getMovesForFrame());
}
