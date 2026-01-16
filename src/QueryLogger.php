<?php

/**
 * QueryLogger - Tracks MySQL query timing and logs performance metrics
 *
 * This class wraps mysqli to automatically track all query execution times,
 * maintain a list of the slowest queries, and log detailed performance data.
 *
 * @author Query Performance Tracker
 */
class QueryLogger extends mysqli
{
    private $queryTimes = [];
    private $totalQueryTime = 0;
    private $queryCount = 0;
    private $requestStartTime;
    private $logFile;
    private $enabled = true;

    /**
     * Constructor - extends mysqli connection
     *
     * @param string $host Database host
     * @param string $username Database username
     * @param string $password Database password
     * @param string $database Database name
     * @param string $logFile Path to log file (default: logs/query_performance.log)
     */
    public function __construct($host, $username, $password, $database, $logFile = 'logs/query_performance.log')
    {
        parent::__construct($host, $username, $password, $database);

        $this->requestStartTime = microtime(true);
        $this->logFile = $logFile;

        // Ensure log directory exists
        $logDir = dirname($logFile);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Register shutdown function to log at end of request
        register_shutdown_function([$this, 'logPerformance']);
    }

    /**
     * Override query method to track timing
     *
     * @param string $query SQL query to execute
     * @param int $resultmode Result mode (optional)
     * @return mysqli_result|bool Query result
     */
    #[\ReturnTypeWillChange]
    public function query($query, $resultmode = MYSQLI_STORE_RESULT)
    {
        if (!$this->enabled) {
            return parent::query($query, $resultmode);
        }

        $startTime = microtime(true);
        $result = parent::query($query, $resultmode);
        $endTime = microtime(true);

        $duration = $endTime - $startTime;

        $this->trackQuery($query, $duration);

        return $result;
    }

    /**
     * Override prepare method to return wrapped statement
     *
     * @param string $query SQL query to prepare
     * @return QueryLoggerStatement|false Prepared statement
     */
    #[\ReturnTypeWillChange]
    public function prepare($query)
    {
        $stmt = parent::prepare($query);

        if ($stmt && $this->enabled) {
            return new QueryLoggerStatement($stmt, $query, $this);
        }

        return $stmt;
    }

    /**
     * Track a query's execution time
     *
     * @param string $query The SQL query
     * @param float $duration Execution time in seconds
     */
    public function trackQuery($query, $duration)
    {
        $this->queryCount++;
        $this->totalQueryTime += $duration;

        // Clean up query for logging (remove extra whitespace)
        $cleanQuery = preg_replace('/\s+/', ' ', trim($query));

        $this->queryTimes[] = [
            'query' => $cleanQuery,
            'duration' => $duration,
            'backtrace' => $this->getSimplifiedBacktrace()
        ];
    }

    /**
     * Get simplified backtrace (file and line only)
     *
     * @return string Simplified backtrace
     */
    private function getSimplifiedBacktrace()
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $caller = '';

        // Skip QueryLogger internal calls
        foreach ($backtrace as $trace) {
            if (isset($trace['file']) && strpos($trace['file'], 'QueryLogger.php') === false) {
                $file = basename($trace['file']);
                $line = $trace['line'] ?? '?';
                $caller = "{$file}:{$line}";
                break;
            }
        }

        return $caller;
    }

    /**
     * Get top N slowest queries
     *
     * @param int $limit Number of queries to return
     * @return array Top slowest queries
     */
    public function getTopSlowQueries($limit = 5)
    {
        $sorted = $this->queryTimes;
        usort($sorted, function($a, $b) {
            return $b['duration'] <=> $a['duration'];
        });

        return array_slice($sorted, 0, $limit);
    }

    /**
     * Get top N most duplicated queries
     *
     * @param int $limit Number of query types to return
     * @return array Top duplicated queries with count and total time
     */
    public function getTopDuplicatedQueries($limit = 5)
    {
        $queryGroups = [];

        // Group queries by normalized query string (remove parameter values)
        foreach ($this->queryTimes as $queryData) {
            $normalized = $this->normalizeQuery($queryData['query']);

            if (!isset($queryGroups[$normalized])) {
                $queryGroups[$normalized] = [
                    'query' => $queryData['query'],
                    'normalized' => $normalized,
                    'count' => 0,
                    'total_duration' => 0,
                    'avg_duration' => 0,
                    'callers' => []
                ];
            }

            $queryGroups[$normalized]['count']++;
            $queryGroups[$normalized]['total_duration'] += $queryData['duration'];

            // Track unique callers
            if (!in_array($queryData['backtrace'], $queryGroups[$normalized]['callers'])) {
                $queryGroups[$normalized]['callers'][] = $queryData['backtrace'];
            }
        }

        // Calculate averages and filter duplicates
        $duplicates = [];
        foreach ($queryGroups as $normalized => $data) {
            if ($data['count'] > 1) { // Only include queries that were executed more than once
                $data['avg_duration'] = $data['total_duration'] / $data['count'];
                $duplicates[] = $data;
            }
        }

        // Sort by count (most duplicated first)
        usort($duplicates, function($a, $b) {
            if ($b['count'] === $a['count']) {
                // If count is same, sort by total duration
                return $b['total_duration'] <=> $a['total_duration'];
            }
            return $b['count'] <=> $a['count'];
        });

        return array_slice($duplicates, 0, $limit);
    }

    /**
     * Normalize query for duplicate detection
     * Replaces numbers and strings with placeholders
     *
     * @param string $query Original query
     * @return string Normalized query
     */
    private function normalizeQuery($query)
    {
        // Replace quoted strings with ?
        $normalized = preg_replace("/'[^']*'/", '?', $query);

        // Replace numbers with ?
        $normalized = preg_replace('/\b\d+\b/', '?', $normalized);

        // Replace multiple spaces with single space
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }

    /**
     * Log performance metrics at end of request
     */
    public function logPerformance()
    {
        if (!$this->enabled || $this->queryCount === 0) {
            return;
        }

        $requestDuration = microtime(true) - $this->requestStartTime;
        $topQueries = $this->getTopSlowQueries(5);

        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'url' => $_SERVER['REQUEST_URI'] ?? 'CLI',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'total_queries' => $this->queryCount,
            'total_query_time' => round($this->totalQueryTime, 4),
            'total_request_time' => round($requestDuration, 4),
            'query_percentage' => round(($this->totalQueryTime / $requestDuration) * 100, 2),
            'avg_query_time' => round($this->totalQueryTime / $this->queryCount, 4),
            'top_5_slowest_queries' => array_map(function($q) {
                return [
                    'duration' => round($q['duration'], 4),
                    'query' => substr($q['query'], 0, 200), // Truncate long queries
                    'caller' => $q['backtrace']
                ];
            }, $topQueries),
            'top_5_duplicated_queries' => array_map(function($q) {
                return [
                    'count' => $q['count'],
                    'total_duration' => round($q['total_duration'], 4),
                    'avg_duration' => round($q['avg_duration'], 4),
                    'query' => substr($q['query'], 0, 200), // Truncate long queries
                    'callers' => $q['callers']
                ];
            }, $this->getTopDuplicatedQueries(5))
        ];

        // Format log entry
        $logLine = json_encode($logEntry, JSON_PRETTY_PRINT) . "\n" . str_repeat('-', 80) . "\n";

        // Write to log file with error suppression
        try {
            // Ensure directory exists one more time
            $logDir = dirname($this->logFile);
            if (!file_exists($logDir)) {
                @mkdir($logDir, 0755, true);
            }

            // Try to write the log
            @file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
        } catch (\Exception $e) {
            // Silently fail - don't break the application if logging fails
        }
    }

    /**
     * Enable or disable query logging
     *
     * @param bool $enabled True to enable, false to disable
     */
    public function setLoggingEnabled($enabled)
    {
        $this->enabled = $enabled;
    }

    /**
     * Get current query statistics (useful for debugging)
     *
     * @return array Current statistics
     */
    public function getStats()
    {
        return [
            'total_queries' => $this->queryCount,
            'total_time' => round($this->totalQueryTime, 4),
            'avg_time' => $this->queryCount > 0 ? round($this->totalQueryTime / $this->queryCount, 4) : 0
        ];
    }
}

/**
 * QueryLoggerStatement - Wraps mysqli_stmt to track prepared statement execution
 */
class QueryLoggerStatement
{
    private $stmt;
    private $query;
    private $logger;
    private $startTime;

    public function __construct($stmt, $query, $logger)
    {
        $this->stmt = $stmt;
        $this->query = $query;
        $this->logger = $logger;
    }

    /**
     * Override execute to track timing
     */
    public function execute($params = null)
    {
        $this->startTime = microtime(true);

        if ($params !== null) {
            $result = $this->stmt->execute($params);
        } else {
            $result = $this->stmt->execute();
        }

        $duration = microtime(true) - $this->startTime;
        $this->logger->trackQuery($this->query, $duration);

        return $result;
    }

    /**
     * Explicit bind_param - critical for proper reference handling
     */
    public function bind_param($types, &...$vars)
    {
        return $this->stmt->bind_param($types, ...$vars);
    }

    /**
     * Explicit bind_result - critical for proper reference handling
     */
    public function bind_result(&...$vars)
    {
        return $this->stmt->bind_result(...$vars);
    }

    /**
     * Pass through all other method calls to the wrapped statement
     */
    public function __call($method, $args)
    {
        return call_user_func_array([$this->stmt, $method], $args);
    }

    /**
     * Pass through property access to the wrapped statement
     */
    public function __get($name)
    {
        return $this->stmt->$name;
    }

    /**
     * Pass through property setting to the wrapped statement
     */
    public function __set($name, $value)
    {
        $this->stmt->$name = $value;
    }
}
