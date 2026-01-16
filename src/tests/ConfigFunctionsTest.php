<?php

use PHPUnit\Framework\TestCase;

/**
 * Test cases for config.php functions
 */
class ConfigFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        global $conn, $validSquadIDs, $clubArray;

        // Clean database before each test
        $conn->query("TRUNCATE TABLE troopers");
        $conn->query("TRUNCATE TABLE 501st_costumes");

        // Clear session
        $_SESSION = [];

        // Clear function caches
        clearFunctionCaches();

        // Set up global variables for readTKNumber tests
        $validSquadIDs = [1, 2, 3, 4, 5];
        $clubArray = [];
    }

    protected function tearDown(): void
    {
        // Clean up after each test
        $_SESSION = [];
    }

    /**
     * Test loggedIn() function
     */
    public function testLoggedInReturnsFalseWhenNotLoggedIn()
    {
        $_SESSION = [];
        $this->assertFalse(loggedIn());
    }

    public function testLoggedInReturnsTrueWhenLoggedIn()
    {
        $_SESSION['id'] = 1;
        $this->assertTrue(loggedIn());
    }

    /**
     * Test isAdmin() function
     */
    public function testIsAdminReturnsFalseWhenNotLoggedIn()
    {
        $_SESSION = [];
        $this->assertFalse(isAdmin());
    }

    public function testIsAdminReturnsFalseForRegularUser()
    {
        insertTestTrooper(1, 'Regular User', 0);
        $_SESSION['id'] = 1;

        $this->assertFalse(isAdmin());
    }

    public function testIsAdminReturnsTrueForSuperAdmin()
    {
        insertTestTrooper(1, 'Super Admin', 1);
        $_SESSION['id'] = 1;

        $this->assertTrue(isAdmin());
    }

    public function testIsAdminReturnsTrueForModerator()
    {
        insertTestTrooper(1, 'Moderator', 2);
        $_SESSION['id'] = 1;

        $this->assertTrue(isAdmin());
    }

    public function testIsAdminCachesResult()
    {
        insertTestTrooper(1, 'Admin', 1);
        $_SESSION['id'] = 1;

        // First call should hit database
        $result1 = isAdmin();

        // Second call should use cache
        $result2 = isAdmin();

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertSame($result1, $result2);
    }

    /**
     * Test getName() function
     */
    public function testGetNameReturnsCorrectName()
    {
        insertTestTrooper(1, 'John Doe', 0);

        $name = getName(1);
        $this->assertEquals('John Doe', $name);
    }

    public function testGetNameReturnsNullForNonexistentTrooper()
    {
        $name = getName(999);
        $this->assertNull($name);
    }

    public function testGetNameCachesResult()
    {
        insertTestTrooper(1, 'Jane Doe', 0);

        // First call
        $name1 = getName(1);

        // Second call should use cache
        $name2 = getName(1);

        $this->assertEquals('Jane Doe', $name1);
        $this->assertEquals('Jane Doe', $name2);
    }

    /**
     * Test getUserID() function
     */
    public function testGetUserIDReturnsCorrectUserId()
    {
        insertTestTrooper(5, 'Test User', 0, 1, 100);

        $userId = getUserID(5);
        $this->assertEquals(100, $userId);
    }

    public function testGetUserIDReturnsNullForNonexistentTrooper()
    {
        $userId = getUserID(999);
        $this->assertNull($userId);
    }

    public function testGetUserIDCachesResult()
    {
        insertTestTrooper(5, 'Test User', 0, 1, 100);

        $userId1 = getUserID(5);
        $userId2 = getUserID(5);

        $this->assertEquals(100, $userId1);
        $this->assertEquals(100, $userId2);
    }

    /**
     * Test getTrooperSquad() function
     */
    public function testGetTrooperSquadReturnsCorrectSquad()
    {
        insertTestTrooper(1, 'Squad Member', 0, 3);

        $squad = getTrooperSquad(1);
        $this->assertEquals(3, $squad);
    }

    public function testGetTrooperSquadReturnsNullForNonexistentTrooper()
    {
        $squad = getTrooperSquad(999);
        $this->assertNull($squad);
    }

    public function testGetTrooperSquadCachesResult()
    {
        insertTestTrooper(1, 'Squad Member', 0, 3);

        $squad1 = getTrooperSquad(1);
        $squad2 = getTrooperSquad(1);

        $this->assertEquals(3, $squad1);
        $this->assertEquals(3, $squad2);
    }

    /**
     * Test getTrooperForum() function
     */
    public function testGetTrooperForumReturnsCorrectForumId()
    {
        insertTestTrooper(1, 'Forum User', 0, 1, null, 'forum123');

        $forumId = getTrooperForum(1);
        $this->assertEquals('forum123', $forumId);
    }

    public function testGetTrooperForumReturnsNullForNonexistentTrooper()
    {
        $forumId = getTrooperForum(999);
        $this->assertNull($forumId);
    }

    public function testGetTrooperForumCachesResult()
    {
        insertTestTrooper(1, 'Forum User', 0, 1, null, 'forum456');

        $forumId1 = getTrooperForum(1);
        $forumId2 = getTrooperForum(1);

        $this->assertEquals('forum456', $forumId1);
        $this->assertEquals('forum456', $forumId2);
    }

    /**
     * Test session-based caching
     */
    public function testSessionCacheForCurrentUser()
    {
        insertTestTrooper(1, 'Current User', 1, 2, 100, 'forum789');
        $_SESSION['id'] = 1;

        // These should populate session cache on first call
        $name = getName(1);
        $squad = getTrooperSquad(1);
        $userId = getUserID(1);
        $forumId = getTrooperForum(1);
        $isAdmin = isAdmin();

        // Verify values are correct
        $this->assertEquals('Current User', $name);
        $this->assertEquals(2, $squad);
        $this->assertEquals(100, $userId);
        $this->assertEquals('forum789', $forumId);
        $this->assertTrue($isAdmin);
    }

    /**
     * Test readTKNumber() function
     */
    public function testReadTKNumberReturnsNotAssignedWhenTKIDIsZero()
    {
        global $validSquadIDs;
        $validSquadIDs = [1, 2, 3];

        $result = readTKNumber(0, 1, 999);
        $this->assertEquals('Not Assigned', $result);
    }

    public function testReadTKNumberReturnsDefaultTKPrefix()
    {
        global $validSquadIDs;
        $validSquadIDs = [1, 2, 3];

        // No costume entry exists, should use default "TK" prefix
        $result = readTKNumber(12345, 1, 999);
        $this->assertEquals('TK12345', $result);
    }

    public function testReadTKNumberReturnsCustomPrefix()
    {
        global $validSquadIDs;
        $validSquadIDs = [1, 2, 3];

        // Insert costume with custom prefix
        insertTestCostume(54321, 'CT');

        $result = readTKNumber(54321, 1, 999);
        $this->assertEquals('CT54321', $result);
    }

    public function testReadTKNumberCachesEntireTable()
    {
        global $validSquadIDs, $conn;
        $validSquadIDs = [1, 2, 3];

        // Insert multiple costumes
        insertTestCostume(1001, 'TK');
        insertTestCostume(1002, 'CT');
        insertTestCostume(1003, 'TB');

        // First call loads entire table
        $result1 = readTKNumber(1001, 1, 999);
        $this->assertEquals('TK1001', $result1);

        // Verify cache was populated (check global cache)
        $this->assertNotNull($GLOBALS['_function_cache']['501st_costumes']);
        $this->assertIsArray($GLOBALS['_function_cache']['501st_costumes']);

        // Second call should use cache (no database query)
        $result2 = readTKNumber(1002, 1, 999);
        $this->assertEquals('CT1002', $result2);

        // Third call should also use cache
        $result3 = readTKNumber(1003, 1, 999);
        $this->assertEquals('TB1003', $result3);
    }

    public function testReadTKNumberCachesResultPerTrooper()
    {
        global $validSquadIDs;
        $validSquadIDs = [1, 2, 3];

        insertTestCostume(2001, 'TK');

        // First call
        $result1 = readTKNumber(2001, 1, 100);

        // Second call with same parameters should return cached value
        $result2 = readTKNumber(2001, 1, 100);

        $this->assertEquals('TK2001', $result1);
        $this->assertEquals('TK2001', $result2);
        $this->assertSame($result1, $result2);
    }

    public function testReadTKNumberLoadsTableOnceForMultipleCalls()
    {
        global $validSquadIDs;
        $validSquadIDs = [1, 2, 3];

        // Insert 10 costumes to simulate realistic scenario
        for ($i = 1; $i <= 10; $i++) {
            insertTestCostume(3000 + $i, 'TK');
        }

        // Call readTKNumber 10 times (simulating the 225 calls issue)
        $results = [];
        for ($i = 1; $i <= 10; $i++) {
            $results[] = readTKNumber(3000 + $i, 1, $i);
        }

        // Verify all results are correct
        for ($i = 1; $i <= 10; $i++) {
            $this->assertEquals('TK' . (3000 + $i), $results[$i - 1]);
        }

        // Verify cache contains all entries
        $this->assertCount(10, $GLOBALS['_function_cache']['501st_costumes']);
    }

    public function testReadTKNumberHandlesMixedPrefixes()
    {
        global $validSquadIDs;
        $validSquadIDs = [1, 2, 3];

        // Insert various costume types
        insertTestCostume(4001, 'TK');   // Stormtrooper
        insertTestCostume(4002, 'CT');   // Clone Trooper
        insertTestCostume(4003, 'TB');   // TIE Pilot
        insertTestCostume(4004, 'TD');   // Scout Trooper
        insertTestCostume(4005, 'IG');   // IG-88

        // Verify each returns correct prefix
        $this->assertEquals('TK4001', readTKNumber(4001, 1, 999));
        $this->assertEquals('CT4002', readTKNumber(4002, 1, 999));
        $this->assertEquals('TB4003', readTKNumber(4003, 1, 999));
        $this->assertEquals('TD4004', readTKNumber(4004, 1, 999));
        $this->assertEquals('IG4005', readTKNumber(4005, 1, 999));
    }

    public function testReadTKNumberHandlesNonExistentLegionID()
    {
        global $validSquadIDs;
        $validSquadIDs = [1, 2, 3];

        // Insert some costumes but query for one that doesn't exist
        insertTestCostume(5001, 'TK');

        // Should return default TK prefix for non-existent ID
        $result = readTKNumber(9999, 1, 999);
        $this->assertEquals('TK9999', $result);
    }
}

// Include the functions we're testing
// config_functions.php is in the same directory as tests/
require_once __DIR__ . '/../config_functions.php';
