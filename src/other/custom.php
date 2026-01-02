<?php

/**
 * This file is used for organizing custom functions
 *
 * @author  Matthew Drennan
 *
 */

/**
 * Gets squad by location using the Google API
 *
 * @param string $address The address of the event
 * @return int Returns the ID of the squad based on location
*/
function getSquad($address)
{
	// this line is just north of Joliet IL. Anything south of this line is Blurrg Squad
	$BLURRG_DIVIDING_LINE = 41.6;
	
	$coord = getLatLong($address);
	
	if ($coord['latitude'] < $BLURRG_DIVIDING_LINE)
	{
		return 15;
	}

	return 14;
}

?>