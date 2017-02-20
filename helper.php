<?php
/**
 * My custom helper super class.
 */

// ArcTan values used to stear bots.
define('DIR_NORTH', 1.57);
define('DIR_EAST', 0.00);
define('DIR_SOUTH', -1.57);
define('DIR_WEST', 3.14);

// Formerly these were used when converting arctan to deg, refactored for performance.
// define('DIR_NORTH', -180);
// define('DIR_EAST',    90);
// define('DIR_SOUTH',  180);
// define('DIR_WEST',   -90);

class Helper {

  // flag for determining if we have logging turned on or not.
  const LOGGING_ENABLED = false;

  // game rule
  const MAX_STRENGTH = 255;

  /**
   * This is meant to hold locations, NOT status of locations on grid
   *
   * @var array
   */
  public $map = [];

  /**
   * Bot's unique instance.
   *
   * @var string
   */
  protected $myID;

  /** @var int $boardWidth */
  protected $boardWidth;

  /** @var int $boardHeight */
  protected $boardHeight;

  /** @var int $estimatedTurns */
  protected $estimatedTurns;

  /** @var int $heartbeat */
  protected $heartbeat;

  /** @var array $moveTracker This is for tracking cells that have moved. */
  protected $moveTracker = [];

  /** @var array $movesForFrame List of Move objects that will be pased to sendFrame */
  protected $movesForFrame = [];

  /**
   * Track various owner ids, part of future planning.
   *
   * @var array $owners
   */
  public $owners = [];

  /**
   * @var GameMap $_frame Current spot in game.
   */
  private $_frame;

  /**
   * Helper constructor.
   *
   * @param string $myID the Bots id
   */
  public function __construct($myID) {
    // if this isn't here we can't do anything
    if (!function_exists('getFrame')) {
      exit('Game code not found');
    }

    $this->myID = $myID;
  }

  /**
   * Game heartbeat.
   *
   * Set the new frame.
   * Reset and frame specific counters.
   * Increase any cross frame counters.
   *
   * @param \GameMap $frame
   * @return $this
   */
  public function heartbeat(GameMap $frame) {
    $this->setFrame($frame);
    $this->clearMoves();
    $this->heartbeat++;
    return $this;
  }

  /**
   * Creates a move object.
   *
   * @param \Location $location
   * @return Helper
   */
  public function makeNextMove(Location $location) {
    $locationDetails = $this->getLocationDetails($location);
    $adjacents = $this->getAdjacents($location);
    $adjacentsDetails = $this->loadAdjacentsDetails($adjacents);

    $nonFriendlyAdjacentDetails = [];
    foreach ($adjacentsDetails as $adjDets) {
      if ($adjDets->getOwner() !== $this->myID) {
        $nonFriendlyAdjacentDetails[] = $adjDets;
      }
    }

    // it's possible that we have no non-friendlies, so we'll skip this bit
    // if we are surrounded by friends
    //
    // @todo Seek lower prod/weaker areas during early game (say first ~15%).
    //
    if (!empty($nonFriendlyAdjacentDetails)) {
      /** @var LocationDetails $target */
      $target = NULL;

      // if we are NOT in the first 15% of the game
      if ($this->getHeartbeat() > ($this->getEstimatedTurns() * .18)) {
        $sortAlgorithm = 'theMathsSorter';
      }
      else {
        $sortAlgorithm = 'theStrengthMinSorter';
      }

      $adjacentsAreSorted = usort($nonFriendlyAdjacentDetails, array(
        $this,
        $sortAlgorithm
      ));

      if ($sortAlgorithm == 'theStrengthMinSorter' && $adjacentsAreSorted) {
        $this->doLog($nonFriendlyAdjacentDetails);
      }

      if ($adjacentsAreSorted) {
        $target = array_shift($nonFriendlyAdjacentDetails);
      }

      if (!is_null($target)) {
        if ($target->getStrength() < $locationDetails->getStrength()) {
          $move = new Move($location, $target->getDirection());
          return $this->addMoveToQueueAndTrack($move);
        }
        if ($this->askAFriendToHelp($target, $locationDetails)) {
          $move = new Move($location, $target->getDirection());
          return $this->addMoveToQueueAndTrack($move);
        }
      }
    }

    // Determining if we should attack or not, if we are too weak we will sit still.
    // formerly used a multiplier of 5 -- 4 performed better
    if ($locationDetails->getStrength() < ($locationDetails->getProduction() * 4)) {
      $move = new Move($location, STILL);
      return $this->addMoveToQueueAndTrack($move);
    }

    // if we're surrounded by friends, seek out and enemy (not on the border)
    if ($this->isSurroundedByFriends($location)) {
      $move = new Move($location, $this->findClosestEnemyDirection($location));
      return $this->addMoveToQueueAndTrack($move);
    }

    $move = new Move($location, STILL);
    return $this->addMoveToQueueAndTrack($move);
  }

  /**
   * Array sorting callback method, sorts based on theMaths method.
   *
   * @param LocationDetails $a
   * @param LocationDetails $b
   *
   * @return int
   */
  public function theMathsSorter($a, $b) {
    $mathA = $this->theMaths($a);
    $mathB = $this->theMaths($b);

    if ($mathA == $mathB) {
      return 0;
    }

    return ($mathA < $mathB ? -1 : 1);
  }

  /**
   * Array sorting callback method, sorts based on Strength, min first.
   *
   * @param LocationDetails $a
   * @param LocationDetails $b
   *
   * @return int
   */
  public function theStrengthMinSorter($a, $b) {
    if ($a->getStrength() === $b->getStrength()) {
      return 0;
    }
    // lesser strength rises up
    return ($a->getStrength() > $b->getStrength() ? 1 : -1);
  }

  /**
   * Overkill Bot's heuristic method used for comparison of sites.
   *
   * @link http://forums.halite.io/t/so-youve-improved-the-random-bot-now-what/482
   *
   * @param LocationDetails $locationDetails the cell we are looking at
   *
   * @return mixed numeric of some sort, may be int, may be float.
   */
  protected function theMaths(LocationDetails $locationDetails) {
    // if the specified location is empty, return the calculated value.
    if ($locationDetails->getOwner() === 0 && $locationDetails->getStrength() > 0) {
      // @todo Determine a good way of figuring out how desirable a neighboring cell is.
      // @todo Possible give adjust weights based on the timing of the game.
      // return $locationDetails->getProduction() / $locationDetails->getStrength();
      return (pow($locationDetails->getProduction(), 2) + 1) / ($locationDetails->getStrength() + 1);
    }

    // start at 0
    $totalDamage = 0;

    $adjacents = $this->getAdjacents($locationDetails->getLocation());
    $adjacentsDetails = $this->loadAdjacentsDetails($adjacents);
    foreach ($adjacentsDetails as $ad) {
      // if the specified cell is not unoccupied and not owned by us
      // we want to track it's strength.
      if ($ad->getOwner() !== 0 && $ad->getOwner() !== $this->myID) {
        $totalDamage += $ad->getStrength();
      }
    }

    return $totalDamage;
  }

  /**
   * Maybe have a friend help us move to take over a space
   *
   * @param \LocationDetails $targetDetails
   * @param \LocationDetails $currentLocationDetails
   * @return bool
   */
  public function askAFriendToHelp(LocationDetails $targetDetails, LocationDetails $currentLocationDetails) {
    $targetAdjacents = $this->getAdjacents($targetDetails->getLocation());
    $ourPowersCombined = $currentLocationDetails->getStrength();
    $friends = [];
    foreach ($targetAdjacents as $neighbor) {
      // This is us, we can't help ourself, if we could we wouldn't be here.
      if ($this->isSameLocation($currentLocationDetails->getLocation(), $neighbor)) {
        continue;
      }

      // Our enemies cannot help us.
      if (!$this->isFriendly($neighbor)) {
        continue;
      }

      // If this friend has already moved it can't help us.
      if ($this->hasMoved($neighbor)) {
        continue;
      }

      $neighborDetails = $this->getLocationDetails($neighbor);

      if (($ourPowersCombined + $neighborDetails->getStrength()) >= 255) {
        break;
      }

      $ourPowersCombined += $neighborDetails->getStrength();
      $friends[] = $neighborDetails;
    }

    if (empty($friends)) {
      return FALSE;
    }

    if (($ourPowersCombined * .75) > $targetDetails->getStrength()) {
      // Move our friends.
      foreach ($friends as $friend) {
        $newDirection = $this->findDirectionToLocation($friend->getLocation(), $targetDetails->getLocation());
        $this->forceMoveAndTrack($friend->getLocation(), $newDirection);
      }

      return TRUE;
    }

    return FALSE;
  }

  /**
   * Checks if the specified location is at a boundary of our area on the map.
   *
   * @param Location $location The location being checked.
   *
   * @return bool
   */
  public function isBorder(Location $location) {
    $border = FALSE;

    $adjacents = $this->getAdjacents($location);

    foreach ($adjacents as $dir => $neighbor) {
      $neighborDetails = $this->getLocationDetails($neighbor, $dir);
      if ($neighborDetails->getOwner() !== $this->myID) {
        $border = TRUE;
      }
    }

    return $border;
  }

  /**
   * Check if the location is at the edge of the map (x or y is at a min/max value).
   *
   * @param Location $location The location being checked.
   *
   * @return bool
   */
  public function isOnEdge(Location $location) {
    if ($location->x == 0 || $location->x == ($this->getWidth() - 1)
      || $location->y == 0 || $location->y == ($this->getHeight() - 1)
    ) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Determine which direction we need to move to get from starting to destination.
   *
   * @param \Location $startingLocation
   * @param \Location $destinationLocation
   * @return int
   */
  public function findDirectionToLocation(Location $startingLocation, Location $destinationLocation) {
    $arctan = $this->getFrame()
      ->getAngle($startingLocation, $destinationLocation);

    switch (number_format($arctan, 2)) {
      case DIR_SOUTH:
        return SOUTH;
        break;
      case DIR_EAST:
      case 0.00:
        return EAST;
        break;
      case DIR_WEST:
        return WEST;
        break;
      case DIR_NORTH:
      default:
        return NORTH;
        break;
    }
  }

  /**
   * Find the closest enemy to the specified location.
   *
   * @param \Location $location
   * @return int
   */
  public function findClosestEnemyDirection(Location $location) {
    /*
     * Ported From Overkill Bot (JS CODE)
     */
    $direction = NORTH;
    $maxDistance = min($this->boardWidth, $this->boardHeight) / 2;

    foreach (CARDINALS as $dir) {
      $distance = 0;
      $current = $location;
      $currentDetails = $this->getLocationDetails($current, $dir);

      while ($currentDetails->getOwner() == $this->myID && $distance < $maxDistance) {
        $distance++;
        $current = $this->getFrame()->getLocation($current, $dir);
        $currentDetails = $this->getLocationDetails($current, $dir);
      }

      if ($distance < $maxDistance) {
        $direction = $dir;
        $maxDistance = $distance;
      }
    }

    return $direction;
  }

  /**
   * Create a location details object ("Site" plus extra) for the specified location.
   *
   * @param $location
   * @param int $direction
   * @return LocationDetails
   */
  public function getLocationDetails($location, $direction = -1) {
    $details = new LocationDetails;

    return $details->setLocation($location)
      ->setDirection($direction)
      ->setStrength($this->getFrame()->getSite($location)->strength)
      ->setProduction($this->getFrame()->getSite($location)->production)
      ->setOwner($this->getFrame()->getSite($location)->owner);
  }

  /**
   * Get a list of location details for specified list of adjacent locations.
   *
   * @param array $adjacents List of locations keys by direction.
   *
   * @return array of LocationDetails for the adjacents.
   */
  public function loadAdjacentsDetails($adjacents) {
    $adjacentsDetails = [];

    foreach ($adjacents as $dir => $location) {
      $adjacentsDetails[] = $this->getLocationDetails($location, $dir);
    }

    return $adjacentsDetails;
  }

  /**
   * Check to see if a loction is surrounded by friends.
   *
   * @param Location $location The spot we are checking.
   *
   * @return bool true surrounded by friends
   */
  public function isSurroundedByFriends(Location $location) {
    return $this->isSurroundedByOwner($location, $this->myID);
  }

  /**
   * Check to see if a loction is surrounded by friends.
   *
   * @param Location $location The spot we are checking.
   * @param int $ownerId The ID of the owner we are looking for.
   *
   * @return bool true surrounded by friends
   */
  public function isSurroundedByOwner(Location $location, $ownerId) {
    foreach ($this->getAdjacents($location) as $neighbor) {
      if ($this->getFrame()->getSite($neighbor)->owner !== $ownerId) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Check if the specified location is friendly (owned by us).
   *
   * @param \Location $location
   * @return bool true if it is friendly otherwise false
   */
  public function isFriendly(Location $location) {

    if ($this->getFrame()->getSite($location)->owner === $this->myID) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Checks to see if the to location are the same place
   *
   * @param Location $locationA
   * @param Location $locationB
   * @return bool
   */
  public function isSameLocation($locationA, $locationB) {
    return $locationA->x == $locationB->x && $locationA->y == $locationB->y;
  }

  /**
   * Get the locations immediately adjacent to the specified location.
   *
   * @param \Location $location
   * @return \Location[]
   */
  public function getAdjacents(Location $location) {
    if (empty($this->map[$location->x][$location->y])) {
      $this->addAdjacentLocations($location);
    }

    return $this->map[$location->x][$location->y];
  }

  /**
   * Calculate neighbors and store them.
   *
   * @param Location $location
   */
  protected function addAdjacentLocations(Location $location) {
    // get the current frame we should only have to do this once and only for the
    // very first frame of the game
    $gameMap = $this->getFrame();

    // calculate adjacent locations
    $north = $gameMap->getLocation($location, NORTH);
    $south = $gameMap->getLocation($location, SOUTH);
    $east = $gameMap->getLocation($location, EAST);
    $west = $gameMap->getLocation($location, WEST);

    // store adjacent locations
    $this->map[$location->x][$location->y] = [
      NORTH => $north,
      SOUTH => $south,
      EAST => $east,
      WEST => $west,
    ];
  }

  /**
   * Forces a cell to move in a specified direction (if it hasn't already moved.)
   *
   * @param Location $location
   * @param int $dir
   */
  public function forceMoveAndTrack($location, $dir) {
    $move = new Move($location, $dir);
    $this->addMoveToFrameQueue($move)
      ->trackLocationMove($location);
  }

  /**
   * Pushed a move onto the stack.
   *
   * @param \Move $move
   * @return $this
   */
  public function addMoveToFrameQueue(Move $move) {
    $this->movesForFrame[] = $move;
    return $this;
  }

  /**
   * Checks to see if the location has already moved in this frame.
   *
   * @param \Location $location
   * @return bool
   */
  public function hasMoved(Location $location) {
    if (in_array('x' . $location->x . 'y' . $location->y, $this->moveTracker)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Add this move to our tracking list.
   *
   * @param \Location $location
   * @return $this
   */
  public function trackLocationMove(Location $location) {
    $this->moveTracker[] = 'x' . $location->x . 'y' . $location->y;
    return $this;
  }

  /**
   * Track a move using the location of a Move object instance.
   *
   * @param \Move $move
   * @return \Helper
   */
  public function trackMove(Move $move) {
    return $this->trackLocationMove($move->loc);
  }

  /**
   * Shortcut method used to both add a Move to the queue and track it.
   *
   * @param $move
   * @return \Helper
   */
  public function addMoveToQueueAndTrack($move) {
    return $this->addMoveToFrameQueue($move)
      ->trackMove($move);
  }

  /**
   * Get the current frame
   *
   * @return \GameMap
   */
  public function getFrame() {
    return $this->_frame;
  }

  /**
   * Set the frame.
   *
   * @param \GameMap $frame
   * @return $this
   */
  public function setFrame(GameMap $frame) {
    $this->_frame = $frame;
    return $this;
  }

  /**
   * Set game board width.
   *
   * @param int $width
   * @return $this
   */
  public function setWidth($width) {
    $this->boardWidth = intval($width);
    return $this;
  }

  /**
   * Get game board width
   *
   * @return int
   */
  public function getWidth() {
    return $this->boardWidth;
  }

  /**
   * Set game board height.
   *
   * @param int $height
   * @return $this
   */
  public function setHeight($height) {
    $this->boardHeight = intval($height);
    return $this;
  }

  /**
   * Get the game board height
   *
   * @return int
   */
  public function getHeight() {
    return $this->boardHeight;
  }

  /**
   * Set an estimate of how many turns we are expecting.
   *
   * @param int $estimatedTurns
   * @return $this
   */
  public function setEstimatedTurns($estimatedTurns) {
    $this->estimatedTurns = intval($estimatedTurns);
    return $this;
  }

  /**
   * Get estimated turns
   *
   * @return int
   */
  public function getEstimatedTurns() {
    return $this->estimatedTurns;
  }

  /**
   * Get the current heartbeat (current frame number).
   *
   * @return mixed
   */
  public function getHeartbeat() {
    return $this->heartbeat;
  }

  /**
   * Setter, but we don't want this to be changed externally so this does nothing
   *
   * @param int $heartbeat Will no be used.
   * @return Helper
   */
  public function setHeartbeat($heartbeat) {
    return $this->heartbeat;
  }

  /**
   * Clear out both the moves for the current frame and the mve tracker.
   *
   * @return Helper
   */
  public function clearMoves() {
    $this->moveTracker = [];
    $this->movesForFrame = [];
    return $this;
  }

  public function getMovesForFrame() {
    return $this->movesForFrame;
  }

  /**
   * Helper function to log errors
   *
   * @param mixed $var
   * return void
   */
  public static function doLog($var) {
    if (!Helper::LOGGING_ENABLED) {
      return;
    }

    error_log(print_r($var, TRUE));
  }

  /**
   * Helper helper function for logging.
   *
   * @param mixed $var
   * return void
   */
  protected function __log($var) {
    Helper::doLog($var);
  }

}

/**
 * Class LocationDetails
 *
 * Used to hold more details about a location than just it's (x,y)
 *
 * Shhh, it's not supposed to be here, it should be in it's own file ;)
 */
class LocationDetails {
  /** @var Location $location */
  public $location;
  /** @var int $direction */
  public $direction;
  /** @var int $strength */
  public $strength;
  /** @var int $production */
  public $production;
  /** @var mixed $owner */
  public $owner;

  /**
   * @param \Location $location
   * @return $this
   */
  public function setLocation(Location $location) {
    $this->location = $location;
    return $this;
  }

  /**
   * @param $direction
   * @return $this
   */
  public function setDirection($direction) {
    $this->direction = $direction;
    return $this;
  }

  /**
   * @param $strength
   * @return $this
   */
  public function setStrength($strength) {
    $this->strength = $strength;
    return $this;
  }

  /**
   * @param $production
   * @return $this
   */
  public function setProduction($production) {
    $this->production = $production;
    return $this;
  }

  /**
   * @param $owner
   * @return $this
   */
  public function setOwner($owner) {
    $this->owner = $owner;
    return $this;
  }

  /**
   * @return \Location
   */
  public function getLocation() {
    return $this->location;
  }

  /**
   * @return int
   */
  public function getDirection() {
    return $this->direction;
  }

  /**
   * @return int
   */
  public function getStrength() {
    return $this->strength;
  }

  /**
   * @return int
   */
  public function getProduction() {
    return $this->production;
  }

  /**
   * @return mixed
   */
  public function getOwner() {
    return $this->owner;
  }
}
